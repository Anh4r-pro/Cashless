<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Biaya admin pada top up massal dari Excel.
 *
 * Top up manual satu per satu tidak butuh kolom baru: biayanya tercatat
 * sebagai baris transaksi 'biaya_admin' dan mutasi Dana Admin, sama seperti
 * jalur portal wali. Impor massal butuh kolom sendiri karena riwayat impor
 * harus bisa menjawab "berapa yang dipotong dari berkas ini" tanpa menjumlah
 * ulang buku besar.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('topup_impor', function (Blueprint $t) {
            $t->boolean('biaya_admin')->default(false)->after('sidik');
            $t->bigInteger('total_biaya')->default(0)->after('total_titipan');
        });
        Schema::table('topup_impor_baris', function (Blueprint $t) {
            $t->bigInteger('biaya')->default(0)->after('jumlah');
        });
    }

    public function down(): void
    {
        Schema::table('topup_impor_baris', function (Blueprint $t) {
            $t->dropColumn('biaya');
        });
        Schema::table('topup_impor', function (Blueprint $t) {
            $t->dropColumn(['biaya_admin', 'total_biaya']);
        });
    }
};
