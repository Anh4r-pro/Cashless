<?php

namespace App\Services;

use App\Models\Siswa;
use Illuminate\Support\Facades\DB;

/**
 * Memotong uang santri dari DUA kantong: saldo kartu dan saldo titipan.
 *
 * Keduanya sama-sama kewajiban sekolah dan sama-sama mengurangi kas saat
 * dipotong, tetapi mereka tinggal di buku yang berbeda — kartu di tabel
 * transaksi, titipan di buku titipan. Pemotongan yang mengambil dari keduanya
 * karena itu menghasilkan DUA baris catatan, bukan satu gabungan: masing-
 * masing buku harus tetap jujur sendiri-sendiri, dan neraca menjumlahkannya
 * dari dua tempat yang berbeda pula.
 *
 * ── Kenapa titipan tidak ikut terambil dengan sendirinya ─────────────────
 * Titipan adalah uang yang SENGAJA diparkir wali, kerap untuk keperluan yang
 * sudah mereka rencanakan. Mengambilnya hanya karena saldo kartu kebetulan
 * kurang bukan keputusan yang boleh diambil sistem sendiri. Karena itu
 * sumbernya selalu disebut dengan sadar, dan bawaannya 'kartu' — persis
 * perilaku yang berjalan sebelum berkas ini ada.
 */
class PotongSaldo
{
    public const SUMBER = ['kartu', 'kartu_titipan', 'titipan_kartu'];

    public const LABEL = [
        'kartu' => 'Kartu saja (titipan tidak disentuh)',
        'kartu_titipan' => 'Kartu dulu, sisanya dari titipan',
        'titipan_kartu' => 'Titipan dulu, sisanya dari kartu',
    ];

    public static function sumberSah(?string $s): string
    {
        return in_array($s, self::SUMBER, true) ? $s : 'kartu';
    }

    /**
     * Hitung berapa yang diambil dari mana — TANPA menyentuh apa pun.
     *
     * Dipakai pratinjau maupun penerapan, supaya angka yang disetujui orang di
     * layar adalah angka yang benar-benar dijalankan.
     *
     * `sebisanya` = ambil sebanyak yang ada walau kurang dari yang diminta.
     * Tanpa itu, kekurangan sepeser pun membuat seluruh baris dilewati —
     * dan itu memang bawaannya, sebab hasil yang setengah jadi lebih sulit
     * ditelusuri daripada hasil yang tidak jadi sama sekali.
     *
     * @return array{kartu:int, titipan:int, total:int, kurang:int, cukup:bool}
     */
    public static function bagi(Siswa $s, int $jumlah, string $sumber, bool $sebisanya): array
    {
        $sumber = self::sumberSah($sumber);
        $adaKartu = max(0, (int) $s->saldo);
        $adaTitipan = $sumber === 'kartu' ? 0 : max(0, (int) $s->saldo_titipan);

        $sisa = max(0, $jumlah);
        $kartu = 0;
        $titipan = 0;

        if ($sumber === 'titipan_kartu') {
            $titipan = min($sisa, $adaTitipan);
            $sisa -= $titipan;
            $kartu = min($sisa, $adaKartu);
            $sisa -= $kartu;
        } else {
            $kartu = min($sisa, $adaKartu);
            $sisa -= $kartu;
            $titipan = min($sisa, $adaTitipan);
            $sisa -= $titipan;
        }

        $cukup = $sisa === 0;
        if (! $cukup && ! $sebisanya) {
            // Kurang dan tidak boleh sebisanya: tidak ada yang diambil sama sekali.
            return ['kartu' => 0, 'titipan' => 0, 'total' => 0, 'kurang' => $sisa, 'cukup' => false];
        }

        return ['kartu' => $kartu, 'titipan' => $titipan, 'total' => $kartu + $titipan,
            'kurang' => $sisa, 'cukup' => $cukup];
    }

    /**
     * Jalankan pemotongannya. WAJIB dipanggil di dalam transaksi basis data —
     * pemanggilnya yang mengaturnya, supaya satu kelas bisa dibungkus jadi
     * satu unit.
     *
     * Pengurangannya BERSYARAT di basis data (`where saldo >= n`), bukan
     * hitung-lalu-simpan: saldo bisa berubah oleh jajan di detik yang sama,
     * dan kartu minus adalah angka yang tidak punya arti apa pun di sistem ini.
     *
     * @return array{kartu:int, titipan:int, total:int, transaksi:mixed, mutasi:mixed, siswa:Siswa}
     */
    public static function ambil(Siswa $s, array $bagi, string $operator, string $ket,
        ?string $txId = null, ?string $refTitipan = null): array
    {
        $transaksi = null;
        $mutasi = null;

        if ($bagi['kartu'] > 0) {
            $n = (int) $bagi['kartu'];
            $ubah = Siswa::where('id', $s->id)->where('saldo', '>=', $n)
                ->update(['saldo' => DB::raw("saldo - {$n}")]);
            if ($ubah === 0) {
                return ['kartu' => 0, 'titipan' => 0, 'total' => 0, 'transaksi' => null,
                    'mutasi' => null, 'siswa' => Siswa::find($s->id), 'gagal' => 'saldo kartu berubah'];
            }
        }
        if ($bagi['titipan'] > 0) {
            $n = (int) $bagi['titipan'];
            $ubah = Siswa::where('id', $s->id)->where('saldo_titipan', '>=', $n)
                ->update(['saldo_titipan' => DB::raw("saldo_titipan - {$n}")]);
            if ($ubah === 0) {
                // Kartunya sudah terlanjur dipotong di atas; pemanggil berada di
                // dalam transaksi basis data, jadi melempar di sini membatalkan
                // keduanya sekaligus.
                throw new \App\Exceptions\ApiError(409,
                    'Titipan berubah saat pemotongan berjalan — ulangi dari awal');
            }
        }

        $baru = Siswa::find($s->id);
        if ($bagi['kartu'] > 0) {
            // Keterangan menyebut pecahannya hanya ketika memang terbagi dua,
            // supaya riwayat pemotongan biasa tidak jadi berisik.
            $ketKartu = $bagi['titipan'] > 0
                ? $ket . ' — ' . Rupiah::f($bagi['kartu']) . ' dari kartu, ' .
                  Rupiah::f($bagi['titipan']) . ' dari titipan'
                : $ket;
            $transaksi = BukuBesar::catatTransaksi($txId, 'koreksi', $baru, -(int) $bagi['kartu'],
                $operator, mb_substr($ketKartu, 0, 300));
        }
        if ($bagi['titipan'] > 0) {
            $ketTitipan = $bagi['kartu'] > 0
                ? $ket . ' — ' . Rupiah::f($bagi['titipan']) . ' dari titipan, ' .
                  Rupiah::f($bagi['kartu']) . ' dari kartu'
                : $ket;
            $mutasi = BukuBesar::catatTitipan($baru, 'tarik', -(int) $bagi['titipan'],
                $operator, mb_substr($ketTitipan, 0, 300), $refTitipan, $transaksi?->id);
        }

        return ['kartu' => (int) $bagi['kartu'], 'titipan' => (int) $bagi['titipan'],
            'total' => (int) $bagi['kartu'] + (int) $bagi['titipan'],
            'transaksi' => $transaksi, 'mutasi' => $mutasi, 'siswa' => Siswa::find($s->id)];
    }
}
