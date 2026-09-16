<?php

namespace App\Http\Controllers\Api;

use App\Models\SesiStaf;
use App\Models\User;
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
        $res = response()->json(['ok' => true, 'user' => [
            'nama' => $u->nama,
            'role' => $u->role,
            'tenant_id' => $u->tenant_id,
            'tenant_nama' => $u->tenant?->nama,
        ]]);

        return Sesi::pasangCookie($res, 'sid', $token, (int) (Sesi::TTL_STAF_MS / 1000));
    }

    public function me(Request $r)
    {
        $u = $r->attributes->get('staf');
        if (! $u) {
            return response()->json(['authed' => false]);
        }

        return response()->json(['authed' => true, 'user' => [
            'nama' => $u->nama,
            'role' => $u->role,
            'tenant_id' => $u->tenant_id,
            'tenant_nama' => $u->tenant?->nama,
        ]]);
    }

    public function logout(Request $r)
    {
        if ($token = $r->cookie('sid')) {
            SesiStaf::where('token', $token)->delete();
        }

        return Sesi::hapusCookie(response()->json(['ok' => true]), 'sid');
    }
}
