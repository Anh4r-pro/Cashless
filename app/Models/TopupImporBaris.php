<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Rincian per baris berkas top up massal, termasuk baris yang gagal.
 */
class TopupImporBaris extends Model
{
    protected $table = 'topup_impor_baris';

    public $timestamps = false;

    protected $fillable = [
        'impor_id',
        'baris',
        'siswa_id',
        'nis',
        'nama',
        'jumlah',
        'biaya',
        'ke_saldo',
        'ke_titipan',
        'metode',
        'tgl_terima',
        'status',
        'pesan',
    ];

    protected $casts = [
            'baris' => 'int',
            'jumlah' => 'int',
            'biaya' => 'int',
            'ke_saldo' => 'int',
            'ke_titipan' => 'int',
    ];

    public function impor(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(TopupImpor::class, 'impor_id');
    }
}
