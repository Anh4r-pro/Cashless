<?php

namespace App\Services;

use App\Exceptions\ApiError;
use App\Models\Siswa;
use Illuminate\Support\Facades\Cache;

/**
 * Sesi layar kios santri — dibuka dengan tap kartu, berumur pendek.
 *
 * Disimpan di cache, bukan memori proses: PHP-FPM melayani tiap permintaan di
 * proses yang berbeda, jadi peta dalam memori akan hilang di antara dua ketukan
 * layar.
 *
 * Sesi membawa keadaannya sendiri, dan tiap bidang punya alasan:
 *   - `selesai` + `hasil` — satu tap = satu transaksi. Sesi yang sudah dipakai
 *     TIDAK langsung dibuang, melainkan disimpan bersama jawabannya sampai
 *     kedaluwarsa: kalau jaringan kios putus tepat setelah donasi tercatat,
 *     kiriman ulang dengan tx_id yang sama menerima jawaban yang sama — bukan
 *     "sesi berakhir" yang membuat santri mengira gagal lalu berdonasi dua kali.
 *   - `cari` — satu tap kartu sendiri cukup untuk menyisir seluruh NIS sekolah
 *     dan memanen daftar nama; NIS adalah PIN bawaan kios, jadi daftar itu bukan
 *     data sepele.
 *   - `riwayat` — PIN diminta sekali per tap, bukan tiap halaman: kalau tiap
 *     halaman meminta PIN, santri mengetik PIN berkali-kali di depan antrean.
 */
class SesiKios
{
    private const TTL_DETIK = 90;             // jeda diam sebelum sesi mati

    private const UMUR_MAKS_DETIK = 600;      // umur mutlak, sepanjang apa pun kegiatannya

    public const CARI_MAKS = 8;

    public static function buka(int $siswaId): string
    {
        // Satu kartu cukup satu sesi hidup: tap ulang membatalkan sesi
        // sebelumnya, sehingga sesi yang tertinggal di layar lain ikut mati.
        self::tutupSemua($siswaId);

        $token = bin2hex(random_bytes(16));
        Cache::put(self::kunci($token), [
            'siswa_id' => $siswaId, 'lahir' => time(),
            'selesai' => false, 'hasil' => null, 'cari' => 0, 'riwayat' => false,
        ], self::TTL_DETIK);

        Cache::put(self::kunciSiswa($siswaId), [$token], self::UMUR_MAKS_DETIK);

        return $token;
    }

    /** Sesi mentah tanpa pemeriksaan — dipakai jalur idempotensi donasi/transfer. */
    public static function mentah(?string $token): ?array
    {
        return Cache::get(self::kunci((string) $token));
    }

    /**
     * @return array{token:string, sesi:array, santri:Santri}
     */
    public static function ambil(?string $tokenMentah): array
    {
        $token = (string) $tokenMentah;
        $t = Cache::get(self::kunci($token));

        $habis = function () use ($token) {
            Cache::forget(self::kunci($token));

            return new ApiError(410, 'Sesi kios sudah berakhir — tempelkan kartu lagi');
        };

        if (! $t) {
            throw new ApiError(410, 'Sesi kios sudah berakhir — tempelkan kartu lagi');
        }
        if ($t['lahir'] + self::UMUR_MAKS_DETIK < time()) {
            throw $habis();
        }
        if ($t['selesai']) {
            throw new ApiError(409, 'Satu tap hanya untuk satu transaksi — tempelkan kartu lagi');
        }
        $s = Siswa::find($t['siswa_id']);
        if (! $s) {
            throw $habis();
        }

        // Ada kegiatan → perpanjang jeda diam.
        Cache::put(self::kunci($token), $t, self::TTL_DETIK);

        return ['token' => $token, 'sesi' => $t, 'siswa' => $s];
    }

    /** Simpan perubahan keadaan sesi (selesai/hasil/cari/riwayat). */
    public static function simpan(string $token, array $sesi): void
    {
        Cache::put(self::kunci($token), $sesi, self::TTL_DETIK);
    }

    public static function tutup(?string $token): void
    {
        Cache::forget(self::kunci((string) $token));
    }

    /** Tutup semua sesi milik satu santri (NIS atau statusnya baru berubah). */
    public static function tutupSemua(int $siswaId): void
    {
        foreach (Cache::get(self::kunciSiswa($siswaId), []) as $token) {
            Cache::forget(self::kunci($token));
        }
        Cache::forget(self::kunciSiswa($siswaId));
    }

    public static function detikSesi(): int
    {
        return self::TTL_DETIK;
    }

    private static function kunci(string $token): string
    {
        return 'kios:' . $token;
    }

    private static function kunciSiswa(int $id): string
    {
        return 'kios-siswa:' . $id;
    }
}
