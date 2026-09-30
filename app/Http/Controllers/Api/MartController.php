<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\ApiError;
use App\Models\MartJual;
use App\Models\MartJualItem;
use App\Models\MartKas;
use App\Models\Produk;
use App\Models\Tenant;
use App\Models\Transaksi;
use App\Services\Kartu;
use App\Services\Keranjang;
use App\Services\Pengaturan;
use App\Services\Rupiah;
use App\Support\WaktuIso;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * IMBOS Mart — kasir swalayan (pindai barcode, qty, bayar kartu atau tunai).
 *
 * Yang TIDAK ada di sini: pembayaran kartu. Itu tetap lewat KasirController
 * (`POST /api/beli`) yang sudah teruji — mulai dari batas harian golongan
 * sampai pencatatan neraca. Layar Mart hanya memanggilnya. Yang baru di
 * berkas ini cuma yang memang belum pernah ada: pencarian barcode, penjualan
 * TUNAI (jalur uang terpisah dari kas sekolah), dan penutupan kas harian.
 */
class MartController extends Controller
{
    /** Nama operator dari permintaan terakhir yang lolos martKu(). */
    private string $operator = 'Kasir Mart';

    /** Tenant milik akun yang login, dan ia harus memang berjenis Mart. */
    private function martKu(Request $r): Tenant
    {
        $u = $this->wajibPeran($r, 'tenant');
        $this->operator = (string) ($u->nama ?: 'Kasir Mart');
        $t = Tenant::find($u->tenant_id);
        if (! $t) {
            throw new ApiError(404, 'Tenant tidak ditemukan');
        }
        if (($t->mode_kasir ?? 'menu') !== 'mart') {
            throw new ApiError(403, 'Akun ini bukan kasir IMBOS Mart');
        }
        if ($t->status !== 'aktif') {
            throw new ApiError(403, 'Tenant sedang nonaktif');
        }

        return $t;
    }

    /** Tanggal hari ini menurut zona sekolah — dipakai sebagai kunci kas harian. */
    private function hariIni(): string
    {
        return Kartu::hariIni();
    }

    public function info(Request $r)
    {
        $t = $this->martKu($r);
        $tgl = $this->hariIni();

        return response()->json([
            'tenant' => ['id' => $t->id, 'nama' => $t->nama, 'jenis' => $t->jenis],
            'tanggal' => $tgl,
            'hari_ini' => $this->ringkasHari($t, $tgl),
            'kas' => MartKas::where('tenant_id', $t->id)->where('tanggal', $tgl)->first(),
            'kas_kemarin' => MartKas::where('tenant_id', $t->id)->where('tanggal', '<', $tgl)
                ->orderByDesc('tanggal')->first(),
            'n_produk' => Produk::where('tenant_id', $t->id)->where('aktif', 1)->count(),
            'n_barcode' => Produk::where('tenant_id', $t->id)->where('aktif', 1)
                ->whereNotNull('barcode')->where('barcode', '!=', '')->count(),
        ]);
    }

    /**
     * Omzet hari berjalan, dipecah menurut jalur uangnya — angka tunai dan
     * cashless sengaja TIDAK dijumlahkan jadi satu di sini, karena keduanya
     * berada di tempat yang berbeda: satu di laci, satu di kas sekolah.
     */
    private function ringkasHari(Tenant $t, string $tgl): array
    {
        [$mulai, $akhir] = [WaktuIso::awalHariIni(), null];
        $tunai = MartJual::where('tenant_id', $t->id)->where('created_at', '>=', $mulai)
            ->selectRaw('COALESCE(SUM(total),0) total, COUNT(*) n')->first();
        $cashless = Transaksi::where('tenant_id', $t->id)->where('tipe', 'beli')
            ->where('created_at', '>=', $mulai)
            ->selectRaw('COALESCE(SUM(-jumlah),0) total, COUNT(*) n')->first();

        return [
            'tunai' => ['total' => (int) $tunai->total, 'n' => (int) $tunai->n],
            'cashless' => ['total' => (int) $cashless->total, 'n' => (int) $cashless->n],
        ];
    }

    /**
     * Cari barang: barcode PERSIS lebih dulu, baru nama.
     *
     * Barcode diperiksa lebih dulu dan dikembalikan sebagai `cocok` tunggal
     * supaya hasil pindaian langsung masuk keranjang tanpa kasir memilih apa
     * pun — itulah inti alur swalayan.
     */
    public function cari(Request $r)
    {
        $t = $this->martKu($r);
        $q = trim((string) $r->input('q', ''));
        if ($q === '') {
            throw new ApiError(400, 'Ketik barcode atau nama barang');
        }

        $dasar = fn () => Produk::where('tenant_id', $t->id)->where('aktif', 1);

        $kode = preg_replace('/[^0-9A-Za-z]/', '', $q);
        if ($kode !== '') {
            $persis = (clone $dasar())->where('barcode', $kode)->first();
            if ($persis) {
                return response()->json(['ok' => true, 'cocok' => $this->ringkasProduk($persis), 'daftar' => []]);
            }
        }

        $daftar = (clone $dasar())->where('nama', 'like', '%' . $q . '%')
            ->orderBy('nama')->limit(8)->get()->map(fn ($p) => $this->ringkasProduk($p));

        return response()->json([
            'ok' => true,
            'cocok' => null,
            'daftar' => $daftar,
            // Dipakai layar untuk menawarkan "tempelkan barcode ini ke barang
            // mana?" — supaya pendataan barcode terjadi sambil melayani,
            // bukan jadi proyek input data tersendiri di awal.
            'barcode_baru' => ($kode !== '' && $daftar->isEmpty() && preg_match('/^\d{6,}$/', $kode)) ? $kode : null,
        ]);
    }

    private function ringkasProduk(Produk $p): array
    {
        return ['id' => $p->id, 'nama' => $p->nama, 'harga' => (int) $p->harga,
            'kategori' => $p->kategori, 'barcode' => $p->barcode];
    }

    /**
     * Tempelkan barcode ke sebuah barang — LANGSUNG, tanpa pengajuan.
     *
     * Berbeda dari nama & harga yang harus disetujui admin karena menentukan
     * berapa santri dibebani, barcode hanya penanda: salah tempel pun tidak
     * mengubah harga sepeser pun, dan bisa dibetulkan seketika.
     */
    public function pasangBarcode(Request $r, int $id)
    {
        $t = $this->martKu($r);
        $p = Produk::where('id', $id)->where('tenant_id', $t->id)->first();
        if (! $p) {
            throw new ApiError(404, 'Barang tidak ditemukan');
        }
        $kode = preg_replace('/[^0-9A-Za-z]/', '', (string) $r->input('barcode', ''));
        if ($kode === '') {
            $p->update(['barcode' => null]);

            return response()->json(['ok' => true, 'produk' => $this->ringkasProduk($p->fresh())]);
        }
        if (strlen($kode) < 6 || strlen($kode) > 32) {
            throw new ApiError(400, 'Barcode 6–32 karakter angka/huruf');
        }
        $bentrok = Produk::where('tenant_id', $t->id)->where('barcode', $kode)
            ->where('id', '!=', $p->id)->first();
        if ($bentrok) {
            throw new ApiError(409, "Barcode ini sudah dipakai \"{$bentrok->nama}\"");
        }
        $p->update(['barcode' => $kode]);

        return response()->json(['ok' => true, 'produk' => $this->ringkasProduk($p->fresh())]);
    }

    /**
     * Penjualan TUNAI.
     *
     * Tidak menyentuh saldo siapa pun dan tidak masuk buku besar sekolah —
     * uangnya ada di laci Mart. Harga tetap diambil dari basis data lewat
     * Keranjang::ringkas (bukan dari angka yang dikirim layar), jadi jalur
     * ini sama ketatnya dengan pembayaran kartu soal harga.
     */
    public function jualTunai(Request $r)
    {
        $t = $this->martKu($r);
        $txId = $r->input('tx_id') ? mb_substr((string) $r->input('tx_id'), 0, 80) : null;

        if ($txId && $lama = MartJual::where('tx_id', $txId)->first()) {
            return response()->json(['ok' => true, 'duplikat' => true,
                'jual' => $lama, 'item' => MartJualItem::where('jual_id', $lama->id)->get()]);
        }

        $items = $r->input('items');
        if (! is_array($items) || ! $items) {
            throw new ApiError(400, 'Keranjang kosong');
        }
        // Batas belanja per golongan TIDAK berlaku di sini: itu aturan untuk
        // saldo kartu santri, sedangkan pembeli tunai bisa siapa saja.
        $x = Keranjang::ringkas($items, $t, Pengaturan::sekolah());

        $uang = (int) floor((float) $r->input('uang_diterima', 0));
        if ($uang < $x['total']) {
            throw new ApiError(400, 'Uang diterima (' . Rupiah::f($uang) . ') kurang dari total ' .
                Rupiah::f($x['total']));
        }
        $kembalian = $uang - $x['total'];

        $hasil = DB::transaction(function () use ($t, $x, $uang, $kembalian, $txId) {
            $jual = MartJual::create([
                'tenant_id' => $t->id, 'tx_id' => $txId, 'total' => $x['total'],
                'uang_diterima' => $uang, 'kembalian' => $kembalian,
                'n_item' => array_sum(array_map(fn ($b) => (int) ($b['qty'] ?? 1), $x['rincian'])),
                'operator' => $this->operator,
                'created_at' => WaktuIso::sekarang(),
            ]);
            foreach ($x['rincian'] as $b) {
                MartJualItem::create([
                    'jual_id' => $jual->id, 'produk_id' => $b['produk_id'] ?? null,
                    'nama' => $b['nama'], 'kategori' => $b['kategori'] ?? 'makanan',
                    'harga' => (int) $b['harga'], 'qty' => (int) ($b['qty'] ?? 1),
                    'subtotal' => (int) $b['subtotal'],
                ]);
            }

            return $jual;
        });

        return response()->json([
            'ok' => true, 'jual' => $hasil->fresh(),
            'item' => MartJualItem::where('jual_id', $hasil->id)->get(),
            'hari_ini' => $this->ringkasHari($t, $this->hariIni()),
        ]);
    }

    /**
     * Tutup kas harian: cocokkan hitungan fisik laci dengan yang seharusnya.
     *
     * Selisih TIDAK menggagalkan penutupan — justru itu yang harus tercatat.
     * Menolak menyimpan selisih hanya akan membuat orang membulatkan angka
     * supaya "cocok", dan kebocoran jadi tak pernah terlihat.
     */
    public function tutupKas(Request $r)
    {
        $t = $this->martKu($r);
        $tgl = $this->hariIni();
        if (MartKas::where('tenant_id', $t->id)->where('tanggal', $tgl)->exists()) {
            throw new ApiError(409, 'Kas hari ini sudah ditutup');
        }

        $kasAwal = (int) floor((float) $r->input('kas_awal', 0));
        $fisik = (int) floor((float) $r->input('hitung_fisik', 0));
        if ($kasAwal < 0 || $fisik < 0) {
            throw new ApiError(400, 'Nominal tidak boleh negatif');
        }

        $tunai = (int) MartJual::where('tenant_id', $t->id)
            ->where('created_at', '>=', WaktuIso::awalHariIni())->sum('total');
        $seharusnya = $kasAwal + $tunai;

        $kas = MartKas::create([
            'tenant_id' => $t->id, 'tanggal' => $tgl, 'kas_awal' => $kasAwal,
            'tunai' => $tunai, 'hitung_fisik' => $fisik, 'selisih' => $fisik - $seharusnya,
            'catatan' => mb_substr(trim((string) $r->input('catatan', '')), 0, 300),
            'operator' => $this->operator,
            'created_at' => WaktuIso::sekarang(),
        ]);

        return response()->json(['ok' => true, 'kas' => $kas->fresh(), 'seharusnya' => $seharusnya]);
    }

    /** Riwayat penutupan kas & penjualan tunai terakhir. */
    public function riwayat(Request $r)
    {
        $t = $this->martKu($r);

        return response()->json([
            'kas' => MartKas::where('tenant_id', $t->id)->orderByDesc('tanggal')->limit(30)->get(),
            'jual' => MartJual::where('tenant_id', $t->id)->orderByDesc('id')->limit(20)->get(),
        ]);
    }
}
