<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * "Tetap masuk di HP ini" — sesi portal wali yang bergeser, bukan tetap.
 *
 * `login_at` adalah jangkar tanggal login SEBENARNYA, terpisah dari
 * `expires_at` yang boleh bergeser maju tiap kali sesi dipakai (lihat
 * Sesi::perpanjangOrtuJikaPerlu). Tanpa jangkar ini, sesi yang rutin dipakai
 * tidak akan pernah punya batas akhir — `expires_at` akan selalu didorong
 * maju sebelum sempat kedaluwarsa.
 *
 * `remember` membedakan sesi biasa (2 jam, tetap, seperti sebelumnya) dari
 * sesi "tetap masuk" (bergeser 30 hari, dengan batas mutlak dihitung dari
 * `login_at`). Sesi lama yang sudah ada sebelum migrasi ini tetap berperilaku
 * seperti dulu — `remember` bawaannya false.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ortu_sessions', function (Blueprint $t) {
            $t->boolean('remember')->default(false)->after('siswa_id');
            $t->unsignedBigInteger('login_at')->nullable()->after('remember');
        });
    }

    public function down(): void
    {
        Schema::table('ortu_sessions', function (Blueprint $t) {
            $t->dropColumn(['remember', 'login_at']);
        });
    }
};
