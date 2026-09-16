<?php

namespace App\Services;

use App\Exceptions\ApiError;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Rem percobaan gagal untuk portal wali dan layar kios.
 *
 * Kredensial di kedua tempat itu memang ringan — NIS, tanggal lahir, 4 digit HP,
 * PIN 4–6 angka — dan semuanya diketahui teman sekelas. Yang menahan
 * tebak-tebakan bukan kerahasiaan nomornya, melainkan rem ini. Karena itu
 * dihitung dua arah: per IP (satu orang mencoba banyak anak) dan per NIS
 * (banyak orang mencoba satu anak).
 */
class Rem
{
    public const MAKS_GAGAL = 5;

    public const JEDA_DETIK = 900;   // 15 menit

    public static function periksa(string $kunci): void
    {
        if (RateLimiter::tooManyAttempts($kunci, self::MAKS_GAGAL)) {
            $menit = (int) ceil(RateLimiter::availableIn($kunci) / 60);
            throw new ApiError(429, "Terlalu banyak percobaan gagal. Coba lagi dalam {$menit} menit.");
        }
    }

    public static function catatGagal(string ...$kunci): void
    {
        foreach ($kunci as $k) {
            RateLimiter::hit($k, self::JEDA_DETIK);
        }
    }

    public static function lupakan(string ...$kunci): void
    {
        foreach ($kunci as $k) {
            RateLimiter::clear($k);
        }
    }

    /** Alamat klien. nginx di depan mengisinya dengan $remote_addr yang tak bisa dikarang klien. */
    public static function ip(\Illuminate\Http\Request $r): string
    {
        return trim((string) $r->header('X-Real-IP')) ?: ($r->ip() ?? '?');
    }
}
