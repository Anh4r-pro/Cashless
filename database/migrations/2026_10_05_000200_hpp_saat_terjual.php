<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Harga modal DIPOTRET saat barang terjual.
 *
 * Sampai sekarang laporan laba menghitung modal dari `produk.hpp` yang
 * berlaku SEKARANG. Itu penyederhanaan yang sengaja diambil dan ditulis
 * apa adanya di MartLaporanController, dengan alasan harga modal jarang
 * berubah di toko sekolah.
 *
 * Alasan itu habis masa berlakunya begitu ada modul pembelian: setiap nota
 * yang masuk memperbarui harga modal, dan itu memang tugasnya. Akibatnya,
 * tanpa potret ini, satu kenaikan harga beli hari ini akan diam-diam
 * mengubah laba yang dilaporkan bulan lalu — angka yang sudah dibaca orang
 * dan mungkin sudah dibawa ke rapat.
 *
 * Kolomnya NULLABLE, dan laporan membaca potretnya dulu lalu jatuh ke
 * `produk.hpp` kalau kosong. Dengan begitu penjualan yang sudah terlanjur
 * tercatat sebelum migrasi ini tidak kehilangan angka modalnya — ia hanya
 * tetap memakai cara lama, persis seperti sebelumnya.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('mart_jual_item', function (Blueprint $t) {
            $t->integer('hpp')->nullable()->after('harga');
        });
        Schema::table('transaksi_item', function (Blueprint $t) {
            $t->integer('hpp')->nullable()->after('harga');
        });
    }

    public function down(): void
    {
        Schema::table('mart_jual_item', function (Blueprint $t) {
            $t->dropColumn('hpp');
        });
        Schema::table('transaksi_item', function (Blueprint $t) {
            $t->dropColumn('hpp');
        });
    }
};
