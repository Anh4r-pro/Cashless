<?php

namespace App\Services;

/** Format rupiah untuk pesan galat yang dibaca kasir: 50000 -> "Rp50.000". */
class Rupiah
{
    public static function f(int|float $n): string
    {
        return 'Rp' . number_format((float) $n, 0, ',', '.');
    }
}
