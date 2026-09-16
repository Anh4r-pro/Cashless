<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Pilihan nominal donasi. Nominal TIDAK bebas diketik siswa — hanya baris
 * di sini yang diterima server, supaya besarannya keputusan sekolah.
 */
class DonasiNominal extends Model
{
    protected $table = 'donasi_nominal';

    public $timestamps = false;

    protected $fillable = [
        'program_id',
        'jumlah',
        'urutan',
    ];

    protected $casts = [
            'jumlah' => 'int',
    ];

    public function program(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(DonasiProgram::class, 'program_id');
    }
}
