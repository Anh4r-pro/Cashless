<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Satu berkas Excel/CSV top up yang sudah diterapkan admin.
 * batch_id UNIQUE: mengunggah ulang berkas yang sama tidak menambah saldo dua kali.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('topup_impor', function (Blueprint $t) {
            $t->id();
            $t->string('batch_id')->unique();
            $t->string('berkas')->default('');
            // Sidik SHA-256 isi berkas. Sengaja BUKAN kunci UNIQUE: berkas dengan
            // isi sama masih boleh diterapkan dua kali kalau admin menyatakannya
            // sengaja — tetapi tanpa pernyataan itu, kiriman kedua ditolak.
            $t->string('sidik')->default('');
            $t->integer('n_baris')->default(0);
            $t->integer('n_berhasil')->default(0);
            $t->integer('n_gagal')->default(0);
            $t->bigInteger('total_saldo')->default(0);    // rupiah yang masuk ke kartu
            $t->bigInteger('total_titipan')->default(0);  // rupiah yang mengendap di titipan
            $t->string('operator')->default('');
            $t->char('created_at', 24)->nullable();
            $t->index('sidik');
        });

        Schema::create('topup_impor_baris', function (Blueprint $t) {
            $t->id();
            $t->foreignId('impor_id')->constrained('topup_impor');
            $t->integer('baris');                         // nomor baris seperti di Excel
            $t->foreignId('siswa_id')->nullable()->constrained('siswa');
            $t->string('nis')->default('');
            $t->string('nama')->default('');              // nama pada berkas, apa adanya
            $t->bigInteger('jumlah')->default(0);
            $t->bigInteger('ke_saldo')->default(0);
            $t->bigInteger('ke_titipan')->default(0);
            $t->string('metode')->default('');
            $t->string('tgl_terima')->default('');        // YYYY-MM-DD
            $t->enum('status', ['berhasil', 'gagal']);
            $t->string('pesan', 300)->default('');
            $t->index(['impor_id', 'baris']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('topup_impor_baris');
        Schema::dropIfExists('topup_impor');
    }
};
