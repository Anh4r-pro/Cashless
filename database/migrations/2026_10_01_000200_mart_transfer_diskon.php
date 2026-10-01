<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Metode bayar TRANSFER di kasir Mart, plus diskon per transaksi.
 *
 * Transfer diperlakukan sama seperti tunai dalam satu hal penting: uangnya
 * TIDAK masuk kas sekolah, jadi tetap di tabel `mart_jual`, bukan di buku
 * besar sekolah. Bedanya hanya tempat uang itu mendarat — rekening Mart,
 * bukan laci. Karena itu tutup kas harian memisahkan keduanya: laci dihitung
 * fisik, transfer dicocokkan ke mutasi rekening. Tanpa pemisahan itu, satu
 * angka gabungan akan selalu terlihat "selisih" padahal uangnya ada, hanya
 * di tempat yang berbeda.
 *
 * Bank & nama pengirim diisi manual kasir — sistem tidak terhubung ke bank
 * mana pun, dan tidak berpura-pura memverifikasi apa pun.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('mart_jual', function (Blueprint $t) {
            $t->enum('metode', ['tunai', 'transfer'])->default('tunai')->after('tenant_id');
            $t->string('bank', 40)->default('')->after('kembalian');
            $t->string('pengirim', 80)->default('')->after('bank');
            $t->bigInteger('diskon')->default(0)->after('total');
            $t->bigInteger('subtotal')->default(0)->after('diskon');   // sebelum diskon
        });
        // Baris lama seluruhnya tunai, dan totalnya memang sudah bersih diskon.
        DB::statement('UPDATE mart_jual SET subtotal = total WHERE subtotal = 0');

        Schema::table('mart_kas', function (Blueprint $t) {
            $t->bigInteger('transfer')->default(0)->after('tunai');            // tercatat sistem
            $t->bigInteger('hitung_transfer')->default(0)->after('hitung_fisik'); // dicocokkan ke rekening
            $t->bigInteger('selisih_transfer')->default(0)->after('selisih');
        });
    }

    public function down(): void
    {
        Schema::table('mart_kas', function (Blueprint $t) {
            $t->dropColumn(['transfer', 'hitung_transfer', 'selisih_transfer']);
        });
        Schema::table('mart_jual', function (Blueprint $t) {
            $t->dropColumn(['metode', 'bank', 'pengirim', 'diskon', 'subtotal']);
        });
    }
};
