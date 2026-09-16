<?php

namespace App\Services;

use App\Models\Settlement;
use App\Models\Transaksi;
use App\Support\WaktuIso;

/**
 * Angka berjalan satu tenant: penjualan, yang sudah disetor, dan sisanya.
 *
 * `outstanding` adalah dasar pemeriksaan setoran — sekolah tidak boleh mencatat
 * setoran melebihi penjualan yang benar-benar terjadi, karena selisihnya akan
 * muncul sebagai uang entah dari mana di neraca kas.
 */
class StatistikTenant
{
    public static function untuk(int $tenantId): array
    {
        $s = Transaksi::where('tipe', 'beli')->where('tenant_id', $tenantId)
            ->selectRaw('COALESCE(SUM(-jumlah),0) AS total')
            ->selectRaw('COALESCE(SUM(CASE WHEN created_at >= ? THEN -jumlah END),0) AS hari_ini', [WaktuIso::awalHariIni()])
            ->selectRaw('COALESCE(SUM(CASE WHEN created_at >= ? THEN -jumlah END),0) AS minggu_ini', [WaktuIso::awalHari(7)])
            ->selectRaw('COUNT(*) AS n_tx')
            ->first();

        $disetor = (int) Settlement::where('tenant_id', $tenantId)->sum('jumlah');

        return [
            'total' => (int) $s->total,
            'hari_ini' => (int) $s->hari_ini,
            'minggu_ini' => (int) $s->minggu_ini,
            'n_tx' => (int) $s->n_tx,
            'disetor' => $disetor,
            'outstanding' => (int) $s->total - $disetor,
        ];
    }
}
