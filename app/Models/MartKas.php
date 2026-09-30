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

    protected $fillable = [
        'tenant_id', 'tanggal', 'kas_awal', 'tunai', 'hitung_fisik',
        'selisih', 'catatan', 'operator', 'created_at',
    ];

    protected $casts = [
        'kas_awal' => 'int', 'tunai' => 'int', 'hitung_fisik' => 'int', 'selisih' => 'int',
    ];
}
