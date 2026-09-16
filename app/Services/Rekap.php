<?php

namespace App\Services;

use App\Models\Transaksi;
use App\Support\WaktuIso;
use Illuminate\Support\Facades\DB;

/** Rekap pemakaian harian — dasar seluruh pemeriksaan batas per hari. */
class Rekap
{
    /**
     * Belanja siswa hari ini, dipisah makanan & non-makanan.
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
}
