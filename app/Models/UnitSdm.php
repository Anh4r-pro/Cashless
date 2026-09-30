<?php

namespace App\Models;

use App\Support\WaktuIso;
use Illuminate\Database\Eloquent\Model;

/**
 * Daftar Unit untuk SDM & personil — dulu angka tetap di kode
 * (BidangSiswa::UNIT_SDM), sekarang bisa admin sunting sendiri.
 */
class UnitSdm extends Model
{
    use WaktuIso;

    public $timestamps = false;

    protected $table = 'unit_sdm';

    protected $fillable = ['nama', 'urutan', 'aktif', 'created_at'];

    /**
     * Penanda disimpan sebagai 0/1, BUKAN true/false — mengikuti pola yang
     * sama seperti Golongan, supaya frontend & uji bisa membandingkannya
     * dengan === terhadap angka.
     */
    protected $casts = ['aktif' => 'integer'];

    /** Nama unit yang aktif, terurut — inilah yang dipakai dropdown & validasi impor. */
    public static function aktifNama(): array
    {
        return static::where('aktif', 1)->orderBy('urutan')->orderBy('id')->pluck('nama')->all();
    }
}
