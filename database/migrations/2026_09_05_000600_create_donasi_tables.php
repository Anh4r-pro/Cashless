<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Nominal donasi TIDAK bebas diketik santri: hanya pilihan yang didaftarkan
 * admin di donasi_nominal yang diterima server.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('donasi_program', function (Blueprint $t) {
            $t->id();
            $t->string('nama');
            $t->string('deskripsi')->default('');
            $t->integer('urutan')->default(0);
            $t->boolean('aktif')->default(true);
            $t->char('created_at', 24)->nullable();
        });

        Schema::create('donasi_nominal', function (Blueprint $t) {
            $t->id();
            $t->foreignId('program_id')->constrained('donasi_program');
            $t->bigInteger('jumlah');
            $t->integer('urutan')->default(0);
            $t->index('program_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('donasi_nominal');
        Schema::dropIfExists('donasi_program');
    }
};
