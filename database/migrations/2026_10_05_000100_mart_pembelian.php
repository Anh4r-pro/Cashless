<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Pemasok & pembelian IMBOS Mart.
 *
 * "Supplier" yang berguna bukan sekadar daftar nama. Yang dibutuhkan toko
 * adalah NOTA: barang apa yang datang, berapa harganya, dari siapa, sudah
 * dibayar atau belum, dan jatuh tempo kapan. Tanpa itu, harga modal di sistem
 * hanya tebakan yang diketik sekali lalu tidak pernah benar lagi.
 *
 * Tiga hal yang dijaga oleh bentuk tabel ini:
 *
 *   1. SATU NOTA = SATU KEJADIAN. Barang masuk, stok naik, dan harga modal
 *      diperbarui dalam satu langkah yang bisa dibatalkan sekaligus. Kalau
 *      ketiganya dikerjakan terpisah, cepat atau lambat ada yang tertinggal.
 *   2. UANG KELUAR DICATAT DI TEMPAT YANG SAMA DENGAN UANG MASUK. Membayar
 *      pemasok dari laci mengurangi isi laci. Sebelum ini, tutup kas hanya
 *      tahu uang yang masuk, jadi setiap pembelian tunai muncul sebagai
 *      selisih laci yang harus dijelaskan dengan catatan tangan.
 *   3. HUTANG KE PEMASOK ADALAH LAWAN DARI PIUTANG. Barangnya sudah ada di
 *      rak, uangnya belum dibayarkan. Dicatat dengan cara yang sama persis
 *      seperti piutang member, supaya keduanya bisa dibaca dengan kebiasaan
 *      berpikir yang sama.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mart_supplier', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('tenant_id')->index();
            $t->string('kode', 20);
            $t->string('nama', 120);
            $t->string('hp', 25)->default('');
            $t->string('alamat', 200)->default('');
            $t->string('keterangan', 160)->default('');
            $t->boolean('aktif')->default(true);
            $t->string('created_at', 30);
            $t->unique(['tenant_id', 'kode']);
            $t->index(['tenant_id', 'nama']);
        });

        Schema::create('mart_beli', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('tenant_id')->index();
            $t->foreignId('supplier_id')->nullable()->constrained('mart_supplier')->nullOnDelete();
            // Nomor nota dari pemasok, apa adanya — inilah yang dicocokkan
            // orang saat berdebat soal tagihan.
            $t->string('no_nota', 60)->default('');
            $t->enum('metode', ['tunai', 'transfer', 'tempo'])->default('tunai');
            $t->enum('status', ['sah', 'batal'])->default('sah');
            $t->string('tanggal', 10)->index();          // tanggal lokal nota
            $t->string('jatuh_tempo', 10)->nullable();
            $t->integer('subtotal')->default(0);
            $t->integer('diskon')->default(0);
            $t->integer('total')->default(0);
            $t->unsignedInteger('n_item')->default(0);
            $t->string('catatan', 200)->default('');
            $t->string('operator', 120);
            $t->string('created_at', 30);
        });

        Schema::create('mart_beli_item', function (Blueprint $t) {
            $t->id();
            $t->foreignId('beli_id')->constrained('mart_beli')->cascadeOnDelete();
            $t->foreignId('produk_id')->nullable()->constrained('produk')->nullOnDelete();
            $t->string('nama', 120);
            $t->unsignedInteger('qty')->default(1);
            $t->integer('harga')->default(0);            // harga beli per satuan
            $t->integer('subtotal')->default(0);
            // Harga modal barang SEBELUM nota ini, supaya pembatalan bisa
            // mengembalikannya apa adanya — bukan menebak-nebak.
            $t->integer('hpp_lama')->nullable();
        });

        Schema::create('mart_beli_bayar', function (Blueprint $t) {
            $t->id();
            $t->foreignId('beli_id')->constrained('mart_beli')->cascadeOnDelete();
            $t->unsignedBigInteger('tenant_id')->index();
            $t->unsignedBigInteger('supplier_id')->nullable()->index();
            $t->integer('jumlah');
            $t->enum('metode', ['tunai', 'transfer'])->default('tunai');
            $t->string('bank', 40)->default('');
            $t->string('catatan', 160)->default('');
            $t->string('operator', 120);
            $t->string('created_at', 30)->index();
        });

        // Uang keluar ikut masuk catatan penutupan kas. Tanpa dua kolom ini,
        // setiap pembelian tunai muncul sebagai selisih laci yang tidak bisa
        // dijelaskan angka mana pun di sistem.
        Schema::table('mart_kas', function (Blueprint $t) {
            $t->integer('keluar')->default(0)->after('transfer');
            $t->integer('keluar_transfer')->default(0)->after('keluar');
        });
    }

    public function down(): void
    {
        Schema::table('mart_kas', function (Blueprint $t) {
            $t->dropColumn(['keluar', 'keluar_transfer']);
        });
        Schema::dropIfExists('mart_beli_bayar');
        Schema::dropIfExists('mart_beli_item');
        Schema::dropIfExists('mart_beli');
        Schema::dropIfExists('mart_supplier');
    }
};
