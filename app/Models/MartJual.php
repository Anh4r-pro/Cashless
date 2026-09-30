<?php

namespace App\Models;

use App\Support\WaktuIso;
use Illuminate\Database\Eloquent\Model;

/**
 * Satu penjualan TUNAI di IMBOS Mart.
 *
 * Sengaja berdiri sendiri, di luar tabel `transaksi`: uang tunai tidak pernah
 * masuk kas sekolah, jadi tidak boleh ikut terhitung sebagai penjualan tenant
 * maupun hutang sekolah. Lihat catatan di migrasi `imbos_mart`.
 */
class MartJual extends Model
{
    use WaktuIso;

    public $timestamps = false;

    protected $table = 'mart_jual';

    protected $fillable = [
        'tenant_id', 'tx_id', 'total', 'uang_diterima', 'kembalian',
        'n_item', 'operator', 'created_at',
    ];

    protected $casts = [
        'total' => 'int', 'uang_diterima' => 'int', 'kembalian' => 'int', 'n_item' => 'int',
    ];

    public function item(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(MartJualItem::class, 'jual_id');
    }
}
