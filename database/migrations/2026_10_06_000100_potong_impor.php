<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Catatan satu berkas potong massal yang pernah diterapkan.
 *
 * Gunanya satu dan penting: `sidik` — sidik isi berkas — membuat berkas yang
 * SAMA PERSIS tidak bisa diterapkan dua kali. Kode batch saja tidak cukup
 * melindungi: layar membuat kode batch baru setiap kali berkas diunggah, jadi
 * berkas yang tidak sengaja diunggah dua kali akan lolos sebagai dua batch
 * berbeda — dan memotong saldo santri dua kali. Di impor top up kesalahan itu
 * menambah uang yang tidak ada; di sini ia MENGAMBIL uang yang ada.
 *
 * Barisnya juga menjadi riwayat: siapa menerapkan, berapa banyak yang kena,
 * berapa yang dilewati, dan berapa total yang dipotong.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('potong_impor', function (Blueprint $t) {
            $t->id();
            $t->string('batch_id', 60)->index();
            $t->string('berkas', 160)->default('');
            $t->string('sidik', 64)->index();
            $t->string('alasan', 300)->default('');
            $t->unsignedInteger('n_baris')->default(0);
            $t->unsignedInteger('n_berhasil')->default(0);
            $t->unsignedInteger('n_gagal')->default(0);
            $t->bigInteger('total_potong')->default(0);
            $t->string('operator', 120);
            $t->string('created_at', 30);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('potong_impor');
    }
};
