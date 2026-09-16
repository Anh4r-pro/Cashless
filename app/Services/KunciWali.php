<?php

namespace App\Services;

/**
 * Pencocokan kunci masuk portal wali.
 *
 * Dulu NISN, sekarang NIS: NISN 10 angka jarang dihafal wali dan gampang salah
 * ketik di ponsel, sedangkan NIS tertulis di kartu anak dan dipakai sehari-hari.
 *
 * Perbandingannya memakai hash_equals — bukan `===` — supaya lama pemeriksaan
 * tidak bocor sebagai petunjuk berapa banyak digit yang sudah benar.
 */
class KunciWali
{
    public static function digitSaja($v): string
    {
        return preg_replace('/\D/', '', (string) $v) ?? '';
    }

    /** 2012-04-17 → "170412" */
    public static function ddmmyy(?string $tglLahir): ?string
    {
        if (! preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', (string) $tglLahir, $m)) {
            return null;
        }

        return $m[3] . $m[2] . substr($m[1], 2);
    }

    public static function sama(string $a, string $b): bool
    {
        return $a !== '' && hash_equals($b, $a);
    }

    /**
     * Nomor HP tersamar: cukup untuk memastikan nomor mana yang terdaftar,
     * tanpa menyiarkan nomor lengkap wali ke layar.
     */
    public static function samarkanHp(?string $hp): string
    {
        $d = self::digitSaja($hp);
        if (strlen($d) < 6) {
            return '—';
        }
        // Kode awal hanya ditampilkan kalau nomornya cukup panjang, supaya
        // bagian depan dan 4 digit belakang tidak menutup seluruh nomor.
        $depan = strlen($d) >= 12 ? substr($d, 0, 4) : '';

        return $depan . str_repeat('*', max(3, strlen($d) - strlen($depan) - 4)) . substr($d, -4);
    }
}
