<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Target RAB & pengaturan kirim laporan SPD ke WhatsApp, per unit Mart.
 *
 * Dua Imbos Mart (putra/putri) bisa punya target bulanan dan nomor WA tujuan
 * yang berbeda — karena itu kolomnya di `tenant`, bukan baris global di
 * `settings` (yang memang tidak punya konsep per-tenant). Tenant kantin biasa
 * tidak tersentuh sama sekali, seluruh kolom nullable/mati bawaan.
 *
 * `wa_terakhir_kirim` adalah penjaga idempotensi untuk kirim otomatis: command
 * terjadwal jalan tiap menit tapi hanya boleh mengirim sekali per tenant per
 * hari, dan kolom inilah yang mengingat "sudah terkirim hari ini".
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenant', function (Blueprint $t) {
            $t->integer('target_rab_bulanan')->nullable()->after('input_manual');
            $t->string('wa_nomor', 20)->nullable()->after('target_rab_bulanan');
            $t->boolean('wa_kirim_otomatis')->default(0)->after('wa_nomor');
            $t->string('wa_jam_kirim', 5)->nullable()->after('wa_kirim_otomatis');
            $t->string('wa_terakhir_kirim', 10)->nullable()->after('wa_jam_kirim');
        });
    }

    public function down(): void
    {
        Schema::table('tenant', function (Blueprint $t) {
            $t->dropColumn(['target_rab_bulanan', 'wa_nomor', 'wa_kirim_otomatis', 'wa_jam_kirim', 'wa_terakhir_kirim']);
        });
    }
};
