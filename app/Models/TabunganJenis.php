<?php

namespace App\Models;

use App\Support\WaktuIso;
use Illuminate\Database\Eloquent\Model;

/**
 * Jenis tabungan yang ditawarkan di kios santri — mirip DonasiProgram, tapi
 * tanpa daftar pilihan nominal: nominal menabung bebas diketik (dalam batas
 * min/maks), karena orang menabung dengan jumlah yang bervariasi.
 */
class TabunganJenis extends Model
{
    use WaktuIso;

    public $timestamps = false;

    protected $table = 'tabungan_jenis';

    protected $fillable = [
        'nama',
        'deskripsi',
        'target',
        'urutan',
        'aktif',
        'created_at',
    ];

    protected $casts = [
        'target' => 'integer',
        'aktif' => 'integer',
    ];
}
