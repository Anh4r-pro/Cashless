<?php

namespace App\Http\Controllers\Api;

use App\Models\ProdukAjuan;
use App\Models\Siswa;
use App\Models\Transaksi;
use App\Services\SqlWaktu;
use App\Services\StatistikTenant;
use App\Support\WaktuIso;
use Illuminate\Http\Request;

class DashboardController extends Controller
{
    public function admin(Request $r)
    {
        $this->wajibPeran($r, 'superadmin');

        $awal = WaktuIso::awalHariIni();
        $global = Transaksi::query()
            ->selectRaw("COALESCE(SUM(CASE WHEN tipe='beli' AND created_at >= ? THEN -jumlah END),0) AS belanja_hari_ini", [$awal])
            ->selectRaw("COALESCE(SUM(CASE WHEN tipe='topup' AND created_at >= ? THEN jumlah END),0) AS topup_hari_ini", [$awal])
            ->first();

        $saldo = Siswa::selectRaw('COALESCE(SUM(saldo),0) AS s, COUNT(*) AS n')->first();

        return response()->json([
            'belanja_hari_ini' => (int) $global->belanja_hari_ini,
            'topup_hari_ini' => (int) $global->topup_hari_ini,
            'total_saldo_siswa' => (int) $saldo->s,
            'jumlah_siswa' => (int) $saldo->n,
            // Lencana di tab admin, supaya usulan tenant tidak menganggur
            // berhari-hari tanpa ada yang melihat.
            'menu_menunggu' => ProdukAjuan::where('status', 'menunggu')->count(),
        ]);
    }

    public function tenant(Request $r)
    {
        $u = $this->wajibPeran($r, 'tenant');
        $stats = StatistikTenant::untuk($u->tenant_id);

        $harian = Transaksi::where('tipe', 'beli')
            ->where('tenant_id', $u->tenant_id)
            ->where('created_at', '>=', WaktuIso::awalHari(14))
            ->selectRaw(SqlWaktu::periode('harian') . ' AS tgl')
            ->selectRaw('COALESCE(SUM(-jumlah),0) AS total')
            ->groupBy('tgl')->orderBy('tgl')->get()
            // SUM() di MySQL kembali sebagai teks lewat PDO; grafik di frontend
            // menjumlahkannya, jadi dikembalikan sebagai angka.
            ->map(fn ($b) => ['tgl' => $b->tgl, 'total' => (int) $b->total]);

        return response()->json(array_merge($stats, ['harian' => $harian]));
    }
}
