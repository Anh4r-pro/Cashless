<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Gender santri & kode keluarga — dasar aturan "transfer hanya sesama gender,
 * kecuali sesama keluarga".
 *
 * Dua-duanya NULLABLE tanpa default: ini keadaan SELURUH pemegang kartu yang
 * sudah ada (santri, SDM, tamu, VIP — tabel ini dipakai bersama lewat kolom
 * `golongan`), dan pengisiannya bertahap lewat penetapan massal per kelas,
 * bukan serentak di migrasi ini. Aturan transfernya sendiri ada di belakang
 * sakelar terpisah (setting `transfer_gender_aktif`, mati sampai admin yakin
 * datanya terisi) — lihat App\Services\Transfer.
 *
 * `jenis_kelamin` SENGAJA tidak diturunkan dari `kelas` di kode mana pun:
 * begitu diset per santri, ia harus tetap melekat walau kelasnya berubah
 * tahun depan (lihat KenaikanKelasController::terapkan() — hanya menyentuh
 * kolom `kelas`, tidak pernah kolom ini).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('siswa', function (Blueprint $t) {
            $t->enum('jenis_kelamin', ['L', 'P'])->nullable()->after('kelas');
            // Teks bebas yang disamakan admin antar kakak-adik — bukan ditebak
            // dari hp_ortu (orang tua bisa cerai, pakai nomor berbeda per anak).
            $t->string('kode_keluarga', 40)->nullable()->after('jenis_kelamin');

            $t->index('kode_keluarga');
        });
    }

    public function down(): void
    {
        Schema::table('siswa', function (Blueprint $t) {
            $t->dropIndex(['kode_keluarga']);
            $t->dropColumn(['jenis_kelamin', 'kode_keluarga']);
        });
    }
};
