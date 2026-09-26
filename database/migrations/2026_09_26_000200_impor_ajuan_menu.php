<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Pengajuan menu lewat berkas Excel: puluhan sampai ratusan barang sekaligus.
 *
 * `paket` mengikat semua ajuan yang lahir dari satu berkas. Dengan itu:
 *   - satu berkas dihitung SATU antrean, bukan ratusan — kalau tidak, rem
 *     anti-banjir per tenant akan menolak berkas koperasi mana pun;
 *   - admin bisa memutus satu berkas sekaligus, bukan menekan setuju ratusan kali.
 *
 * `kategori_usulan` hanyalah USULAN tenant. Yang menetapkan kategori tetap
 * admin saat menyetujui — tenant yang boleh menetapkan sendiri akan menandai
 * semuanya "makanan" agar lolos batas belanja non-makanan.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('produk_ajuan', function (Blueprint $t) {
            $t->string('paket', 40)->nullable()->after('jenis');
            $t->string('kategori_usulan', 20)->nullable()->after('harga');
            $t->index(['paket', 'status']);
        });
    }

    public function down(): void
    {
        Schema::table('produk_ajuan', function (Blueprint $t) {
            $t->dropIndex(['paket', 'status']);
            $t->dropColumn(['paket', 'kategori_usulan']);
        });
    }
};
