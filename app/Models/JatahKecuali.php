<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Daftar pengecualian limit non-makanan harian (Seragam, Alat Sekolah, dst).
 * `wajib_keterangan` menandai entri "tangkapan" (mis. "Lainnya") yang
 * namanya sendiri tidak menjelaskan apa-apa — keterangan bebas WAJIB diisi
 * saat entri itu dipakai di transaksi, entri lain opsional.
 */
class JatahKecuali extends Model
{
    protected $table = 'jatah_kecuali';

    public $timestamps = false;

    protected $fillable = [
        'nama',
        'deskripsi',
        'wajib_keterangan',
        'urutan',
        'aktif',
    ];

    protected $casts = [
        'wajib_keterangan' => 'boolean',
        'aktif' => 'integer',
    ];
}
