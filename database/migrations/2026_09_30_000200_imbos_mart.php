<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * IMBOS Mart — kasir swalayan dengan pemindai barcode, melayani DUA metode
 * bayar yang jalur uangnya berbeda sama sekali:
 *
 *   KARTU (cashless) — memotong saldo santri, menambah hutang sekolah ke
 *     tenant. Memakai jalur `transaksi` yang sudah ada, jadi neraca sekolah
 *     tetap utuh dan tidak ada tabel baru untuk ini.
 *
 *   TUNAI — uangnya TIDAK PERNAH masuk kas sekolah; langsung ke laci Mart.
 *     Karena itu ia WAJIB berdiri di tabel sendiri (`mart_jual`), bukan
 *     menumpang `transaksi`: kalau ditumpangkan, penjualan tunai akan ikut
 *     terhitung sebagai "penjualan tenant", menaikkan hutang sekolah atas
 *     uang yang tidak pernah sekolah terima, dan selisih neraca langsung
 *     pecah. Pemisahan ini yang menjaga persamaan neraca tetap benar.
 *
 * `mart_kas` mencocokkan uang fisik di laci dengan penjualan tunai tercatat,
 * sekali sehari. Tanpa pencocokan itu, uang tunai yang hilang tidak akan
 * pernah ketahuan oleh siapa pun.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('produk', function (Blueprint $t) {
            // Khas per tenant (ditegakkan aplikasi, seperti nama barang), bukan
            // global: dua tenant boleh menjual barang bermerek sama.
            $t->string('barcode', 32)->nullable()->after('nama');
            $t->index(['tenant_id', 'barcode']);
        });

        Schema::create('mart_jual', function (Blueprint $t) {
            $t->id();
            $t->foreignId('tenant_id')->constrained('tenant');
            // Idempotensi: kasir yang koneksinya putus lalu mengirim ulang
            // tidak boleh mencatat penjualan dua kali.
            $t->string('tx_id')->nullable()->unique();
            $t->bigInteger('total')->default(0);
            $t->bigInteger('uang_diterima')->default(0);
            $t->bigInteger('kembalian')->default(0);
            $t->integer('n_item')->default(0);
            $t->string('operator')->default('');
            $t->char('created_at', 24)->nullable();
            $t->index(['tenant_id', 'created_at']);
        });

        Schema::create('mart_jual_item', function (Blueprint $t) {
            $t->id();
            $t->foreignId('jual_id')->constrained('mart_jual');
            // nullOnDelete: barang boleh dihapus dari daftar menu kelak tanpa
            // menghanguskan riwayat penjualannya — nama & harga sudah disalin.
            $t->foreignId('produk_id')->nullable()->constrained('produk')->nullOnDelete();
            $t->string('nama');
            $t->string('kategori', 20)->default('makanan');
            $t->bigInteger('harga')->default(0);
            $t->integer('qty')->default(1);
            $t->bigInteger('subtotal')->default(0);
            $t->index('jual_id');
        });

        Schema::create('mart_kas', function (Blueprint $t) {
            $t->id();
            $t->foreignId('tenant_id')->constrained('tenant');
            $t->char('tanggal', 10);                    // YYYY-MM-DD menurut zona sekolah
            $t->bigInteger('kas_awal')->default(0);
            $t->bigInteger('tunai')->default(0);        // penjualan tunai hari itu (disalin saat tutup)
            $t->bigInteger('hitung_fisik')->default(0);
            $t->bigInteger('selisih')->default(0);      // hitung_fisik - (kas_awal + tunai)
            $t->string('catatan', 300)->default('');
            $t->string('operator')->default('');
            $t->char('created_at', 24)->nullable();
            $t->unique(['tenant_id', 'tanggal']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mart_kas');
        Schema::dropIfExists('mart_jual_item');
        Schema::dropIfExists('mart_jual');
        Schema::table('produk', function (Blueprint $t) {
            $t->dropIndex(['tenant_id', 'barcode']);
            $t->dropColumn('barcode');
        });
    }
};
