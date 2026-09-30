<?php
use App\Services\BidangSiswa;
use App\Support\WaktuIso;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Daftar Unit untuk SDM & personil, dulu angka tetap di kode
 * (BidangSiswa::UNIT_SDM), sekarang bisa admin tambah/hapus sendiri.
 *
 * Sengaja TIDAK punya kolom `kode` seperti golongan: nama itu sendiri yang
 * dirujuk (tersimpan apa adanya di kolom siswa.kelas untuk baris SDM), jadi
 * mengganti NAMA di sini tidak otomatis mengubah data lama — konsisten
 * dengan endpoint satu-satu yang sudah lama menerima unit di luar daftar.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('unit_sdm', function (Blueprint $t) {
            $t->id();
            $t->string('nama', 30);
            $t->integer('urutan')->default(0);
            $t->boolean('aktif')->default(true);
            $t->char('created_at', 24)->nullable();
        });

        foreach (BidangSiswa::UNIT_SDM as $i => $nama) {
            \DB::table('unit_sdm')->insert([
                'nama' => $nama, 'urutan' => $i, 'aktif' => true,
                'created_at' => WaktuIso::sekarang(),
            ]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('unit_sdm');
    }
};
