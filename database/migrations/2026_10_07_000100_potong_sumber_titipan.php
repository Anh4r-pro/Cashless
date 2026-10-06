<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Pemotongan boleh mengambil dari titipan — dan wali boleh melihat riwayatnya.
 *
 * Dua hal yang memang sepasang:
 *
 *   `potong_impor.sumber` mencatat dari kantong mana satu berkas potong massal
 *   mengambil uangnya. Tanpa kolom ini, riwayat impor tidak bisa menjawab
 *   "titipan siapa yang berkurang karena berkas yang mana".
 *
 *   `ortu_lihat_titipan` membuka riwayat titipan di portal wali. Ia menyala
 *   secara bawaan, dan itu disengaja: begitu titipan bisa dipotong, angka yang
 *   dilihat wali akan berubah oleh sebab yang tidak ada di halaman mana pun
 *   yang bisa mereka jangkau. Sekolah boleh mematikannya, tetapi mematikannya
 *   harus menjadi keputusan yang diambil, bukan keadaan yang kebetulan.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('potong_impor', function (Blueprint $t) {
            $t->string('sumber', 20)->default('kartu')->after('alasan');
        });
        DB::table('settings')->updateOrInsert(['key' => 'ortu_lihat_titipan'], ['value' => 1]);
    }

    public function down(): void
    {
        Schema::table('potong_impor', function (Blueprint $t) {
            $t->dropColumn('sumber');
        });
        DB::table('settings')->where('key', 'ortu_lihat_titipan')->delete();
    }
};
