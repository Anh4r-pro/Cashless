<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Biaya admin pada top up mandiri wali.
 *
 * Wali membayar Rp100.000, yang masuk kartu Rp97.500, dan Rp2.500 menjadi
 * pemasukan sekolah. Yang membuat ini tidak sepele adalah neraca kas: uang itu
 * MASUK sebagai kas tetapi BUKAN kewajiban, sehingga harus muncul di dua sisi
 * persamaan sekaligus —
 *
 *     masuk  += biaya   (uang tunai yang benar-benar diterima sekolah)
 *     keluar += biaya   (diakui jadi milik sekolah, lepas dari kewajiban)
 *
 * Kalau hanya dicatat di satu sisi, `selisih` neraca langsung bergeser dan
 * pemeriksaan keutuhan akan melaporkan "ada saldo yang muncul tanpa uangnya
 * pernah masuk" — padahal yang terjadi justru sebaliknya.
 *
 * Tipe transaksi tersendiri (bukan menumpang 'koreksi') karena artinya berbeda:
 * koreksi adalah uang yang KELUAR dari kas sekolah, biaya admin adalah uang
 * yang MASUK. Menumpangkannya akan membuat kedua angka itu saling meniadakan
 * di laporan yang sama.
 */
return new class extends Migration {
    public function up(): void
    {
        DB::statement("ALTER TABLE transaksi MODIFY tipe
            ENUM('topup','beli','koreksi','donasi','transfer_keluar','transfer_masuk','biaya_admin') NOT NULL");
    }

    public function down(): void
    {
        // Baris biaya dihapus lebih dulu: tanpa itu MODIFY ditolak karena ada
        // nilai di luar enum tujuan. Menghapusnya memang mengubah neraca —
        // itulah sebabnya turun-versi ini hanya masuk akal pada basis uji.
        DB::table('transaksi')->where('tipe', 'biaya_admin')->delete();
        DB::statement("ALTER TABLE transaksi MODIFY tipe
            ENUM('topup','beli','koreksi','donasi','transfer_keluar','transfer_masuk') NOT NULL");
    }
};
