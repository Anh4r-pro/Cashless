<?php

namespace App\Services;

use App\Models\Golongan;
use App\Models\Setting;
use App\Models\Siswa;

/**
 * Batas yang berlaku untuk satu pemegang kartu.
 *
 * Angkanya berlapis: pengaturan sekolah lebih dulu, lalu DITIMPA angka
 * golongannya bila memang diisi. Guru tidak pantas kena jatah "10x jajan per
 * hari" yang dirancang untuk anak, dan tamu tidak pantas memegang saldo
 * sebesar guru.
 */
class Pengaturan
{
    /** Batas yang boleh ditimpa per golongan. NULL di baris golongan = ikut sekolah. */
    public const BATAS_GOLONGAN = [
        'max_saldo', 'max_topup_harian', 'max_beli_harian', 'max_belanja_harian',
        'max_per_transaksi', 'max_nonmakanan_harian', 'max_beli_nonmakanan_harian',
        'max_nonmakanan_transaksi',
    ];

    private static ?array $cache = null;

    /** Pengaturan sekolah apa adanya. */
    public static function sekolah(): array
    {
        return self::$cache ??= Setting::semua();
    }

    /** Dipanggil setelah PUT /api/settings supaya proses ini tidak memakai angka basi. */
    public static function lupakanCache(): void
    {
        self::$cache = null;
    }

    /**
     * Pengaturan yang berlaku untuk SATU orang.
     *
     * Perhatikan pemeriksaan `!== null`, bukan `?:` — nol adalah angka yang sah
     * di sini (mis. golongan tamu yang sengaja tidak boleh belanja non-makanan
     * sama sekali), dan operator "kosong-berarti-bawaan" akan diam-diam
     * menggantinya dengan angka sekolah.
     */
    public static function untuk(?Siswa $orang, ?array $dasar = null): array
    {
        $cfg = $dasar ?? self::sekolah();
        $g = self::golongan($orang?->golongan);
        if (! $g) {
            return $cfg;
        }
        foreach (self::BATAS_GOLONGAN as $k) {
            if ($g->{$k} !== null) {
                $cfg[$k] = (int) $g->{$k};
            }
        }

        return $cfg;
    }

    /** Satu golongan menurut kodenya; jatuh ke kode 'siswa' bila kodenya tidak dikenal. */
    public static function golongan(?string $kode): ?Golongan
    {
        return Golongan::where('kode', $kode ?: 'siswa')->first()
            ?? Golongan::where('kode', 'siswa')->first();
    }
}
