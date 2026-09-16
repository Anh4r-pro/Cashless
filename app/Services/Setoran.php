<?php

namespace App\Services;

use App\Exceptions\ApiError;
use App\Models\Siswa;
use Illuminate\Support\Facades\DB;

/**
 * Penerimaan uang masuk — satu pintu untuk top up manual, impor massal, dan
 * persetujuan pengajuan wali.
 *
 * Uang yang tidak muat di kartu TIDAK ditolak: kelebihannya mengendap di
 * titipan dan menunggu diteteskan smart top up. Itu keputusan yang disengaja —
 * menolak setoran orang tua di loket lebih merepotkan daripada menyimpannya.
 */
class Setoran
{
    /**
     * Bagi setoran menjadi porsi kartu dan porsi titipan.
     *
     * Kartu blokir dianggap tidak punya ruang sama sekali dalam mode titipan:
     * seluruh setoran diarahkan ke titipan dan menunggu kartunya dipulihkan.
     * Di luar mode titipan, saldo kartu blokir tetap boleh ditambah admin —
     * kadang justru itu yang sedang dibetulkan.
     */
    public static function bagi(Siswa $s, int $jumlah, array $cfg, bool $keTitipan): array
    {
        $topupHariIni = Rekap::topupHariIni($s->id);
        $boleh = $keTitipan && ($cfg['titipan_aktif'] ?? 0) === 1;

        $ruangKartu = $s->status === 'aktif'
            ? max(0, min($cfg['max_saldo'] - $s->saldo, $cfg['max_topup_harian'] - $topupHariIni))
            : 0;

        $keSaldo = $boleh ? min($jumlah, $ruangKartu) : $jumlah;
        $keTitip = $jumlah - $keSaldo;

        if ($keTitip > 0 && $s->saldo_titipan + $keTitip > $cfg['max_titipan']) {
            throw new ApiError(400,
                'Titipan siswa akan menjadi ' . Rupiah::f($s->saldo_titipan + $keTitip) .
                ', melebihi batas ' . Rupiah::f($cfg['max_titipan']) .
                ' — naikkan batasnya di pengaturan atau kurangi nominal');
        }

        return ['keSaldo' => $keSaldo, 'keTitipan' => $keTitip,
            'topupHariIni' => $topupHariIni, 'ruangKartu' => $ruangKartu];
    }

    /**
     * Terima setoran. Wajib dipanggil DI DALAM transaksi basis data — pemanggil
     * yang mengaturnya, supaya satu batch impor bisa dibungkus jadi satu unit.
     */
    public static function terima(int $siswaId, int $jumlah, array $opsi): array
    {
        $s0 = Siswa::find($siswaId);
        if (! $s0) {
            throw new ApiError(404, 'Pemegang kartu tidak ditemukan');
        }
        // Batas saldo & top up harian mengikuti golongan pemegangnya: kartu guru
        // memang boleh menampung lebih banyak daripada kartu siswa.
        $cfg = Pengaturan::untuk($s0);
        $bagi = self::bagi($s0, $jumlah, $cfg, (bool) ($opsi['keTitipan'] ?? false));

        if ($bagi['keSaldo'] > 0) {
            // Jalur tanpa titipan: batas harian diperiksa lebih dulu, sebab
            // keSaldo di jalur itu adalah seluruh nominal dan belum dipotong
            // sisa jatah harian.
            if ($bagi['topupHariIni'] + $bagi['keSaldo'] > $cfg['max_topup_harian']) {
                throw new ApiError(400,
                    'Melebihi batas top up harian (sudah ' . Rupiah::f($bagi['topupHariIni']) .
                    ' hari ini, batas ' . Rupiah::f($cfg['max_topup_harian']) . ')' .
                    (($cfg['titipan_aktif'] ?? 0) === 1
                        ? ' — centang "simpan kelebihan sebagai titipan" agar uangnya tetap diterima'
                        : ''));
            }
            $ubah = Siswa::where('id', $siswaId)
                ->whereRaw('saldo + ? <= ?', [$bagi['keSaldo'], $cfg['max_saldo']])
                ->update(['saldo' => DB::raw("saldo + {$bagi['keSaldo']}")]);
            if ($ubah === 0) {
                throw new ApiError(400, 'Melebihi batas saldo (saldo ' . Rupiah::f($s0->saldo) .
                    ', maksimal ' . Rupiah::f($cfg['max_saldo']) . ')');
            }
        }
        if ($bagi['keTitipan'] > 0) {
            Siswa::where('id', $siswaId)
                ->update(['saldo_titipan' => DB::raw("saldo_titipan + {$bagi['keTitipan']}")]);
        }

        $s = Siswa::find($siswaId);
        $ket = (string) $opsi['keterangan'];

        $transaksi = null;
        if ($bagi['keSaldo'] > 0) {
            $transaksi = BukuBesar::catatTransaksi(
                $opsi['txId'] ?? null, 'topup', $s, $bagi['keSaldo'], (string) $opsi['operator'],
                $bagi['keTitipan'] > 0 ? "{$ket} — " . Rupiah::f($bagi['keTitipan']) . ' masuk titipan' : $ket,
            );
        }
        $mutasi = null;
        if ($bagi['keTitipan'] > 0) {
            $mutasi = BukuBesar::catatTitipan(
                $s, 'setor', $bagi['keTitipan'], (string) $opsi['operator'],
                "{$ket} — kelebihan dari setoran " . Rupiah::f($jumlah),
                $opsi['refTitipan'] ?? null, $transaksi?->id,
            );
        }

        // Setoran yang seluruhnya mengendap di titipan tidak menghasilkan baris
        // transaksi apa pun; itu benar — saldo kartunya memang tidak berubah.
        return ['siswa' => $s, 'transaksi' => $transaksi, 'mutasi' => $mutasi,
            'ke_saldo' => $bagi['keSaldo'], 'ke_titipan' => $bagi['keTitipan']];
    }
}
