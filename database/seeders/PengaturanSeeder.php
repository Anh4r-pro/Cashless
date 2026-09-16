<?php

namespace Database\Seeders;

use App\Models\AlasanPotong;
use App\Models\DonasiNominal;
use App\Models\DonasiProgram;
use App\Models\Golongan;
use App\Models\KiosConfig;
use App\Models\Setting;
use App\Models\TopupInfo;
use Illuminate\Database\Seeder;
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
            'max_saldo' => 500000,           // saldo siswa tidak boleh melebihi ini
            'max_topup_harian' => 300000,    // total top up per siswa per hari
            'max_beli_harian' => 10,         // jumlah transaksi pembelian per siswa per hari
            'max_belanja_harian' => 100000,  // akumulasi nominal belanja per siswa per hari
            'max_per_transaksi' => 50000,    // nominal maksimal satu transaksi pembelian

            'kios_aktif' => 1,
            'donasi_min' => 1000,
            'donasi_maks_harian' => 50000,

            'topup_ortu_aktif' => 1,
            'topup_ortu_min' => 10000,
            'topup_ortu_maks' => 300000,
            'topup_ortu_antre' => 3,         // maks pengajuan menunggu per siswa

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
                    'Pengembalian saldo (siswa keluar/lulus)',
                    'Pembayaran di luar kantin',
                ] as $i => $teksAlasan) {
                    AlasanPotong::create(['teks' => $teksAlasan, 'urutan' => $i]);
                }
            }
            Setting::create(['key' => 'alasan_potong_terisi', 'value' => 1]);
        }

        $this->golongan();
        $this->donasi();
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
                ['siswa', 'Siswa', 'Kelas', 'siswa', 0, 1, 1, 1, null, null, null, null, null, null, null, null],
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

        // Jaring pengaman: golongan 'siswa' harus selalu ada, berapa pun yang
        // dihapus admin — tanpa barisnya, setiap kartu lama kehilangan aturannya.
        Golongan::firstOrCreate(['kode' => 'siswa'], [
            'nama' => 'Siswa', 'label_unit' => 'Kelas', 'jenis' => 'siswa',
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
