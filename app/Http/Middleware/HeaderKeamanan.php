<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

/**
 * Header keamanan dasar.
 *
 * Sistem lama tidak memasang satu pun — bukan karena diputuskan begitu, tetapi
 * karena tidak pernah ada tempat yang wajar untuk menuliskannya. Di sini ada.
 */
class HeaderKeamanan
{
    public function handle(Request $r, Closure $next)
    {
        $res = $next($r);

        $res->headers->set('X-Content-Type-Options', 'nosniff');
        $res->headers->set('X-Frame-Options', 'DENY');
        $res->headers->set('Referrer-Policy', 'same-origin');
        // Layar kios dipasang di perangkat sekolah; tidak ada alasan halaman ini
        // meminta kamera, mikrofon, atau lokasi.
        $res->headers->set('Permissions-Policy', 'camera=(), microphone=(), geolocation=()');

        return $res;
    }
}
