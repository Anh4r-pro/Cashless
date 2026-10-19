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
        'mode_kasir',
        'input_manual',
        'target_rab_bulanan',
        'created_at',
    ];

    protected $casts = ['input_manual' => 'bool'];

    public function produk(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(Produk::class, 'tenant_id');
    }

    public function transaksi(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(Transaksi::class, 'tenant_id');
    }
}
