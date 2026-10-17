<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Satu barcode, dua satuan: eceran (pcs) dan grosir (dus/karton).
 *
 * Yang diminta tim kasir bukan dua barang terpisah, melainkan SATU barang yang
 * bisa dibeli dan dijual dalam dua kemasan. Dua barang terpisah berarti dua
 * stok yang harus disamakan tangan — dan begitu keduanya berbeda, tidak ada
 * cara menebak mana yang benar.
 *
 * Karena itu STOK TETAP SATU, disimpan dalam satuan eceran (pcs). Grosir hanya
 * cara membeli/menjualnya: 1 grosir = `isi_grosir` pcs, dan penjualan satu dus
 * memotong stok sebanyak isinya. Tidak ada stok kedua yang bisa menyimpang.
 *
 * `isi_grosir` NULL berarti barang ini tidak dijual grosir — dan itu keadaan
 * SELURUH barang yang sudah ada, termasuk barang kantin biasa yang memakai
 * tabel `produk` yang sama. Tidak ada satu pun baris lama yang berubah arti.
 *
 * `satuan` + `isi` di baris penjualan & pembelian adalah POTRET, bukan rujukan
 * ke master: `isi_grosir` boleh berubah kelak (pemasok mengganti kemasan), dan
 * nota yang sudah tercatat tidak boleh ikut berubah angkanya. Alasan yang sama
 * persis dengan potret `hpp` pada migrasi 2026_10_05_000200.
 *
 * Tanggal kedaluwarsa menumpang migrasi ini karena ia menyentuh tabel yang
 * sama. Satu tanggal per barang (keputusan rapat), diperbarui saat nota masuk;
 * `expired_lama` menyimpan nilai sebelumnya supaya pembatalan nota bisa
 * memulihkannya, pola yang sama dengan `hpp_lama` yang sudah ada.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('produk', function (Blueprint $t) {
            // Jumlah eceran dalam satu kemasan grosir. NULL = tidak dijual grosir.
            $t->integer('isi_grosir')->nullable()->after('hpp');
            $t->bigInteger('hpp_grosir')->nullable()->after('isi_grosir');
            $t->bigInteger('harga_grosir')->nullable()->after('hpp_grosir');
            // YYYY-MM-DD lokal, disimpan sebagai teks seperti mart_beli.tanggal.
            $t->string('expired', 10)->nullable()->after('stok_min');
        });

        Schema::table('mart_jual_item', function (Blueprint $t) {
            $t->enum('satuan', ['ecer', 'grosir'])->default('ecer')->after('qty');
            $t->integer('isi')->default(1)->after('satuan');
        });

        // Buku kedua ikut dapat kolom yang sama. Penjualan kartu RFID di Mart
        // memakai tabel ini, dan laporan "barang terlaris" menjumlahkan qty
        // dari KEDUA buku: tanpa `isi` di sini, satu dus akan terhitung sama
        // dengan satu pcs begitu dua buku itu digabung.
        Schema::table('transaksi_item', function (Blueprint $t) {
            $t->enum('satuan', ['ecer', 'grosir'])->default('ecer')->after('qty');
            $t->integer('isi')->default(1)->after('satuan');
        });

        // Keranjang yang DITAHAN ikut menyimpan satuannya. Tanpa ini, baris
        // grosir yang ditahan akan kembali sebagai eceran saat dilanjutkan —
        // terjual dengan harga eceran, dan memotong stok satu pcs, diam-diam.
        Schema::table('mart_tahan_item', function (Blueprint $t) {
            $t->enum('satuan', ['ecer', 'grosir'])->default('ecer')->after('qty');
            $t->integer('isi')->default(1)->after('satuan');
        });

        Schema::table('mart_beli_item', function (Blueprint $t) {
            $t->enum('satuan', ['ecer', 'grosir'])->default('ecer')->after('qty');
            $t->integer('isi')->default(1)->after('satuan');
            $t->string('expired', 10)->nullable()->after('hpp_lama');
            $t->string('expired_lama', 10)->nullable()->after('expired');
        });
    }

    public function down(): void
    {
        Schema::table('produk', function (Blueprint $t) {
            $t->dropColumn(['isi_grosir', 'hpp_grosir', 'harga_grosir', 'expired']);
        });
        Schema::table('mart_jual_item', function (Blueprint $t) {
            $t->dropColumn(['satuan', 'isi']);
        });
        Schema::table('transaksi_item', function (Blueprint $t) {
            $t->dropColumn(['satuan', 'isi']);
        });
        Schema::table('mart_tahan_item', function (Blueprint $t) {
            $t->dropColumn(['satuan', 'isi']);
        });
        Schema::table('mart_beli_item', function (Blueprint $t) {
            $t->dropColumn(['satuan', 'isi', 'expired', 'expired_lama']);
        });
    }
};
