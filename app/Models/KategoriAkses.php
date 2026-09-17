<?php

namespace App\Models;

use App\Support\WaktuIso;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Kategori akses — peran yang bisa dibuat dan disetel admin.
 *
 * Yang tersimpan di tabel fitur hanya sakelar yang MENYALA. Fitur baru yang
 * kelak ditambahkan ke katalog karenanya mati untuk semua kategori lama, dan
 * harus dinyalakan dengan sadar — bukan menyebar diam-diam ke semua orang.
 */
class KategoriAkses extends Model
{
    use WaktuIso;

    public $timestamps = false;

    protected $table = 'kategori_akses';

    protected $fillable = ['nama', 'deskripsi', 'terkunci', 'bawaan', 'urutan', 'created_at'];

    /** 0/1, bukan true/false — seluruh frontend membandingkannya dengan angka. */
    protected $casts = [
        'terkunci' => 'integer',
        'bawaan' => 'integer',
        'urutan' => 'integer',
    ];

    public function fitur(): HasMany
    {
        return $this->hasMany(KategoriAksesFitur::class, 'kategori_id');
    }

    public function users(): HasMany
    {
        return $this->hasMany(User::class, 'kategori_id');
    }
}
