<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Buku besar saldo kartu — SATU-SATUNYA sumber kebenaran saldo.
 *
 * created_at disimpan sebagai string ISO-8601 UTC (bukan DATETIME) karena
 * seluruh penyaringan periode membandingkannya secara leksikografis, dan
 * kontrak JSON ke frontend/suite uji menyebut format itu apa adanya.
 *
 * siswa_nama / kelas / tenant_nama sengaja DIDENORMALISASI: laporan bulan lalu
 * harus tetap menyebut keadaan saat transaksi terjadi, bukan keadaan hari ini.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('transaksi', function (Blueprint $t) {
            $t->id();
            $t->string('tx_id')->nullable()->unique();    // rem anti-dobel-catat
            $t->enum('tipe', ['topup', 'beli', 'koreksi', 'donasi', 'transfer_keluar', 'transfer_masuk']);
            $t->foreignId('siswa_id')->constrained('siswa');
            $t->string('siswa_nama');
            $t->string('kelas')->default('');
            $t->foreignId('tenant_id')->nullable()->constrained('tenant');
            $t->string('tenant_nama')->nullable();
            $t->bigInteger('jumlah');                     // +topup, -beli, -donasi
            $t->bigInteger('saldo_sesudah');
            $t->string('operator')->default('');
            $t->string('keterangan', 300)->default('');
            $t->foreignId('donasi_id')->nullable()->constrained('donasi_program');
            // Porsi rupiah non-makanan dalam satu transaksi 'beli'. Disimpan di
            // barisnya sendiri karena kategori produk bisa berubah kemudian,
            // sementara batas harian yang berlaku hari itu harus tetap terbaca sama.
            $t->bigInteger('nonmakanan')->default(0);
            $t->foreignId('lawan_id')->nullable()->constrained('siswa');
            $t->string('lawan_nama')->default('');
            $t->char('created_at', 24)->nullable();

            $t->index('created_at');
            $t->index(['tenant_id', 'created_at']);
            $t->index(['siswa_id', 'created_at']);
            $t->index(['donasi_id', 'created_at']);
        });

        /**
         * Rincian baris belanja. nama & harga didenormalisasi dengan sengaja:
         * menu bisa berganti nama atau naik harga, sedangkan laporan penjualan
         * bulan lalu harus tetap menyebut apa yang benar-benar terjual saat itu.
         */
        Schema::create('transaksi_item', function (Blueprint $t) {
            $t->id();
            $t->foreignId('transaksi_id')->constrained('transaksi')->cascadeOnDelete();
            $t->foreignId('produk_id')->nullable()->constrained('produk')->nullOnDelete();
            $t->foreignId('tenant_id')->nullable()->constrained('tenant');
            $t->string('tenant_nama')->default('');
            $t->string('nama');
            $t->string('kategori')->default('makanan');
            $t->bigInteger('harga')->default(0);
            $t->integer('qty')->default(1);
            $t->bigInteger('subtotal')->default(0);
            $t->foreignId('siswa_id')->nullable()->constrained('siswa');
            $t->string('kelas')->default('');
            $t->string('golongan')->default('siswa');
            $t->char('created_at', 24)->nullable();       // disalin dari transaksi, hemat JOIN

            $t->index('transaksi_id');
            $t->index(['tenant_id', 'nama', 'created_at']);
            $t->index('created_at');
            $t->index('produk_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('transaksi_item');
        Schema::dropIfExists('transaksi');
    }
};
