<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use App\Support\WaktuIso;

/**
 * Kantin atau koperasi yang berjualan di sekolah.
 */
class Tenant extends Model
{
    use WaktuIso;

    public $timestamps = false;

    protected $table = 'tenant';

    protected $fillable = [
        'nama',
        'pemilik',
        'status',
        'jenis',
        'created_at',
    ];

    public function produk(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(Produk::class, 'tenant_id');
    }

    public function transaksi(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(Transaksi::class, 'tenant_id');
    }
}
