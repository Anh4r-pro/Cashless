<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Pemegang kartu — santri maupun SDM/tamu/VIP. Sengaja satu tabel: seluruh buku
 * besar (transaksi, titipan_mutasi, settlement, neraca) menunjuk ke sini, dan
 * memecahnya berarti memecah buku besarnya juga.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('siswa', function (Blueprint $t) {
            $t->id();
            $t->string('nis')->default('');
            $t->string('nama');
            $t->string('kelas')->default('');
            $t->string('card_uid')->nullable()->unique();
            $t->bigInteger('saldo')->default(0);
            $t->enum('status', ['aktif', 'blokir'])->default('aktif');
            $t->string('foto')->nullable();               // nama berkas di uploads/

            // kunci masuk portal orang tua
            $t->string('nisn')->default('');
            $t->string('tgl_lahir')->default('');         // YYYY-MM-DD
            $t->string('hp_ortu')->default('');           // hanya digit

            // saldo titipan & smart top up
            $t->bigInteger('saldo_titipan')->default(0);
            $t->enum('smart_mode', ['ikut', 'aktif', 'mati'])->default('ikut');
            $t->bigInteger('smart_ambang')->nullable();   // NULL = pakai angka sekolah
            $t->bigInteger('smart_isi')->nullable();

            // golongan pemegang kartu
            $t->string('golongan')->default('siswa');
            $t->string('jabatan')->default('');
            $t->string('catatan')->default('');
            $t->string('berlaku_sampai')->default('');    // YYYY-MM-DD, kosong = tanpa batas

            $t->char('created_at', 24)->nullable();

            $t->index('card_uid');
            $t->index('nama');
            $t->index('kelas');
            $t->index('nisn');
            $t->index('nis');
            $t->index('golongan');
        });

        /**
         * PIN kios. Tabel terpisah, bukan kolom di santri: banyak endpoint
         * mengembalikan baris santri apa adanya, jadi hash di sana berisiko
         * ikut terkirim ke layar admin/kasir tanpa disadari.
         * Tidak ada baris = PIN masih bawaan (NIS santri).
         */
        Schema::create('siswa_pin', function (Blueprint $t) {
            $t->foreignId('siswa_id')->primary()->constrained('siswa');
            $t->string('pin_hash');       // Hash::make, salt sudah di dalam hash
            $t->char('updated_at', 24)->nullable();
        });

        // Sesi portal orang tua (cookie `osid`). Terpisah total dari sesi staf
        // supaya kredensial ringan wali tidak pernah menjelma jadi sesi admin.
        Schema::create('ortu_sessions', function (Blueprint $t) {
            $t->string('token')->primary();
            $t->foreignId('siswa_id')->constrained('siswa');
            $t->unsignedBigInteger('expires_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ortu_sessions');
        Schema::dropIfExists('siswa_pin');
        Schema::dropIfExists('siswa');
    }
};
