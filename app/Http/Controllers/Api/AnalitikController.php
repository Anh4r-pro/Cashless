<?php

namespace App\Http\Controllers\Api;

use App\Models\Produk;
use App\Models\Transaksi;
use App\Models\TransaksiItem;
use App\Models\User;
use App\Services\SqlWaktu;
use App\Support\WaktuIso;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

/**
 * Analitik penjualan.
 *
 * Dua hal sengaja dilakukan di bagian per-menu, keduanya supaya angkanya jujur:
 *
 *   1. Menu yang TIDAK laku sekalipun tetap muncul. Menu yang hilang dari
 *      laporan karena nol penjualan justru menu yang paling perlu dilihat.
 *   2. Belanja lama tanpa rincian dilaporkan apa adanya sebagai "tanpa
 *      rincian", bukan diabaikan. Kalau tidak, total per menu akan lebih kecil
 *      daripada total penjualan dan tidak ada yang tahu mengapa.
 */
class AnalitikController extends Controller
{
    /** Menu tanpa penjualan selama ini = kandidat diganti. */
    private const MENU_SEPI_HARI = 14;

    public function umum(Request $r)
    {
        $u = $this->wajibPeran($r, 'tenant', 'superadmin');
        $q = $r->query();
        $gran = SqlWaktu::granularitasSah($r->query('granularitas'));
        $P = SqlWaktu::periode($gran);

        // Top up tidak terikat tenant (tenant_id NULL), jadi saat disaring per
        // tenant deret top up otomatis kosong. Frontend menyembunyikannya agar
        // tidak rancu.
        $scopedTenant = $u->role === 'tenant' || (bool) $r->query('tenant_id');

        $series = $this->saring($u, $q)
            ->selectRaw("{$P} AS periode")
            ->selectRaw("COALESCE(SUM(CASE WHEN tipe='beli' THEN -jumlah END),0) AS belanja")
            ->selectRaw("COALESCE(SUM(CASE WHEN tipe='topup' THEN jumlah END),0) AS topup")
            ->selectRaw("SUM(CASE WHEN tipe='beli' THEN 1 ELSE 0 END) AS n_beli")
            ->selectRaw("SUM(CASE WHEN tipe='topup' THEN 1 ELSE 0 END) AS n_topup")
            ->selectRaw('COUNT(DISTINCT siswa_id) AS siswa_aktif')
            ->groupBy('periode')->orderBy('periode')->get()
            ->map(fn ($s) => [
                'periode' => $s->periode, 'belanja' => (int) $s->belanja, 'topup' => (int) $s->topup,
                'n_beli' => (int) $s->n_beli, 'n_topup' => (int) $s->n_topup,
                'siswa_aktif' => (int) $s->siswa_aktif,
            ]);

        $ringkas = $this->saring($u, $q)->where('tipe', 'beli')
            ->selectRaw('COUNT(*) AS n_beli, COALESCE(SUM(-jumlah),0) AS belanja')
            ->selectRaw('COALESCE(AVG(-jumlah),0) AS rata2, COUNT(DISTINCT siswa_id) AS siswa_unik')
            ->first();

        $ringkasTopup = $this->saring($u, $q)->where('tipe', 'topup')
            ->selectRaw('COUNT(*) AS n_topup, COALESCE(SUM(jumlah),0) AS topup')->first();

        $perTenant = $this->saring($u, $q)->where('tipe', 'beli')
            ->selectRaw("tenant_id, COALESCE(tenant_nama,'-') AS nama, COUNT(*) AS n, COALESCE(SUM(-jumlah),0) AS total")
            ->groupBy('tenant_id', 'nama')->orderByDesc('total')->get()
            ->map(fn ($x) => ['tenant_id' => $x->tenant_id, 'nama' => $x->nama,
                'n' => (int) $x->n, 'total' => (int) $x->total]);

        $perJam = $this->saring($u, $q)->where('tipe', 'beli')
            ->selectRaw('CAST(HOUR(' . SqlWaktu::wib() . ') AS SIGNED) AS jam')
            ->selectRaw('COUNT(*) AS n, COALESCE(SUM(-jumlah),0) AS total')
            ->groupBy('jam')->orderBy('jam')->get()
            ->map(fn ($x) => ['jam' => (int) $x->jam, 'n' => (int) $x->n, 'total' => (int) $x->total]);

        $perKelas = $this->saring($u, $q)->where('tipe', 'beli')
            ->selectRaw('kelas, COUNT(*) AS n, COALESCE(SUM(-jumlah),0) AS total, COUNT(DISTINCT siswa_id) AS siswa')
            ->groupBy('kelas')->orderByDesc('total')->limit(10)->get()
            ->map(fn ($x) => ['kelas' => $x->kelas, 'n' => (int) $x->n,
                'total' => (int) $x->total, 'siswa' => (int) $x->siswa]);

        $topSiswa = $this->saring($u, $q)->where('tipe', 'beli')
            ->selectRaw('siswa_id, MAX(siswa_nama) AS siswa_nama, MAX(kelas) AS kelas, COUNT(*) AS n, COALESCE(SUM(-jumlah),0) AS total')
            ->groupBy('siswa_id')->orderByDesc('total')->limit(10)->get()
            ->map(fn ($x) => ['siswa_nama' => $x->siswa_nama, 'kelas' => $x->kelas,
                'n' => (int) $x->n, 'total' => (int) $x->total]);

        $puncak = $series->sortByDesc('belanja')->first();

        return response()->json([
            'granularitas' => $gran, 'scoped_tenant' => $scopedTenant,
            'series' => $series->values(), 'per_tenant' => $perTenant, 'per_jam' => $perJam,
            'per_kelas' => $perKelas, 'top_siswa' => $topSiswa,
            'ringkasan' => [
                'n_beli' => (int) $ringkas->n_beli, 'belanja' => (int) $ringkas->belanja,
                'siswa_unik' => (int) $ringkas->siswa_unik,
                'n_topup' => (int) $ringkasTopup->n_topup, 'topup' => (int) $ringkasTopup->topup,
                'rata2' => (int) round((float) $ringkas->rata2),
                'hari_puncak' => $puncak['periode'] ?? null,
                'belanja_puncak' => $puncak['belanja'] ?? 0,
            ],
        ]);
    }

    public function menu(Request $r)
    {
        $u = $this->wajibPeran($r, 'tenant', 'superadmin');
        $q = $r->query();
        $batas = min(200, max(5, (int) $r->query('limit', 50) ?: 50));

        // Dikelompokkan menurut (tenant, nama) dan BUKAN produk_id: menu yang
        // sudah dihapus dari daftar tetap punya sejarah penjualan, dan
        // produk_id-nya sudah menjadi NULL.
        $perMenu = $this->saringItem($u, $q)
            ->selectRaw('tenant_id, MAX(tenant_nama) AS tenant_nama, nama, MAX(kategori) AS kategori')
            ->selectRaw('SUM(qty) AS qty, SUM(subtotal) AS total')
            ->selectRaw('COUNT(DISTINCT transaksi_id) AS n_transaksi, COUNT(DISTINCT siswa_id) AS n_pembeli')
            ->selectRaw('MAX(harga) AS harga_akhir, MIN(created_at) AS pertama, MAX(created_at) AS terakhir')
            ->groupBy('tenant_id', 'nama')->orderByDesc('total')->get();

        $totalRp = (int) $perMenu->sum('total');
        $totalQty = (int) $perMenu->sum('qty');

        // Menu yang masih terdaftar tetapi tidak muncul sama sekali dalam
        // periode ini — inilah daftar yang dicari penjual saat memutuskan
        // mengganti menu.
        $semuaProduk = Produk::query()
            ->join('tenant', 'tenant.id', '=', 'produk.tenant_id')
            ->select('produk.id', 'produk.tenant_id', 'tenant.nama AS tenant_nama',
                'produk.nama', 'produk.harga', 'produk.aktif', 'produk.kategori')
            ->when($u->role === 'tenant', fn ($w) => $w->where('produk.tenant_id', $u->tenant_id))
            ->when($u->role !== 'tenant' && ! empty($q['tenant_id']),
                fn ($w) => $w->where('produk.tenant_id', (int) $q['tenant_id']))
            ->orderBy('tenant.nama')->orderBy('produk.nama')->get();

        $terjual = $perMenu->map(fn ($r2) => $r2->tenant_id . '|' . $r2->nama)->flip();
        $belumLaku = $semuaProduk->reject(fn ($p) => $terjual->has($p->tenant_id . '|' . $p->nama))->values();

        // Kapan terakhir tiap menu laku — dihitung TANPA saringan periode,
        // karena "terakhir laku 40 hari lalu" jauh lebih berguna daripada
        // "0 bulan ini".
        $terakhirLaku = TransaksiItem::selectRaw('tenant_id, nama, MAX(created_at) AS terakhir')
            ->groupBy('tenant_id', 'nama')->get()
            ->mapWithKeys(fn ($x) => [$x->tenant_id . '|' . $x->nama => $x->terakhir]);

        $ambangSepi = WaktuIso::awalHari(self::MENU_SEPI_HARI);

        // Belanja yang barisnya tidak tercatat (transaksi sebelum fitur ini ada,
        // atau kiriman kasir versi lama).
        $semuaBelanja = $this->saring($u, $q)->where('tipe', 'beli')
            ->selectRaw('COALESCE(SUM(-jumlah),0) AS total, COUNT(*) AS n')->first();

        return response()->json([
            'menu' => $perMenu->take($batas)->map(fn ($m) => [
                'tenant_id' => $m->tenant_id, 'tenant_nama' => $m->tenant_nama, 'nama' => $m->nama,
                'kategori' => $m->kategori, 'qty' => (int) $m->qty, 'total' => (int) $m->total,
                'n_transaksi' => (int) $m->n_transaksi, 'n_pembeli' => (int) $m->n_pembeli,
                'harga_akhir' => (int) $m->harga_akhir, 'pertama' => $m->pertama, 'terakhir' => $m->terakhir,
                'porsi' => $totalRp ? round((int) $m->total / $totalRp * 1000) / 10 : 0,
                'sepi' => $m->terakhir < $ambangSepi,
            ])->values(),
            'belum_laku' => $belumLaku->map(fn ($p) => array_merge($p->toArray(),
                ['terakhir' => $terakhirLaku[$p->tenant_id . '|' . $p->nama] ?? null])),
            'ringkasan' => [
                'n_menu' => $perMenu->count(),
                'n_menu_terdaftar' => $semuaProduk->count(),
                'n_belum_laku' => $belumLaku->count(),
                'qty' => $totalQty,
                'total' => $totalRp,
                'belanja_total' => (int) $semuaBelanja->total,
                'n_belanja' => (int) $semuaBelanja->n,
                // Selisih ini BUKAN kesalahan hitung: itu belanja yang memang
                // tidak punya rincian barisnya. Disebutkan terang-terangan
                // supaya tidak dikira bug.
                'tanpa_rincian' => max(0, (int) $semuaBelanja->total - $totalRp),
                'hari_sepi' => self::MENU_SEPI_HARI,
            ],
        ]);
    }

    /**
     * Tren satu menu dari waktu ke waktu. Sepuluh terlaris ditampilkan sebagai
     * deret sendiri; sisanya digabung jadi "lainnya", supaya grafik tetap
     * terbaca tanpa menyembunyikan angkanya.
     */
    public function menuTren(Request $r)
    {
        $u = $this->wajibPeran($r, 'tenant', 'superadmin');
        $q = $r->query();
        $gran = SqlWaktu::granularitasSah($r->query('granularitas'));
        $P = SqlWaktu::periode($gran);

        $atas = $this->saringItem($u, $q)
            ->selectRaw('tenant_id, MAX(tenant_nama) AS tenant_nama, nama, SUM(subtotal) AS total')
            ->groupBy('tenant_id', 'nama')->orderByDesc('total')->limit(10)->get();

        $deret = $this->saringItem($u, $q)
            ->selectRaw("{$P} AS periode, tenant_id, nama, SUM(qty) AS qty, SUM(subtotal) AS total")
            ->groupBy('periode', 'tenant_id', 'nama')->orderBy('periode')->get();

        $periode = $deret->pluck('periode')->unique()->sort()->values();
        $posisi = $periode->flip();

        $seri = $atas->map(fn ($m) => [
            'tenant_id' => $m->tenant_id, 'tenant_nama' => $m->tenant_nama,
            'nama' => $m->nama, 'total' => (int) $m->total,
            'titik' => array_fill(0, $periode->count(), 0),
        ])->all();
        $indeks = [];
        foreach ($seri as $i => $m) {
            $indeks[$m['tenant_id'] . '|' . $m['nama']] = $i;
        }
        $lainnya = array_fill(0, $periode->count(), 0);

        foreach ($deret as $d) {
            $i = $posisi[$d->periode];
            $k = $d->tenant_id . '|' . $d->nama;
            if (isset($indeks[$k])) {
                $seri[$indeks[$k]]['titik'][$i] += (int) $d->total;
            } else {
                $lainnya[$i] += (int) $d->total;
            }
        }

        return response()->json([
            'granularitas' => $gran, 'periode' => $periode, 'seri' => array_values($seri),
            'lainnya' => array_sum($lainnya) ? $lainnya : null,
        ]);
    }

    /**
     * Saringan bersama untuk agregasi transaksi. Pembatasan tenant dipasang di
     * kueri, jadi akun tenant tidak bisa melihat angka tenant lain.
     */
    private function saring(User $u, array $q): Builder
    {
        $t = Transaksi::query();
        if ($u->role === 'tenant') {
            $t->where('tenant_id', $u->tenant_id);
        } elseif (! empty($q['tenant_id'])) {
            $t->where('tenant_id', (int) $q['tenant_id']);
        }
        if (! empty($q['dari'])) {
            $t->where('created_at', '>=', $q['dari']);
        }
        if (! empty($q['sampai'])) {
            $t->where('created_at', '<=', $q['sampai']);
        }
        if (! empty($q['kelas'])) {
            $t->where('kelas', strtoupper((string) $q['kelas']));
        }

        return $t;
    }

    private function saringItem(User $u, array $q): Builder
    {
        $i = TransaksiItem::query();
        if ($u->role === 'tenant') {
            $i->where('tenant_id', $u->tenant_id);
        } elseif (! empty($q['tenant_id'])) {
            $i->where('tenant_id', (int) $q['tenant_id']);
        }
        if (! empty($q['dari'])) {
            $i->where('created_at', '>=', $q['dari']);
        }
        if (! empty($q['sampai'])) {
            $i->where('created_at', '<=', $q['sampai']);
        }
        if (! empty($q['kelas'])) {
            $i->where('kelas', strtoupper((string) $q['kelas']));
        }
        if (! empty($q['golongan'])) {
            $i->where('golongan', (string) $q['golongan']);
        }

        return $i;
    }
}
