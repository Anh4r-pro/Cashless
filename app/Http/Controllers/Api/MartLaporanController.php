<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\ApiError;
use App\Models\MartJual;
use App\Models\MartKas;
use App\Models\Produk;
use App\Models\Tenant;
use App\Models\Transaksi;
use App\Models\TransaksiItem;
use App\Services\Stok;
use App\Support\WaktuIso;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Dashboard & laporan IMBOS Mart.
 *
 * Satu hal yang dijaga ketat di sini: ANGKA LABA TIDAK DIKLAIM LEBIH DARI
 * YANG BISA DIBUKTIKAN. Sistem mencatat penjualan dan modal barang (HPP),
 * tetapi tidak mencatat biaya operasional — gaji penjaga, listrik, sewa,
 * penyusutan. Maka yang dihitung di sini adalah LABA KOTOR (penjualan −
 * modal barang), dan diberi label begitu apa adanya. Menyebutnya "laba
 * bersih" akan membuat angka ini dibawa ke rapat yayasan sebagai sesuatu
 * yang bukan dirinya.
 *
 * Penjualan Mart datang dari dua buku yang sengaja terpisah: `mart_jual`
 * (tunai & transfer, uang di Mart) dan `transaksi` (kartu, uang di kas
 * sekolah). Laporan ini menyatukan keduanya untuk kebutuhan yayasan, tanpa
 * pernah menyatukan uangnya.
 */
class MartLaporanController extends Controller
{
    private function martKu(Request $r): Tenant
    {
        $u = $this->wajibPeran($r, 'tenant', 'superadmin');
        // Superadmin boleh membaca laporan Mart mana pun lewat ?tenant_id=,
        // supaya admin pusat tetap melihat seluruh dagangan unit ini.
        $id = $u->role === 'superadmin' ? (int) $r->query('tenant_id') : (int) $u->tenant_id;
        $t = Tenant::find($id);
        if (! $t || ($t->mode_kasir ?? 'menu') !== 'mart') {
            throw new ApiError(404, 'Tenant Mart tidak ditemukan');
        }

        return $t;
    }

    /** Batas awal periode menurut zona sekolah. */
    private function sejak(string $periode): ?string
    {
        return match ($periode) {
            'hari' => WaktuIso::awalHariIni(),
            'pekan' => WaktuIso::awalHari(6),
            'bulan' => WaktuIso::awalHari(29),
            default => null,                       // 'semua'
        };
    }

    public function dashboard(Request $r)
    {
        $t = $this->martKu($r);

        return response()->json([
            'tenant' => ['id' => $t->id, 'nama' => $t->nama],
            'hari' => $this->omzet($t, 'hari'),
            'pekan' => $this->omzet($t, 'pekan'),
            'bulan' => $this->omzet($t, 'bulan'),
            'stok' => $this->stokPeringatan($t),
            'terlaris' => $this->terlaris($t, 'bulan', 8, 'desc'),
            'tidak_laku' => $this->tidakLaku($t, 8),
            'transaksi' => $this->riwayatGabungan($t, 15),
            'kas_terakhir' => MartKas::where('tenant_id', $t->id)->orderByDesc('tanggal')->limit(7)->get(),
        ]);
    }

    /**
     * Omzet & laba kotor satu periode, dipecah menurut jalur uangnya.
     *
     * HPP diambil dari harga modal SEKARANG, bukan saat transaksi terjadi.
     * Ini penyederhanaan yang disengaja: menyimpan HPP per baris penjualan
     * akan lebih tepat, tetapi untuk toko sekolah yang harga modalnya jarang
     * berubah, bedanya kecil dan kerumitannya besar. Yang penting: angkanya
     * konsisten, dan asumsinya tertulis di sini.
     */
    private function omzet(Tenant $t, string $periode): array
    {
        $sejak = $this->sejak($periode);

        $mart = MartJual::where('tenant_id', $t->id)
            ->when($sejak, fn ($q) => $q->where('created_at', '>=', $sejak))
            ->selectRaw("COALESCE(SUM(CASE WHEN metode='tunai' THEN total END),0) tunai")
            ->selectRaw("COALESCE(SUM(CASE WHEN metode='transfer' THEN total END),0) transfer")
            ->selectRaw('COALESCE(SUM(diskon),0) diskon, COUNT(*) n')->first();

        $kartu = Transaksi::where('tenant_id', $t->id)->where('tipe', 'beli')
            ->when($sejak, fn ($q) => $q->where('created_at', '>=', $sejak))
            ->selectRaw('COALESCE(SUM(-jumlah),0) total, COUNT(*) n')->first();

        $tunai = (int) $mart->tunai;
        $transfer = (int) $mart->transfer;
        $cashless = (int) $kartu->total;
        $kotor = $tunai + $transfer + $cashless;
        $nTx = (int) $mart->n + (int) $kartu->n;

        $hpp = $this->hpp($t, $sejak);

        return [
            'periode' => $periode,
            'penjualan_kotor' => $kotor,
            'tunai' => $tunai, 'transfer' => $transfer, 'cashless' => $cashless,
            'diskon' => (int) $mart->diskon,
            'hpp' => $hpp,
            'laba_kotor' => $kotor - $hpp,
            'margin_persen' => $kotor > 0 ? round(($kotor - $hpp) / $kotor * 100, 1) : null,
            'n_transaksi' => $nTx,
            'rata_keranjang' => $nTx > 0 ? (int) round($kotor / $nTx) : 0,
        ];
    }

    /** Modal barang yang terjual pada periode — dari dua buku penjualan sekaligus. */
    private function hpp(Tenant $t, ?string $sejak): int
    {
        $a = DB::table('mart_jual_item as i')->join('mart_jual as j', 'j.id', '=', 'i.jual_id')
            ->join('produk as p', 'p.id', '=', 'i.produk_id')
            ->where('j.tenant_id', $t->id)
            ->when($sejak, fn ($q) => $q->where('j.created_at', '>=', $sejak))
            ->selectRaw('COALESCE(SUM(i.qty * COALESCE(p.hpp,0)),0) n')->value('n');

        $b = DB::table('transaksi_item as i')->join('produk as p', 'p.id', '=', 'i.produk_id')
            ->where('i.tenant_id', $t->id)
            ->when($sejak, fn ($q) => $q->where('i.created_at', '>=', $sejak))
            ->selectRaw('COALESCE(SUM(i.qty * COALESCE(p.hpp,0)),0) n')->value('n');

        return (int) $a + (int) $b;
    }

    private function stokPeringatan(Tenant $t): array
    {
        $p = Produk::where('tenant_id', $t->id)->where('aktif', 1)->whereNotNull('stok');

        return [
            'habis' => (clone $p)->where('stok', '<=', 0)->orderBy('nama')
                ->get(['id', 'nama', 'barcode', 'stok', 'stok_min'])->all(),
            'menipis' => (clone $p)->whereColumn('stok', '<=', 'stok_min')->where('stok', '>', 0)
                ->orderBy('stok')->get(['id', 'nama', 'barcode', 'stok', 'stok_min'])->all(),
            'nilai_stok' => (int) (clone $p)->selectRaw('COALESCE(SUM(stok * COALESCE(hpp,0)),0) n')->value('n'),
        ];
    }

    /** Barang terlaris — qty dari dua buku penjualan digabung. */
    private function terlaris(Tenant $t, string $periode, int $limit, string $arah)
    {
        $sejak = $this->sejak($periode);

        $mart = DB::table('mart_jual_item as i')->join('mart_jual as j', 'j.id', '=', 'i.jual_id')
            ->where('j.tenant_id', $t->id)->whereNotNull('i.produk_id')
            ->when($sejak, fn ($q) => $q->where('j.created_at', '>=', $sejak))
            ->selectRaw('i.produk_id, SUM(i.qty) qty, SUM(i.subtotal) nilai')
            ->groupBy('i.produk_id')->get();

        $kartu = DB::table('transaksi_item as i')->where('i.tenant_id', $t->id)
            ->whereNotNull('i.produk_id')
            ->when($sejak, fn ($q) => $q->where('i.created_at', '>=', $sejak))
            ->selectRaw('i.produk_id, SUM(i.qty) qty, SUM(i.subtotal) nilai')
            ->groupBy('i.produk_id')->get();

        $gabung = [];
        foreach ([$mart, $kartu] as $kel) {
            foreach ($kel as $b) {
                $id = (int) $b->produk_id;
                $gabung[$id] ??= ['produk_id' => $id, 'qty' => 0, 'nilai' => 0];
                $gabung[$id]['qty'] += (int) $b->qty;
                $gabung[$id]['nilai'] += (int) $b->nilai;
            }
        }
        $nama = Produk::whereIn('id', array_keys($gabung))->pluck('nama', 'id');
        $hasil = array_values(array_map(fn ($b) => array_merge($b,
            ['nama' => $nama[$b['produk_id']] ?? '(barang terhapus)']), $gabung));
        usort($hasil, fn ($a, $b) => $arah === 'desc' ? $b['qty'] <=> $a['qty'] : $a['qty'] <=> $b['qty']);

        return array_slice($hasil, 0, $limit);
    }

    /** Barang yang SAMA SEKALI belum laku sebulan terakhir — modal yang mengendap. */
    private function tidakLaku(Tenant $t, int $limit)
    {
        $laku = array_column($this->terlaris($t, 'bulan', 9999, 'desc'), 'produk_id');

        return Produk::where('tenant_id', $t->id)->where('aktif', 1)
            ->when($laku, fn ($q) => $q->whereNotIn('id', $laku))
            ->orderBy('nama')->limit($limit)
            ->get(['id', 'nama', 'barcode', 'harga', 'stok'])->all();
    }

    /** Riwayat transaksi dua buku, diurut waktu — untuk layar dan unduhan. */
    private function riwayatGabungan(Tenant $t, int $limit, ?string $sejak = null): array
    {
        $mart = MartJual::where('tenant_id', $t->id)
            ->when($sejak, fn ($q) => $q->where('created_at', '>=', $sejak))
            ->orderByDesc('id')->limit($limit)->get()
            ->map(fn ($j) => [
                'waktu' => $j->created_at, 'metode' => $j->metode, 'total' => (int) $j->total,
                'n_item' => (int) $j->n_item, 'operator' => $j->operator,
                'ket' => $j->metode === 'transfer' ? trim($j->bank . ' · ' . $j->pengirim) : '',
            ])->all();

        $kartu = Transaksi::where('tenant_id', $t->id)->where('tipe', 'beli')
            ->when($sejak, fn ($q) => $q->where('created_at', '>=', $sejak))
            ->orderByDesc('id')->limit($limit)->get()
            ->map(fn ($x) => [
                'waktu' => $x->created_at, 'metode' => 'kartu', 'total' => (int) -$x->jumlah,
                'n_item' => null, 'operator' => $x->operator,
                'ket' => $x->siswa_nama . ($x->kelas ? ' · ' . $x->kelas : ''),
            ])->all();

        $semua = array_merge($mart, $kartu);
        usort($semua, fn ($a, $b) => strcmp((string) $b['waktu'], (string) $a['waktu']));

        return array_slice($semua, 0, $limit);
    }

    /** Laporan keuangan rinci satu periode + daftar transaksinya. */
    public function keuangan(Request $r)
    {
        $t = $this->martKu($r);
        $periode = in_array($r->query('periode'), ['hari', 'pekan', 'bulan', 'semua'], true)
            ? $r->query('periode') : 'bulan';

        return response()->json([
            'tenant' => ['id' => $t->id, 'nama' => $t->nama],
            'periode' => $periode,
            'ringkas' => $this->omzet($t, $periode),
            'terlaris' => $this->terlaris($t, $periode, 20, 'desc'),
            'transaksi' => $this->riwayatGabungan($t, 200, $this->sejak($periode)),
            'kas' => MartKas::where('tenant_id', $t->id)->orderByDesc('tanggal')->limit(31)->get(),
        ]);
    }
}
