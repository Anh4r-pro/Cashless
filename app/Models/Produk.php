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
        // Khusus Mart: kategori dagang (etalase), modal, dan stok.
        // `stok` NULL berarti barang ini tidak dilacak stoknya sama sekali.
        'kategori_id',
        'hpp',
        'stok',
        'stok_min',
    ];

    protected $casts = [
            'aktif' => 'integer',
            'harga' => 'int',
            'hpp' => 'int',
            'stok' => 'int',
            'stok_min' => 'int',
    ];

    public function tenant(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Tenant::class, 'tenant_id');
    }
}
