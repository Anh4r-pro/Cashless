<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Mode kasir per tenant: 'menu' (pilih dari daftar menu) atau 'bebas'
 * (kasir mengetik sendiri deskripsi & nominal barang).
 *
 * Ada karena koperasi menjual puluhan sampai ratusan barang, dan daftar itu
 * tidak selalu sempat diinput sebelum koperasi mulai berjualan. Tanpa mode
 * ini, koperasi yang daftar barangnya belum lengkap tidak bisa bertransaksi
 * sama sekali — atau terpaksa menulis "Input manual" di setiap riwayat santri.
 *
 * Per tenant, bukan satu sakelar sekolah: kantin yang daftar menunya sudah
 * rapi tidak boleh ikut kehilangan layar menunya hanya karena koperasi belum siap.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenant', function (Blueprint $t) {
            $t->string('mode_kasir', 10)->default('menu')->after('jenis');
        });
    }

    public function down(): void
    {
        Schema::table('tenant', function (Blueprint $t) {
            $t->dropColumn('mode_kasir');
        });
    }
};
