<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use App\Support\WaktuIso;

/**
 * Program donasi yang ditawarkan di kios santri.
 */
class DonasiProgram extends Model
{
    use WaktuIso;

    public $timestamps = false;

    protected $table = 'donasi_program';

    protected $fillable = [
        'nama',
        'deskripsi',
        'urutan',
        'aktif',
        'created_at',
    ];

    protected $casts = [
            'aktif' => 'integer',
    ];

    public function nominal(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(DonasiNominal::class, 'program_id');
    }
}
