<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Kategori akses: peran yang bisa dibuat & disetel admin, bukan lagi daftar
 * tetap di dalam kode.
 *
 * Satu kategori memegang sekumpulan fitur yang boleh dijalankan. Yang disimpan
 * di pivot HANYA fitur yang menyala — baris yang tidak ada berarti mati. Cara
 * ini membuat fitur yang kelak ditambahkan ke katalog otomatis mati untuk
 * kategori lama, bukan menyala diam-diam untuk semua orang.
 *
 * `terkunci` menandai kategori "Akses Penuh": ia selalu memegang seluruh
 * katalog dan tidak bisa disunting maupun dihapus lewat antarmuka. Tanpa
 * pengaman itu, satu kekeliruan mematikan sakelar bisa mengunci semua orang
 * keluar dari menu pengelolaan akun — dan tidak ada jalan masuk kembali.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('kategori_akses', function (Blueprint $t) {
            $t->id();
            $t->string('nama');
            $t->string('deskripsi', 300)->default('');
            $t->boolean('terkunci')->default(false);   // Akses Penuh — tak bisa disunting
            $t->boolean('bawaan')->default(false);     // dibuat seeder, bukan admin
            $t->integer('urutan')->default(0);
            $t->char('created_at', 24)->nullable();
        });

        Schema::create('kategori_akses_fitur', function (Blueprint $t) {
            $t->foreignId('kategori_id')->constrained('kategori_akses')->cascadeOnDelete();
            $t->string('fitur', 60);
            $t->primary(['kategori_id', 'fitur']);
        });

        Schema::table('users', function (Blueprint $t) {
            $t->foreignId('kategori_id')->nullable()->after('role')->constrained('kategori_akses');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $t) {
            $t->dropConstrainedForeignId('kategori_id');
        });
        Schema::dropIfExists('kategori_akses_fitur');
        Schema::dropIfExists('kategori_akses');
    }
};
