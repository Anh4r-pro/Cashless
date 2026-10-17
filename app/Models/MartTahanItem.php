<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Satu baris barang pada keranjang yang tertahan. */
class MartTahanItem extends Model
{
    public $timestamps = false;

    protected $table = 'mart_tahan_item';

    protected $fillable = ['tahan_id', 'produk_id', 'nama', 'kategori', 'harga', 'qty', 'satuan', 'isi', 'subtotal'];

    protected $casts = ['harga' => 'int', 'qty' => 'int', 'subtotal' => 'int'];
}
