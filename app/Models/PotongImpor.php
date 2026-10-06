<?php

namespace App\Models;

use App\Support\WaktuIso;
use Illuminate\Database\Eloquent\Model;

/**
 * Satu berkas potong massal yang pernah diterapkan.
 *
 * `sidik` adalah alasan utama tabel ini ada: ia membuat berkas yang sama
 * persis tidak bisa diterapkan dua kali, walau kode batchnya baru.
 */
class PotongImpor extends Model
{
    use WaktuIso;

    public $timestamps = false;

    protected $table = 'potong_impor';

    protected $fillable = ['batch_id', 'berkas', 'sidik', 'alasan', 'n_baris',
        'n_berhasil', 'n_gagal', 'total_potong', 'operator', 'created_at'];

    protected $casts = ['n_baris' => 'int', 'n_berhasil' => 'int', 'n_gagal' => 'int',
        'total_potong' => 'int'];
}
