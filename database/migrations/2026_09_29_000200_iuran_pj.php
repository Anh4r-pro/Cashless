<?php
use App\Models\Setting;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Iuran ke PJ (SDM/pegawai) lewat kios — santri membayar sendiri, penerima
 * dipilih dari daftar (bukan mengetik NIY), deskripsi wajib diisi.
 *
 * `terima_iuran` sengaja bukan sakelar golongan (seperti `transfer` di
 * golongan): siapa yang jadi PJ berbeda-beda per ORANG, bukan per golongan —
 * dua guru di golongan SDM yang sama bisa saja hanya satu yang jadi PJ
 * kelompok ngaji.
 *
 * Batasnya (iuran_min/maks/...) sengaja TERPISAH dari transfer_* meski
 * mesinnya sama persis: transfer antar teman sengaja diketatkan (rawan
 * pemerasan uang jajan), sedangkan iuran ke PJ yang jelas identitasnya boleh
 * bernominal lebih besar tanpa melonggarkan batas transfer pertemanan.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('siswa', function (Blueprint $t) {
            $t->boolean('terima_iuran')->default(false)->after('biaya_admin');
        });

        DB::statement("ALTER TABLE transaksi MODIFY tipe
            ENUM('topup','beli','koreksi','donasi','transfer_keluar','transfer_masuk','biaya_admin',
                'iuran_keluar','iuran_masuk') NOT NULL");

        foreach ([
            'iuran_aktif' => 1,
            'iuran_min' => 1000,
            'iuran_maks' => 100000,
            'iuran_maks_harian' => 200000,
            'iuran_maks_kali_harian' => 10,
            'iuran_terima_harian' => 1000000,
        ] as $k => $v) {
            Setting::firstOrCreate(['key' => $k], ['value' => $v]);
        }
    }

    public function down(): void
    {
        DB::table('transaksi')->whereIn('tipe', ['iuran_keluar', 'iuran_masuk'])->delete();
        DB::statement("ALTER TABLE transaksi MODIFY tipe
            ENUM('topup','beli','koreksi','donasi','transfer_keluar','transfer_masuk','biaya_admin') NOT NULL");
        Schema::table('siswa', function (Blueprint $t) {
            $t->dropColumn('terima_iuran');
        });
        Setting::whereIn('key', [
            'iuran_aktif', 'iuran_min', 'iuran_maks', 'iuran_maks_harian',
            'iuran_maks_kali_harian', 'iuran_terima_harian',
        ])->delete();
    }
};
