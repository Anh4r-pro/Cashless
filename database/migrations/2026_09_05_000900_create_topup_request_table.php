<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Pengajuan top up dari portal /ortu. Uangnya berpindah DI LUAR sistem
 * (QRIS statis / transfer bank), jadi baris di sini bukan saldo — hanya klaim
 * yang menunggu diperiksa admin. transaksi_id adalah bukti pencairan: selama
 * kolom itu terisi, pengajuan yang sama tidak bisa dicairkan dua kali.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('topup_request', function (Blueprint $t) {
            $t->id();
            $t->foreignId('siswa_id')->constrained('siswa');
            $t->string('siswa_nama');
            $t->string('kelas')->default('');
            $t->bigInteger('jumlah');
            $t->enum('metode', ['qris', 'transfer']);
            $t->string('tgl_bayar');                      // YYYY-MM-DD menurut ortu
            $t->string('pengirim')->default('');
            $t->string('bukti')->nullable();
            $t->enum('status', ['pending', 'disetujui', 'ditolak'])->default('pending');
            $t->string('alasan', 300)->default('');       // alasan penolakan
            $t->foreignId('transaksi_id')->nullable()->constrained('transaksi');
            $t->string('operator')->default('');
            $t->char('created_at', 24)->nullable();
            $t->char('decided_at', 24)->nullable();

            $t->index(['status', 'created_at']);
            $t->index(['siswa_id', 'created_at']);
        });
    }

    public function down(): void { Schema::dropIfExists('topup_request'); }
};
