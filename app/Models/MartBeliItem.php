<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Satu baris barang pada nota pembelian. */
class MartBeliItem extends Model
{
    public $timestamps = false;

    protected $table = 'mart_beli_item';

    protected $fillable = ['beli_id', 'produk_id', 'nama', 'qty', 'satuan', 'isi', 'harga',
        'subtotal', 'hpp_lama', 'expired', 'expired_lama'];

    protected $casts = ['qty' => 'int', 'isi' => 'int', 'harga' => 'int', 'subtotal' => 'int',
        'hpp_lama' => 'int'];
}
