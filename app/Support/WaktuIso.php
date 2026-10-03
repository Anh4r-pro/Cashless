<?php

namespace App\Support;

/**
 * Stempel waktu ISO-8601 UTC — format yang dipakai seluruh buku besar.
 *
 * Sengaja BUKAN DATETIME MySQL: setiap penyaringan periode membandingkan kolom
 * ini secara leksikografis (`created_at >= '2026-09-05T00:00:00.000Z'`), dan
 * kontrak JSON ke frontend maupun suite uji menyebut format ini apa adanya.
 * Mengubahnya ke DATETIME berarti mengubah dua hal sekaligus tanpa perlu.
 *
 * Model yang memakai trait ini WAJIB menyetel `public $timestamps = false;`
 * sendiri — PHP melarang trait menimpa properti yang sudah didefinisikan Model.
 */
trait WaktuIso
{
    public static function bootWaktuIso(): void
    {
        static::creating(function ($model) {
            if (in_array('created_at', $model->getFillable(), true) && empty($model->created_at)) {
                $model->created_at = self::sekarang();
            }
        });
    }

    /** Contoh keluaran: 2026-09-05T06:38:12.345Z */
    public static function sekarang(): string
    {
        return gmdate('Y-m-d\TH:i:s') . '.' . str_pad((string) (int) ((microtime(true) * 1000) % 1000), 3, '0', STR_PAD_LEFT) . 'Z';
    }

    /** Awal hari ini menurut zona sekolah, dalam ISO UTC — untuk batas harian. */
    public static function awalHariIni(): string
    {
        return self::awalHari(0);
    }

    /**
     * Tanggal lokal (YYYY-MM-DD) dari satu cap waktu ISO UTC.
     *
     * Dipakai untuk menjawab "penjualan ini milik hari yang mana?" — dan
     * jawabannya harus menurut zona sekolah, bukan UTC. Penjualan pukul 06.30
     * WIB tercatat sebagai 23.30 UTC hari sebelumnya; dibaca mentah-mentah, ia
     * akan dicocokkan dengan kas hari yang salah.
     */
    public static function tanggalLokal(string $iso): string
    {
        try {
            $d = new \DateTime($iso);
        } catch (\Throwable) {
            return self::awalHari(0);
        }
        $d->setTimezone(new \DateTimeZone(config('app.timezone', 'Asia/Jakarta')));

        return $d->format('Y-m-d');
    }

    /** Awal hari ke-$mundur hari yang lalu, dalam ISO UTC. */
    public static function awalHari(int $mundur = 0): string
    {
        $tz = new \DateTimeZone(config('app.timezone', 'Asia/Jakarta'));
        $d = new \DateTime('now', $tz);
        if ($mundur) {
            $d->modify("-{$mundur} days");
        }
        $d->setTime(0, 0, 0);
        $d->setTimezone(new \DateTimeZone('UTC'));

        return $d->format('Y-m-d\TH:i:s.v\Z');
    }
}
