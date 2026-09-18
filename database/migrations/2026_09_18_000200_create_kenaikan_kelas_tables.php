<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Kenaikan kelas massal, dicatat sebagai BATCH.
 *
 * Dua alasan kenapa ini tidak boleh jadi sekadar satu UPDATE:
 *
 *  1. Dijalankan dua kali, 7A menjadi 9A. Senyap, dan menyentuh seluruh
 *     angkatan. Pasangan (tahun_ajaran, percobaan) dibuat UNIQUE supaya tahun
 *     yang sama tidak mungkin dinaikkan dua kali secara bersamaan — pengaman
 *     yang sama seperti batch_id pada topup massal.
 *  2. Kalau pemetaannya keliru, tidak ada jalan pulang. Rincian per santri
 *     (kelas & status sebelum) disimpan, jadi satu batch bisa dibatalkan utuh.
 *
 * Kelas pada TRANSAKSI sengaja tidak disentuh: nilainya sudah didenormalisasi
 * di sana, sehingga laporan tahun lalu tetap menunjukkan kelas yang berlaku
 * saat transaksinya terjadi. Itu memang yang diinginkan.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('kenaikan_kelas', function (Blueprint $t) {
            $t->id();
            $t->string('tahun_ajaran', 20);               // mis. "2026/2027"
            /*
             * Percobaan ke berapa untuk tahun ajaran ini.
             *
             * UNIQUE dipasang pada (tahun_ajaran, percobaan), BUKAN pada
             * tahun_ajaran saja. Kalau hanya tahun ajarannya yang unik, batch
             * yang sudah dibatalkan tetap menempati satu-satunya tempat yang
             * tersedia — sehingga kenaikan yang pemetaannya keliru tidak bisa
             * dibatalkan lalu dijalankan ulang, padahal itu justru satu-satunya
             * alasan tombol Batalkan ada.
             *
             * Bentuk gabungan ini tetap menjamin hal yang harus dijamin: dua
             * admin yang menekan Terapkan pada saat yang sama akan menghitung
             * percobaan yang sama, dan basis data menolak yang kedua.
             */
            $t->unsignedInteger('percobaan')->default(1);
            $t->string('operator');
            $t->string('catatan', 300)->default('');
            $t->integer('n_naik')->default(0);
            $t->integer('n_arsip')->default(0);
            $t->char('dibatalkan_pada', 24)->nullable();
            $t->string('dibatalkan_oleh')->default('');
            $t->char('created_at', 24)->nullable();

            $t->unique(['tahun_ajaran', 'percobaan']);
        });

        Schema::create('kenaikan_kelas_baris', function (Blueprint $t) {
            $t->id();
            $t->foreignId('batch_id')->constrained('kenaikan_kelas')->cascadeOnDelete();
            /*
             * cascadeOnDelete, BUKAN NO ACTION.
             *
             * Tanpa ini, santri yang pernah sekali saja ikut kenaikan kelas
             * tidak akan bisa dihapus lagi selamanya — termasuk baris salah
             * entri yang belum punya satu pun transaksi — dan kegagalannya
             * muncul sebagai halaman galat 500, bukan pesan yang bisa dibaca
             * admin. Rincian kenaikan untuk santri yang sudah tidak ada juga
             * tidak berarti apa-apa: tidak ada lagi yang bisa dipulihkan.
             */
            $t->foreignId('siswa_id')->constrained('siswa')->cascadeOnDelete();
            $t->string('kelas_lama')->default('');
            $t->string('kelas_baru')->default('');
            $t->string('status_lama', 20)->default('');
            $t->string('status_baru', 20)->default('');
            $t->index(['batch_id', 'siswa_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('kenaikan_kelas_baris');
        Schema::dropIfExists('kenaikan_kelas');
    }
};
