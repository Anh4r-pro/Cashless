<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class WajibOrtu
{
    public function handle(Request $r, Closure $next)
    {
        if (! $r->attributes->get('ortu')) {
            return response()->json(['error' => 'Sesi wali sudah berakhir — masuk lagi'], 401);
        }

        return $next($r);
    }
}
