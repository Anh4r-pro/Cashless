<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Daur hidup pemegang kartu: Alumni, Lanjut Study, Pindah.
 *
 * Santri yang selesai TIDAK dihapus. Ada delapan kunci asing dari buku besar
 * (transaksi, titipan_mutasi, topup_request, …) yang menunjuk ke barisnya, dan
 * neraca kas menghitung kewajiban sekolah dari SUM(saldo) seluruh kartu —
 * menghapus barisnya berarti kewajiban itu hilang dari buku sementara uangnya
 * masih ada di kas, dan pertanyaan wali enam bulan kemudian tidak bisa dijawab.
 *
 * Istilahnya mengikuti pesantren, dan perbedaannya bukan kosmetik:
 *   alumni       — tamat SMA (kelas 12), keluar
 *   lanjut_study — tamat SMP (kelas 9) lalu melanjutkan DI LUAR pesantren ini.
 *                  Yang lanjut ke SMA di sini BUKAN ini: mereka naik kelas biasa
 *                  dari 9 ke 10 dan tetap memakai kartu serta saldo yang sama.
 *   pindah       — keluar di tengah jalan, sebab apa pun
 *
 * Seluruh status baru ini bukan 'aktif', dan 23 tempat di kode sudah
 * membandingkan status terhadap 'aktif' — jadi kartunya berhenti dilayani kasir
 * dan kios dengan sendirinya, tanpa satu pun dari tempat itu perlu diubah.
 */
return new class extends Migration {
    public function up(): void
    {
        DB::statement("ALTER TABLE siswa MODIFY status
            ENUM('aktif','blokir','alumni','lanjut_study','pindah') NOT NULL DEFAULT 'aktif'");

        Schema::table('siswa', function (Blueprint $t) {
            $t->char('arsip_pada', 24)->nullable()->after('status');
            $t->string('arsip_catatan', 300)->default('')->after('arsip_pada');

            /*
             * Indeks gabungan (golongan, status) — bukan sekadar kerapian.
             * Setiap pembukaan daftar santri aktif menyaring dua kolom itu
             * sekaligus. Tanpa indeksnya, baris arsip yang menumpuk tiap tahun
             * ikut dipindai, dan layar yang hari ini ringan akan melambat
             * perlahan tanpa ada perubahan kode yang bisa disalahkan.
             */
            $t->index(['golongan', 'status'], 'siswa_golongan_status_index');
        });
    }

    public function down(): void
    {
        Schema::table('siswa', function (Blueprint $t) {
            $t->dropIndex('siswa_golongan_status_index');
            $t->dropColumn(['arsip_pada', 'arsip_catatan']);
        });
        // Baris berstatus baru dikembalikan ke 'blokir' lebih dulu: tanpa itu
        // MODIFY akan menolak karena ada nilai di luar enum tujuan.
        DB::table('siswa')->whereIn('status', ['alumni', 'lanjut_study', 'pindah'])
            ->update(['status' => 'blokir']);
        DB::statement("ALTER TABLE siswa MODIFY status
            ENUM('aktif','blokir') NOT NULL DEFAULT 'aktif'");
    }
};
