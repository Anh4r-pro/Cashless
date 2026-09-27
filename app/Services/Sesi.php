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

    /**
     * "Tetap masuk di HP ini" — dua angka, dua peran berbeda:
     *
     *   TTL_ORTU_INGAT_MS      jendela yang BERGESER. Tiap kali sesi dipakai
     *                          dan sisa umurnya sudah menipis, jendela ini
     *                          digeser maju dari SAAT ITU — dipakai rutin
     *                          berarti nyaris tidak pernah kedaluwarsa.
     *   TTL_ORTU_INGAT_MAKS_MS batas MUTLAK, dihitung dari saat login, bukan
     *                          dari pemakaian terakhir. Jendela di atas tidak
     *                          pernah digeser melewati titik ini — jaring
     *                          pengaman kalau HP hilang dan tetap sesekali
     *                          dibuka orang lain selama berbulan-bulan.
     *
     * Wali yang TIDAK mencentang "tetap masuk" tetap memakai TTL_ORTU_MS
     * seperti sebelumnya: tetap (bukan bergeser), dan tidak pernah diperpanjang.
     */
    public const TTL_ORTU_INGAT_MS = 30 * 24 * 3600 * 1000;

    public const TTL_ORTU_INGAT_MAKS_MS = 180 * 24 * 3600 * 1000;

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

    public static function buatOrtu(Siswa $s, bool $ingat = false): string
    {
        $token = self::token();
        $sekarang = self::sekarangMs();
        SesiOrtu::create([
            'token' => $token,
            'siswa_id' => $s->id,
            'remember' => $ingat,
            'login_at' => $sekarang,
            'expires_at' => $sekarang + ($ingat ? self::TTL_ORTU_INGAT_MS : self::TTL_ORTU_MS),
        ]);
        SesiOrtu::where('expires_at', '<', $sekarang)->delete();

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
        if ($s->remember) {
            self::perpanjangOrtuJikaPerlu($s);
        }
        // Dibaca /api/ortu/me untuk menampilkan keterangan sesi yang jujur —
        // tanpa ini, wali yang masuk lewat sesi lama (bukan login barusan)
        // tidak ada cara mengetahui apakah "tetap masuk"-nya masih aktif.
        $r->attributes->set('ortu_ingat', $s->remember);

        return Siswa::find($s->siswa_id);
    }

    /**
     * Menggeser jendela HANYA kalau sisa umurnya sudah menipis (di bawah
     * separuh jendela geser) — bukan pada setiap permintaan, supaya sesi yang
     * dipakai wara-wiri sepanjang hari tidak menulis basis data tiap detik.
     *
     * Tidak pernah menggeser melewati login_at + TTL_ORTU_INGAT_MAKS_MS,
     * berapa pun aktifnya sesi ini.
     */
    private static function perpanjangOrtuJikaPerlu(SesiOrtu $s): void
    {
        $sekarang = self::sekarangMs();
        if ($s->expires_at - $sekarang > self::TTL_ORTU_INGAT_MS / 2) {
            return;
        }
        $batasMutlak = $s->login_at + self::TTL_ORTU_INGAT_MAKS_MS;
        $baru = min($sekarang + self::TTL_ORTU_INGAT_MS, $batasMutlak);
        if ($baru > $s->expires_at) {
            $s->update(['expires_at' => $baru]);
        }
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
