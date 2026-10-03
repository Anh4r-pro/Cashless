<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Pembetulan transaksi Mart — dengan jejak, bukan diam-diam.
 *
 * Kasir perlu bisa membetulkan transaksi yang baru saja salah: terpindai dua
 * kali, salah ketik harga, diskon kelupaan. Menolak itu hanya membuat orang
 * mencari jalan lain yang lebih buruk.
 *
 * Tetapi kalau angka penjualan bisa berubah SETELAH uang laci dihitung, maka
 * selisih laci selalu bisa "dirapikan" dengan mengedit transaksi tadi pagi —
 * dan tutup kas kehilangan seluruh gunanya. Karena itu dua pagar dipasang di
 * sini, dan keduanya tidak bisa ditawar:
 *
 *   1. SETIAP perubahan meninggalkan baris di `mart_jual_ubah`: apa yang
 *      diubah, dari berapa jadi berapa, oleh siapa, jam berapa, dan alasannya.
 *      Nilai lamanya tersimpan utuh, bukan hanya ringkasannya.
 *   2. Transaksi TERKUNCI begitu kas hari itu ditutup. Setelah uang dihitung,
 *      angkanya tidak boleh bergerak lagi.
 *
 * `status` dipakai untuk pembatalan: barisnya TIDAK pernah dihapus, hanya
 * ditandai batal dan dikeluarkan dari semua penjumlahan. Menghapus baris
 * penjualan akan melubangi stok, hitungan laci, dan laporan laba yang sudah
 * terlanjur dibaca orang.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('mart_jual', function (Blueprint $t) {
            $t->enum('status', ['sah', 'batal'])->default('sah')->after('metode');
            $t->unsignedInteger('n_ubah')->default(0)->after('operator');
            $t->string('diubah_oleh', 120)->nullable()->after('n_ubah');
            $t->string('diubah_pada', 30)->nullable()->after('diubah_oleh');
            $t->index(['tenant_id', 'status']);
        });

        Schema::create('mart_jual_ubah', function (Blueprint $t) {
            $t->id();
            $t->foreignId('jual_id')->constrained('mart_jual')->cascadeOnDelete();
            $t->unsignedBigInteger('tenant_id')->index();
            // 'ubah' atau 'batal'. Dibedakan supaya daftar riwayat bisa
            // menyebutnya dengan kata yang benar tanpa menebak dari isinya.
            $t->enum('jenis', ['ubah', 'batal'])->default('ubah');
            $t->string('alasan', 200);
            $t->integer('total_lama');
            $t->integer('total_baru');
            $t->string('ringkas', 400);          // untuk dibaca manusia di layar
            $t->text('sebelum');                 // potret utuh, untuk ditelusuri kelak
            $t->text('sesudah');
            $t->string('operator', 120);
            $t->string('created_at', 30);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mart_jual_ubah');
        Schema::table('mart_jual', function (Blueprint $t) {
            $t->dropIndex(['tenant_id', 'status']);
            $t->dropColumn(['status', 'n_ubah', 'diubah_oleh', 'diubah_pada']);
        });
    }
};
