<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Member & piutang IMBOS Mart.
 *
 * Hutang hanya boleh atas nama MEMBER TERDAFTAR. Piutang tanpa identitas yang
 * pasti adalah piutang yang tidak bisa ditagih, dan nama yang diketik bebas
 * ("Pak Anto", "anto", "P. Anto") membuat daftar piutang tidak bisa
 * dijumlahkan per orang.
 *
 * `batas_hutang` bernilai 0 berarti member itu TIDAK BOLEH berhutang. Itu
 * bawaannya, dan disengaja: memberi plafon adalah keputusan yang harus
 * diambil seseorang, bukan sesuatu yang didapat hanya karena namanya terdaftar.
 *
 * ── Kenapa penjualan hutang tidak lahir di tabel baru ────────────────────
 * Ia tetap satu baris `mart_jual` bermetode 'hutang'. Barangnya benar-benar
 * keluar dari rak, jadi ia memang penjualan: omzet, laba, stok, dan barang
 * terlaris semuanya harus menghitungnya sejak hari barang itu diambil.
 * Yang BEDA hanya uangnya — ia tidak pernah masuk laci hari itu, jadi
 * tutup kas tidak boleh mencarinya di sana.
 *
 * Uangnya baru datang lewat `mart_piutang_bayar`, dan dihitung sebagai
 * pemasukan pada hari pembayaran. Dua waktu yang berbeda untuk dua hal yang
 * berbeda: kapan barangnya terjual, dan kapan uangnya diterima.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mart_member', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('tenant_id')->index();
            $t->string('kode', 20);
            $t->string('nama', 120);
            $t->string('hp', 25)->default('');
            $t->string('keterangan', 160)->default('');
            // 0 = tidak boleh berhutang sama sekali.
            $t->integer('batas_hutang')->default(0);
            $t->boolean('aktif')->default(true);
            $t->string('created_at', 30);
            $t->unique(['tenant_id', 'kode']);
            $t->index(['tenant_id', 'nama']);
        });

        Schema::table('mart_jual', function (Blueprint $t) {
            $t->foreignId('member_id')->nullable()->after('tenant_id')
                ->constrained('mart_member')->nullOnDelete();
        });
        // Enum diubah lewat SQL mentah: Blueprint->change() memerlukan doctrine
        // /dbal yang tidak dipasang di proyek ini.
        \Illuminate\Support\Facades\DB::statement(
            "ALTER TABLE mart_jual MODIFY metode ENUM('tunai','transfer','hutang') NOT NULL DEFAULT 'tunai'"
        );

        Schema::create('mart_piutang_bayar', function (Blueprint $t) {
            $t->id();
            $t->foreignId('jual_id')->constrained('mart_jual')->cascadeOnDelete();
            $t->unsignedBigInteger('tenant_id')->index();
            $t->unsignedBigInteger('member_id')->index();
            $t->integer('jumlah');
            $t->enum('metode', ['tunai', 'transfer'])->default('tunai');
            $t->string('bank', 40)->default('');
            $t->string('pengirim', 80)->default('');
            $t->string('catatan', 160)->default('');
            $t->string('operator', 120);
            $t->string('created_at', 30)->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mart_piutang_bayar');
        Schema::table('mart_jual', function (Blueprint $t) {
            $t->dropForeign(['member_id']);
            $t->dropColumn('member_id');
        });
        \Illuminate\Support\Facades\DB::statement(
            "ALTER TABLE mart_jual MODIFY metode ENUM('tunai','transfer') NOT NULL DEFAULT 'tunai'"
        );
        Schema::dropIfExists('mart_member');
    }
};
