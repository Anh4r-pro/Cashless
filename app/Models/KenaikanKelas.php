<?php

namespace App\Models;

use App\Support\WaktuIso;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Satu kali kenaikan kelas massal, satu tahun ajaran.
 *
 * Pasangan (tahun_ajaran, percobaan) bersifat UNIQUE di basis data, bukan
 * sekadar diperiksa di controller: itulah yang membuat kenaikan ganda mustahil
 * sekalipun dua admin menekan tombolnya pada saat yang sama. `percobaan` maju
 * setiap kali tahun yang sama dijalankan ulang setelah pembatalan, sehingga
 * riwayat percobaan sebelumnya tidak tertimpa.
 */
class KenaikanKelas extends Model
{
    use WaktuIso;

    public $timestamps = false;

    protected $table = 'kenaikan_kelas';

    protected $fillable = [
        'tahun_ajaran', 'percobaan', 'operator', 'catatan', 'n_naik', 'n_arsip',
        'dibatalkan_pada', 'dibatalkan_oleh', 'created_at',
    ];

    protected $casts = ['n_naik' => 'integer', 'n_arsip' => 'integer', 'percobaan' => 'integer'];

    public function baris(): HasMany
    {
        return $this->hasMany(KenaikanKelasBaris::class, 'batch_id');
    }

    public function aktif(): bool
    {
        return $this->dibatalkan_pada === null;
    }
}
