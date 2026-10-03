<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Rincian barang pada satu penjualan tunai Mart. */
class MartJualItem extends Model
{
    public $timestamps = false;

    protected $table = 'mart_jual_item';

    // `hpp` adalah POTRET harga modal saat barang itu terjual. Tanpa potret,
    // satu kenaikan harga beli hari ini akan diam-diam mengubah laba yang
    // dilaporkan bulan lalu.
    protected $fillable = ['jual_id', 'produk_id', 'nama', 'kategori', 'harga', 'hpp',
        'qty', 'subtotal'];

    protected $casts = ['harga' => 'int', 'hpp' => 'int', 'qty' => 'int', 'subtotal' => 'int'];
}
