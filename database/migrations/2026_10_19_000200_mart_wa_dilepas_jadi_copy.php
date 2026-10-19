<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Kirim WA otomatis DIBATALKAN — digantikan tombol "copy laporan" yang jauh
 * lebih sederhana (teks disalin ke clipboard, admin tempel sendiri ke
 * WhatsApp). Alasannya: butuh gateway WhatsApp mandiri yang harus dipasang,
 * di-pairing manual via scan QR, dan dirawat terus — kerumitan yang tidak
 * sepadan untuk laporan yang cukup di-tempel manual sekali sehari.
 *
 * `target_rab_bulanan` TETAP — itu bagian dari isi laporan, tidak tergantung
 * cara pengirimannya. Kolom `wa_*` yang dilepas murni infrastruktur kirim
 * otomatis yang sudah tidak dipakai.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenant', function (Blueprint $t) {
            $t->dropColumn(['wa_nomor', 'wa_kirim_otomatis', 'wa_jam_kirim', 'wa_terakhir_kirim']);
        });
    }

    public function down(): void
    {
        Schema::table('tenant', function (Blueprint $t) {
            $t->string('wa_nomor', 20)->nullable()->after('target_rab_bulanan');
            $t->boolean('wa_kirim_otomatis')->default(0)->after('wa_nomor');
            $t->string('wa_jam_kirim', 5)->nullable()->after('wa_kirim_otomatis');
            $t->string('wa_terakhir_kirim', 10)->nullable()->after('wa_jam_kirim');
        });
    }
};
