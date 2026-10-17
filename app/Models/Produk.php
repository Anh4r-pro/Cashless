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
        // Grosir: satu barcode, dua satuan. `isi_grosir` NULL = tidak dijual
        // grosir, dan itu keadaan seluruh barang lama (termasuk barang kantin
        // biasa, yang memakai tabel ini juga).
        'isi_grosir',
        'hpp_grosir',
        'harga_grosir',
        // Satu tanggal kedaluwarsa per barang, diperbarui saat nota masuk.
        'expired',
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
