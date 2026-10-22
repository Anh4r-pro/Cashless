<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Barcode kedua: khusus satuan grosir (dus/karton).
 *
 * Dus punya barcode sendiri yang berbeda dari barcode bungkusnya — ITF-14 di
 * karton, EAN-13 di satuan. Dengan satu kolom barcode, memindai karton terbaca
 * "belum terdaftar" padahal barangnya ada di katalog, dan kasir harus mencari
 * namanya lalu menukar satuan secara manual.
 *
 * Nullable dan tanpa UNIQUE, mengikuti pola kolom `barcode` yang sudah ada
 * (lihat 2026_09_30_000200_imbos_mart): kekembaran dijaga aplikasi dalam
 * lingkup tenant, karena dua tenant boleh menjual barang bermerek sama.
 * Tabel `produk` dipakai bersama tenant kantin — kolom ini tidak pernah dibaca
 * di luar jalur Mart, jadi barang kantin aman dengan nilainya kosong.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('produk', function (Blueprint $t) {
            $t->string('barcode_grosir', 32)->nullable()->after('barcode');
            $t->index(['tenant_id', 'barcode_grosir']);
        });
    }

    public function down(): void
    {
        Schema::table('produk', function (Blueprint $t) {
            $t->dropIndex(['tenant_id', 'barcode_grosir']);
            $t->dropColumn('barcode_grosir');
        });
    }
};
