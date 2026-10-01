<?php

namespace App\Models;

use App\Support\WaktuIso;
use Illuminate\Database\Eloquent\Model;

/** Satu sesi stok opname. */
class MartOpname extends Model
{
    use WaktuIso;

    public $timestamps = false;

    protected $table = 'mart_opname';

    protected $fillable = [
        'tenant_id', 'status', 'n_item', 'n_surplus', 'n_defisit',
        'nilai_surplus', 'nilai_defisit', 'nilai_bersih', 'catatan',
        'operator', 'created_at', 'selesai_pada',
    ];

    protected $casts = [
        'n_item' => 'int', 'n_surplus' => 'int', 'n_defisit' => 'int',
        'nilai_surplus' => 'int', 'nilai_defisit' => 'int', 'nilai_bersih' => 'int',
    ];

    public function item(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(MartOpnameItem::class, 'opname_id');
    }
}
