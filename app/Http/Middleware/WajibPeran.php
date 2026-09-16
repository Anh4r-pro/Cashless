<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

/**
 * Pembatasan peran, dipasang per rute: `->middleware('peran:superadmin')`.
 *
 * Ini pagar KEDUA. Pagar pertama ada di kueri masing-masing controller — akun
 * tenant menyaring datanya sendiri berdasarkan tenant_id, bukan mengandalkan
 * menu yang tidak ditampilkan.
 */
class WajibPeran
{
    public function handle(Request $r, Closure $next, string ...$peran)
    {
        $u = $r->attributes->get('staf');
        if (! $u) {
            return response()->json(['error' => 'Belum masuk'], 401);
        }
        if ($peran && ! in_array($u->role, $peran, true)) {
            return response()->json(['error' => 'Tidak berhak'], 403);
        }

        return $next($r);
    }
}
