<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Buku besar SALDO TITIPAN — uang yang sudah diterima sekolah tetapi BELUM
 * bisa dibelanjakan: kartu hanya boleh memegang max_saldo, jadi kelebihan
 * setoran menunggu di sini dan diteteskan ke kartu oleh smart top up.
 *
 * Titipan punya buku besarnya sendiri supaya tidak ada satu pun angka di
 * riwayat lama yang berubah artinya. Baris yang memindahkan titipan → saldo
 * menyebut transaksi pasangannya, jadi dua sisi mutasi selalu bisa dicocokkan.
 *
 * ref adalah kunci idempotensi (UNIQUE): kiriman ulang impor atau klik ganda
 * ditolak oleh basis data, bukan diandalkan ke pengecekan di kode aplikasi.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('titipan_mutasi', function (Blueprint $t) {
            $t->id();
            $t->foreignId('siswa_id')->constrained('siswa');
            $t->enum('jenis', ['setor', 'isi_saldo', 'tarik', 'koreksi']);
            $t->bigInteger('jumlah');                     // + masuk, - keluar
            $t->bigInteger('titipan_sesudah');
            $t->foreignId('transaksi_id')->nullable()->constrained('transaksi');
            $t->string('operator')->default('');
            $t->string('keterangan', 300)->default('');
            $t->string('ref')->nullable()->unique();
            $t->char('created_at', 24)->nullable();
            $t->index(['siswa_id', 'created_at']);
        });
    }

    public function down(): void { Schema::dropIfExists('titipan_mutasi'); }
};
