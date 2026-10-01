<?php

namespace Database\Seeders;

use App\Models\KategoriAkses;
use App\Models\MartKategori;
use App\Models\MartStokMutasi;
use App\Models\Produk;
use App\Models\Siswa;
use App\Models\Tenant;
use App\Models\Transaksi;
use App\Models\User;
use App\Support\WaktuIso;
use Illuminate\Database\Seeder;

/**
 * Data contoh: empat tenant (tiga kantin/koperasi + IMBOS Mart), akun stafnya,
 * menu & barang, serta kartu uji.
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
        // IMBOS Mart: unit usaha yayasan, berjualan swalayan. Tetap `koperasi`
        // (supaya boleh menjual non-makanan) tetapi `mode_kasir = mart`, yang
        // menukar layar kasirnya menjadi POS berbarcode & berstok.
        $t4 = Tenant::create(['nama' => 'IMBOS Mart', 'pemilik' => 'Yayasan IMBOS',
            'jenis' => 'koperasi', 'mode_kasir' => 'mart']);

        // Akun bawaan ditempatkan di kategori terkunci "Akses Penuh" supaya
        // layar Kelola Akun menampilkannya sebagaimana adanya, bukan sebagai
        // baris tanpa kategori yang diam-diam memegang segalanya.
        $penuh = KategoriAkses::where('terkunci', 1)->first();
        User::create(['username' => 'superadmin', 'password' => 'super123', 'nama' => 'Super Admin',
            'role' => 'superadmin', 'kategori_id' => $penuh?->id]);
        User::create(['username' => 'sari', 'password' => 'sari123', 'nama' => 'Ibu Sari', 'role' => 'tenant', 'tenant_id' => $t1->id]);
        User::create(['username' => 'budi', 'password' => 'budi123', 'nama' => 'Bapak Budi', 'role' => 'tenant', 'tenant_id' => $t2->id]);
        User::create(['username' => 'koperasi', 'password' => 'koperasi123', 'nama' => 'Ibu Rina', 'role' => 'tenant', 'tenant_id' => $t3->id]);
        User::create(['username' => 'mart', 'password' => 'mart123', 'nama' => 'Kasir Mart', 'role' => 'tenant', 'tenant_id' => $t4->id]);

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

        // Barang IMBOS Mart: berbeda dari menu kantin karena punya SKU, harga
        // modal, dan stok. Tiga keadaan stok sengaja dibuat lengkap — aman,
        // menipis, dan habis — supaya warna statusnya bisa dilihat apa adanya
        // di layar tanpa perlu menjual apa pun lebih dulu.
        $katMart = [];
        foreach (['Makanan & Minuman', 'Sembako', 'ATK', 'Lainnya'] as $i => $nama) {
            $katMart[$nama] = MartKategori::create([
                'tenant_id' => $t4->id, 'nama' => $nama, 'urutan' => $i + 1,
                'aktif' => true, 'created_at' => WaktuIso::sekarang(),
            ])->id;
        }
        $barangMart = [
            // nama, SKU, kategori etalase, HPP, harga jual, stok, jatah santri
            ['Aqua 600ml', '8991002101019', 'Makanan & Minuman', 2500, 4000, 48, 'makanan'],
            ['Teh Pucuk 350ml', '8991002101026', 'Makanan & Minuman', 3200, 5000, 36, 'makanan'],
            ['Chitato Sapi 68g', '8992753101011', 'Makanan & Minuman', 8500, 11000, 12, 'makanan'],
            ['Roti Sari Roti Coklat', '8992753101028', 'Makanan & Minuman', 4800, 6500, 4, 'makanan'],
            ['Indomie Goreng', '8998866200011', 'Sembako', 2700, 3500, 60, 'makanan'],
            ['Gula Pasir 1kg', '8998866200028', 'Sembako', 14000, 17000, 9, 'makanan'],
            ['Minyak Goreng 1L', '8998866200035', 'Sembako', 16500, 19500, 0, 'makanan'],
            ['Buku Tulis 38 lbr', '8994567100014', 'ATK', 4200, 6000, 40, 'non_makanan'],
            ['Pulpen Standard AE7', '8994567100021', 'ATK', 1800, 3000, 25, 'non_makanan'],
            ['Pensil 2B', '8994567100038', 'ATK', 1500, 2500, 3, 'non_makanan'],
            ['Sabun Mandi Batang', '8997012300017', 'Lainnya', 3500, 5000, 18, 'non_makanan'],
            ['Pasta Gigi 75g', '8997012300024', 'Lainnya', 7500, 10000, 2, 'non_makanan'],
        ];
        foreach ($barangMart as $b) {
            $p = Produk::create([
                'tenant_id' => $t4->id, 'nama' => $b[0], 'barcode' => $b[1],
                'kategori_id' => $katMart[$b[2]], 'hpp' => $b[3], 'harga' => $b[4],
                'stok' => $b[5], 'stok_min' => 5, 'kategori' => $b[6],
            ]);
            if ($p->stok > 0) {
                MartStokMutasi::create([
                    'tenant_id' => $t4->id, 'produk_id' => $p->id, 'jenis' => 'masuk',
                    'qty' => $p->stok, 'stok_sesudah' => $p->stok, 'nilai' => $p->stok * $b[3],
                    'ref' => '', 'keterangan' => 'stok awal data contoh',
                    'operator' => 'Kasir Mart', 'created_at' => WaktuIso::sekarang(),
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
