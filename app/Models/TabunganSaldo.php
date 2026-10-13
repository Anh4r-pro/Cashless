<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Saldo tabungan PER (santri, jenis) — satu santri boleh punya banyak baris,
 * satu per jenis tabungan yang pernah diisinya.
 */
class TabunganSaldo extends Model
{
    public $timestamps = false;

    protected $table = 'tabungan_saldo';

    protected $fillable = [
        'siswa_id',
        'jenis_id',
        'saldo',
        'updated_at',
    ];

    protected $casts = [
        'saldo' => 'integer',
    ];

    public function jenis(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(TabunganJenis::class, 'jenis_id');
    }
}
