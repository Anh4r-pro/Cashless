<?php

namespace App\Services;

use App\Models\SesiOrtu;
use App\Models\SesiStaf;
use App\Models\Siswa;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use App\Support\CookieWarisan;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Cookie;

/**
 * Sesi berbasis token, dua jalur yang sengaja tidak pernah bertemu.
 *
 * Staf memakai cookie `sid`, wali murid memakai `osid`, dan keduanya punya
 * tabelnya sendiri. Kredensial wali itu ringan — NIS, tanggal lahir, dan 4
 * digit HP, semuanya diketahui teman sekelas — jadi tidak boleh ada jalan bagi
 * sesi wali untuk menjelma menjadi sesi admin, sekalipun ada kekeliruan kode
 * di salah satu sisi.
 *
 * Cookie ditulis langsung ke header, bukan lewat helper cookie Laravel, supaya
 * bentuknya persis seperti yang dibaca frontend dan suite uji.
 */
class Sesi
{
    public const TTL_STAF_MS = 12 * 3600 * 1000;

    public const TTL_ORTU_MS = 2 * 3600 * 1000;

    public static function sekarangMs(): int
    {
        return (int) round(microtime(true) * 1000);
    }

    public static function token(): string
    {
        return bin2hex(random_bytes(24));
    }

    // ------------------------------------------------------------- staf

    public static function buatStaf(User $u): string
    {
        $token = self::token();
        SesiStaf::create([
            'token' => $token,
            'user_id' => $u->id,
            'expires_at' => self::sekarangMs() + self::TTL_STAF_MS,
        ]);
        // Sekalian bersihkan sesi kedaluwarsa — murah, dan menjaga tabel tetap kecil.
        SesiStaf::where('expires_at', '<', self::sekarangMs())->delete();

        return $token;
    }

    public static function staf(Request $r): ?User
    {
        $token = $r->cookie('sid');
        if (! $token) {
            return null;
        }
        $s = SesiStaf::find($token);
        if (! $s || $s->expires_at < self::sekarangMs()) {
            return null;
        }

        return User::with('tenant')->find($s->user_id);
    }

    // ------------------------------------------------------------- ortu

    public static function buatOrtu(Siswa $s): string
    {
        $token = self::token();
        SesiOrtu::create([
            'token' => $token,
            'siswa_id' => $s->id,
            'expires_at' => self::sekarangMs() + self::TTL_ORTU_MS,
        ]);
        SesiOrtu::where('expires_at', '<', self::sekarangMs())->delete();

        return $token;
    }

    public static function ortu(Request $r): ?Siswa
    {
        $token = $r->cookie('osid');
        if (! $token) {
            return null;
        }
        $s = SesiOrtu::find($token);
        if (! $s || $s->expires_at < self::sekarangMs()) {
            return null;
        }

        return Siswa::find($s->siswa_id);
    }

    // ------------------------------------------------------------- cookie

    public static function pasangCookie(JsonResponse $res, string $nama, string $token, int $detik): JsonResponse
    {
        // Secure hanya kalau situsnya memang HTTPS: memasangnya di instalasi
        // HTTP akan membuat cookie tidak pernah terkirim, dan gejalanya muncul
        // sebagai "login berhasil tapi langsung logout" yang sulit dilacak.
        $aman = str_starts_with((string) config('app.url'), 'https://');

        $res->headers->setCookie(new CookieWarisan(
            $nama, $token, time() + $detik, '/', null, $aman, true, true, Cookie::SAMESITE_LAX,
        ));

        return $res;
    }

    public static function hapusCookie(JsonResponse $res, string $nama): JsonResponse
    {
        $res->headers->setCookie(new CookieWarisan($nama, '', 1, '/', null, false, false, true, null));

        return $res;
    }
}
