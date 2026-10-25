<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Jenis tabungan sampai sekarang satu daftar GLOBAL, ditawarkan apa adanya
 * ke santri lewat kios maupun pegawai lewat portal /ortu. Permintaan
 * pemilik: bedakan — pegawai mungkin ingin "Tabungan Menikah" atau
 * "Tabungan Sekolah Anak", jenis yang tidak relevan (dan janggal) untuk
 * ditawarkan ke santri, begitu juga sebaliknya.
 *
 * `untuk` bawaan 'semua' supaya SELURUH jenis yang sudah ada hari ini tetap
 * tampil persis seperti sebelumnya di kios MAUPUN portal pegawai — migrasi
 * ini sendiri tidak mengubah apa yang terlihat siapa pun sampai admin
 * sengaja menyempitkan sebuah jenis ke salah satu golongan.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement("ALTER TABLE tabungan_jenis
            ADD COLUMN untuk ENUM('semua','siswa','pegawai') NOT NULL DEFAULT 'semua' AFTER target");
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE tabungan_jenis DROP COLUMN untuk');
    }
};
