<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class WajibMasuk
{
    public function handle(Request $r, Closure $next)
    {
        if (! $r->attributes->get('staf')) {
            return response()->json(['error' => 'Belum masuk'], 401);
        }

        return $next($r);
    }
}
