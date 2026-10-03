<?php

namespace App\Models;

use App\Support\WaktuIso;
use Illuminate\Database\Eloquent\Model;

/**
 * Satu kali pembayaran ke pemasok.
 *
 * Uang KELUAR, dan karena itu ia ikut dihitung saat tutup kas: membayar
 * pemasok dari laci membuat isi laci berkurang, dan laci yang berkurang tanpa
 * catatan hanya akan terbaca sebagai selisih.
 */
class MartBeliBayar extends Model
{
    use WaktuIso;

    public $timestamps = false;

    protected $table = 'mart_beli_bayar';

    protected $fillable = ['beli_id', 'tenant_id', 'supplier_id', 'jumlah', 'metode',
        'bank', 'catatan', 'operator', 'created_at'];

    protected $casts = ['jumlah' => 'int'];
}
