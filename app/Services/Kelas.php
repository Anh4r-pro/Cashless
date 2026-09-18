<?php

namespace App\Services;

use App\Exceptions\ApiError;

/**
 * Penalaran tentang kelas santri: membaca bentuknya, menaikkan tingkatnya, dan
 * mengurutkannya.
 *
 * TIDAK ADA daftar kelas yang ditulis di sini. Kelas hidup sebagai teks bebas
 * di kolom `siswa.kelas`, dan pilihan yang tampil di layar diturunkan dari data
 * yang benar-benar ada. Akibatnya kelas paralel baru — 7K, 7L, berapa pun —
 * langsung bekerja tanpa didaftarkan lebih dulu, dan membuka tingkat baru hanya
 * mengubah satu angka di Pengaturan.
 *
 * Yang dipegang di sini hanya ATURANNYA: angka di depan naik satu, huruf di
 * belakang dipertahankan apa adanya, dan tingkat tertinggi berujung pada arsip.
 */
class Kelas
{
    /** Tujuan kenaikan yang berarti "keluar", bukan kelas. */
    public const ARSIP = ['alumni', 'lanjut_study', 'pindah'];

    public const LABEL_ARSIP = [
        'alumni' => 'Alumni (tamat SMA)',
        'lanjut_study' => 'Lanjut Study (tamat SMP, melanjutkan di luar)',
        'pindah' => 'Pindah',
    ];

    /**
     * Bentuk kelas yang dianggap wajar: angka tingkat lalu satu atau beberapa
     * huruf paralel, mis. 7A, 12J, 8AB. Spasi di tengah sengaja TIDAK diterima —
     * "7 A" dan "7A" akan menjadi dua kelas berbeda di laporan, dan itu justru
     * jenis kekeliruan yang perlu ditandai.
     */
    private const POLA = '/^(\d{1,2})([A-Z]{1,2})$/';

    public static function tingkatAwal(): int
    {
        return (int) (Pengaturan::sekolah()['kelas_tingkat_awal'] ?? 7);
    }

    public static function tingkatLulus(): int
    {
        return (int) (Pengaturan::sekolah()['kelas_tingkat_lulus'] ?? 12);
    }

    /** ['tingkat' => 7, 'paralel' => 'A'] — atau null kalau bentuknya menyimpang. */
    public static function urai(?string $kelas): ?array
    {
        $k = strtoupper(trim((string) $kelas));
        if (! preg_match(self::POLA, $k, $m)) {
            return null;
        }

        return ['tingkat' => (int) $m[1], 'paralel' => $m[2]];
    }

    public static function sah(?string $kelas): bool
    {
        $u = self::urai($kelas);
        if (! $u) {
            return false;
        }

        // Tingkat di luar jenjang yang dibuka sekolah tetap "tidak wajar",
        // sekalipun bentuknya angka+huruf: 70A lolos pola tapi jelas salah ketik.
        return $u['tingkat'] >= self::tingkatAwal() && $u['tingkat'] <= self::tingkatLulus();
    }

    /**
     * Kelas berikutnya menurut aturan kenaikan.
     *
     * Mengembalikan nama kelas ('8A'), atau 'alumni' bila tingkatnya sudah yang
     * tertinggi, atau null bila bentuk kelasnya tidak bisa dibaca sama sekali —
     * dan null itu SENGAJA tidak ditebak-tebak. Menaikkan kelas yang salah ketik
     * hanya akan memindahkan santri ke kelas yang lebih salah lagi.
     */
    public static function naik(?string $kelas): ?string
    {
        $u = self::urai($kelas);
        if (! $u) {
            return null;
        }

        /*
         * Tingkat di luar jenjang yang dibuka sekolah TIDAK dinaikkan dan TIDAK
         * dianggap lulus — dikembalikan null supaya tertangkap sebagai kelas
         * menyimpang.
         *
         * Ini pernah salah: `70A` (salah ketik `7A`) memenuhi syarat
         * "tingkat >= tingkat lulus", sehingga santrinya akan diusulkan menjadi
         * Alumni dan ikut terarsip tanpa ada yang menyadari. Salah ketik satu
         * angka tidak boleh berarti seseorang dikeluarkan dari sekolah.
         */
        if ($u['tingkat'] < self::tingkatAwal() || $u['tingkat'] > self::tingkatLulus()) {
            return null;
        }
        if ($u['tingkat'] === self::tingkatLulus()) {
            return 'alumni';
        }

        return ($u['tingkat'] + 1) . $u['paralel'];
    }

    /**
     * Ekspresi ORDER BY yang sadar angka.
     *
     * `ORDER BY kelas` mengurutkan sebagai TEKS, sehingga begitu ada kelas dua
     * digit urutannya menjadi 10A, 11A, 12A, 7A, 8A, 9A — dan itu muncul di
     * saringan kelas, template topup massal, serta tabel analitik sekaligus.
     * CAST mengambil angka di depan (CAST('10A') = 10), lalu nama aslinya
     * menjadi pemecah seri untuk hurufnya.
     *
     * Unit SDM yang bukan angka (mis. "Pengasuhan") menghasilkan 0, jadi
     * semuanya berkumpul di depan dan diurutkan menurut abjad — bukan tercampur.
     */
    public static function urutSql(string $kolom = 'kelas'): string
    {
        return "CAST({$kolom} AS UNSIGNED), {$kolom}";
    }

    /** Urutkan daftar kelas di sisi PHP dengan aturan yang sama. */
    public static function urutkan(array $kelas): array
    {
        usort($kelas, function ($a, $b) {
            $ua = self::urai($a);
            $ub = self::urai($b);
            $ta = $ua['tingkat'] ?? 0;
            $tb = $ub['tingkat'] ?? 0;

            return $ta === $tb ? strcmp((string) $a, (string) $b) : $ta <=> $tb;
        });

        return array_values($kelas);
    }

    public static function wajibStatusArsip(?string $status): string
    {
        $s = strtolower(trim((string) $status));
        if (! in_array($s, self::ARSIP, true)) {
            throw new ApiError(400, 'Status arsip harus salah satu dari: ' . implode(', ', self::ARSIP));
        }

        return $s;
    }

    /** "2026/2027" — tahun ajaran yang bentuknya dijaga supaya bisa diurutkan. */
    public static function wajibTahunAjaran(?string $t): string
    {
        $v = trim((string) $t);
        if (! preg_match('#^(\d{4})/(\d{4})$#', $v, $m)) {
            throw new ApiError(400, 'Tahun ajaran harus berbentuk YYYY/YYYY, mis. 2026/2027');
        }
        if ((int) $m[2] !== (int) $m[1] + 1) {
            throw new ApiError(400, "Tahun ajaran {$v} tidak berurutan — tahun kedua harus tepat setelah yang pertama");
        }

        return $v;
    }
}
