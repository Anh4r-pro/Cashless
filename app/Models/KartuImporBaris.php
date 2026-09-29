<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Rincian per baris/tap sesi pendaftaran kartu RFID kolektif, termasuk yang gagal.
 */
class KartuImporBaris extends Model
{
    protected $table = 'kartu_impor_baris';

    public $timestamps = false;

    protected $fillable = [
        'impor_id',
        'baris',
        'siswa_id',
        'nis',
        'nama',
        'uid',
        'uid_lama',
        'status',
        'pesan',
    ];

    protected $casts = [
        'baris' => 'int',
    ];

    public function impor(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(KartuImpor::class, 'impor_id');
    }
}
