<?php

namespace App\Models;

use App\Support\WaktuIso;
use Illuminate\Database\Eloquent\Model;

/** Kategori dagang Mart (Sembako, ATK, …) — label etalase, bukan penentu jatah belanja. */
class MartKategori extends Model
{
    use WaktuIso;

    public $timestamps = false;

    protected $table = 'mart_kategori';

    protected $fillable = ['tenant_id', 'nama', 'urutan', 'aktif', 'created_at'];

    protected $casts = ['aktif' => 'integer', 'urutan' => 'integer'];
}
