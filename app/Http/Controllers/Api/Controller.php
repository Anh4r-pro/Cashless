<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\ApiError;
use App\Models\User;
use Illuminate\Http\Request;

/**
 * Dasar seluruh controller API.
 *
 * Menyediakan akses ke staf yang sedang masuk beserta pagar perannya. Pagar
 * ini adalah lapis kedua — pembatasan yang sebenarnya ada di kueri masing-
 * masing controller, karena menyembunyikan menu bukan pengamanan.
 */
abstract class Controller
{
    protected function staf(Request $r): User
    {
        $u = $r->attributes->get('staf');
        if (! $u) {
            throw new ApiError(401, 'Belum masuk');
        }

        return $u;
    }

    protected function wajibPeran(Request $r, string ...$peran): User
    {
        $u = $this->staf($r);
        if (! in_array($u->role, $peran, true)) {
            throw new ApiError(403, 'Tidak berhak');
        }

        return $u;
    }
}
