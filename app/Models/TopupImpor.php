<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use App\Support\WaktuIso;

/**
 * Satu berkas top up massal yang sudah diterapkan. batch_id UNIQUE:
 * mengunggah ulang berkas yang sama tidak menambah saldo dua kali.
 */
class TopupImpor extends Model
{
    use WaktuIso;

    public $timestamps = false;

    protected $table = 'topup_impor';

    protected $fillable = [
        'batch_id',
        'berkas',
        'sidik',
        'n_baris',
        'n_berhasil',
        'n_gagal',
        'total_saldo',
        'total_titipan',
        'operator',
        'created_at',
    ];

    protected $casts = [
            'n_baris' => 'int',
            'n_berhasil' => 'int',
            'n_gagal' => 'int',
            'total_saldo' => 'int',
            'total_titipan' => 'int',
    ];

    public function baris(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(TopupImporBaris::class, 'impor_id');
    }
}
