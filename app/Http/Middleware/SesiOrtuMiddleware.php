<?php

namespace App\Http\Middleware;

use App\Services\Sesi;
use Closure;
use Illuminate\Http\Request;

/** Menempelkan siswa yang sedang dibuka walinya, dari cookie `osid`. */
class SesiOrtuMiddleware
{
    public function handle(Request $r, Closure $next)
    {
        $r->attributes->set('ortu', Sesi::ortu($r));

        return $next($r);
    }
}
