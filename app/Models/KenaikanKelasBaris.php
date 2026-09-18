<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Keadaan SEBELUM dan SESUDAH untuk satu santri dalam satu batch kenaikan.
 *
 * Kolom `kelas_lama` dan `status_lama` itulah yang membuat pembatalan mungkin.
 * Tanpa keduanya, pemetaan yang keliru pada operasi setahun sekali ini hanya
 * bisa dibetulkan dengan menyunting ratusan santri satu per satu.
 */
class KenaikanKelasBaris extends Model
{
    public $timestamps = false;

    protected $table = 'kenaikan_kelas_baris';

    protected $fillable = [
        'batch_id', 'siswa_id', 'kelas_lama', 'kelas_baru', 'status_lama', 'status_baru',
    ];
}
