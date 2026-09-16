<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('produk', function (Blueprint $t) {
            $t->id();
            $t->foreignId('tenant_id')->constrained('tenant');
            $t->string('nama');
            $t->bigInteger('harga');
            $t->boolean('aktif')->default(true);
            // Bawaan 'makanan' supaya tidak ada belanja yang mendadak tertolak;
            // admin sendiri yang menandai barang non-makanan milik koperasi.
            $t->enum('kategori', ['makanan', 'non_makanan'])->default('makanan');
            $t->index('tenant_id');
        });
    }

    public function down(): void { Schema::dropIfExists('produk'); }
};
