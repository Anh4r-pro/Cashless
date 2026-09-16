<?php

namespace App\Http\Middleware;

use App\Services\Sesi;
use Closure;
use Illuminate\Http\Request;

/**
 * Menempelkan pengguna staf ke permintaan bila cookie `sid`-nya sah.
 *
 * Sengaja TIDAK menolak permintaan tanpa sesi: beberapa endpoint (mis.
 * GET /api/me) memang harus bisa menjawab "belum masuk" dengan status 200.
 * Penolakan dikerjakan WajibMasuk / WajibPeran yang dipasang per rute.
 */
class SesiStafMiddleware
{
    public function handle(Request $r, Closure $next)
    {
        $r->attributes->set('staf', Sesi::staf($r));

        return $next($r);
    }
}
