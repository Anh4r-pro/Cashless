<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Sakelar "input manual" per tenant.
 *
 * Di mode menu, kasir tetap punya kotak nominal bebas di samping daftar menu.
 * Itu disengaja dulu — ada saja barang yang belum sempat diajukan — tetapi
 * akibatnya menu yang sudah disetujui admin bisa dilewati begitu saja: kasir
 * tinggal mengetik angka sendiri, dan tidak ada yang bisa membandingkan apa
 * yang dijual dengan apa yang pernah disetujui.
 *
 * Dengan sakelar ini sekolah bisa mematikannya, lalu menyalakannya lagi saat
 * memang dibutuhkan (menu baru belum sempat diajukan, ada titipan dadakan)
 * tanpa perlu mengubah mode kasir tenantnya.
 *
 * BAWAANNYA 1 — menyala, persis seperti sebelum migrasi ini ada. Mematikannya
 * secara serentak akan menghentikan penjualan yang sekarang memang berjalan
 * lewat jalur itu, dan itu bukan keputusan yang boleh diambil diam-diam oleh
 * sebuah migrasi.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenant', function (Blueprint $t) {
            $t->boolean('input_manual')->default(1)->after('mode_kasir');
        });
    }

    public function down(): void
    {
        Schema::table('tenant', function (Blueprint $t) {
            $t->dropColumn('input_manual');
        });
    }
};
