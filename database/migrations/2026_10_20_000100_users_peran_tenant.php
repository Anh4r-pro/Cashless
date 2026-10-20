<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Sub-peran di dalam akun tenant: `admin` (bawaan, akses penuh — persis
 * perilaku sebelum kolom ini ada) atau `kasir` (serve-only — lihat
 * `Controller::wajibAdminToko()`).
 *
 * Skema `users` sejak awal tidak punya unique constraint pada `tenant_id`,
 * jadi migrasi ini TIDAK membuka kemampuan baru di level basis data — ia
 * cuma memberi nama pada sub-peran yang kodenya baru sekarang membedakan.
 * Bawaannya `admin` supaya seluruh akun tenant yang sudah ada tetap
 * berperilaku sama persis tanpa migrasi data.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $t) {
            $t->enum('peran_tenant', ['admin', 'kasir'])->default('admin')->after('tenant_id');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $t) {
            $t->dropColumn('peran_tenant');
        });
    }
};
