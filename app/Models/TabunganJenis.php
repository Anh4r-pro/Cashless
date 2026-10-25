<?php

namespace App\Models;

use App\Support\WaktuIso;
use Illuminate\Database\Eloquent\Model;

/**
 * Jenis tabungan yang ditawarkan di kios santri ATAU portal pegawai — mirip
 * DonasiProgram, tapi tanpa daftar pilihan nominal: nominal menabung bebas
 * diketik (dalam batas min/maks), karena orang menabung dengan jumlah yang
 * bervariasi.
 *
 * `untuk` ('semua'/'siswa'/'pegawai') menyempitkan siapa yang boleh
 * melihat & mengisi satu jenis — pegawai mungkin ingin "Tabungan Menikah"
 * yang janggal ditawarkan ke santri, begitu juga sebaliknya. Disaring di
 * KiosController::bekal()/tabungan() dan OrtuPegawaiController::opsi()/
 * tabungan(), bukan di sini — kolom ini murni data.
 */
class TabunganJenis extends Model
{
    use WaktuIso;

    public $timestamps = false;

    protected $table = 'tabungan_jenis';

    protected $fillable = [
        'nama',
        'deskripsi',
        'target',
        'untuk',
        'urutan',
        'aktif',
        'created_at',
    ];

    protected $casts = [
        'target' => 'integer',
        'aktif' => 'integer',
    ];
}
