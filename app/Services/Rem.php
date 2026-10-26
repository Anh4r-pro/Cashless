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

    /**
     * Alamat klien untuk kunci rem. Dasarnya REMOTE_ADDR — alamat koneksi
     * sebenarnya, yang tidak bisa dikarang pengirim.
     *
     * Header `X-Real-IP` HANYA dipercaya kalau koneksinya datang dari mesin
     * ini sendiri (loopback). Dua alasan, dan keduanya penting:
     *
     *   1. DI PRODUKSI header itu diabaikan total, karena REMOTE_ADDR di sana
     *      adalah IP publik klien, bukan loopback. Jadi pengamanannya tidak
     *      bergantung pada konfigurasi nginx yang benar — aman secara bawaan,
     *      bahkan kalau aplikasi ini kelak dipasang di server lain yang lupa
     *      menimpa headernya. Versi sebelumnya mempercayai header itu apa
     *      adanya dengan anggapan "nginx yang mengisinya", padahal nginx di
     *      sini tidak pernah mengisinya; akibatnya SELURUH rem per-IP bisa
     *      dilewati cukup dengan mengganti satu header tiap percobaan —
     *      sandi layar kios (yang remnya memang HANYA per-IP) jadi bisa
     *      ditebak tanpa batas. Ditemukan saat audit 26 Okt 2026, dan nginx
     *      produksi kini juga menimpa header itu sebagai lapis kedua.
     *   2. DARI LOOPBACK header itu tetap berguna: suite uji memanggil
     *      `artisan serve` di 127.0.0.1 dan memakai header ini untuk
     *      mensimulasikan banyak IP berbeda — tanpa itu, rem per-IP tidak
     *      bisa diuji sama sekali karena semua permintaan uji seolah datang
     *      dari satu alamat yang sama.
     *
     * `X-Forwarded-For` tidak pernah dibaca di mana pun. Kalau kelak ada
     * proxy/CDN sungguhan di depan, daftarkan lewat `trustProxies()` di
     * bootstrap/app.php — biar Laravel yang memutuskan header mana yang layak
     * dipercaya DAN dari pengirim mana, bukan dikarang ulang di sini.
     */
    public static function ip(\Illuminate\Http\Request $r): string
    {
        $asli = $r->ip() ?: '?';
        if (in_array($asli, ['127.0.0.1', '::1'], true)) {
            return trim((string) $r->header('X-Real-IP')) ?: $asli;
        }

        return $asli;
    }
}
