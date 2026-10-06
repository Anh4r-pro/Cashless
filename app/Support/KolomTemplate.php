<?php

namespace App\Support;

use PhpOffice\PhpSpreadsheet\Cell\Coordinate;

/**
 * Huruf kolom Excel diturunkan dari DEFINISI kolom, bukan diketik tangan.
 *
 * Template di sistem ini memasang aturan isian Excel (data validation) per
 * kolom: "D harus angka", "E harus tanggal DD/MM/YYYY". Selama huruf itu
 * ditulis langsung di kode, menyisipkan satu kolom baru di tengah akan
 * menggeser semuanya — dan aturannya diam-diam menempel pada kolom yang
 * keliru. Tidak ada yang gagal, tidak ada galat; hanya kolom nominal yang
 * tiba-tiba menuntut bentuk tanggal, dan orang yang mengisinya menyangka
 * berkasnya rusak.
 *
 * Itu persis yang nyaris terjadi ketika kolom Kelas disisipkan setelah Nama.
 */
trait KolomTemplate
{
    /** 'nominal' → 'E', menurut urutannya di KOLOM. */
    public static function huruf(string $kunci, ?array $kolom = null): string
    {
        foreach ($kolom ?? static::KOLOM as $i => $kol) {
            if (($kol['kunci'] ?? null) === $kunci) {
                return Coordinate::stringFromColumnIndex($i + 1);
            }
        }

        throw new \InvalidArgumentException("Kolom \"{$kunci}\" tidak ada di template");
    }

    /** Huruf kolom terakhir — untuk menebalkan baris judul, mis. 'A1:G1'. */
    public static function hurufAkhir(?array $kolom = null): string
    {
        return Coordinate::stringFromColumnIndex(count($kolom ?? static::KOLOM));
    }
}
