<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Pengajuan perubahan menu oleh tenant, menunggu putusan superadmin.
 * Menu TIDAK pernah berubah sampai disetujui — tidak ada perubahan harga
 * diam-diam. Yang tidak lewat sini: menandai menu habis/tersedia, itu
 * keputusan harian tenant yang tidak boleh menunggu admin.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('produk_ajuan', function (Blueprint $t) {
            $t->id();
            $t->foreignId('tenant_id')->constrained('tenant');
            // NULL untuk usulan menu baru, dan menjadi NULL lagi kalau menunya
            // kelak dihapus — barisnya tetap tersimpan sebagai jejak putusan.
            $t->foreignId('produk_id')->nullable()->constrained('produk')->nullOnDelete();
            $t->enum('jenis', ['baru', 'ubah', 'hapus']);
            $t->string('nama')->default('');
            $t->bigInteger('harga')->nullable();
            $t->string('nama_lama')->default('');         // keadaan saat diajukan
            $t->bigInteger('harga_lama')->nullable();
            $t->enum('status', ['menunggu', 'disetujui', 'ditolak', 'batal'])->default('menunggu');
            $t->string('catatan', 300)->default('');
            $t->string('pengaju')->default('');
            $t->string('penilai')->default('');
            $t->char('created_at', 24)->nullable();
            $t->char('decided_at', 24)->nullable();

            $t->index(['tenant_id', 'status', 'created_at']);
            $t->index(['status', 'created_at']);
        });
    }

    public function down(): void { Schema::dropIfExists('produk_ajuan'); }
};
