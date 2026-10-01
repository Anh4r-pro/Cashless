<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Riwayat pergerakan stok. Setiap perubahan angka stok meninggalkan baris di
 * sini — tanpa itu, "stoknya kok tinggal 3?" tidak akan pernah terjawab.
 */
class MartStokMutasi extends Model
{
    public $timestamps = false;

    protected $table = 'mart_stok_mutasi';

    protected $fillable = [
        'tenant_id', 'produk_id', 'jenis', 'qty', 'stok_sesudah',
        'nilai', 'ref', 'keterangan', 'operator', 'created_at',
    ];

    protected $casts = ['qty' => 'int', 'stok_sesudah' => 'int', 'nilai' => 'int'];
}
