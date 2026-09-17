<?php

namespace App\Services;

use App\Exceptions\ApiError;
use App\Models\KategoriAkses;
use App\Models\User;

/**
 * Katalog hak akses & pemeriksanya.
 *
 * Granularitasnya PER AKSI, bukan per menu: "lihat" dipisah dari "tambah",
 * "ubah", dan "hapus". Dengan begitu pembatasan yang muncul kemudian —
 * misalnya bendahara baru yang boleh melihat data santri tapi tidak boleh
 * mengubahnya — cukup dimatikan lewat sakelar, tanpa menyentuh kode lagi.
 *
 * Katalog ini adalah SUMBER KEBENARAN. Kode fitur yang dipasang di rute tapi
 * tidak terdaftar di sini akan ditolak keras saat dipakai (lihat wajibTerdaftar),
 * supaya salah ketik pada nama fitur tidak berubah menjadi rute yang diam-diam
 * terbuka untuk semua orang.
 */
class Izin
{
    /**
     * Kelompok => [kode fitur => label yang dibaca admin].
     *
     * Kelompoknya sengaja mengikuti menu yang terlihat di layar, bukan mengikuti
     * berkas controller: yang menyetel sakelar ini adalah kepala sekolah atau
     * bendahara, dan mereka mencarinya lewat nama menu.
     */
    public const KATALOG = [
        'Dashboard & Neraca' => [
            'dashboard.lihat' => 'Membuka Dashboard',
            'dashboard.setor' => 'Mencatat setoran tenant',
            'neraca.lihat' => 'Melihat Neraca Kas',
            'neraca.saldo_awal' => 'Menetapkan saldo awal neraca',
            'settings.lihat' => 'Melihat pengaturan & batas sekolah',
            'settings.ubah' => 'Mengubah pengaturan & batas sekolah',
        ],
        'Saldo Santri' => [
            'saldo.lihat' => 'Membuka menu Saldo Santri',
            'saldo.topup' => 'Menambah saldo (top up)',
            'saldo.koreksi' => 'Memotong / mengoreksi saldo',
            'saldo.koreksi_massal' => 'Memotong saldo kolektif satu kelas',
            'saldo.alasan' => 'Mengelola kategori alasan potongan',
            'titipan.setor' => 'Menyetor ke saldo titipan',
            'titipan.tarik' => 'Menarik saldo titipan',
            'titipan.cairkan' => 'Mencairkan titipan ke kartu',
            'titipan.smart' => 'Mengatur smart top up per kartu',
        ],
        'Topup Massal' => [
            'massal.lihat' => 'Membuka menu Topup Massal & riwayat impor',
            'massal.template' => 'Mengunduh template topup',
            'massal.terapkan' => 'Menerapkan berkas topup massal',
        ],
        'Topup Orang Tua' => [
            'topup_ortu.lihat' => 'Melihat antrean pengajuan wali',
            'topup_ortu.putuskan' => 'Menyetujui / menolak pengajuan wali',
            'topup_ortu.pengaturan' => 'Mengatur rekening, QRIS, & batas topup wali',
        ],
        'Pemegang Kartu — Santri' => [
            'santri.lihat' => 'Melihat data santri',
            'santri.tambah' => 'Menambah santri',
            'santri.ubah' => 'Mengubah data santri',
            'santri.hapus' => 'Menghapus santri',
            'santri.kartu' => 'Mendaftarkan / mengganti kartu santri',
            'santri.reset_pin' => 'Mereset PIN kios santri',
            'santri.impor' => 'Menambah santri kolektif via Excel',
        ],
        'Pemegang Kartu — SDM & Personil' => [
            'sdm.lihat' => 'Melihat data SDM & personil',
            'sdm.tambah' => 'Menambah personil',
            'sdm.ubah' => 'Mengubah data personil',
            'sdm.hapus' => 'Menghapus personil',
            'sdm.kartu' => 'Mendaftarkan / mengganti kartu personil',
            'sdm.reset_pin' => 'Mereset PIN kios personil',
            'sdm.impor' => 'Menambah personil kolektif via Excel',
            'golongan.kelola' => 'Mengelola golongan & batasnya',
        ],
        'Kantin & Koperasi' => [
            'tenant.lihat' => 'Melihat daftar tenant',
            'tenant.tambah' => 'Menambah tenant beserta akun kasirnya',
            'tenant.ubah' => 'Mengubah tenant / reset sandi kasir',
            'tenant.hapus' => 'Menghapus tenant',
            'ajuan.lihat' => 'Melihat usulan menu tenant',
            'ajuan.putuskan' => 'Menyetujui / menolak usulan menu',
            'produk.kategori' => 'Memindah kategori barang koperasi',
        ],
        'Donasi & Kios' => [
            'donasi.lihat' => 'Membuka menu Donasi & rekapnya',
            'donasi.program' => 'Mengubah program & nominal donasi',
            'donasi.pengaturan' => 'Mengubah batas donasi',
            'kios.pengaturan' => 'Mengatur kios & sandi layar',
        ],
        'Laporan' => [
            'riwayat.lihat' => 'Membuka Riwayat transaksi',
            'riwayat.ekspor' => 'Mengekspor & mencetak riwayat',
            'analitik.lihat' => 'Membuka Analitik penjualan',
        ],
        'Sistem' => [
            'akun.lihat' => 'Melihat daftar akun pengguna',
            'akun.kelola' => 'Menambah / mengubah / menghapus akun',
            'akses.kelola' => 'Mengelola kategori akses & sakelar fiturnya',
        ],
    ];

    /** Seluruh kode fitur sebagai daftar rata. */
    public static function semua(): array
    {
        return array_merge(...array_map('array_keys', array_values(self::KATALOG)));
    }

    public static function terdaftar(string $kode): bool
    {
        return in_array($kode, self::semua(), true);
    }

    /**
     * Fitur yang dipegang seorang staf.
     *
     * Akun tenant TIDAK memakai sistem ini sama sekali: jangkauannya sudah
     * dipagari peran + tenant_id di setiap kueri, dan memberinya daftar fitur
     * admin hanya akan mengaburkan batas itu.
     */
    public static function milik(?User $u): array
    {
        if (! $u || $u->role !== 'superadmin') {
            return [];
        }
        $kat = $u->kategori;

        // Akun tanpa kategori = akun superadmin warisan (termasuk akun bawaan
        // seeder). Ia memegang seluruh katalog, supaya pemasangan sistem izin
        // ini tidak pernah mengunci sekolah keluar dari sistemnya sendiri.
        if (! $kat || $kat->terkunci) {
            return self::semua();
        }

        return $kat->fitur->pluck('fitur')->all();
    }

    public static function boleh(?User $u, string $kode): bool
    {
        return in_array($kode, self::milik($u), true);
    }

    /**
     * Pagar yang dipakai controller. Melempar 403 dengan menyebut fitur yang
     * kurang — admin yang kena tolak harus bisa tahu sakelar mana yang perlu
     * dinyalakan, bukan sekadar "Tidak berhak".
     */
    public static function wajib(?User $u, string $kode): void
    {
        self::wajibTerdaftar($kode);
        if (! self::boleh($u, $kode)) {
            throw new ApiError(403, 'Akun Anda tidak punya hak "' . self::label($kode) . '"');
        }
    }

    public static function label(string $kode): string
    {
        foreach (self::KATALOG as $grup) {
            if (isset($grup[$kode])) {
                return $grup[$kode];
            }
        }

        return $kode;
    }

    /**
     * Kode yang tidak ada di katalog adalah SALAH TULIS, dan diperlakukan
     * sebagai galat server — bukan sebagai izin yang kebetulan tidak dimiliki
     * siapa pun. Keduanya terlihat sama dari luar (403), tapi yang pertama
     * berarti ada rute yang pagarnya tidak pernah bisa dilewati siapa pun.
     */
    private static function wajibTerdaftar(string $kode): void
    {
        if (! self::terdaftar($kode)) {
            throw new ApiError(500, "Kode izin \"{$kode}\" tidak terdaftar di katalog");
        }
    }

    /** Kategori bawaan yang dipasang seeder. */
    public static function bawaan(): array
    {
        return [
            [
                'nama' => 'Akses Penuh',
                'deskripsi' => 'Seluruh menu dan seluruh aksi, termasuk pengelolaan akun. Tidak dapat disunting.',
                'terkunci' => true,
                'urutan' => 0,
                'fitur' => self::semua(),
            ],
            [
                'nama' => 'Bendahara',
                'deskripsi' => 'Mengurus uang: top up, potongan, titipan, topup massal, dan pengajuan wali. Tidak bisa mengubah data santri maupun pengaturan sekolah.',
                'terkunci' => false,
                'urutan' => 1,
                'fitur' => [
                    'dashboard.lihat', 'dashboard.setor', 'neraca.lihat',
                    'saldo.lihat', 'saldo.topup', 'saldo.koreksi', 'saldo.koreksi_massal',
                    'titipan.setor', 'titipan.tarik', 'titipan.cairkan', 'titipan.smart',
                    'massal.lihat', 'massal.template', 'massal.terapkan',
                    'topup_ortu.lihat', 'topup_ortu.putuskan',
                    'santri.lihat', 'sdm.lihat',
                    'riwayat.lihat', 'riwayat.ekspor', 'analitik.lihat',
                ],
            ],
            [
                'nama' => 'Admin Data Santri',
                'deskripsi' => 'Mengelola data pemegang kartu: santri, SDM, kartu, dan PIN kios. Tidak menyentuh saldo.',
                'terkunci' => false,
                'urutan' => 2,
                'fitur' => [
                    'dashboard.lihat',
                    'santri.lihat', 'santri.tambah', 'santri.ubah', 'santri.hapus',
                    'santri.kartu', 'santri.reset_pin', 'santri.impor',
                    'sdm.lihat', 'sdm.tambah', 'sdm.ubah', 'sdm.hapus',
                    'sdm.kartu', 'sdm.reset_pin', 'sdm.impor',
                    'riwayat.lihat',
                ],
            ],
            [
                'nama' => 'Pengawas (hanya lihat)',
                'deskripsi' => 'Hanya membaca laporan dan data. Tidak ada satu pun aksi yang mengubah data.',
                'terkunci' => false,
                'urutan' => 3,
                'fitur' => [
                    'dashboard.lihat', 'neraca.lihat', 'settings.lihat',
                    'saldo.lihat', 'santri.lihat', 'sdm.lihat',
                    'tenant.lihat', 'ajuan.lihat', 'donasi.lihat',
                    'massal.lihat', 'topup_ortu.lihat',
                    'riwayat.lihat', 'analitik.lihat',
                ],
            ],
        ];
    }
}
