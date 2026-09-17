<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Satu sakelar yang menyala. Tabelnya berkunci gabungan (kategori_id, fitur)
 * dan tanpa kolom id — baris kembar mustahil tercipta, sehingga menyalakan
 * fitur yang sudah menyala bukan galat.
 */
class KategoriAksesFitur extends Model
{
    public $timestamps = false;

    public $incrementing = false;

    protected $table = 'kategori_akses_fitur';

    protected $fillable = ['kategori_id', 'fitur'];
}
