<?php

namespace App\Services;

use App\Exceptions\ApiError;
use App\Models\Siswa;
use App\Models\SiswaPin;
use App\Support\WaktuIso;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

/**
 * PIN kios siswa.
 *
 * PIN bawaan setiap siswa adalah NIS-nya. Begitu siswa mengganti PIN, barisnya
 * masuk tabel siswa_pin; menghapus baris itu (reset oleh admin) otomatis
 * mengembalikan PIN ke NIS lagi. Lupa PIN TIDAK bisa diselesaikan sendiri oleh
 * siswa — harus admin, karena satu-satunya bukti identitas di kios adalah kartu
 * yang mungkin justru sedang dipegang orang lain.
 */
class Pin
{
    public const MIN = 4;

    public const MAKS = 20;

    /** Panjang maksimal PIN yang boleh DIPILIH siswa sendiri. */
    public const PIN_MAKS = 8;

    /** Rem per IP jauh lebih longgar daripada per kartu: satu kios melayani antrean. */
    private const MAKS_GAGAL_IP = 30;

    public static function tersimpan(int $siswaId): ?SiswaPin
    {
        return SiswaPin::find($siswaId);
    }

    public static function bawaan(Siswa $s): string
    {
        return KunciWali::digitSaja($s->nis);
    }

    /** Punya PIN yang bisa dipakai sama sekali (sudah ganti sendiri, atau NIS layak). */
    public static function siap(Siswa $s): bool
    {
        return self::tersimpan($s->id) !== null || strlen(self::bawaan($s)) >= self::MIN;
    }

    public static function cocok(Siswa $s, string $pin): bool
    {
        $p = KunciWali::digitSaja($pin);
        if ($row = self::tersimpan($s->id)) {
            return Hash::check($p, $row->pin_hash);
        }
        $bawaan = self::bawaan($s);
        if (strlen($bawaan) < self::MIN) {
            return false;      // NIS belum layak jadi PIN
        }

        return KunciWali::sama($p, $bawaan);
    }

    public static function verifikasi(Siswa $s, $pin, Request $r): void
    {
        $kKartu = 'pin:' . $s->id;
        $kIp = 'kios:' . Rem::ip($r);

        Rem::periksa($kKartu);
        if (\Illuminate\Support\Facades\RateLimiter::tooManyAttempts($kIp, self::MAKS_GAGAL_IP)) {
            throw new ApiError(429, 'Terlalu banyak percobaan dari perangkat ini. Coba lagi nanti.');
        }

        if (! self::siap($s)) {
            throw new ApiError(409, 'PIN belum bisa dipakai karena NIS belum terdaftar. Hubungi admin kantin.');
        }

        $salah = function () use ($kKartu, $kIp) {
            Rem::catatGagal($kKartu, $kIp);

            return new ApiError(401, 'PIN salah. Setelah ' . Rem::MAKS_GAGAL . ' kali salah, kartu terkunci 15 menit.');
        };

        $p = KunciWali::digitSaja($pin);
        if (strlen($p) < self::MIN || strlen($p) > self::MAKS) {
            throw $salah();
        }
        if (! self::cocok($s, $p)) {
            throw $salah();
        }
        Rem::lupakan($kKartu);
    }

    /** PIN baru yang terlalu mudah ditebak ditolak sejak diketik, bukan nanti. */
    public static function periksaBaru($pinBaru, Siswa $s): string
    {
        $p = trim((string) ($pinBaru ?? ''));
        if (! preg_match('/^\d+$/', $p)) {
            throw new ApiError(400, 'PIN baru hanya boleh angka');
        }
        if (strlen($p) < self::MIN || strlen($p) > self::PIN_MAKS) {
            throw new ApiError(400, 'PIN baru harus ' . self::MIN . '–' . self::PIN_MAKS . ' angka');
        }
        if (preg_match('/^(\d)\1+$/', $p)) {
            throw new ApiError(400, 'PIN terlalu mudah ditebak (angkanya sama semua)');
        }
        if (str_contains('0123456789', $p) || str_contains('9876543210', $p)) {
            throw new ApiError(400, 'PIN terlalu mudah ditebak (angka berurutan)');
        }
        if ($p === self::bawaan($s)) {
            throw new ApiError(400, 'PIN baru tidak boleh sama dengan NIS — itu PIN bawaannya');
        }

        return $p;
    }

    public static function ganti(Siswa $s, string $pinBaru): void
    {
        SiswaPin::updateOrCreate(
            ['siswa_id' => $s->id],
            ['pin_hash' => Hash::make($pinBaru), 'updated_at' => WaktuIso::sekarang()],
        );
    }
}
