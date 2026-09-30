<?php

namespace App\Http\Controllers\Api;

use App\Models\SesiStaf;
use App\Models\User;
use App\Services\Izin;
use App\Services\Sesi;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Masuk & keluar untuk akun staf.
 *
 * Percobaan gagal direm per IP: tanpa itu, daftar username yang pendek dan
 * sandi seed yang mudah ditebak membuat sekolah hanya sejauh satu skrip dari
 * akun superadmin.
 */
class AuthController extends Controller
{
    private const MAKS_GAGAL = 8;

    private const JEDA_DETIK = 900;

    public function login(Request $r)
    {
        $username = strtolower(trim((string) $r->input('username')));
        $kunci = 'masuk:' . $r->ip();

        if (RateLimiter::tooManyAttempts($kunci, self::MAKS_GAGAL)) {
            $sisa = RateLimiter::availableIn($kunci);

            return response()->json([
                'error' => "Terlalu banyak percobaan masuk. Coba lagi dalam {$sisa} detik.",
            ], 429);
        }

        $u = User::where('username', $username)->first();
        if (! $u || ! Hash::check((string) $r->input('password'), $u->password)) {
            RateLimiter::hit($kunci, self::JEDA_DETIK);

            return response()->json(['error' => 'Username atau password salah'], 401);
        }
        RateLimiter::clear($kunci);

        $token = Sesi::buatStaf($u);
        $res = response()->json(['ok' => true, 'user' => self::profil($u)]);

        return Sesi::pasangCookie($res, 'sid', $token, (int) (Sesi::TTL_STAF_MS / 1000));
    }

    public function me(Request $r)
    {
        $u = $r->attributes->get('staf');
        if (! $u) {
            return response()->json(['authed' => false]);
        }

        return response()->json(['authed' => true, 'user' => self::profil($u)]);
    }

    /**
     * Profil yang dikirim ke frontend.
     *
     * `izin` ikut dikirim supaya menu yang tidak berhak dibuka tidak usah
     * digambar sama sekali. Itu SEMATA kenyamanan tampilan — pagar yang
     * sebenarnya tetap middleware `izin:` di sisi server, karena daftar ini
     * ada di peramban dan karenanya bisa diubah siapa pun yang mau.
     */
    private static function profil(User $u): array
    {
        return [
            'nama' => $u->nama,
            'role' => $u->role,
            'tenant_id' => $u->tenant_id,
            'tenant_nama' => $u->tenant?->nama,
            // Menentukan layar kasir mana yang digambar (menu / bebas / mart).
            'tenant_mode' => $u->tenant?->mode_kasir ?? 'menu',
            'tenant_jenis' => $u->tenant?->jenis,
            'kategori_id' => $u->kategori_id,
            'kategori_nama' => $u->kategori?->nama
                ?? ($u->role === 'superadmin' ? 'Akses Penuh (bawaan)' : null),
            'izin' => Izin::milik($u),
        ];
    }

    public function logout(Request $r)
    {
        if ($token = $r->cookie('sid')) {
            SesiStaf::where('token', $token)->delete();
        }

        return Sesi::hapusCookie(response()->json(['ok' => true]), 'sid');
    }
}
