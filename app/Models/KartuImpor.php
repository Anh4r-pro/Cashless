<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use App\Support\WaktuIso;

/**
 * Satu sesi pendaftaran kartu RFID kolektif yang sudah diterapkan — dari
 * berkas Excel atau dari sesi tap berturut-turut. batch_id UNIQUE: mengirim
 * batch yang sama dua kali tidak mendaftarkan ulang kartu yang sudah tersimpan.
 */
class KartuImpor extends Model
{
    use WaktuIso;

    public $timestamps = false;

    protected $table = 'kartu_impor';

    protected $fillable = [
        'batch_id',
        'jenis',
        'sumber',
        'berkas',
        'sidik',
        'n_baris',
        'n_berhasil',
        'n_gagal',
        'operator',
        'created_at',
    ];

    protected $casts = [
        'n_baris' => 'int',
        'n_berhasil' => 'int',
        'n_gagal' => 'int',
    ];

    public function baris(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(KartuImporBaris::class, 'impor_id');
    }
}
