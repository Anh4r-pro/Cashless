<?php

namespace App\Services;

use App\Exceptions\ApiError;
use Illuminate\Support\Facades\Cache;

/**
 * Satu tempelan kartu = satu transaksi.
 *
 * Token diterbitkan saat kartu ditempelkan dan dikunci saat dipakai, jadi layar
 * kasir yang ter-klik dua kali tidak bisa memotong saldo dua kali. Berbeda dari
 * versi Node yang menyimpannya di memori proses, di sini token dititipkan ke
 * cache: PHP-FPM menjalankan tiap permintaan di proses yang berbeda, sehingga
 * penyimpanan dalam memori proses akan hilang di antara dua permintaan.
 */
class TapToken
{
    private const TTL_DETIK = 120;

    public static function terbitkan(int $siswaId): string
    {
        $token = bin2hex(random_bytes(16));
        Cache::put(self::kunci($token), ['siswa_id' => $siswaId, 'used' => false], self::TTL_DETIK);

        return $token;
    }

    /** Mengunci token. Dibuka lagi lewat lepas() bila transaksinya GAGAL. */
    public static function pakai(?string $token, int $siswaId): string
    {
        $k = self::kunci((string) $token);
        $t = Cache::get($k);
        if (! $t) {
            throw new ApiError(410, 'Sesi tap kedaluwarsa — tempelkan kartu lagi');
        }
        if ($t['used']) {
            throw new ApiError(409, 'Satu tap hanya untuk satu transaksi — tempelkan kartu lagi');
        }
        if ($t['siswa_id'] !== $siswaId) {
            throw new ApiError(400, 'Token tidak cocok dengan kartu');
        }
        $t['used'] = true;
        Cache::put($k, $t, self::TTL_DETIK);

        return $k;
    }

    /** Transaksi gagal → tap masih boleh dipakai (mis. kasir mengurangi belanjaan). */
    public static function lepas(string $kunciCache): void
    {
        $t = Cache::get($kunciCache);
        if ($t) {
            $t['used'] = false;
            Cache::put($kunciCache, $t, self::TTL_DETIK);
        }
    }

    private static function kunci(string $token): string
    {
        return 'tap:' . $token;
    }
}
