<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use App\Support\WaktuIso;

/**
 * Golongan pemegang kartu. Kolom max_* adalah PENIMPA batas sekolah:
 * NULL berarti "ikut pengaturan sekolah", bukan nol.
 */
class Golongan extends Model
{
    use WaktuIso;

    public $timestamps = false;

    protected $table = 'golongan';

    protected $fillable = [
        'kode',
        'nama',
        'label_unit',
        'jenis',
        'bawaan',
        'urutan',
        'aktif',
        'portal_ortu',
        'kios',
        'transfer',
        'biaya_admin_kena',
        'max_saldo',
        'max_topup_harian',
        'max_beli_harian',
        'max_belanja_harian',
        'max_per_transaksi',
        'max_nonmakanan_harian',
        'max_beli_nonmakanan_harian',
        'max_nonmakanan_transaksi',
        'created_at',
    ];

    /**
     * Penanda disimpan sebagai 0/1, BUKAN true/false: seluruh frontend dan
     * suite uji membandingkannya dengan === terhadap angka, persis seperti
     * yang dikembalikan basis data lama.
     */
    protected $casts = [
            'bawaan' => 'integer',
            'aktif' => 'integer',
            'portal_ortu' => 'integer',
            'kios' => 'integer',
            'transfer' => 'integer',
            'biaya_admin_kena' => 'integer',
    ];
}
