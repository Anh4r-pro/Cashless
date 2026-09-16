<?php

namespace App\Services;

/**
 * Potongan SQL untuk mengelompokkan kolom `created_at` per periode WIB.
 *
 * created_at disimpan sebagai teks ISO-8601 UTC, jadi harus diurai dulu lalu
 * digeser +7 jam. Dikumpulkan di sini karena dipakai dashboard maupun analitik,
 * dan salah geser jam berarti penjualan sore hari tercatat di tanggal berikutnya.
 */
class SqlWaktu
{
    /** Ekspresi DATETIME dalam waktu WIB dari kolom teks ISO UTC. */
    public static function wib(string $kolom = 'created_at'): string
    {
        return "DATE_ADD(STR_TO_DATE({$kolom}, '%Y-%m-%dT%H:%i:%s.%fZ'), INTERVAL 7 HOUR)";
    }

    /** Kunci pengelompokan untuk granularitas yang diminta. */
    public static function periode(string $gran, string $kolom = 'created_at'): string
    {
        $wib = self::wib($kolom);

        return match ($gran) {
            'mingguan' => "DATE_FORMAT({$wib}, '%x-W%v')",
            'bulanan' => "DATE_FORMAT({$wib}, '%Y-%m')",
            default => "DATE_FORMAT({$wib}, '%Y-%m-%d')",
        };
    }

    public static function granularitasSah(?string $g): string
    {
        return in_array($g, ['harian', 'mingguan', 'bulanan'], true) ? $g : 'harian';
    }
}
