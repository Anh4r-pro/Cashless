<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Transaksi tertahan — keranjang yang diparkir sebentar.
 *
 * Pembeli lupa membawa uang, atau menyuruh temannya mengambil satu barang
 * lagi, sementara antrean di belakangnya memanjang. Tanpa ini kasir hanya
 * punya dua pilihan buruk: menahan antrean, atau mengosongkan keranjang yang
 * sudah dipindai satu per satu.
 *
 * Disimpan di SERVER, bukan di peramban. Peramban kasir bisa tertutup, PC-nya
 * bisa restart, dan keranjang yang diparkir harus tetap ada sesudahnya. Lagi
 * pula kalau Mart punya dua PC kasir, transaksi yang ditahan di satu meja
 * harus bisa dilanjutkan di meja sebelahnya.
 *
 * Yang ditahan TIDAK memotong stok: barangnya belum terjual, dan memotongnya
 * lebih awal akan membuat rak terlihat kosong oleh sesuatu yang mungkin tidak
 * pernah jadi dibeli. Stok baru bergerak saat transaksinya benar-benar
 * dibayar, lewat jalur penjualan yang sama seperti biasa.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mart_tahan', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('tenant_id')->index();
            // Catatan kasir untuk mengenalinya kembali: "Bu Siti", "yang beli
            // galon". Boleh kosong — waktunya sendiri sudah jadi penanda.
            $t->string('catatan', 80)->default('');
            $t->unsignedInteger('n_item')->default(0);
            $t->integer('subtotal')->default(0);
            $t->integer('diskon')->default(0);
            $t->integer('total')->default(0);
            $t->string('operator', 120);
            $t->string('created_at', 30);
        });

        Schema::create('mart_tahan_item', function (Blueprint $t) {
            $t->id();
            $t->foreignId('tahan_id')->constrained('mart_tahan')->cascadeOnDelete();
            // Barang yang keburu dihapus dari daftar tidak membatalkan seluruh
            // keranjang yang tertahan — barisnya tinggal, produk_id-nya kosong,
            // dan kasir melihatnya saat melanjutkan.
            $t->foreignId('produk_id')->nullable()->constrained('produk')->nullOnDelete();
            $t->string('nama', 120);
            $t->string('kategori', 20)->default('makanan');
            $t->integer('harga');
            $t->unsignedInteger('qty')->default(1);
            $t->integer('subtotal');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mart_tahan_item');
        Schema::dropIfExists('mart_tahan');
    }
};
