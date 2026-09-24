<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Sidik jari isi bukti bayar, untuk mengenali satu bukti yang dipakai berkali-kali.
 *
 * Wali yang mentransfer SEKALI lalu mengajukan DUA KALI akan lolos dari semua
 * pemeriksaan lama: nominalnya sah, tanggalnya sah, antreannya belum penuh.
 * Yang membedakannya cuma satu — bukti bayarnya gambar yang sama persis.
 * Kolom ini membuat kesamaan itu bisa diperiksa dalam sekali kueri, bukan
 * dengan membandingkan gambar satu per satu dengan mata.
 *
 * Tidak dibuat UNIQUE. Penolakannya diputuskan di lapisan aplikasi supaya
 * pesannya bisa menjelaskan duduk perkaranya kepada wali, dan supaya pengajuan
 * yang sudah DITOLAK tidak ikut memblokir pengajuan ulang yang sah.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('topup_request', function (Blueprint $t) {
            $t->char('bukti_sidik', 64)->nullable()->after('bukti');
            $t->index('bukti_sidik');
        });
    }

    public function down(): void
    {
        Schema::table('topup_request', function (Blueprint $t) {
            $t->dropIndex(['bukti_sidik']);
            $t->dropColumn('bukti_sidik');
        });
    }
};
