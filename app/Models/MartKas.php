<?php

namespace App\Models;

use App\Support\WaktuIso;
use Illuminate\Database\Eloquent\Model;

/**
 * Penutupan kas harian Mart — mencocokkan uang fisik di laci dengan penjualan
 * tunai yang tercatat. Satu baris per tenant per tanggal.
 */
class MartKas extends Model
{
    use WaktuIso;

    public $timestamps = false;

    protected $table = 'mart_kas';

    // Dua kantong uang, dua pasang kolom: laci dihitung fisik, transfer
    // dicocokkan ke mutasi rekening. Kolom yang lupa didaftarkan di sini akan
    // tersimpan sebagai nol, dan selisihnya terbaca seolah uangnya hilang.
    protected $fillable = [
        'tenant_id', 'tanggal', 'kas_awal', 'tunai', 'hitung_fisik', 'selisih',
        'transfer', 'keluar', 'keluar_transfer', 'hitung_transfer', 'selisih_transfer',
        'catatan', 'operator', 'created_at',
    ];

    protected $casts = [
        'kas_awal' => 'int', 'tunai' => 'int', 'hitung_fisik' => 'int', 'selisih' => 'int',
        'transfer' => 'int', 'keluar' => 'int', 'keluar_transfer' => 'int',
        'hitung_transfer' => 'int', 'selisih_transfer' => 'int',
    ];
}
