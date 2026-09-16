<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use App\Support\WaktuIso;

/**
 * Setoran hasil jualan tenant ke sekolah.
 */
class Settlement extends Model
{
    use WaktuIso;

    public $timestamps = false;

    protected $table = 'settlement';

    protected $fillable = [
        'tenant_id',
        'jumlah',
        'metode',
        'bukti',
        'keterangan',
        'operator',
        'created_at',
    ];

    protected $casts = [
            'jumlah' => 'int',
    ];

    public function tenant(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Tenant::class, 'tenant_id');
    }
}
