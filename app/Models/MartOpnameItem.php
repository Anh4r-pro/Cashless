<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Satu baris hitungan fisik dalam sesi opname. */
class MartOpnameItem extends Model
{
    public $timestamps = false;

    protected $table = 'mart_opname_item';

    protected $fillable = [
        'opname_id', 'produk_id', 'barcode', 'nama', 'stok_sistem',
        'stok_fisik', 'selisih', 'hpp', 'nilai_selisih', 'alasan',
    ];

    protected $casts = [
        'stok_sistem' => 'int', 'stok_fisik' => 'int', 'selisih' => 'int',
        'hpp' => 'int', 'nilai_selisih' => 'int',
    ];
}
