<?php

namespace App\Models;

use App\Support\WaktuIso;
use Illuminate\Database\Eloquent\Model;

/**
 * Pemegang kartu — santri maupun SDM, tamu, dan VIP.
 *
 * Satu tabel untuk semuanya adalah keputusan yang disengaja: seluruh buku besar
 * (transaksi, titipan_mutasi, settlement, neraca) menunjuk ke sini. Memecah SDM
 * ke tabelnya sendiri berarti memecah buku besarnya juga, dan persamaan neraca
 * kas ("tidak ada pintu lain") langsung kehilangan artinya.
 */
class Siswa extends Model
{
    use WaktuIso;

    public $timestamps = false;

    protected $table = 'siswa';

    protected $fillable = [
        'nis', 'nama', 'kelas', 'card_uid', 'saldo', 'status', 'foto',
        'nisn', 'tgl_lahir', 'hp_ortu',
        'saldo_titipan', 'smart_mode', 'smart_ambang', 'smart_isi',
        'golongan', 'jabatan', 'catatan', 'berlaku_sampai',
        'created_at',
    ];

    protected $casts = [
        'saldo' => 'int',
        'saldo_titipan' => 'int',
        'smart_ambang' => 'int',
        'smart_isi' => 'int',
    ];

    /**
     * Kolom yang TIDAK pernah boleh ikut terkirim ke klien.
     * Kunci portal wali (tgl_lahir, hp_ortu) diketahui teman sekelas, jadi
     * membocorkannya lewat layar admin sama saja membagikan kata sandinya.
     */
    protected $hidden = [];

    public function golonganRow(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Golongan::class, 'golongan', 'kode');
    }

    public function transaksi(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(Transaksi::class, 'siswa_id');
    }

    public function pin(): \Illuminate\Database\Eloquent\Relations\HasOne
    {
        return $this->hasOne(SiswaPin::class, 'siswa_id');
    }

    /** Kartu tamu/VIP lazimnya berlaku sementara. Kosong = tanpa batas waktu. */
    public function kedaluwarsa(): bool
    {
        return $this->berlaku_sampai !== ''
            && $this->berlaku_sampai < now(config('app.timezone'))->format('Y-m-d');
    }
}
