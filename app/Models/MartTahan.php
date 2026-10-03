<?php

namespace App\Models;

use App\Support\WaktuIso;
use Illuminate\Database\Eloquent\Model;

/** Keranjang yang diparkir sebentar — lihat migrasi `mart_tahan`. */
class MartTahan extends Model
{
    use WaktuIso;

    public $timestamps = false;

    protected $table = 'mart_tahan';

    protected $fillable = ['tenant_id', 'catatan', 'n_item', 'subtotal', 'diskon',
        'total', 'operator', 'created_at'];

    protected $casts = ['n_item' => 'int', 'subtotal' => 'int', 'diskon' => 'int', 'total' => 'int'];

    public function item(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(MartTahanItem::class, 'tahan_id');
    }
}
