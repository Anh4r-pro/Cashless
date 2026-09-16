<?php

namespace App\Services;

use App\Exceptions\ApiError;
use App\Models\Siswa;

/**
 * Pemeriksaan yang berlaku untuk SETIAP jalur yang mengurangi saldo — kasir,
 * kios, donasi, transfer. Ditulis satu kali di sini supaya kartu yang sudah
 * lewat masa berlakunya tidak lolos hanya karena satu jalur lupa memeriksanya.
 */
class Kartu
{
    /** Tanggal hari ini (YYYY-MM-DD) menurut zona sekolah. */
    public static function hariIni(): string
    {
        return (new \DateTime('now', new \DateTimeZone(config('app.timezone', 'Asia/Jakarta'))))
            ->format('Y-m-d');
    }

    /**
     * Kartu tamu/VIP lazimnya berlaku sementara. Kolom kosong = tanpa batas;
     * tanggal yang tertulis berlaku sampai AKHIR hari itu, bukan pukul 00:00 —
     * kartu bertuliskan "berlaku sampai 20 Agustus" harus masih bisa dipakai
     * untuk makan siang tanggal 20.
     */
    public static function kedaluwarsa(Siswa $orang): bool
    {
        $sampai = trim((string) $orang->berlaku_sampai);
        if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $sampai)) {
            return false;
        }

        return self::hariIni() > $sampai;
    }

    public static function pastikanBerlaku(Siswa $orang): void
    {
        if ($orang->status !== 'aktif') {
            throw new ApiError(403, 'Kartu diblokir');
        }
        if (self::kedaluwarsa($orang)) {
            throw new ApiError(403,
                "Masa berlaku kartu {$orang->nama} sudah habis ({$orang->berlaku_sampai}) — hubungi admin");
        }
    }
}
