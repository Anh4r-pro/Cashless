<?php

use App\Support\WaktuIso;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Pengecualian limit non-makanan harian — supaya barang besar sesekali
 * (seragam, alat sekolah) tidak kena jatah yang sebenarnya dirancang untuk
 * menahan jajan harian, tanpa menaikkan limit golongan secara keseluruhan
 * (yang akan membuka SELURUH belanja non-makanan, bukan cuma barang itu).
 *
 * Pola CRUD-nya identik `alasan_potong` (daftar teks sederhana, admin bisa
 * tambah/ubah/hapus) — bedanya tiap entri bisa menuntut keterangan wajib
 * saat dipakai (`wajib_keterangan`), untuk entri "tangkapan" semacam
 * "Lainnya" yang namanya sendiri tidak menjelaskan apa-apa di laporan.
 *
 * `produk.jatah_kecuali_id` menandai barang katalog yang memang disiapkan
 * sebagai pengecualian (mis. "Seragam Pramuka" di Imbos Mart). Transaksi
 * manual (kasir ketik sendiri) tidak punya baris produk, jadi pilihannya
 * dicatat per baris di `transaksi_item` sendiri.
 *
 * `transaksi.kecuali` adalah AGREGAT porsi non-makanan yang DIKECUALIKAN
 * dalam satu transaksi — sengaja kolom terpisah dari `nonmakanan` (yang
 * tetap berarti "porsi non-makanan yang MASIH kena jatah"), supaya
 * Rekap::belanjaHariIni() bisa membedakan ketiganya: makanan (kena jatah
 * makanan), non-makanan (kena jatah non-makanan), dan kecuali (tidak kena
 * jatah apa pun). Tanpa kolom terpisah ini, porsi yang dikecualikan akan
 * salah masuk hitungan jatah MAKANAN (karena makanan selama ini dihitung
 * sebagai total−nonmakanan).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('jatah_kecuali', function (Blueprint $t) {
            $t->id();
            $t->string('nama', 60);
            $t->string('deskripsi', 200)->default('');
            $t->boolean('wajib_keterangan')->default(false);
            $t->integer('urutan')->default(0);
            $t->boolean('aktif')->default(true);
            $t->char('created_at', 24)->nullable();
        });

        Schema::table('produk', function (Blueprint $t) {
            $t->foreignId('jatah_kecuali_id')->nullable()->after('kategori')
                ->constrained('jatah_kecuali')->nullOnDelete();
        });

        Schema::table('transaksi', function (Blueprint $t) {
            $t->bigInteger('kecuali')->default(0)->after('nonmakanan');
        });

        Schema::table('transaksi_item', function (Blueprint $t) {
            $t->foreignId('jatah_kecuali_id')->nullable()->after('kategori')
                ->constrained('jatah_kecuali')->nullOnDelete();
            $t->string('jatah_kecuali_keterangan', 200)->default('')->after('jatah_kecuali_id');
        });

        // Dua pilihan bawaan yang sudah disepakati — admin bebas menambah/
        // mengubah/menghapus setelahnya. "Lainnya" sengaja mewajibkan
        // keterangan: namanya sendiri tidak menjelaskan apa-apa di laporan.
        $sekarang = WaktuIso::sekarang();
        DB::table('jatah_kecuali')->insert([
            ['nama' => 'Seragam', 'deskripsi' => 'Seragam resmi sekolah (pramuka, olahraga, batik, dll)',
                'wajib_keterangan' => false, 'urutan' => 0, 'aktif' => true, 'created_at' => $sekarang],
            ['nama' => 'Lainnya', 'deskripsi' => 'Barang besar sesekali di luar kategori lain',
                'wajib_keterangan' => true, 'urutan' => 1, 'aktif' => true, 'created_at' => $sekarang],
        ]);
    }

    public function down(): void
    {
        Schema::table('transaksi_item', function (Blueprint $t) {
            $t->dropConstrainedForeignId('jatah_kecuali_id');
            $t->dropColumn('jatah_kecuali_keterangan');
        });
        Schema::table('transaksi', function (Blueprint $t) {
            $t->dropColumn('kecuali');
        });
        Schema::table('produk', function (Blueprint $t) {
            $t->dropConstrainedForeignId('jatah_kecuali_id');
        });
        Schema::dropIfExists('jatah_kecuali');
    }
};
