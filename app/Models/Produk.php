<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Menu/barang jualan. `kategori` menentukan batas harian mana yang berlaku
 * saat barang itu dibeli (makanan vs non-makanan koperasi).
 */
class Produk extends Model
{
    protected $table = 'produk';

    public $timestamps = false;

    protected $fillable = [
        'tenant_id',
        'nama',
        // Barcode dipasang/diganti LANGSUNG oleh kasir Mart tanpa lewat
        // pengajuan: ia penanda barang, bukan syarat dagang seperti nama &
        // harga yang menentukan berapa santri dibebani.
        'barcode',
        'harga',
        'aktif',
        'kategori',
    ];

    protected $casts = [
            'aktif' => 'integer',
            'harga' => 'int',
    ];

    public function tenant(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Tenant::class, 'tenant_id');
    }
}
