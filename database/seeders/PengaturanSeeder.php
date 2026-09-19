<?php

namespace Database\Seeders;

use App\Models\AlasanPotong;
use App\Models\DonasiNominal;
use App\Models\DonasiProgram;
use App\Models\Golongan;
use App\Models\KategoriAkses;
use App\Models\KategoriAksesFitur;
use App\Models\KiosConfig;
use App\Models\Setting;
use App\Models\User;
use App\Services\Izin;
use App\Models\TopupInfo;
use Illuminate\Database\Seeder;
use App\Support\WaktuIso;
use Illuminate\Support\Facades\Hash;

/**
 * Pengaturan bawaan sekolah. Semuanya memakai pola "isi kalau belum ada", jadi
 * menjalankan seeder ulang tidak menimpa angka yang sudah disetel admin.
 */
class PengaturanSeeder extends Seeder
{
    public function run(): void
    {
        $angka = [
            // batas pokok
            'max_saldo' => 500000,           // saldo santri tidak boleh melebihi ini
            'max_topup_harian' => 300000,    // total top up per santri per hari
            'max_beli_harian' => 10,         // jumlah transaksi pembelian per santri per hari
            'max_belanja_harian' => 100000,  // akumulasi nominal belanja per santri per hari
            'max_per_transaksi' => 50000,    // nominal maksimal satu transaksi pembelian

            'kios_aktif' => 1,
            'donasi_min' => 1000,
            'donasi_maks_harian' => 50000,

            'topup_ortu_aktif' => 1,
            'topup_ortu_min' => 10000,
            'topup_ortu_maks' => 300000,
            'topup_ortu_antre' => 3,         // maks pengajuan menunggu per santri
            /*
             * Biaya admin top up mandiri wali. 0 = tidak memungut.
             *
             * Sengaja mati sejak awal: memungut uang dari wali harus keputusan
             * sadar sekolah. Kuncinya WAJIB ada di sini walau bernilai nol —
             * pernah terlewat, dan akibatnya menyimpan pengaturan Topup Ortu
             * gagal dengan "Undefined array key", bukan dengan pesan yang bisa
             * dimengerti siapa pun.
             */
            'biaya_admin_topup_ortu' => 0,

            // saldo titipan & smart top up
            'titipan_aktif' => 1,
            'max_titipan' => 5000000,
            'smart_aktif' => 1,
            'smart_ambang' => 20000,         // saldo <= angka ini memicu isi ulang
            'smart_isi' => 100000,           // setelah isi ulang, saldo diarahkan ke sini

            // Neraca kas: saldo yang sudah ada SEBELUM sistem mencatat mutasi.
            // 0 = belum pernah ditetapkan admin.
            'neraca_saldo_awal' => 0,
            'neraca_titipan_awal' => 0,
            'neraca_awal_pada' => 0,

            // belanja non-makanan (koperasi) — batasnya TERPISAH dari makanan
            'nonmakanan_aktif' => 1,
            'max_nonmakanan_harian' => 50000,
            'max_beli_nonmakanan_harian' => 3,
            'max_nonmakanan_transaksi' => 100000,

            // transfer saldo antar teman lewat kios
            'transfer_aktif' => 1,
            'transfer_min' => 1000,
            'transfer_maks' => 25000,
            'transfer_maks_harian' => 50000,
            'transfer_terima_harian' => 100000,   // rem penampungan
            'transfer_maks_kali_harian' => 5,

            // portal wali: 4 digit HP sebagai kunci ketiga
            'ortu_minta_hp' => 0,

            // Jenjang kelas santri. DISIMPAN SEBAGAI PENGATURAN, bukan ditulis
            // di kode: pesantren ini menjalankan SMP+SMA (kelas 7-12), tapi
            // membuka jenjang baru kelak hanya boleh berarti mengubah angka di
            // sini — bukan menyunting aturan kenaikan kelas.
            'kelas_tingkat_awal' => 7,
            'kelas_tingkat_lulus' => 12,     // tingkat ini berujung ke Alumni
        ];
        foreach ($angka as $k => $v) {
            Setting::firstOrCreate(['key' => $k], ['value' => $v]);
        }

        // Nomor rekening dan gambar QRIS sengaja DIKOSONGKAN: keduanya data milik
        // sekolah, dan portal wali menyembunyikan metode yang belum diisi admin
        // daripada menayangkan contoh yang bisa membuat wali salah transfer.
        $teks = [
            'qris_gambar' => '',
            'qris_nama' => '',
            'qris_instruksi' => 'Buka aplikasi bank atau e-wallet Anda, pilih menu bayar/scan QRIS, lalu arahkan ke kode di atas. Pastikan nama penerima sesuai sebelum membayar.',
            'bank_nama' => '',
            'bank_rekening' => '',
            'bank_atasnama' => '',
            'bank_instruksi' => 'Transfer sesuai nominal yang Anda isi di formulir konfirmasi. Cantumkan nama dan kelas putra/putri Anda pada berita transfer agar mudah dicocokkan.',
        ];
        foreach ($teks as $k => $v) {
            TopupInfo::firstOrCreate(['key' => $k], ['value' => $v]);
        }

        // Sandi bawaan mode layar penuh kios, plus penanda "masih bawaan" untuk
        // peringatan di panel admin.
        if (! KiosConfig::find('sandi_hash')) {
            KiosConfig::create(['key' => 'sandi_hash', 'value' => Hash::make('rahasia123')]);
            KiosConfig::create(['key' => 'sandi_bawaan', 'value' => '1']);
        }

        // Kategori potongan bawaan — diisi TEPAT SEKALI. Penandanya disimpan
        // terpisah, bukan sekadar "tabelnya kosong": daftar kosong bisa berarti
        // belum pernah diisi ATAU sengaja dikosongkan admin, dan tanpa penanda
        // ini admin yang menghapus semuanya akan kejatuhan bawaan lagi.
        if (! Setting::find('alasan_potong_terisi')) {
            if (AlasanPotong::count() === 0) {
                foreach ([
                    'Penarikan tunai saldo',
                    'Koreksi kelebihan top up',
                    'Pengembalian saldo (santri keluar/lulus)',
                    'Pembayaran di luar kantin',
                ] as $i => $teksAlasan) {
                    AlasanPotong::create(['teks' => $teksAlasan, 'urutan' => $i]);
                }
            }
            Setting::create(['key' => 'alasan_potong_terisi', 'value' => 1]);
        }

        $this->golongan();
        $this->donasi();
        $this->kategoriAkses();
    }

    /**
     * Kategori akses bawaan.
     *
     * "Akses Penuh" DITEGAKKAN ULANG setiap kali seeder jalan: ia terkunci,
     * dan keberadaannya yang menjamin selalu ada jalan masuk ke pengelolaan
     * akun sekalipun kategori lain disetel keliru. Tiga kategori contoh
     * lainnya hanya dipasang sekali — sesudah itu sepenuhnya milik admin,
     * termasuk kalau mereka menghapusnya.
     */
    private function kategoriAkses(): void
    {
        foreach (Izin::bawaan() as $i => $b) {
            if (! $b['terkunci'] && Setting::find('kategori_akses_terisi')) {
                continue;
            }
            $k = KategoriAkses::firstOrCreate(['nama' => $b['nama']], [
                'deskripsi' => $b['deskripsi'],
                'terkunci' => $b['terkunci'],
                'bawaan' => true,
                'urutan' => $b['urutan'],
                'created_at' => WaktuIso::sekarang(),
            ]);

            // Kategori terkunci memegang seluruh katalog apa adanya — termasuk
            // fitur yang baru ditambahkan pada pemutakhiran berikutnya, jadi
            // barisnya ditulis ulang setiap kali seeder dijalankan.
            if ($b['terkunci']) {
                KategoriAksesFitur::where('kategori_id', $k->id)->delete();
                foreach ($b['fitur'] as $f) {
                    KategoriAksesFitur::create(['kategori_id' => $k->id, 'fitur' => $f]);
                }

                continue;
            }
            if ($k->wasRecentlyCreated) {
                foreach ($b['fitur'] as $f) {
                    KategoriAksesFitur::firstOrCreate(['kategori_id' => $k->id, 'fitur' => $f]);
                }
            }
        }
        Setting::firstOrCreate(['key' => 'kategori_akses_terisi'], ['value' => 1]);

        // Akun sisi admin yang belum punya kategori dinaikkan ke "Akses Penuh".
        // Tanpa langkah ini, pemasangan sistem izin di atas basis data yang
        // sudah berjalan meninggalkan baris tanpa kategori — yang memang tetap
        // berhak penuh (lihat Izin::milik), tapi tidak terbaca begitu di layar
        // Kelola Akun, dan itu justru jenis kekaburan yang berbahaya pada
        // daftar hak akses.
        if ($penuh = KategoriAkses::where('terkunci', 1)->first()) {
            User::where('role', 'superadmin')->whereNull('kategori_id')
                ->update(['kategori_id' => $penuh->id]);
        }
    }

    /**
     * Golongan bawaan. Angka yang ditulis di sini hanya untuk golongan yang
     * memang tidak masuk akal memakai jatah anak sekolah; NULL berarti "ikut
     * pengaturan sekolah".
     */
    private function golongan(): void
    {
        if (! Setting::find('golongan_terisi')) {
            $baris = [
                // kode, nama, label_unit, jenis, urutan, ortu, kios, transfer,
                // saldo, topup/hr, beli/hr, belanja/hr, per-tx, nm/hr, nm x/hr, nm/tx
                ['siswa', 'Santri', 'Kelas', 'siswa', 0, 1, 1, 1, null, null, null, null, null, null, null, null],
                ['sdm', 'SDM / Pegawai', 'Unit', 'internal', 1, 0, 1, 1, 2000000, 1000000, 30, 300000, 200000, 300000, 10, 300000],
                ['tamu', 'Tamu', 'Instansi', 'tamu', 2, 0, 1, 0, 500000, 500000, 20, 200000, 100000, 100000, 5, 100000],
                ['vip', 'VIP / Undangan', 'Keterangan', 'tamu', 3, 0, 1, 0, 5000000, 5000000, 50, 1000000, 500000, 500000, 20, 500000],
            ];
            foreach ($baris as $r) {
                Golongan::firstOrCreate(['kode' => $r[0]], [
                    'nama' => $r[1], 'label_unit' => $r[2], 'jenis' => $r[3], 'bawaan' => true,
                    'urutan' => $r[4], 'portal_ortu' => $r[5], 'kios' => $r[6], 'transfer' => $r[7],
                    'max_saldo' => $r[8], 'max_topup_harian' => $r[9], 'max_beli_harian' => $r[10],
                    'max_belanja_harian' => $r[11], 'max_per_transaksi' => $r[12],
                    'max_nonmakanan_harian' => $r[13], 'max_beli_nonmakanan_harian' => $r[14],
                    'max_nonmakanan_transaksi' => $r[15],
                ]);
            }
            Setting::create(['key' => 'golongan_terisi', 'value' => 1]);
        }

        // Penyesuaian istilah pesantren: golongan bawaan yang NAMANYA masih
        // 'Santri' diubah menjadi 'Santri'. Hanya menyentuh baris yang masih
        // memakai nama bawaan — nama yang sudah disunting admin dibiarkan.
        Golongan::where('kode', 'siswa')->where('nama', 'Siswa')->update(['nama' => 'Santri']);

        // Sisa istilah lama pada baris yang sudah tercipta sebelum penyesuaian
        // istilah pesantren. Hanya menyentuh teks bawaan yang belum disunting admin.
        AlasanPotong::where('teks', 'Pengembalian saldo (siswa keluar/lulus)')
            ->update(['teks' => 'Pengembalian saldo (santri keluar/lulus)']);

        // Jaring pengaman: golongan 'santri' harus selalu ada, berapa pun yang
        // dihapus admin — tanpa barisnya, setiap kartu lama kehilangan aturannya.
        // KODE-nya tetap 'santri' (tersimpan di ribuan baris data dan di kunci
        // asing), yang berubah hanya NAMA yang terbaca di layar.
        Golongan::firstOrCreate(['kode' => 'siswa'], [
            'nama' => 'Santri', 'label_unit' => 'Kelas', 'jenis' => 'siswa',
            'bawaan' => true, 'urutan' => 0, 'portal_ortu' => true,
        ]);
    }

    private function donasi(): void
    {
        if (Setting::find('donasi_program_terisi')) {
            return;
        }
        if (DonasiProgram::count() === 0) {
            $daftar = [
                ['Infaq Jumat', 'Infaq pekanan santri, disalurkan setiap Jumat', [2000, 5000, 10000, 20000]],
                ['Santunan Yatim & Dhuafa', 'Santunan bulanan untuk yatim dan dhuafa sekitar pesantren', [5000, 10000, 25000, 50000]],
                ['Peduli Bencana', 'Penggalangan dana insidental saat terjadi bencana', [5000, 10000, 20000, 50000]],
            ];
            foreach ($daftar as $i => [$nama, $deskripsi, $nominal]) {
                $p = DonasiProgram::create(['nama' => $nama, 'deskripsi' => $deskripsi, 'urutan' => $i]);
                foreach ($nominal as $k => $j) {
                    DonasiNominal::create(['program_id' => $p->id, 'jumlah' => $j, 'urutan' => $k]);
                }
            }
        }
        Setting::create(['key' => 'donasi_program_terisi', 'value' => 1]);
    }
}
