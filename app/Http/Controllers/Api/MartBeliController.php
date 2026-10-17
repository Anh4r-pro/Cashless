<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\ApiError;
use App\Models\MartBeli;
use App\Models\MartBeliBayar;
use App\Models\MartBeliItem;
use App\Models\MartKas;
use App\Models\MartSupplier;
use App\Models\Produk;
use App\Models\Tenant;
use App\Services\Ekspor;
use App\Services\Rupiah;
use App\Services\Stok;
use App\Support\WaktuIso;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Pemasok & pembelian IMBOS Mart.
 *
 * "Supplier" yang berguna bukan sekadar daftar nama. Yang dibutuhkan toko
 * adalah NOTA: barang apa yang datang, berapa harganya, dari siapa, sudah
 * dibayar atau belum. Tanpa itu, harga modal di sistem hanya angka yang
 * diketik sekali lalu tidak pernah benar lagi — dan seluruh laporan laba
 * berdiri di atasnya.
 *
 * Tiga hal yang dikerjakan satu nota sekaligus, dan karena itu bisa
 * dibatalkan sekaligus pula:
 *
 *   1. Stok naik, lewat Services\Stok seperti semua pergerakan stok lain.
 *   2. Harga modal (`produk.hpp`) diperbarui ke harga beli terbaru. Harga
 *      modal LAMA disimpan di barisnya, supaya pembatalan bisa
 *      mengembalikannya apa adanya alih-alih menebak.
 *   3. Kewajiban ke pemasok lahir: lunas kalau dibayar tunai/transfer saat
 *      itu, atau hutang kalau bertempo.
 *
 * Yang dipakai harga beli TERBARU, bukan rata-rata tertimbang. Rata-rata
 * lebih tepat di atas kertas, tetapi tidak ada orang di toko yang bisa
 * memeriksanya terhadap nota di tangannya — dan angka yang tidak bisa
 * diperiksa siapa pun adalah angka yang akhirnya tidak dipercaya siapa pun.
 */
class MartBeliController extends Controller
{
    private string $operator = 'Kasir Mart';

    private function martKu(Request $r): Tenant
    {
        $u = $this->wajibPeran($r, 'tenant');
        $this->operator = (string) ($u->nama ?: 'Kasir Mart');
        $t = Tenant::find($u->tenant_id);
        if (! $t || ($t->mode_kasir ?? 'menu') !== 'mart') {
            throw new ApiError(403, 'Akun ini bukan kasir IMBOS Mart');
        }

        return $t;
    }

    // ------------------------------------------------------------ pemasok

    public function supplier(Request $r)
    {
        $t = $this->martKu($r);
        $hutang = $this->hutangPerSupplier($t);

        return response()->json([
            'supplier' => MartSupplier::where('tenant_id', $t->id)->orderBy('nama')->get()
                ->map(fn ($s) => array_merge($s->toArray(), [
                    'hutang' => (int) ($hutang[$s->id] ?? 0),
                ])),
            'ringkas' => [
                'n_supplier' => MartSupplier::where('tenant_id', $t->id)->count(),
                'hutang_total' => array_sum($hutang),
            ],
        ]);
    }

    /**
     * Sisa hutang ke tiap pemasok — satu kueri, bukan satu per pemasok.
     *
     * Pembayarannya disaring ke nota BERTEMPO saja. Nota tunai juga melahirkan
     * baris pembayaran (itulah yang membuat uangnya tercatat keluar), dan
     * kalau ikut dikurangkan di sini, membayar tunai satu nota akan terlihat
     * seolah melunasi hutang nota yang lain.
     */
    private function hutangPerSupplier(Tenant $t): array
    {
        $beli = MartBeli::sah()->where('tenant_id', $t->id)->where('metode', 'tempo')
            ->whereNotNull('supplier_id')
            ->selectRaw('supplier_id, SUM(total) n')->groupBy('supplier_id')->pluck('n', 'supplier_id');
        $bayar = DB::table('mart_beli_bayar as b')
            ->join('mart_beli as n', 'n.id', '=', 'b.beli_id')
            ->where('b.tenant_id', $t->id)->where('n.metode', 'tempo')->where('n.status', 'sah')
            ->whereNotNull('b.supplier_id')
            ->selectRaw('b.supplier_id, SUM(b.jumlah) n')->groupBy('b.supplier_id')
            ->pluck('n', 'supplier_id');

        $hasil = [];
        foreach ($beli as $id => $n) {
            $hasil[(int) $id] = max(0, (int) $n - (int) ($bayar[$id] ?? 0));
        }

        return $hasil;
    }

    public function supplierSimpan(Request $r, ?int $id = null)
    {
        $t = $this->martKu($r);
        $nama = mb_substr(trim(preg_replace('/\s+/u', ' ', (string) $r->input('nama', ''))), 0, 120);
        if (mb_strlen($nama) < 3) {
            throw new ApiError(400, 'Nama sales minimal 3 huruf');
        }
        $kembar = MartSupplier::where('tenant_id', $t->id)
            ->whereRaw('LOWER(nama) = ?', [mb_strtolower($nama)])
            ->when($id, fn ($q) => $q->where('id', '!=', $id))->first();
        if ($kembar) {
            throw new ApiError(409, "Sales bernama \"{$nama}\" sudah ada");
        }

        $isi = [
            'nama' => $nama,
            'hp' => mb_substr(preg_replace('/[^0-9+]/', '', (string) $r->input('hp', '')), 0, 25),
            'alamat' => mb_substr(trim((string) $r->input('alamat', '')), 0, 200),
            'keterangan' => mb_substr(trim((string) $r->input('keterangan', '')), 0, 160),
            'aktif' => $r->input('aktif') === null ? true : (bool) $r->input('aktif'),
        ];

        if ($id) {
            $s = MartSupplier::where('id', $id)->where('tenant_id', $t->id)->first();
            if (! $s) {
                throw new ApiError(404, 'Sales tidak ditemukan');
            }
            $s->update($isi);
        } else {
            $s = MartSupplier::create(array_merge($isi, [
                'tenant_id' => $t->id,
                'kode' => 'S-' . str_pad((string) (MartSupplier::where('tenant_id', $t->id)
                    ->count() + 1), 4, '0', STR_PAD_LEFT),
                'created_at' => WaktuIso::sekarang(),
            ]));
        }

        return response()->json(['ok' => true, 'supplier' => $s->fresh()]);
    }

    public function supplierHapus(Request $r, int $id)
    {
        $t = $this->martKu($r);
        $s = MartSupplier::where('id', $id)->where('tenant_id', $t->id)->first();
        if (! $s) {
            throw new ApiError(404, 'Sales tidak ditemukan');
        }
        $hutang = $this->hutangPerSupplier($t)[$s->id] ?? 0;
        if ($hutang > 0) {
            throw new ApiError(409, 'Masih ada hutang ' . Rupiah::f($hutang) .
                ' ke pemasok ini — lunasi dulu');
        }
        if (MartBeli::where('supplier_id', $s->id)->exists()) {
            $s->update(['aktif' => false]);

            return response()->json(['ok' => true, 'dinonaktifkan' => true,
                'pesan' => 'Sales punya riwayat nota, jadi dinonaktifkan — riwayatnya tetap utuh.']);
        }
        $s->delete();

        return response()->json(['ok' => true, 'dinonaktifkan' => false]);
    }

    // ---------------------------------------------------------- pembelian

    /**
     * Barang yang disuplai SATU sales, pada rentang tanggal tertentu.
     *
     * Alasannya disebut tim kasir di rapat: barang yang sama bisa berpindah
     * sales dari satu periode ke periode berikutnya. Daftar "barang milik
     * sales" yang tidak terikat periode karena itu menyesatkan — yang berguna
     * justru "apa saja yang DATANG dari sales ini, antara tanggal ini".
     *
     * Dikelompokkan per barang, bukan per baris nota: yang ditanya petugas
     * adalah "berapa banyak teh kotak dari dia bulan ini", bukan daftar
     * belasan nota yang harus dijumlah sendiri.
     *
     * Nota yang DIBATALKAN tidak ikut — barangnya tidak pernah benar-benar
     * datang.
     */
    public function barangSales(Request $r, int $id)
    {
        $t = $this->martKu($r);
        $sup = MartSupplier::where('id', $id)->where('tenant_id', $t->id)->first();
        if (! $sup) {
            throw new ApiError(404, 'Sales tidak ditemukan');
        }

        $tgl = fn ($v) => preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $v) && strtotime((string) $v)
            ? (string) $v : null;
        $dari = $tgl($r->query('dari'));
        $sampai = $tgl($r->query('sampai'));
        if ($dari && $sampai && $dari > $sampai) {
            [$dari, $sampai] = [$sampai, $dari];
        }

        $baris = DB::table('mart_beli_item as i')
            ->join('mart_beli as b', 'b.id', '=', 'i.beli_id')
            ->where('b.tenant_id', $t->id)->where('b.supplier_id', $sup->id)
            ->where('b.status', 'sah')
            ->when($dari, fn ($q) => $q->where('b.tanggal', '>=', $dari))
            ->when($sampai, fn ($q) => $q->where('b.tanggal', '<=', $sampai))
            ->groupBy('i.nama')
            ->selectRaw('i.nama, SUM(i.qty) qty, SUM(i.subtotal) nilai, COUNT(DISTINCT b.id) n_nota,
                MAX(b.tanggal) terakhir, MIN(b.tanggal) pertama,
                SUBSTRING_INDEX(GROUP_CONCAT(i.harga ORDER BY b.tanggal DESC, b.id DESC), ",", 1) harga_terakhir')
            ->orderByDesc('nilai')->get()
            ->map(fn ($b) => [
                'nama' => $b->nama, 'qty' => (int) $b->qty, 'nilai' => (int) $b->nilai,
                'n_nota' => (int) $b->n_nota, 'pertama' => $b->pertama, 'terakhir' => $b->terakhir,
                'harga_terakhir' => (int) $b->harga_terakhir,
            ])->all();

        $total = [
            'n_barang' => count($baris),
            'qty' => array_sum(array_column($baris, 'qty')),
            'nilai' => array_sum(array_column($baris, 'nilai')),
            'n_nota' => (int) MartBeli::where('tenant_id', $t->id)->where('supplier_id', $sup->id)
                ->where('status', 'sah')
                ->when($dari, fn ($q) => $q->where('tanggal', '>=', $dari))
                ->when($sampai, fn ($q) => $q->where('tanggal', '<=', $sampai))->count(),
        ];

        if ($r->query('format')) {
            return $this->barangSalesUnduhan($sup, $baris, $total, $dari, $sampai,
                Ekspor::formatSah($r->query('format')));
        }

        return response()->json([
            'sales' => ['id' => $sup->id, 'kode' => $sup->kode, 'nama' => $sup->nama],
            'dari' => $dari, 'sampai' => $sampai,
            'baris' => $baris, 'total' => $total,
        ]);
    }

    /** Barang per sales sebagai berkas unduhan (.xls / .doc / .csv). */
    private function barangSalesUnduhan(MartSupplier $sup, array $baris, array $total,
        ?string $dari, ?string $sampai, string $format)
    {
        $kepala = ['Barang', 'Qty', 'Nilai (Rp)', 'Harga beli terakhir (Rp)', 'Jumlah nota',
            'Pertama datang', 'Terakhir datang'];
        $isi = array_map(fn ($b) => [$b['nama'], $b['qty'], $b['nilai'], $b['harga_terakhir'],
            $b['n_nota'], $b['pertama'], $b['terakhir']], $baris);
        $isi[] = ['TOTAL', $total['qty'], $total['nilai'], '', $total['n_nota'], '', ''];

        $periode = $dari || $sampai
            ? ($dari ?: 'awal') . ' s/d ' . ($sampai ?: 'kini') : 'seluruh periode';
        $judul = 'Barang dari sales ' . $sup->nama . ' (' . $sup->kode . ')';
        $catatan = ["Periode: {$periode}", 'Nota yang dibatalkan tidak ikut dihitung.',
            'Nilai dihitung dari harga beli pada notanya, sebelum diskon nota.'];
        $nama = 'barang-sales-' . preg_replace('/[^A-Za-z0-9]+/', '-', $sup->kode) . ".{$format}";
        [$tipe] = Ekspor::TIPE[$format];

        $badan = $format === 'csv'
            ? Ekspor::csvUmum($kepala, array_merge($isi, [[]], [[$judul]],
                array_map(fn ($c) => [$c], $catatan)))
            : Ekspor::tabelUmum($judul, $kepala, $isi, $catatan, [1, 2, 3, 4]);

        return response($badan, 200, [
            'Content-Type' => $tipe,
            'Content-Disposition' => "attachment; filename=\"{$nama}\"",
        ]);
    }

    public function index(Request $r)
    {
        $t = $this->martKu($r);
        $nama = MartSupplier::where('tenant_id', $t->id)->pluck('nama', 'id');
        $bayar = MartBeliBayar::where('tenant_id', $t->id)
            ->selectRaw('beli_id, SUM(jumlah) n')->groupBy('beli_id')->pluck('n', 'beli_id');

        $q = MartBeli::where('tenant_id', $t->id)
            ->when($r->query('dari'), fn ($w) => $w->where('tanggal', '>=', substr((string) $r->query('dari'), 0, 10)))
            ->when($r->query('sampai'), fn ($w) => $w->where('tanggal', '<=', substr((string) $r->query('sampai'), 0, 10)))
            ->when($r->query('supplier_id'), fn ($w) => $w->where('supplier_id', (int) $r->query('supplier_id')))
            ->orderByDesc('id')->limit(200)->get();

        $agg = MartBeli::sah()->where('tenant_id', $t->id)
            ->when($r->query('dari'), fn ($w) => $w->where('tanggal', '>=', substr((string) $r->query('dari'), 0, 10)))
            ->when($r->query('sampai'), fn ($w) => $w->where('tanggal', '<=', substr((string) $r->query('sampai'), 0, 10)))
            ->selectRaw('COALESCE(SUM(total),0) total, COUNT(*) n')->first();

        return response()->json([
            'beli' => $q->map(function ($b) use ($nama, $bayar) {
                $sudah = (int) ($bayar[$b->id] ?? 0);

                return array_merge($b->toArray(), [
                    'supplier' => $b->supplier_id ? ($nama[$b->supplier_id] ?? '(pemasok terhapus)') : '—',
                    'dibayar' => $sudah,
                    'sisa' => $b->status === 'sah' ? max(0, (int) $b->total - $sudah) : 0,
                ]);
            }),
            'ringkas' => [
                'n_nota' => (int) $agg->n, 'nilai' => (int) $agg->total,
                'hutang_total' => array_sum($this->hutangPerSupplier($t)),
            ],
        ]);
    }

    public function detail(Request $r, int $id)
    {
        $t = $this->martKu($r);
        $b = MartBeli::where('id', $id)->where('tenant_id', $t->id)->first();
        if (! $b) {
            throw new ApiError(404, 'Nota tidak ditemukan');
        }
        $s = $b->supplier_id ? MartSupplier::find($b->supplier_id) : null;
        $bayar = MartBeliBayar::where('beli_id', $b->id)->orderBy('id')->get();

        return response()->json([
            'beli' => array_merge($b->toArray(), [
                'supplier' => $s?->nama ?? '—',
                'dibayar' => (int) $bayar->sum('jumlah'),
                'sisa' => $b->status === 'sah' ? max(0, (int) $b->total - (int) $bayar->sum('jumlah')) : 0,
                'terkunci' => $this->kasTerkunci($t, (string) $b->tanggal),
            ]),
            'item' => MartBeliItem::where('beli_id', $b->id)->orderBy('id')->get(),
            'bayar' => $bayar,
        ]);
    }

    private function kasTerkunci(Tenant $t, string $tanggal): bool
    {
        return MartKas::where('tenant_id', $t->id)->where('tanggal', $tanggal)->exists();
    }

    /**
     * Catat nota pembelian: barang masuk, harga modal diperbarui, kewajiban
     * ke pemasok lahir — satu langkah, satu transaksi basis data.
     */
    public function simpan(Request $r)
    {
        $t = $this->martKu($r);
        $items = $r->input('items');
        if (! is_array($items) || ! $items) {
            throw new ApiError(400, 'Nota kosong — tambahkan dulu barangnya');
        }
        if (count($items) > 100) {
            throw new ApiError(400, 'Maksimal 100 baris per nota — pecah notanya');
        }

        $supplier = MartSupplier::where('id', (int) $r->input('supplier_id'))
            ->where('tenant_id', $t->id)->first();
        if (! $supplier) {
            throw new ApiError(400, 'Pilih dulu sales-nya');
        }
        $metode = in_array($r->input('metode'), ['tunai', 'transfer', 'tempo'], true)
            ? $r->input('metode') : 'tunai';

        $baris = [];
        $subtotal = 0;
        foreach ($items as $it) {
            $p = Produk::where('id', (int) ($it['produk_id'] ?? 0))
                ->where('tenant_id', $t->id)->first();
            if (! $p) {
                throw new ApiError(404, 'Ada barang di nota yang bukan milik tenant ini');
            }
            $qty = (int) floor((float) ($it['qty'] ?? 0));
            $harga = (int) floor((float) ($it['harga'] ?? 0));
            if ($qty < 1 || $qty > 100000) {
                throw new ApiError(400, "Jumlah \"{$p->nama}\" tidak valid");
            }
            if ($harga < 0) {
                throw new ApiError(400, "Harga beli \"{$p->nama}\" tidak boleh negatif");
            }
            /*
             * Satuan barang yang DATANG: eceran (pcs) atau grosir (dus/karton).
             *
             * Harga yang diketik petugas adalah harga PER SATUAN YANG DATANG —
             * satu dus, bukan satu pcs. Isinya diambil dari master barang, dan
             * di sanalah pagarnya: barang yang belum disiapkan grosir tidak
             * bisa diterima per dus, karena isinya tidak diketahui dan stoknya
             * akan salah hitung tanpa ada yang menyadarinya.
             */
            $satuan = (($it['satuan'] ?? 'ecer') === 'grosir') ? 'grosir' : 'ecer';
            $isi = 1;
            if ($satuan === 'grosir') {
                $isi = (int) ($p->isi_grosir ?? 0);
                if ($isi < 2) {
                    throw new ApiError(409, "\"{$p->nama}\" belum punya isi per grosir — " .
                        'isi dulu di Barang & Stok, atau catat notanya dalam satuan eceran.');
                }
            }

            $exp = trim((string) ($it['expired'] ?? ''));
            if ($exp !== '' && (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $exp) || ! strtotime($exp))) {
                throw new ApiError(400, "Tanggal kedaluwarsa \"{$p->nama}\" tidak valid (YYYY-MM-DD)");
            }

            $baris[] = ['produk' => $p, 'qty' => $qty, 'harga' => $harga,
                'satuan' => $satuan, 'isi' => $isi, 'expired' => $exp ?: null];
            $subtotal += $harga * $qty;
        }

        $diskon = max(0, (int) floor((float) $r->input('diskon', 0)));
        if ($diskon > $subtotal) {
            throw new ApiError(400, 'Diskon tidak boleh melebihi nilai nota');
        }
        $total = $subtotal - $diskon;

        $tanggal = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $r->input('tanggal', ''))
            ? $r->input('tanggal') : WaktuIso::tanggalLokal(WaktuIso::sekarang());
        if ($this->kasTerkunci($t, $tanggal) && $metode !== 'tempo') {
            throw new ApiError(409, "Kas tanggal {$tanggal} sudah ditutup — " .
                'nota tunai/transfer bertanggal itu akan menggeser hitungan laci yang sudah selesai. ' .
                'Pakai tanggal hari ini, atau catat sebagai tempo lalu bayar terpisah.');
        }
        $tempo = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $r->input('jatuh_tempo', ''))
            ? $r->input('jatuh_tempo') : null;

        $op = $this->operator;
        $beli = DB::transaction(function () use ($t, $supplier, $baris, $subtotal, $diskon,
            $total, $metode, $tanggal, $tempo, $op, $r) {
            $beli = MartBeli::create([
                'tenant_id' => $t->id, 'supplier_id' => $supplier->id,
                'no_nota' => mb_substr(trim((string) $r->input('no_nota', '')), 0, 60),
                'metode' => $metode, 'status' => 'sah',
                'tanggal' => $tanggal, 'jatuh_tempo' => $metode === 'tempo' ? $tempo : null,
                'subtotal' => $subtotal, 'diskon' => $diskon, 'total' => $total,
                'n_item' => array_sum(array_map(fn ($b) => $b['qty'], $baris)),
                'catatan' => mb_substr(trim((string) $r->input('catatan', '')), 0, 200),
                'operator' => $op, 'created_at' => WaktuIso::sekarang(),
            ]);

            foreach ($baris as $b) {
                $p = $b['produk'];
                MartBeliItem::create([
                    'beli_id' => $beli->id, 'produk_id' => $p->id, 'nama' => $p->nama,
                    'qty' => $b['qty'], 'satuan' => $b['satuan'], 'isi' => $b['isi'],
                    'harga' => $b['harga'],
                    'subtotal' => $b['harga'] * $b['qty'],
                    'hpp_lama' => $p->hpp === null ? null : (int) $p->hpp,
                    'expired' => $b['expired'],
                    // Nilai SEBELUM nota ini, supaya pembatalan bisa
                    // memulihkannya — pola yang sama dengan hpp_lama.
                    'expired_lama' => $p->expired,
                ]);
                // Stok NULL berarti barang itu tidak dilacak. Nota pembelian
                // adalah saat yang tepat untuk mulai melacaknya.
                if ($p->stok === null) {
                    $p->update(['stok' => 0]);
                }
                // Stok selalu dalam satuan eceran: 3 dus berisi 12 menambah 36 pcs.
                Stok::masuk($p->id, $b['qty'] * $b['isi'],
                    'nota ' . ($beli->no_nota ?: '#' . $beli->id) . ' · ' . $supplier->nama .
                    ($b['satuan'] === 'grosir' ? " ({$b['qty']} grosir × {$b['isi']})" : ''), $op);
                if ($b['expired']) {
                    $p->update(['expired' => $b['expired']]);
                }
                if ($b['harga'] > 0) {
                    /*
                     * Harga modal diperbarui dari harga beli di nota ini.
                     *
                     * Nota grosir memperbarui DUA kolom sekaligus: modal
                     * grosirnya apa adanya, dan modal ecerannya dibagi isi.
                     * Membiarkan modal eceran tetap pada angka lama akan
                     * membuat laba penjualan satuan dihitung dengan harga beli
                     * yang sudah tidak berlaku.
                     */
                    if ($b['satuan'] === 'grosir') {
                        $p->update([
                            'hpp_grosir' => $b['harga'],
                            'hpp' => (int) round($b['harga'] / max(1, $b['isi'])),
                        ]);
                    } else {
                        $p->update(['hpp' => $b['harga']]);
                    }
                }
            }

            // Dibayar saat itu juga → langsung tercatat sebagai uang keluar
            // hari ini, dan tutup kas hari ini yang akan memperhitungkannya.
            if ($metode !== 'tempo' && $total > 0) {
                MartBeliBayar::create([
                    'beli_id' => $beli->id, 'tenant_id' => $t->id, 'supplier_id' => $supplier->id,
                    'jumlah' => $total, 'metode' => $metode,
                    'bank' => $metode === 'transfer'
                        ? mb_substr(trim((string) $r->input('bank', '')), 0, 40) : '',
                    'catatan' => 'dibayar saat nota dicatat',
                    'operator' => $op, 'created_at' => WaktuIso::sekarang(),
                ]);
            }

            return $beli;
        });

        return response()->json(['ok' => true, 'beli' => $beli->fresh(),
            'item' => MartBeliItem::where('beli_id', $beli->id)->get()]);
    }

    /**
     * Batalkan nota: stok ditarik kembali dan harga modal dikembalikan ke
     * angka sebelum nota ini. Barisnya TIDAK dihapus — nota yang pernah
     * dicatat tidak boleh hilang tanpa bekas.
     */
    public function batal(Request $r, int $id)
    {
        $t = $this->martKu($r);
        $b = MartBeli::where('id', $id)->where('tenant_id', $t->id)->first();
        if (! $b) {
            throw new ApiError(404, 'Nota tidak ditemukan');
        }
        if ($b->status !== 'sah') {
            throw new ApiError(409, 'Nota ini sudah dibatalkan');
        }
        if (MartBeliBayar::where('beli_id', $b->id)->exists()) {
            throw new ApiError(409, 'Nota ini sudah ada pembayarannya — ' .
                'uangnya sudah keluar dan mungkin sudah ikut ditutup di kas hari itu. ' .
                'Selesaikan dengan pemasok, lalu catat koreksinya sebagai nota tersendiri.');
        }
        if ($this->kasTerkunci($t, (string) $b->tanggal)) {
            throw new ApiError(409, "Kas tanggal {$b->tanggal} sudah ditutup — nota itu terkunci");
        }
        $alasan = mb_substr(trim((string) $r->input('alasan', '')), 0, 200);
        if (mb_strlen($alasan) < 4) {
            throw new ApiError(400, 'Tulis dulu alasannya (minimal 4 huruf)');
        }

        $op = $this->operator;
        DB::transaction(function () use ($b, $alasan, $op) {
            foreach (MartBeliItem::where('beli_id', $b->id)->get() as $it) {
                if (! $it->produk_id) {
                    continue;
                }
                // Ditarik sebanyak yang DULU masuk: 3 dus berisi 12 menambah
                // 36 pcs, jadi pembatalannya juga 36 — bukan 3.
                Stok::koreksi((int) $it->produk_id, -(int) $it->qty * max(1, (int) $it->isi),
                    'batal nota #' . $b->id . ': ' . $alasan, $op);
                // Harga modal dikembalikan ke angka SEBELUM nota ini, bukan
                // dihitung mundur — angka lamanya memang sudah disimpan.
                if ($it->hpp_lama !== null) {
                    Produk::where('id', $it->produk_id)->update(['hpp' => $it->hpp_lama]);
                }
                // Begitu juga tanggal kedaluwarsanya: nota yang dibatalkan
                // tidak boleh meninggalkan tanggal dari barang yang ternyata
                // tidak pernah jadi datang.
                if ($it->expired !== null) {
                    Produk::where('id', $it->produk_id)->update(['expired' => $it->expired_lama]);
                }
            }
            $b->update(['status' => 'batal',
                'catatan' => mb_substr(trim($b->catatan . ' · BATAL: ' . $alasan), 0, 200)]);
        });

        return response()->json(['ok' => true, 'beli' => $b->fresh()]);
    }

    /** Bayar hutang ke pemasok. Uang KELUAR hari ini. */
    public function bayar(Request $r, int $id)
    {
        $t = $this->martKu($r);
        $b = MartBeli::where('id', $id)->where('tenant_id', $t->id)->first();
        if (! $b) {
            throw new ApiError(404, 'Nota tidak ditemukan');
        }
        if ($b->status !== 'sah') {
            throw new ApiError(409, 'Nota ini sudah dibatalkan');
        }
        $sudah = (int) MartBeliBayar::where('beli_id', $b->id)->sum('jumlah');
        $sisa = (int) $b->total - $sudah;
        if ($sisa <= 0) {
            throw new ApiError(409, 'Nota ini sudah lunas');
        }

        $jumlah = (int) floor((float) $r->input('jumlah', 0));
        if ($jumlah <= 0) {
            throw new ApiError(400, 'Nominal pembayaran harus lebih dari nol');
        }
        if ($jumlah > $sisa) {
            throw new ApiError(400, 'Pembayaran (' . Rupiah::f($jumlah) .
                ') melebihi sisa hutang ' . Rupiah::f($sisa));
        }
        $metode = $r->input('metode') === 'transfer' ? 'transfer' : 'tunai';

        $bayar = MartBeliBayar::create([
            'beli_id' => $b->id, 'tenant_id' => $t->id, 'supplier_id' => $b->supplier_id,
            'jumlah' => $jumlah, 'metode' => $metode,
            'bank' => $metode === 'transfer' ? mb_substr(trim((string) $r->input('bank', '')), 0, 40) : '',
            'catatan' => mb_substr(trim((string) $r->input('catatan', '')), 0, 160),
            'operator' => $this->operator, 'created_at' => WaktuIso::sekarang(),
        ]);

        return response()->json(['ok' => true, 'bayar' => $bayar,
            'sisa' => $sisa - $jumlah, 'lunas' => $sisa - $jumlah === 0]);
    }

    /** Hutang ke pemasok yang masih berjalan, jatuh tempo terdekat lebih dulu. */
    public function hutang(Request $r)
    {
        $t = $this->martKu($r);
        $nama = MartSupplier::where('tenant_id', $t->id)->pluck('nama', 'id');
        $bayar = MartBeliBayar::where('tenant_id', $t->id)
            ->selectRaw('beli_id, SUM(jumlah) n')->groupBy('beli_id')->pluck('n', 'beli_id');
        $hariIni = WaktuIso::tanggalLokal(WaktuIso::sekarang());

        $baris = MartBeli::sah()->where('tenant_id', $t->id)->where('metode', 'tempo')
            ->orderBy('jatuh_tempo')->orderBy('id')->get()
            ->map(function ($b) use ($bayar, $nama, $hariIni) {
                $sudah = (int) ($bayar[$b->id] ?? 0);

                return [
                    'id' => (int) $b->id, 'no_nota' => $b->no_nota ?: '#' . $b->id,
                    'tanggal' => $b->tanggal, 'jatuh_tempo' => $b->jatuh_tempo,
                    'supplier' => $b->supplier_id ? ($nama[$b->supplier_id] ?? '(pemasok terhapus)') : '—',
                    'total' => (int) $b->total, 'dibayar' => $sudah,
                    'sisa' => max(0, (int) $b->total - $sudah),
                    // Negatif = masih ada sisa hari; positif = sudah lewat.
                    'telat_hari' => $b->jatuh_tempo
                        ? (int) floor((strtotime($hariIni) - strtotime($b->jatuh_tempo)) / 86400) : null,
                ];
            })
            ->filter(fn ($b) => $b['sisa'] > 0)->values();

        return response()->json(['hutang' => $baris, 'total_sisa' => (int) $baris->sum('sisa')]);
    }
}
