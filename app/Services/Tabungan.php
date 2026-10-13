<?php

namespace App\Services;

use App\Exceptions\ApiError;
use App\Models\Siswa;
use App\Models\TabunganJenis;
use App\Models\TabunganMutasi;
use App\Models\TabunganSaldo;
use App\Support\WaktuIso;
use Illuminate\Support\Facades\DB;

/**
 * Tabungan santri — uang milik santri sendiri yang disisihkan untuk dipakai
 * NANTI oleh santri yang sama, bukan disumbangkan ke pihak lain. Karena itu
 * mesinnya berbeda dari Donasi (satu arah, permanen): setiap rupiah yang
 * masuk ke sini harus bisa kembali ke kartu.
 *
 * Tipe transaksi/titipan 'tabungan' SENGAJA tidak dihitung sebagai uang
 * masuk/keluar oleh Neraca (lihat Neraca::ringkasTransaksi/ringkasTitipan) —
 * ini cuma uang berpindah kantong di dalam sistem yang sama, bukan uang baru
 * ataupun uang yang pergi. Yang berubah hanyalah DI MANA kewajiban itu
 * dicatat: di `saldo`/`saldo_titipan`, atau di `tabungan_saldo`.
 */
class Tabungan
{
    /** Menabung ke satu jenis — sumbernya kartu saja, atau campur titipan (admin). WAJIB dalam DB::transaction. */
    public static function setor(Siswa $s, TabunganJenis $jenis, int $jumlah, string $sumber,
        string $operator, string $keterangan, ?string $txId = null, ?string $refTitipan = null): array
    {
        if ($jumlah <= 0) {
            throw new ApiError(400, 'Nominal menabung tidak valid');
        }
        $bagi = PotongSaldo::bagi($s, $jumlah, $sumber, false);
        if (! $bagi['cukup']) {
            throw new ApiError(402, 'Saldo tidak cukup untuk menabung sejumlah itu');
        }

        if ($bagi['kartu'] > 0) {
            $n = (int) $bagi['kartu'];
            $ubah = Siswa::where('id', $s->id)->where('saldo', '>=', $n)
                ->update(['saldo' => DB::raw("saldo - {$n}")]);
            if ($ubah === 0) {
                throw new ApiError(409, 'Saldo berubah saat menabung — ulangi dari awal');
            }
        }
        if ($bagi['titipan'] > 0) {
            $n = (int) $bagi['titipan'];
            $ubah = Siswa::where('id', $s->id)->where('saldo_titipan', '>=', $n)
                ->update(['saldo_titipan' => DB::raw("saldo_titipan - {$n}")]);
            if ($ubah === 0) {
                throw new ApiError(409, 'Titipan berubah saat menabung — ulangi dari awal');
            }
        }

        $baru = Siswa::find($s->id);
        $transaksi = null;
        if ($bagi['kartu'] > 0) {
            $ketKartu = $bagi['titipan'] > 0
                ? $keterangan . ' — ' . Rupiah::f($bagi['kartu']) . ' dari kartu, ' .
                  Rupiah::f($bagi['titipan']) . ' dari titipan'
                : $keterangan;
            $transaksi = BukuBesar::catatTransaksi($txId, 'tabungan', $baru, -(int) $bagi['kartu'],
                $operator, mb_substr($ketKartu, 0, 300));
        }
        if ($bagi['titipan'] > 0) {
            $ketTitipan = $bagi['kartu'] > 0
                ? $keterangan . ' — ' . Rupiah::f($bagi['titipan']) . ' dari titipan, ' .
                  Rupiah::f($bagi['kartu']) . ' dari kartu'
                : $keterangan;
            BukuBesar::catatTitipan($baru, 'tabungan', -(int) $bagi['titipan'],
                $operator, mb_substr($ketTitipan, 0, 300), $refTitipan, $transaksi?->id);
        }

        $sumberDicatat = $bagi['titipan'] > 0 ? ($bagi['kartu'] > 0 ? 'kartu_titipan' : 'titipan') : 'kartu';
        $saldoRow = self::tambah($baru, $jenis, $jumlah, $sumberDicatat, $operator, $keterangan, $transaksi?->id);

        return ['kartu' => $bagi['kartu'], 'titipan' => $bagi['titipan'], 'total' => $jumlah,
            'siswa' => $baru, 'saldo_tabungan' => $saldoRow];
    }

    private static function tambah(Siswa $s, TabunganJenis $jenis, int $jumlah, string $sumber,
        string $operator, string $keterangan, ?int $transaksiId): TabunganSaldo
    {
        $row = TabunganSaldo::where('siswa_id', $s->id)->where('jenis_id', $jenis->id)->first();
        if (! $row) {
            $row = TabunganSaldo::create(['siswa_id' => $s->id, 'jenis_id' => $jenis->id, 'saldo' => 0,
                'updated_at' => WaktuIso::sekarang()]);
        }
        TabunganSaldo::where('id', $row->id)
            ->update(['saldo' => DB::raw("saldo + {$jumlah}"), 'updated_at' => WaktuIso::sekarang()]);
        $row = TabunganSaldo::find($row->id);

        TabunganMutasi::create([
            'siswa_id' => $s->id, 'jenis_id' => $jenis->id, 'jenis_mutasi' => 'masuk',
            'jumlah' => $jumlah, 'saldo_sesudah' => $row->saldo, 'sumber' => $sumber,
            'transaksi_id' => $transaksiId, 'operator' => $operator,
            'keterangan' => mb_substr($keterangan, 0, 300), 'created_at' => WaktuIso::sekarang(),
        ]);

        return $row;
    }

    /**
     * Cairkan SELURUH saldo satu jenis, kembali ke kartu — admin-only, dan
     * sengaja tidak bisa sebagian: mencairkan berarti menutup pos tabungan
     * ini, bukan menarik dikit-dikit sembarang waktu seperti titipan.
     */
    public static function cairkan(Siswa $s, TabunganJenis $jenis, string $operator,
        string $keterangan, ?string $txId = null): array
    {
        $row = TabunganSaldo::where('siswa_id', $s->id)->where('jenis_id', $jenis->id)->first();
        $jumlah = $row ? (int) $row->saldo : 0;
        if ($jumlah <= 0) {
            throw new ApiError(400, 'Santri ini tidak punya saldo tabungan jenis ini');
        }

        // Dananya mengalir lewat aturan yang SAMA dengan setiap uang lain yang
        // "datang" ke santri (kartu dulu sampai batas golongannya, sisanya ke
        // titipan) — bukan dipaksa seluruhnya ke kartu, yang bisa membuat
        // saldo kartu melampaui plafon golongannya.
        $cfg = Pengaturan::untuk($s);
        $ruangKartu = max(0, $cfg['max_saldo'] - (int) $s->saldo);
        $keKartu = min($jumlah, $ruangKartu);
        $keTitipan = $jumlah - $keKartu;
        if ($keTitipan > 0) {
            $ruangTitipan = max(0, $cfg['max_titipan'] - (int) $s->saldo_titipan);
            if ($keTitipan > $ruangTitipan) {
                throw new ApiError(400, 'Kartu dan titipan santri ini sudah di batas maksimal — ' .
                    'kosongkan dulu sebagian sebelum mencairkan');
            }
        }

        TabunganSaldo::where('id', $row->id)
            ->update(['saldo' => 0, 'updated_at' => WaktuIso::sekarang()]);
        if ($keKartu > 0) {
            Siswa::where('id', $s->id)->update(['saldo' => DB::raw("saldo + {$keKartu}")]);
        }
        if ($keTitipan > 0) {
            Siswa::where('id', $s->id)->update(['saldo_titipan' => DB::raw("saldo_titipan + {$keTitipan}")]);
        }
        $baru = Siswa::find($s->id);

        $ket = mb_substr($keterangan, 0, 300);
        $transaksi = null;
        if ($keKartu > 0) {
            $ketKartu = $keTitipan > 0
                ? $ket . ' — ' . Rupiah::f($keKartu) . ' ke kartu, ' . Rupiah::f($keTitipan) . ' ke titipan'
                : $ket;
            $transaksi = BukuBesar::catatTransaksi($txId, 'tabungan', $baru, $keKartu, $operator,
                mb_substr($ketKartu, 0, 300));
        }
        if ($keTitipan > 0) {
            $ketTitipan = $keKartu > 0
                ? $ket . ' — ' . Rupiah::f($keTitipan) . ' ke titipan, ' . Rupiah::f($keKartu) . ' ke kartu'
                : $ket;
            BukuBesar::catatTitipan($baru, 'tabungan', $keTitipan, $operator,
                mb_substr($ketTitipan, 0, 300), null, $transaksi?->id);
        }

        TabunganMutasi::create([
            'siswa_id' => $s->id, 'jenis_id' => $jenis->id, 'jenis_mutasi' => 'keluar',
            'jumlah' => $jumlah, 'saldo_sesudah' => 0,
            'sumber' => $keTitipan > 0 ? ($keKartu > 0 ? 'kartu_titipan' : 'titipan') : 'kartu',
            'transaksi_id' => $transaksi?->id, 'operator' => $operator,
            'keterangan' => mb_substr($keterangan, 0, 300), 'created_at' => WaktuIso::sekarang(),
        ]);

        return ['jumlah' => $jumlah, 'siswa' => $baru];
    }

    /** Total tabungan (semua jenis) satu santri — dipakai kios & portal wali. */
    public static function daftarSaldo(int $siswaId): array
    {
        return TabunganSaldo::where('siswa_id', $siswaId)->where('saldo', '>', 0)
            ->with('jenis')->get()
            ->map(fn ($r) => ['jenis_id' => $r->jenis_id, 'nama' => $r->jenis?->nama,
                'target' => $r->jenis?->target, 'saldo' => (int) $r->saldo])
            ->values()->all();
    }

    /** Berapa yang sudah ditabung (semua jenis) hari ini — untuk batas harian kios. */
    public static function hariIni(int $siswaId): int
    {
        return (int) TabunganMutasi::where('siswa_id', $siswaId)->where('jenis_mutasi', 'masuk')
            ->where('created_at', '>=', WaktuIso::awalHariIni())->sum('jumlah');
    }
}
