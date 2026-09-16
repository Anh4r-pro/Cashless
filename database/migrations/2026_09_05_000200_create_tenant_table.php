<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('tenant', function (Blueprint $t) {
            $t->id();
            $t->string('nama');
            $t->string('pemilik')->default('');
            $t->enum('status', ['aktif', 'nonaktif'])->default('aktif');
            $t->enum('jenis', ['kantin', 'koperasi'])->default('kantin');
            $t->char('created_at', 24)->nullable();
        });
    }

    public function down(): void { Schema::dropIfExists('tenant'); }
};
