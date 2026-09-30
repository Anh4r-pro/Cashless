<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Rincian barang pada satu penjualan tunai Mart. */
class MartJualItem extends Model
{
    public $timestamps = false;

    protected $table = 'mart_jual_item';

    protected $fillable = ['jual_id', 'produk_id', 'nama', 'kategori', 'harga', 'qty', 'subtotal'];

    protected $casts = ['harga' => 'int', 'qty' => 'int', 'subtotal' => 'int'];
}
