<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Akun staf. Memakai hashing bawaan Laravel (bcrypt) lewat kolom `password`,
 * menggantikan scrypt + salt manual sistem lama: PHP tidak punya scrypt bawaan,
 * dan Hash::make sudah menyertakan salt di dalam hash-nya sendiri.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('users', function (Blueprint $t) {
            $t->id();
            $t->string('username')->unique();
            $t->string('password');
            $t->string('nama');
            $t->enum('role', ['superadmin', 'tenant']);
            $t->foreignId('tenant_id')->nullable()->constrained('tenant');
            $t->char('created_at', 24)->nullable();
        });

        // Sesi staf berbasis token (cookie `sid`), terpisah dari sesi Laravel.
        Schema::create('sessions', function (Blueprint $t) {
            $t->string('token')->primary();
            $t->foreignId('user_id')->constrained('users');
            $t->unsignedBigInteger('expires_at');         // epoch milidetik
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sessions');
        Schema::dropIfExists('users');
    }
};
