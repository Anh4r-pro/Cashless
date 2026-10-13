<?php

use App\Models\Setting;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Tabungan santri — mirip Donasi (admin menentukan jenisnya, santri mengisi
 * sendiri lewat kios), tapi SATU ARAH-nya terbalik: uang ini tetap milik
 * santri yang sama dan harus bisa kembali ke kartunya, bukan menjadi
 * kewajiban permanen seperti donasi.
 *
 * Karena itu ia punya buku besarnya SENDIRI (seperti titipan), bukan
 * menumpang kolom `saldo`/`saldo_titipan` di `siswa` — satu santri bisa
 * punya banyak JENIS tabungan sekaligus (Study Tour, Qurban, dst), jadi
 * saldonya disimpan per (siswa, jenis), bukan satu kolom agregat.
 *
 * Mencairkan SENGAJA tidak memakai tipe transaksi 'topup' (beda dari
 * pencairan titipan): tipe 'tabungan' baru dibuat supaya Neraca tidak
 * menghitungnya sebagai uang BARU masuk kas — uang ini sudah pernah
 * dihitung masuk kas saat pertama kali ditabungkan, cuma berpindah
 * "kantong" bolak-balik antara kartu dan tabungan.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tabungan_jenis', function (Blueprint $t) {
            $t->id();
            $t->string('nama', 60);
            $t->string('deskripsi', 200)->default('');
            // null = bebas tanpa batas; diisi = tampil progres terkumpul/target.
            $t->bigInteger('target')->nullable();
            $t->integer('urutan')->default(0);
            $t->boolean('aktif')->default(true);
            $t->char('created_at', 24)->nullable();
        });

        Schema::create('tabungan_saldo', function (Blueprint $t) {
            $t->id();
            $t->foreignId('siswa_id')->constrained('siswa');
            $t->foreignId('jenis_id')->constrained('tabungan_jenis');
            $t->bigInteger('saldo')->default(0);
            $t->char('updated_at', 24)->nullable();
            $t->unique(['siswa_id', 'jenis_id']);
        });

        Schema::create('tabungan_mutasi', function (Blueprint $t) {
            $t->id();
            $t->foreignId('siswa_id')->constrained('siswa');
            $t->foreignId('jenis_id')->constrained('tabungan_jenis');
            $t->enum('jenis_mutasi', ['masuk', 'keluar']);
            $t->bigInteger('jumlah');              // selalu positif, arah dari jenis_mutasi
            $t->bigInteger('saldo_sesudah');        // saldo JENIS ini sesudah mutasi, bukan total semua jenis
            // Hanya relevan untuk 'masuk': dari mana uangnya diambil. Kios
            // selalu 'kartu'; admin boleh pilih kartu/titipan/campuran.
            $t->string('sumber', 20)->nullable();
            $t->foreignId('transaksi_id')->nullable()->constrained('transaksi');
            $t->string('operator')->default('');
            $t->string('keterangan', 300)->default('');
            $t->string('ref')->nullable()->unique();
            $t->char('created_at', 24)->nullable();
            $t->index(['siswa_id', 'jenis_id', 'created_at']);
        });

        DB::statement("ALTER TABLE transaksi MODIFY tipe
            ENUM('topup','beli','koreksi','donasi','transfer_keluar','transfer_masuk','biaya_admin',
                'iuran_keluar','iuran_masuk','tabungan') NOT NULL");

        DB::statement("ALTER TABLE titipan_mutasi MODIFY jenis
            ENUM('setor','isi_saldo','tarik','koreksi','tabungan') NOT NULL");

        foreach (['tabungan_min' => 1000, 'tabungan_maks_harian' => 500000] as $k => $v) {
            Setting::firstOrCreate(['key' => $k], ['value' => $v]);
        }
    }

    public function down(): void
    {
        DB::table('transaksi')->where('tipe', 'tabungan')->delete();
        DB::table('titipan_mutasi')->where('jenis', 'tabungan')->delete();
        DB::statement("ALTER TABLE transaksi MODIFY tipe
            ENUM('topup','beli','koreksi','donasi','transfer_keluar','transfer_masuk','biaya_admin',
                'iuran_keluar','iuran_masuk') NOT NULL");
        DB::statement("ALTER TABLE titipan_mutasi MODIFY jenis
            ENUM('setor','isi_saldo','tarik','koreksi') NOT NULL");
        Setting::whereIn('key', ['tabungan_min', 'tabungan_maks_harian'])->delete();
        Schema::dropIfExists('tabungan_mutasi');
        Schema::dropIfExists('tabungan_saldo');
        Schema::dropIfExists('tabungan_jenis');
    }
};
