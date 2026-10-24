<?php

namespace App\Services;

use App\Models\Siswa;
use App\Models\Transaksi;
use App\Support\WaktuIso;
use Illuminate\Support\Facades\DB;

/** Rekap pemakaian harian — dasar seluruh pemeriksaan batas per hari. */
class Rekap
{
    /**
     * Belanja santri hari ini, dipisah makanan & non-makanan.
     *
     * Dipisah karena jatahnya memang terpisah: transaksi yang isinya hanya
     * handuk tidak boleh memakai jatah "10x jajan/hari", dan sebungkus nasi
     * tidak boleh memakai jatah non-makanan.
     */
    public static function belanjaHariIni(int $siswaId): array
    {
        $r = Transaksi::where('tipe', 'beli')
            ->where('siswa_id', $siswaId)
            ->where('created_at', '>=', WaktuIso::awalHariIni())
            ->selectRaw('COUNT(*) AS n')
            ->selectRaw('COALESCE(SUM(-jumlah),0) AS total')
            ->selectRaw('COALESCE(SUM(nonmakanan),0) AS nm')
            ->selectRaw('COALESCE(SUM(CASE WHEN nonmakanan > 0 THEN 1 ELSE 0 END),0) AS n_nm')
            ->selectRaw('COALESCE(SUM(CASE WHEN -jumlah > nonmakanan THEN 1 ELSE 0 END),0) AS n_mk')
            ->first();

        return [
            'n' => (int) $r->n,
            'total' => (int) $r->total,
            'nm' => (int) $r->nm,
            'n_nm' => (int) $r->n_nm,
            'n_mk' => (int) $r->n_mk,
        ];
    }

    /** Total top up yang masuk ke satu kartu hari ini. */
    public static function topupHariIni(int $siswaId): int
    {
        return (int) Transaksi::where('tipe', 'topup')
            ->where('siswa_id', $siswaId)
            ->where('created_at', '>=', WaktuIso::awalHariIni())
            ->sum('jumlah');
    }

    /**
     * Sisa jatah belanja harian seorang pemegang kartu, dipakai bersama kios
     * dan kasir supaya begitu kartu ditap, semua pihak bisa melihat LEBIH
     * DULU kenapa sebuah transaksi bisa ditolak — bukan baru tahu sesudahnya.
     *
     * Dua gerbang independen per kategori (lihat KasirController::
     * periksaBatasHarian): nominal DAN jumlah kali, jadi keduanya dilaporkan.
     * Memakai Pengaturan::untuk($s), bukan sekolah() — batas per golongan
     * wajib ikut terbaca di sini.
     */
    public static function sisaHarian(Siswa $s): array
    {
        $cfg = Pengaturan::untuk($s);
        $h = self::belanjaHariIni($s->id);
        $belanjaMakanan = $h['total'] - $h['nm'];

        return [
            'makanan' => [
                'rp' => max(0, $cfg['max_belanja_harian'] - $belanjaMakanan),
                'kali' => max(0, $cfg['max_beli_harian'] - $h['n_mk']),
            ],
            'nonmakanan' => [
                'rp' => max(0, $cfg['max_nonmakanan_harian'] - $h['nm']),
                'kali' => max(0, $cfg['max_beli_nonmakanan_harian'] - $h['n_nm']),
            ],
        ];
    }
}
