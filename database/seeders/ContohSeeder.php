<?php

namespace Database\Seeders;

use App\Models\KategoriAkses;
use App\Models\Produk;
use App\Models\Siswa;
use App\Models\Tenant;
use App\Models\Transaksi;
use App\Models\User;
use Illuminate\Database\Seeder;

/**
 * Data contoh: tiga tenant, akun stafnya, menu, dan kartu uji.
 *
 * Lima kartu pertama sengaja bernilai TETAP — beberapa skenario pengujian
 * mengandalkan saldonya persis seperti di sini (mis. "Cahyo Rp3.000" untuk
 * menguji saldo kurang). Jangan diubah tanpa memeriksa suite yang memakainya.
 */
class ContohSeeder extends Seeder
{
    public function run(): void
    {
        if (Tenant::count() > 0) {
            return;
        }

        $t1 = Tenant::create(['nama' => 'Kantin Bu Sari', 'pemilik' => 'Ibu Sari', 'jenis' => 'kantin']);
        $t2 = Tenant::create(['nama' => 'Kantin Pak Budi', 'pemilik' => 'Bapak Budi', 'jenis' => 'kantin']);
        // Hanya tenant berjenis koperasi yang boleh punya barang non-makanan.
        $t3 = Tenant::create(['nama' => 'Koperasi Sekolah', 'pemilik' => 'Ibu Rina', 'jenis' => 'koperasi']);

        // Akun bawaan ditempatkan di kategori terkunci "Akses Penuh" supaya
        // layar Kelola Akun menampilkannya sebagaimana adanya, bukan sebagai
        // baris tanpa kategori yang diam-diam memegang segalanya.
        $penuh = KategoriAkses::where('terkunci', 1)->first();
        User::create(['username' => 'superadmin', 'password' => 'super123', 'nama' => 'Super Admin',
            'role' => 'superadmin', 'kategori_id' => $penuh?->id]);
        User::create(['username' => 'sari', 'password' => 'sari123', 'nama' => 'Ibu Sari', 'role' => 'tenant', 'tenant_id' => $t1->id]);
        User::create(['username' => 'budi', 'password' => 'budi123', 'nama' => 'Bapak Budi', 'role' => 'tenant', 'tenant_id' => $t2->id]);
        User::create(['username' => 'koperasi', 'password' => 'koperasi123', 'nama' => 'Ibu Rina', 'role' => 'tenant', 'tenant_id' => $t3->id]);

        $NM = 'non_makanan';
        $menu = [
            $t1->id => [['Nasi Goreng', 10000], ['Nasi Uduk', 8000], ['Ayam Geprek', 12000], ['Es Teh', 3000], ['Es Jeruk', 4000], ['Kerupuk', 1000]],
            $t2->id => [['Mie Ayam', 9000], ['Bakso', 10000], ['Mie Ayam Bakso', 13000], ['Es Campur', 6000], ['Teh Hangat', 2000]],
            $t3->id => [
                ['Air Mineral', 4000], ['Roti Bakar', 6000], ['Snack', 2500], ['Susu Kotak', 5000],
                ['Buku Tulis', 7000, $NM], ['Pulpen', 3500, $NM], ['Sabun Mandi', 6000, $NM],
                ['Sikat Gigi', 8000, $NM], ['Pasta Gigi', 9000, $NM], ['Handuk Kecil', 25000, $NM],
                ['Deterjen Sachet', 3000, $NM],
            ],
        ];
        foreach ($menu as $tid => $items) {
            foreach ($items as $it) {
                Produk::create([
                    'tenant_id' => $tid, 'nama' => $it[0], 'harga' => $it[1],
                    'kategori' => $it[2] ?? 'makanan',
                ]);
            }
        }

        // nisn / tgl_lahir / hp_ortu = kunci masuk portal orang tua (/ortu).
        $kartu = [
            ['2024001', 'Andi Saputra', '7A', '04A1B2C3', 50000, 'aktif', '0091234561', '2012-04-17', '081234561234'],
            ['2024002', 'Bunga Lestari', '7B', 'A1B2C3D4', 25000, 'aktif', '0091234562', '2012-09-03', '081234562345'],
            ['2024003', 'Cahyo Nugroho', '8A', '1122334455', 3000, 'aktif', '0091234563', '2011-01-25', '081234563456'],
            ['2024004', 'Dewi Anggraini', '8B', null, 0, 'aktif', '0091234564', '2011-11-08', '081234564567'],
            ['2024005', 'Eko Prasetyo', '9A', null, 10000, 'blokir', '0091234565', '2010-06-30', '081234565678'],
        ];
        foreach ($kartu as $k) {
            $s = Siswa::create([
                'nis' => $k[0], 'nama' => $k[1], 'kelas' => $k[2], 'card_uid' => $k[3],
                'saldo' => $k[4], 'status' => $k[5], 'nisn' => $k[6],
                'tgl_lahir' => $k[7], 'hp_ortu' => $k[8],
            ]);
            // Saldo pembuka DICATAT sebagai transaksi, bukan sekadar ditulis ke
            // kolom saldo. Kalau tidak, uang itu ada di kartu tanpa satu pun
            // baris buku besar yang menjelaskannya — dan panel neraca akan
            // melaporkannya sebagai selisih, tepat seperti yang seharusnya.
            if ($k[4] > 0) {
                Transaksi::create([
                    'tipe' => 'topup', 'siswa_id' => $s->id, 'siswa_nama' => $s->nama,
                    'kelas' => $s->kelas, 'jumlah' => $k[4], 'saldo_sesudah' => $k[4],
                    'operator' => 'Super Admin', 'keterangan' => 'Saldo pembuka (data contoh)',
                    // Dipatok jauh di belakang agar tetap menjadi baris
                    // PERTAMA walau riwayat contoh ikut dibuat sesudahnya.
                    'created_at' => '2026-06-25T02:00:00.000Z',
                ]);
            }
        }
    }
}
