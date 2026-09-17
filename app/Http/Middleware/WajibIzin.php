<?php

namespace App\Http\Middleware;

use App\Services\Izin;
use Closure;
use Illuminate\Http\Request;

/**
 * Pagar per aksi: `->middleware('izin:santri.tambah')`.
 *
 * Dipasang BERDAMPINGAN dengan `peran:`, bukan menggantikannya. `peran` masih
 * memisahkan sisi admin dari sisi kasir; `izin` yang memilah aksi di dalam sisi
 * admin. Menyembunyikan menu di layar bukan pengamanan — sakelar yang dimatikan
 * admin harus menutup endpoint-nya juga, dan di sinilah itu terjadi.
 */
class WajibIzin
{
    public function handle(Request $r, Closure $next, string ...$kode)
    {
        $u = $r->attributes->get('staf');
        if (! $u) {
            return response()->json(['error' => 'Belum masuk'], 401);
        }

        // Beberapa kode = cukup salah satu dimiliki. Dipakai pada endpoint yang
        // melayani dua layar sekaligus (mis. daftar kelas dipakai menu Santri
        // maupun Topup Massal).
        foreach ($kode as $k) {
            if (Izin::boleh($u, $k)) {
                return $next($r);
            }
        }

        return response()->json([
            'error' => 'Akun Anda tidak punya hak "' . Izin::label($kode[0]) . '"',
        ], 403);
    }
}
