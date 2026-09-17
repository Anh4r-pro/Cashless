<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Golongan pemegang kartu (santri / SDM / tamu / VIP).
 * Kolom max_* adalah PENIMPA batas sekolah, bukan batas kedua:
 * NULL berarti "ikut pengaturan sekolah", bukan nol.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('golongan', function (Blueprint $t) {
            $t->id();
            $t->string('kode')->unique();                 // dirujuk santri.golongan
            $t->string('nama');
            $t->string('label_unit')->default('Kelas');
            $t->enum('jenis', ['siswa', 'internal', 'tamu'])->default('internal');
            $t->boolean('bawaan')->default(false);        // true = tidak boleh dihapus admin
            $t->integer('urutan')->default(0);
            $t->boolean('aktif')->default(true);
            $t->boolean('portal_ortu')->default(false);
            $t->boolean('kios')->default(true);
            $t->boolean('transfer')->default(true);
            foreach ([
                'max_saldo', 'max_topup_harian', 'max_beli_harian', 'max_belanja_harian',
                'max_per_transaksi', 'max_nonmakanan_harian', 'max_beli_nonmakanan_harian',
                'max_nonmakanan_transaksi',
            ] as $k) {
                $t->bigInteger($k)->nullable();           // NULL = ikut settings sekolah
            }
            $t->char('created_at', 24)->nullable();
        });
    }

    public function down(): void { Schema::dropIfExists('golongan'); }
};
