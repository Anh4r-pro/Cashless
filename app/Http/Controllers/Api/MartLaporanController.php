<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\ApiError;
use App\Models\MartJual;
use App\Models\MartKas;
use App\Models\MartPiutangBayar;
use App\Models\Produk;
use App\Models\Tenant;
use App\Models\Transaksi;
use App\Models\TransaksiItem;
use App\Services\Ekspor;
use App\Services\Kartu;
use App\Services\Rupiah;
use App\Services\SqlWaktu;
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
            // 'transaksi' dilepas bersama panel "Riwayat Transaksi Terakhir"
            // (keputusan rapat: riwayat tempatnya di menu Riwayat). Kueri
            // gabungan dua buku itu tidak murah, jadi jangan dijalankan untuk
            // data yang tidak digambar siapa pun.
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

        $mart = MartJual::sah()->where('tenant_id', $t->id)
            ->when($sejak, fn ($q) => $q->where('created_at', '>=', $sejak))
            ->selectRaw("COALESCE(SUM(CASE WHEN metode='tunai' THEN total END),0) tunai")
            ->selectRaw("COALESCE(SUM(CASE WHEN metode='transfer' THEN total END),0) transfer")
            ->selectRaw("COALESCE(SUM(CASE WHEN metode='hutang' THEN total END),0) hutang")
            ->selectRaw('COALESCE(SUM(diskon),0) diskon, COUNT(*) n')->first();

        // Cicilan piutang yang MASUK pada periode ini. Ia bukan omzet — omzetnya
        // sudah dihitung pada hari barangnya keluar — melainkan uang yang baru
        // sampai sekarang. Dua hal berbeda yang mudah tertukar kalau disatukan.
        $cicil = MartPiutangBayar::where('tenant_id', $t->id)
            ->when($sejak, fn ($q) => $q->where('created_at', '>=', $sejak))
            ->selectRaw("COALESCE(SUM(CASE WHEN metode='tunai' THEN jumlah END),0) tunai")
            ->selectRaw("COALESCE(SUM(CASE WHEN metode='transfer' THEN jumlah END),0) transfer")
            ->selectRaw('COALESCE(SUM(jumlah),0) total')->first();

        $kartu = Transaksi::where('tenant_id', $t->id)->where('tipe', 'beli')
            ->when($sejak, fn ($q) => $q->where('created_at', '>=', $sejak))
            ->selectRaw('COALESCE(SUM(-jumlah),0) total, COUNT(*) n')->first();

        $tunai = (int) $mart->tunai;
        $transfer = (int) $mart->transfer;
        $hutang = (int) $mart->hutang;
        $cashless = (int) $kartu->total;
        $kotor = $tunai + $transfer + $hutang + $cashless;
        $nTx = (int) $mart->n + (int) $kartu->n;

        $hpp = $this->hpp($t, $sejak);

        return [
            'periode' => $periode,
            'penjualan_kotor' => $kotor,
            // Penjualan menurut jalurnya (kapan BARANG keluar).
            'tunai' => $tunai, 'transfer' => $transfer, 'hutang' => $hutang,
            'cashless' => $cashless,
            // Uang yang benar-benar diterima pada periode ini (kapan UANG masuk):
            // penjualan tunai/transfer ditambah cicilan piutang yang dibayar.
            'masuk_tunai' => $tunai + (int) $cicil->tunai,
            'masuk_transfer' => $transfer + (int) $cicil->transfer,
            'piutang_tertagih' => (int) $cicil->total,
            'piutang_sisa' => $this->sisaPiutang($t),
            'diskon' => (int) $mart->diskon,
            'hpp' => $hpp,
            'laba_kotor' => $kotor - $hpp,
            'margin_persen' => $kotor > 0 ? round(($kotor - $hpp) / $kotor * 100, 1) : null,
            'n_transaksi' => $nTx,
            'rata_keranjang' => $nTx > 0 ? (int) round($kotor / $nTx) : 0,
        ];
    }

    /**
     * Seluruh piutang yang belum tertagih, apa pun periodenya.
     *
     * Sengaja TIDAK mengikuti saringan periode: "sisa piutang" adalah keadaan
     * hari ini, bukan kejadian dalam rentang waktu. Piutang bulan lalu yang
     * belum dibayar tetap piutang hari ini.
     */
    private function sisaPiutang(Tenant $t): int
    {
        $hutang = (int) MartJual::sah()->where('tenant_id', $t->id)
            ->where('metode', 'hutang')->sum('total');
        $bayar = (int) MartPiutangBayar::where('tenant_id', $t->id)->sum('jumlah');

        return max(0, $hutang - $bayar);
    }

    /**
     * Modal barang yang terjual pada periode — dari dua buku penjualan sekaligus.
     *
     * Yang dipakai POTRET harga modal pada baris penjualannya (`i.hpp`), bukan
     * harga modal yang berlaku sekarang. Sejak ada modul pembelian, harga modal
     * berubah setiap kali nota masuk; membaca harga hari ini akan membuat laba
     * bulan lalu ikut bergerak — angka yang sudah dibaca orang dan mungkin
     * sudah dibawa ke rapat.
     *
     * Baris lama yang belum punya potret jatuh ke `produk.hpp`, persis seperti
     * cara lama. Ia tidak menjadi lebih benar, tetapi juga tidak kehilangan
     * angkanya hanya karena kolom baru ditambahkan.
     */
    private function hpp(Tenant $t, ?string $sejak): int
    {
        $a = DB::table('mart_jual_item as i')->join('mart_jual as j', 'j.id', '=', 'i.jual_id')
            ->leftJoin('produk as p', 'p.id', '=', 'i.produk_id')
            ->where('j.tenant_id', $t->id)->where('j.status', 'sah')
            ->when($sejak, fn ($q) => $q->where('j.created_at', '>=', $sejak))
            ->selectRaw('COALESCE(SUM(i.qty * COALESCE(i.hpp, p.hpp, 0)),0) n')->value('n');

        $b = DB::table('transaksi_item as i')->leftJoin('produk as p', 'p.id', '=', 'i.produk_id')
            ->where('i.tenant_id', $t->id)
            ->when($sejak, fn ($q) => $q->where('i.created_at', '>=', $sejak))
            ->selectRaw('COALESCE(SUM(i.qty * COALESCE(i.hpp, p.hpp, 0)),0) n')->value('n');

        return (int) $a + (int) $b;
    }

    /** Maks rentang yang boleh diminta sekali jalan. */
    private const SPD_MAKS_HARI = 400;

    /**
     * Laporan Closing Harian — SPD (Sales Per-Day).
     *
     * Satu baris per HARI: penjualan, modal barangnya, marginnya, dan catatan
     * penutupan kas hari itu. Bentuknya mengikuti format yang dibawa tim kasir
     * ke rapat (Tanggal · Sales Per-Day · HPP · Margin · Ket).
     *
     * Dua hal yang menentukan kebenarannya:
     *
     *   1. HARI DIHITUNG MENURUT WIB, bukan UTC. created_at disimpan sebagai
     *      teks ISO UTC; tanpa pergeseran +7 jam, penjualan sore hari jatuh ke
     *      tanggal berikutnya — dan laporan closing harian yang salah hari
     *      adalah laporan yang tidak ada gunanya. Pergeserannya dipinjam dari
     *      Services/SqlWaktu yang sudah dipakai dashboard & analitik.
     *   2. DISKON ADA DI LEVEL NOTA, bukan di barisnya. Karena itu penjualan
     *      diambil dari SUM(total) nota (yang sudah bersih diskon), sedangkan
     *      HPP dijumlahkan dari barisnya. Menjumlahkan subtotal baris akan
     *      menggelembungkan omzet harian tepat sebesar diskon.
     */
    public function spd(Request $r)
    {
        $t = $this->martKu($r);

        $hariIni = Kartu::hariIni();
        $tgl = fn ($v, $bawaan) => preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $v) && strtotime((string) $v)
            ? (string) $v : $bawaan;
        $dari = $tgl($r->query('dari'), $hariIni);
        $sampai = $tgl($r->query('sampai'), $hariIni);
        if ($dari > $sampai) {
            [$dari, $sampai] = [$sampai, $dari];
        }
        // Pagar rentang: laporan ini membaca dua buku sekaligus, dan permintaan
        // "dari 1970" akan memindai seluruh tabel tanpa ada yang membutuhkannya.
        if ((strtotime($sampai) - strtotime($dari)) / 86400 > self::SPD_MAKS_HARI) {
            throw new ApiError(400, 'Rentang maksimal ' . self::SPD_MAKS_HARI .
                ' hari sekali minta. Persempit tanggalnya.');
        }
        $kategori = in_array($r->query('kategori'), ['semua', 'tertinggi', 'terendah'], true)
            ? $r->query('kategori') : 'semua';

        $hari = SqlWaktu::periode('harian');
        $hariJual = SqlWaktu::periode('harian', 'j.created_at');
        $hariItem = SqlWaktu::periode('harian', 'i.created_at');
        $antara = fn ($ekspresi) => [$ekspresi . ' BETWEEN ? AND ?', [$dari, $sampai]];

        // --- penjualan per hari, dua buku -----------------------------------
        $jual = MartJual::sah()->where('tenant_id', $t->id)
            ->whereRaw(...$antara($hari))
            ->selectRaw("{$hari} AS tgl, COALESCE(SUM(total),0) nilai, COUNT(*) n")
            ->groupBy('tgl')->get()->keyBy('tgl');

        $kartu = Transaksi::where('tenant_id', $t->id)->where('tipe', 'beli')
            ->whereRaw(...$antara($hari))
            ->selectRaw("{$hari} AS tgl, COALESCE(SUM(-jumlah),0) nilai, COUNT(*) n")
            ->groupBy('tgl')->get()->keyBy('tgl');

        // --- modal barang terjual per hari, dua buku -------------------------
        $hppJual = DB::table('mart_jual_item as i')->join('mart_jual as j', 'j.id', '=', 'i.jual_id')
            ->leftJoin('produk as p', 'p.id', '=', 'i.produk_id')
            ->where('j.tenant_id', $t->id)->where('j.status', 'sah')
            ->whereRaw(...$antara($hariJual))
            ->selectRaw("{$hariJual} AS tgl, COALESCE(SUM(i.qty * COALESCE(i.hpp, p.hpp, 0)),0) hpp")
            ->groupBy('tgl')->pluck('hpp', 'tgl');

        $hppKartu = DB::table('transaksi_item as i')->leftJoin('produk as p', 'p.id', '=', 'i.produk_id')
            ->where('i.tenant_id', $t->id)
            ->whereRaw(...$antara($hariItem))
            ->selectRaw("{$hariItem} AS tgl, COALESCE(SUM(i.qty * COALESCE(i.hpp, p.hpp, 0)),0) hpp")
            ->groupBy('tgl')->pluck('hpp', 'tgl');

        // --- catatan penutupan kas (kolom "Ket") -----------------------------
        $kas = MartKas::where('tenant_id', $t->id)->whereBetween('tanggal', [$dari, $sampai])
            ->get()->keyBy('tanggal');

        /*
         * Hari yang ditampilkan: yang PUNYA KEJADIAN — ada penjualan, atau
         * kasnya ditutup. Hari sepi tanpa catatan apa pun sengaja tidak
         * dibariskan: pada rentang sebulan ia hanya menambah baris kosong yang
         * harus dilewati mata, dan tidak mengubah satu angka pun.
         */
        $tanggal = collect($jual->keys())->merge($kartu->keys())->merge($kas->keys())
            ->unique()->sort()->values();

        $baris = [];
        foreach ($tanggal as $d) {
            $spd = (int) ($jual[$d]->nilai ?? 0) + (int) ($kartu[$d]->nilai ?? 0);
            $hpp = (int) ($hppJual[$d] ?? 0) + (int) ($hppKartu[$d] ?? 0);
            $k = $kas[$d] ?? null;
            $baris[] = [
                'tanggal' => $d,
                'spd' => $spd,
                'hpp' => $hpp,
                'margin' => $spd - $hpp,
                'margin_persen' => $spd > 0 ? round(($spd - $hpp) / $spd * 100, 1) : null,
                'n_transaksi' => (int) ($jual[$d]->n ?? 0) + (int) ($kartu[$d]->n ?? 0),
                // "Ket" diambil dari penutupan kas hari itu — ini laporan
                // CLOSING, jadi catatan penutupannyalah yang relevan di sini.
                'ket' => $k ? trim((string) $k->catatan) : '',
                'kas_ditutup' => (bool) $k,
                'selisih_kas' => $k ? (int) $k->selisih : null,
            ];
        }

        // Urutan: bawaan tanggal TERBARU dulu (konsisten dengan daftar
        // bertanggal lain di aplikasi ini); "tertinggi"/"terendah" mengurutkan
        // menurut SPD supaya hari teramai & tersepi langsung kelihatan.
        usort($baris, match ($kategori) {
            'tertinggi' => fn ($a, $b) => $b['spd'] <=> $a['spd'],
            'terendah' => fn ($a, $b) => $a['spd'] <=> $b['spd'],
            default => fn ($a, $b) => strcmp($b['tanggal'], $a['tanggal']),
        });

        $total = [
            'spd' => array_sum(array_column($baris, 'spd')),
            'hpp' => array_sum(array_column($baris, 'hpp')),
            'n_hari' => count($baris),
            'n_transaksi' => array_sum(array_column($baris, 'n_transaksi')),
        ];
        $total['margin'] = $total['spd'] - $total['hpp'];
        $total['margin_persen'] = $total['spd'] > 0
            ? round($total['margin'] / $total['spd'] * 100, 1) : null;
        $total['rata_spd'] = $total['n_hari'] > 0 ? (int) round($total['spd'] / $total['n_hari']) : 0;

        if ($r->query('format')) {
            return $this->spdUnduhan($t, $baris, $total, $dari, $sampai, $kategori,
                Ekspor::formatSah($r->query('format')));
        }

        return response()->json([
            'tenant' => ['id' => $t->id, 'nama' => $t->nama],
            'dari' => $dari, 'sampai' => $sampai, 'kategori' => $kategori,
            'baris' => $baris, 'total' => $total,
        ]);
    }

    /** SPD sebagai berkas unduhan (.xls / .doc / .csv) — lihat Services\Ekspor. */
    private function spdUnduhan(Tenant $t, array $baris, array $total,
        string $dari, string $sampai, string $kategori, string $format)
    {
        $kepala = ['Tanggal', 'Sales Per-Day (Rp)', 'HPP (Rp)', 'Margin (Rp)', 'Margin %',
            'Transaksi', 'Ket'];
        $isi = array_map(fn ($b) => [
            $b['tanggal'], $b['spd'], $b['hpp'], $b['margin'],
            $b['margin_persen'] === null ? '' : $b['margin_persen'],
            $b['n_transaksi'],
            trim(($b['kas_ditutup'] ? '' : 'kas belum ditutup') . ' ' . $b['ket']),
        ], $baris);
        $isi[] = ['TOTAL', $total['spd'], $total['hpp'], $total['margin'],
            $total['margin_persen'] ?? '', $total['n_transaksi'], $total['n_hari'] . ' hari'];

        $judul = 'Laporan Closing Harian (SPD) — ' . $t->nama;
        $catatan = [
            "Periode: {$dari} s/d {$sampai} · urut: {$kategori}",
            'Margin di sini adalah LABA KOTOR (penjualan − modal barang terjual); '
                . 'biaya operasional belum dikurangkan.',
            'Rata-rata SPD: ' . Rupiah::f($total['rata_spd']) . ' per hari berjualan.',
        ];
        $nama = "closing-harian-{$dari}-sd-{$sampai}.{$format}";
        [$tipe] = Ekspor::TIPE[$format];

        // Pada CSV, keterangannya ditaruh SESUDAH datanya: csvUmum menulis baris
        // kepala lebih dulu, jadi judul yang diselipkan di depan justru mendarat
        // di bawah kepala tabel. Lagi pula periodenya sudah ada di nama berkas.
        $badan = $format === 'csv'
            ? Ekspor::csvUmum($kepala, array_merge($isi, [[]], [[$judul]],
                array_map(fn ($c) => [$c], $catatan)))
            : Ekspor::tabelUmum($judul, $kepala, $isi, $catatan, [1, 2, 3, 4, 5]);

        return response($badan, 200, [
            'Content-Type' => $tipe,
            'Content-Disposition' => "attachment; filename=\"{$nama}\"",
        ]);
    }

    /**
     * Nilai barang terjual yang HARGA MODALNYA TIDAK DIKETAHUI.
     *
     * Dua sumbernya: barang yang diketik bebas di kasir (tidak terdaftar, jadi
     * tidak punya HPP sama sekali — lihat Services/Keranjang), dan barang
     * terdaftar yang HPP-nya memang belum pernah diisi.
     *
     * Keduanya masuk omzet penuh dengan modal nol, sehingga laba kotor ikut
     * menggelembung tanpa ada satu pun angka di layar yang mengakuinya. Yang
     * dikembalikan di sini bukan koreksi — menebak modalnya justru lebih buruk —
     * melainkan bahan untuk mengatakannya terus terang kepada pembacanya.
     */
    private function tanpaModal(Tenant $t, ?string $sejak): array
    {
        $a = DB::table('mart_jual_item as i')->join('mart_jual as j', 'j.id', '=', 'i.jual_id')
            ->leftJoin('produk as p', 'p.id', '=', 'i.produk_id')
            ->where('j.tenant_id', $t->id)->where('j.status', 'sah')
            ->when($sejak, fn ($q) => $q->where('j.created_at', '>=', $sejak))
            ->whereRaw('COALESCE(i.hpp, p.hpp, 0) = 0')
            ->selectRaw('COALESCE(SUM(i.subtotal),0) nilai, COUNT(*) n')->first();

        $b = DB::table('transaksi_item as i')->leftJoin('produk as p', 'p.id', '=', 'i.produk_id')
            ->where('i.tenant_id', $t->id)
            ->when($sejak, fn ($q) => $q->where('i.created_at', '>=', $sejak))
            ->whereRaw('COALESCE(i.hpp, p.hpp, 0) = 0')
            ->selectRaw('COALESCE(SUM(i.subtotal),0) nilai, COUNT(*) n')->first();

        return ['nilai' => (int) $a->nilai + (int) $b->nilai, 'n' => (int) $a->n + (int) $b->n];
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
            ->where('j.tenant_id', $t->id)->where('j.status', 'sah')->whereNotNull('i.produk_id')
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
        $mart = MartJual::sah()->where('tenant_id', $t->id)
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
            // Barang yang terjual TANPA harga modal tercatat. Angkanya tidak
            // dipakai menghitung apa pun — ia dipajang apa adanya sebagai
            // peringatan, karena setiap rupiah di sini masuk ke laba seolah
            // modalnya nol dan membuat laba tampak lebih besar dari yang benar.
            'tanpa_modal' => $this->tanpaModal($t, $this->sejak($periode)),
        ]);
    }
}
