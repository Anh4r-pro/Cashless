<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        // Hanya menampung ANGKA. Nilai teks punya tabelnya sendiri di bawah.
        Schema::create('settings', function (Blueprint $t) {
            $t->string('key')->primary();
            $t->bigInteger('value');
        });

        /**
         * Kategori alasan pemotongan saldo. Hanya daftar pilihan cepat: yang
         * tersimpan di transaksi tetap TEKS alasannya, jadi menghapus kategori
         * tidak pernah mengubah riwayat yang sudah ada.
         */
        Schema::create('alasan_potong', function (Blueprint $t) {
            $t->id();
            $t->string('teks');
            $t->integer('urutan')->default(0);
            $t->boolean('aktif')->default(true);
        });

        // Instruksi pembayaran manual yang tampil ke orang tua (teks & nama berkas).
        Schema::create('topup_info', function (Blueprint $t) {
            $t->string('key')->primary();
            $t->string('value', 1000)->default('');
        });

        // Sandi keluar mode layar penuh kios — ter-hash + salt, tidak pernah
        // dikirim ke layar kios.
        Schema::create('kios_config', function (Blueprint $t) {
            $t->string('key')->primary();
            $t->string('value', 500)->default('');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('kios_config');
        Schema::dropIfExists('topup_info');
        Schema::dropIfExists('alasan_potong');
        Schema::dropIfExists('settings');
    }
};
