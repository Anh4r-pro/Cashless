<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Kategori member — penentu SIAPA yang boleh berhutang, dan pilihan keterangan.
 *
 * Sebelum ini "boleh berhutang" hanya ditentukan plafon per orang: plafon 0
 * berarti tidak boleh. Itu bekerja, tetapi keputusannya tersebar di ratusan
 * baris member dan tidak ada tempat untuk menyatakan aturannya sekali — mis.
 * "wali kelas boleh, warung luar tidak". Kategori memberi tempat itu.
 *
 * Kategori TIDAK menggantikan plafon, ia menyaring lebih dulu: kategori yang
 * `boleh_hutang`-nya mati menolak hutang berapa pun plafonnya. Dengan begitu
 * seluruh member yang sudah ada — yang kategorinya NULL — berperilaku persis
 * seperti sebelumnya, dan tidak ada hutang berjalan yang tiba-tiba berubah
 * aturannya.
 *
 * `mart_member_ket` adalah daftar pilihan untuk kolom Keterangan, mengikuti
 * pola tabel `alasan_potong` yang sudah ada. Kolom `mart_member.keterangan`
 * sengaja TETAP berupa teks, bukan diganti kunci asing: nilai bebas yang sudah
 * terlanjur diketik admin tetap terbaca apa adanya, dan tidak ada data yang
 * perlu dipindahkan.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mart_member_kategori', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('tenant_id')->index();
            $t->string('nama', 40);
            // Sakelar yang jadi alasan tabel ini ada.
            $t->boolean('boleh_hutang')->default(true);
            $t->integer('urutan')->default(0);
            $t->boolean('aktif')->default(true);
            $t->string('created_at', 30)->nullable();

            $t->index(['tenant_id', 'urutan']);
        });

        Schema::table('mart_member', function (Blueprint $t) {
            // NULL = belum dikategorikan; berperilaku seperti sebelum migrasi ini.
            $t->foreignId('kategori_id')->nullable()->after('nama')
                ->constrained('mart_member_kategori')->nullOnDelete();
        });

        Schema::create('mart_member_ket', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('tenant_id')->index();
            $t->string('teks', 160);
            $t->integer('urutan')->default(0);
            $t->boolean('aktif')->default(true);
            $t->string('created_at', 30)->nullable();

            $t->index(['tenant_id', 'urutan']);
        });
    }

    public function down(): void
    {
        Schema::table('mart_member', function (Blueprint $t) {
            $t->dropConstrainedForeignId('kategori_id');
        });
        Schema::dropIfExists('mart_member_ket');
        Schema::dropIfExists('mart_member_kategori');
    }
};
