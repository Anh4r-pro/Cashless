<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Catatan pada penjualan — terutama untuk transaksi hutang.
 *
 * Tim kasir meminta kolom deskripsi di bawah pilihan member saat menjual
 * dengan hutang. Alasannya praktis: saat menagih berminggu-minggu kemudian,
 * "hutang Rp150.000" tanpa keterangan apa pun sering berakhir jadi perdebatan
 * tentang untuk apa dan atas nama siapa barang itu diambil.
 *
 * Kolomnya dibuat untuk SEMUA metode, bukan khusus hutang: nota tunai pun
 * kadang perlu catatan ("titipan ustadz", "ganti barang rusak"), dan membuat
 * kolom yang hanya sah untuk satu metode hanya menambah aturan yang harus
 * diingat tanpa menghemat apa pun.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('mart_jual', function (Blueprint $t) {
            $t->string('catatan', 200)->default('')->after('pengirim');
        });
    }

    public function down(): void
    {
        Schema::table('mart_jual', function (Blueprint $t) {
            $t->dropColumn('catatan');
        });
    }
};
