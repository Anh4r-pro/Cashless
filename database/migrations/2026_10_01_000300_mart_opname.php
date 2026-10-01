<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Stok opname — verifikasi fisik barang, lalu rekonsiliasi.
 *
 * Nilai selisih dihitung dengan HPP SAAT ITU dan disalin ke baris opname,
 * bukan dirujuk ke produk: harga modal berubah dari waktu ke waktu, dan
 * audit bulan lalu harus tetap terbaca dengan angka bulan lalu.
 *
 * Rekonsiliasi menyesuaikan stok ke angka fisik dan mencatat rugi/laba
 * inventarisnya di buku Mart. Ia TIDAK menyentuh neraca kas sekolah —
 * barang yang hilang di rak Mart bukan uang yang hilang dari kas sekolah.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mart_opname', function (Blueprint $t) {
            $t->id();
            $t->foreignId('tenant_id')->constrained('tenant');
            $t->enum('status', ['berjalan', 'selesai'])->default('berjalan');
            $t->integer('n_item')->default(0);
            $t->integer('n_surplus')->default(0);
            $t->integer('n_defisit')->default(0);
            $t->bigInteger('nilai_surplus')->default(0);
            $t->bigInteger('nilai_defisit')->default(0);
            $t->bigInteger('nilai_bersih')->default(0);   // surplus − defisit
            $t->string('catatan', 300)->default('');
            $t->string('operator')->default('');
            $t->char('created_at', 24)->nullable();
            $t->char('selesai_pada', 24)->nullable();
            $t->index(['tenant_id', 'created_at']);
        });

        Schema::create('mart_opname_item', function (Blueprint $t) {
            $t->id();
            $t->foreignId('opname_id')->constrained('mart_opname');
            $t->foreignId('produk_id')->nullable()->constrained('produk')->nullOnDelete();
            $t->string('barcode', 32)->default('');
            $t->string('nama');
            $t->integer('stok_sistem')->default(0);
            $t->integer('stok_fisik')->nullable();        // NULL = belum dihitung staf
            $t->integer('selisih')->default(0);
            $t->bigInteger('hpp')->default(0);            // disalin saat opname dibuat
            $t->bigInteger('nilai_selisih')->default(0);
            $t->string('alasan', 40)->default('');        // sesuai / rusak / hilang / salah catat / kedaluwarsa
            $t->index('opname_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mart_opname_item');
        Schema::dropIfExists('mart_opname');
    }
};
