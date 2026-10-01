<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\ApiError;
use App\Models\MartKategori;
use App\Models\MartStokMutasi;
use App\Models\Produk;
use App\Models\Tenant;
use App\Services\Stok;
use App\Support\WaktuIso;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Manajemen barang & stok IMBOS Mart.
 *
 * Bedanya dengan menu tenant biasa: DI SINI TIDAK ADA PENGAJUAN. Kantin
 * mengusulkan menu lalu menunggu admin karena harga mereka menentukan
 * berapa santri dibebani oleh pihak luar. Mart adalah unit usaha yayasan
 * sendiri — menunggu persetujuan untuk mengubah harga barang sendiri hanya
 * menghambat tanpa menambah pengamanan apa pun. Yang tetap dijaga: seluruh
 * barang & mutasinya tetap terbaca penuh oleh admin pusat lewat laporan.
 */
class MartStokController extends Controller
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

    // ------------------------------------------------------------- kategori

    public function kategori(Request $r)
    {
        $t = $this->martKu($r);

        return response()->json(['kategori' => $this->daftarKategori($t)]);
    }

    private function daftarKategori(Tenant $t)
    {
        $n = Produk::where('tenant_id', $t->id)->whereNotNull('kategori_id')
            ->selectRaw('kategori_id, COUNT(*) n')->groupBy('kategori_id')->pluck('n', 'kategori_id');

        return MartKategori::where('tenant_id', $t->id)->orderBy('urutan')->orderBy('id')->get()
            ->map(fn ($k) => array_merge($k->toArray(), ['jumlah' => (int) ($n[$k->id] ?? 0)]));
    }

    public function kategoriSimpan(Request $r, ?int $id = null)
    {
        $t = $this->martKu($r);
        $nama = mb_substr(trim((string) $r->input('nama', '')), 0, 40);
        if ($nama === '') {
            throw new ApiError(400, 'Nama kategori wajib diisi');
        }
        $q = MartKategori::where('tenant_id', $t->id)->whereRaw('LOWER(nama) = ?', [mb_strtolower($nama)]);
        if ($id) {
            $q->where('id', '!=', $id);
        }
        if ($q->exists()) {
            throw new ApiError(409, "Kategori \"{$nama}\" sudah ada");
        }

        if ($id) {
            $k = MartKategori::where('id', $id)->where('tenant_id', $t->id)->first();
            if (! $k) {
                throw new ApiError(404, 'Kategori tidak ditemukan');
            }
            $k->update(['nama' => $nama,
                'aktif' => $r->input('aktif') === null ? $k->aktif : (bool) $r->input('aktif')]);
        } else {
            $k = MartKategori::create([
                'tenant_id' => $t->id, 'nama' => $nama, 'aktif' => true,
                'urutan' => (int) MartKategori::where('tenant_id', $t->id)->max('urutan') + 1,
                'created_at' => WaktuIso::sekarang(),
            ]);
        }

        return response()->json(['ok' => true, 'kategori' => $k->fresh()]);
    }

    public function kategoriHapus(Request $r, int $id)
    {
        $t = $this->martKu($r);
        $k = MartKategori::where('id', $id)->where('tenant_id', $t->id)->first();
        if (! $k) {
            throw new ApiError(404, 'Kategori tidak ditemukan');
        }
        // Barangnya tidak ikut terhapus — kategorinya saja yang dilepas, supaya
        // menghapus label tidak pernah berarti menghapus dagangan.
        Produk::where('tenant_id', $t->id)->where('kategori_id', $k->id)->update(['kategori_id' => null]);
        $k->delete();

        return response()->json(['ok' => true]);
    }

    // --------------------------------------------------------------- barang

    public function index(Request $r)
    {
        $t = $this->martKu($r);
        $q = Produk::where('tenant_id', $t->id);

        if ($cari = trim((string) $r->query('cari', ''))) {
            $like = '%' . $cari . '%';
            $q->where(fn ($w) => $w->where('nama', 'like', $like)->orWhere('barcode', 'like', $like));
        }
        if ($kat = $r->query('kategori_id')) {
            $q->where('kategori_id', (int) $kat);
        }
        if ($r->query('status') === 'menipis') {
            $q->whereNotNull('stok')->whereColumn('stok', '<=', 'stok_min')->where('stok', '>', 0);
        } elseif ($r->query('status') === 'habis') {
            $q->whereNotNull('stok')->where('stok', '<=', 0);
        }

        $kat = MartKategori::where('tenant_id', $t->id)->pluck('nama', 'id');

        return response()->json([
            'produk' => $q->orderBy('nama')->get()->map(fn ($p) => $this->baris($p, $kat)),
            'kategori' => $this->daftarKategori($t),
            'ringkas' => $this->ringkasStok($t),
        ]);
    }

    private function baris(Produk $p, $kat): array
    {
        $hpp = (int) ($p->hpp ?? 0);
        $harga = (int) $p->harga;

        return [
            'id' => $p->id, 'nama' => $p->nama, 'barcode' => $p->barcode,
            'kategori_id' => $p->kategori_id, 'kategori_nama' => $kat[$p->kategori_id] ?? null,
            'kategori' => $p->kategori,                 // makanan / non_makanan (penentu jatah santri)
            'hpp' => $hpp, 'harga' => $harga,
            // Margin dihitung atas HARGA JUAL (bukan atas modal): itu yang
            // lazim dibaca pemilik toko sebagai "berapa persen untungnya".
            'margin' => $harga > 0 && $hpp > 0 ? round(($harga - $hpp) / $harga * 100, 1) : null,
            'untung' => $hpp > 0 ? $harga - $hpp : null,
            'stok' => $p->stok, 'stok_min' => (int) $p->stok_min,
            'status' => Stok::status($p->stok, $p->stok_min),
            'aktif' => (int) $p->aktif,
        ];
    }

    private function ringkasStok(Tenant $t): array
    {
        $p = Produk::where('tenant_id', $t->id)->whereNotNull('stok');

        return [
            'n_barang' => Produk::where('tenant_id', $t->id)->count(),
            'n_habis' => (clone $p)->where('stok', '<=', 0)->count(),
            'n_menipis' => (clone $p)->whereColumn('stok', '<=', 'stok_min')->where('stok', '>', 0)->count(),
            // Nilai modal yang sedang "tidur" di rak — angka yang dicari
            // yayasan saat menanyakan berapa uang tertanam di Mart.
            'nilai_stok' => (int) (clone $p)->selectRaw('COALESCE(SUM(stok * COALESCE(hpp,0)),0) n')->value('n'),
        ];
    }

    public function simpan(Request $r, ?int $id = null)
    {
        $t = $this->martKu($r);
        $b = $r->all();

        $nama = mb_substr(trim(preg_replace('/\s+/u', ' ', (string) ($b['nama'] ?? ''))), 0, 120);
        if (mb_strlen($nama) < 2) {
            throw new ApiError(400, 'Nama barang minimal 2 huruf');
        }
        $harga = (int) floor((float) ($b['harga'] ?? 0));
        if ($harga <= 0) {
            throw new ApiError(400, 'Harga jual harus lebih dari nol');
        }
        // `?? null` bukan hiasan: `$b['hpp']` pada kiriman yang tidak menyebut
        // kolom ini memicu peringatan PHP, dan Laravel mengubah peringatan jadi
        // galat 500 — permintaan sah akan gagal tanpa sebab yang terbaca.
        $hppMentah = $b['hpp'] ?? null;
        $hpp = $hppMentah === null || $hppMentah === '' ? null : (int) floor((float) $hppMentah);
        if ($hpp !== null && $hpp < 0) {
            throw new ApiError(400, 'Harga beli tidak boleh negatif');
        }
        // Rugi per penjualan hampir selalu salah ketik, tetapi kadang memang
        // disengaja (barang promo). Ditolak keras akan menghalangi yang kedua,
        // jadi cukup dibiarkan — laporan laba yang akan menunjukkannya.

        $barcode = preg_replace('/[^0-9A-Za-z]/', '', (string) ($b['barcode'] ?? ''));
        if ($barcode !== '') {
            if (strlen($barcode) < 4 || strlen($barcode) > 32) {
                throw new ApiError(400, 'SKU/barcode 4–32 karakter angka/huruf');
            }
            $bentrok = Produk::where('tenant_id', $t->id)->where('barcode', $barcode)
                ->when($id, fn ($q) => $q->where('id', '!=', $id))->first();
            if ($bentrok) {
                throw new ApiError(409, "SKU/barcode itu sudah dipakai \"{$bentrok->nama}\"");
            }
        }

        $namaBentrok = Produk::where('tenant_id', $t->id)->whereRaw('LOWER(nama) = ?', [mb_strtolower($nama)])
            ->when($id, fn ($q) => $q->where('id', '!=', $id))->first();
        if ($namaBentrok) {
            throw new ApiError(409, "Barang bernama \"{$nama}\" sudah ada");
        }

        $katId = $b['kategori_id'] ?? null;
        if ($katId && ! MartKategori::where('id', $katId)->where('tenant_id', $t->id)->exists()) {
            throw new ApiError(400, 'Kategori tidak dikenal');
        }
        // Kategori jatah santri: hanya makanan/non_makanan, dan hanya tenant
        // berjenis koperasi yang boleh punya non-makanan (aturan lama).
        $jatah = ($b['kategori'] ?? 'makanan') === 'non_makanan' && $t->jenis === 'koperasi'
            ? 'non_makanan' : 'makanan';

        $isi = [
            'tenant_id' => $t->id, 'nama' => $nama, 'harga' => $harga, 'hpp' => $hpp,
            'barcode' => $barcode ?: null, 'kategori_id' => $katId ?: null, 'kategori' => $jatah,
            'stok_min' => max(0, (int) ($b['stok_min'] ?? 5)),
            'aktif' => array_key_exists('aktif', $b) ? (bool) $b['aktif'] : true,
        ];

        if ($id) {
            $p = Produk::where('id', $id)->where('tenant_id', $t->id)->first();
            if (! $p) {
                throw new ApiError(404, 'Barang tidak ditemukan');
            }
            // Stok TIDAK diubah lewat formulir barang: ia hanya boleh bergerak
            // lewat penjualan, barang masuk, koreksi, atau opname — semuanya
            // meninggalkan jejak. Mengizinkannya di sini akan membuat angka
            // stok bisa berubah tanpa siapa pun tahu sebabnya.
            $p->update($isi);
        } else {
            $isi['stok'] = array_key_exists('stok', $b) && $b['stok'] !== null && $b['stok'] !== ''
                ? max(0, (int) $b['stok']) : 0;
            $p = Produk::create($isi);
            if ($p->stok > 0) {
                MartStokMutasi::create([
                    'tenant_id' => $t->id, 'produk_id' => $p->id, 'jenis' => 'masuk',
                    'qty' => $p->stok, 'stok_sesudah' => $p->stok,
                    'nilai' => $p->stok * (int) ($hpp ?? 0), 'ref' => '',
                    'keterangan' => 'stok awal saat barang dibuat',
                    'operator' => $this->operator, 'created_at' => WaktuIso::sekarang(),
                ]);
            }
        }

        $kat = MartKategori::where('tenant_id', $t->id)->pluck('nama', 'id');

        return response()->json(['ok' => true, 'produk' => $this->baris($p->fresh(), $kat)]);
    }

    public function hapus(Request $r, int $id)
    {
        $t = $this->martKu($r);
        $p = Produk::where('id', $id)->where('tenant_id', $t->id)->first();
        if (! $p) {
            throw new ApiError(404, 'Barang tidak ditemukan');
        }
        // Barang yang pernah terjual TIDAK dihapus, hanya dinonaktifkan:
        // menghapusnya akan melubangi riwayat penjualan & laporan laba yang
        // sudah terlanjur menyebut barang itu.
        $pernah = DB::table('mart_jual_item')->where('produk_id', $p->id)->exists()
            || DB::table('transaksi_item')->where('produk_id', $p->id)->exists();
        if ($pernah) {
            $p->update(['aktif' => false]);

            return response()->json(['ok' => true, 'dinonaktifkan' => true,
                'pesan' => 'Barang pernah terjual, jadi dinonaktifkan (tidak muncul di kasir) — riwayat penjualannya tetap utuh.']);
        }
        MartStokMutasi::where('produk_id', $p->id)->delete();
        $p->delete();

        return response()->json(['ok' => true, 'dinonaktifkan' => false]);
    }

    /** Barang masuk dari pemasok, atau koreksi manual. */
    public function stokMasuk(Request $r, int $id)
    {
        $t = $this->martKu($r);
        $p = Produk::where('id', $id)->where('tenant_id', $t->id)->first();
        if (! $p) {
            throw new ApiError(404, 'Barang tidak ditemukan');
        }
        $qty = (int) floor((float) $r->input('qty', 0));
        if ($qty === 0) {
            throw new ApiError(400, 'Jumlah tidak boleh nol');
        }
        $ket = mb_substr(trim((string) $r->input('keterangan', '')), 0, 200);
        if ($p->stok === null) {
            $p->update(['stok' => 0]);     // mulai dilacak begitu ada pergerakan
        }

        if ($r->input('jenis') === 'koreksi') {
            Stok::koreksi($p->id, $qty, $ket ?: 'koreksi manual', $this->operator);
        } else {
            if ($qty < 0) {
                throw new ApiError(400, 'Barang masuk tidak boleh negatif — pakai koreksi bila perlu mengurangi');
            }
            Stok::masuk($p->id, $qty, $ket, $this->operator);
        }

        $kat = MartKategori::where('tenant_id', $t->id)->pluck('nama', 'id');

        return response()->json(['ok' => true, 'produk' => $this->baris($p->fresh(), $kat)]);
    }

    /** Riwayat pergerakan stok satu barang. */
    public function mutasi(Request $r, int $id)
    {
        $t = $this->martKu($r);

        return response()->json(['mutasi' => MartStokMutasi::where('tenant_id', $t->id)
            ->where('produk_id', $id)->orderByDesc('id')->limit(60)->get()]);
    }
}
