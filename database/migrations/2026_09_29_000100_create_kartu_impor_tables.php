<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Satu sesi pendaftaran kartu RFID kolektif yang sudah diterapkan admin —
 * baik dari berkas Excel (UID sudah diketahui lebih dulu) maupun dari sesi
 * tap berturut-turut (UID dibaca reader saat itu juga). `sumber` membedakan
 * keduanya di riwayat. batch_id UNIQUE: mengirim batch yang sama dua kali
 * tidak mendaftarkan ulang kartu yang sudah tersimpan.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('kartu_impor', function (Blueprint $t) {
            $t->id();
            $t->string('batch_id')->unique();
            $t->enum('jenis', ['santri', 'sdm']);
            $t->enum('sumber', ['excel', 'tap'])->default('excel');
            $t->string('berkas')->default('');    // kosong untuk sumber 'tap'
            $t->string('sidik')->default('');      // kosong untuk sumber 'tap'
            $t->integer('n_baris')->default(0);
            $t->integer('n_berhasil')->default(0);
            $t->integer('n_gagal')->default(0);
            $t->string('operator')->default('');
            $t->char('created_at', 24)->nullable();
            $t->index('sidik');
        });

        Schema::create('kartu_impor_baris', function (Blueprint $t) {
            $t->id();
            $t->foreignId('impor_id')->constrained('kartu_impor');
            $t->integer('baris');                  // nomor baris Excel, atau urutan tap
            // nullOnDelete: santri yang kartunya sudah lama tercatat di sini boleh
            // dihapus (uji, atau arsip lama yang dibersihkan) tanpa terhalang
            // FK — riwayatnya tetap ada, cuma pranala ke orangnya jadi kosong.
            $t->foreignId('siswa_id')->nullable()->constrained('siswa')->nullOnDelete();
            $t->string('nis')->default('');
            $t->string('nama')->default('');       // nama pada berkas/hasil pencarian, apa adanya
            $t->string('uid')->default('');
            $t->string('uid_lama')->default('');   // UID sebelum ditimpa, kosong kalau kartu baru
            $t->enum('status', ['berhasil', 'gagal']);
            $t->string('pesan', 300)->default('');
            $t->index(['impor_id', 'baris']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('kartu_impor_baris');
        Schema::dropIfExists('kartu_impor');
    }
};
