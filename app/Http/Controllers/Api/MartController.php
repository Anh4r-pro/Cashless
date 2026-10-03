<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\ApiError;
use App\Models\MartJual;
use App\Models\MartJualItem;
use App\Models\MartJualUbah;
use App\Models\MartKas;
use App\Models\MartKategori;
use App\Models\MartMember;
use App\Models\MartPiutangBayar;
use App\Models\Produk;
use App\Models\Tenant;
use App\Models\Transaksi;
use App\Services\Kartu;
use App\Services\Keranjang;
use App\Services\Pengaturan;
use App\Services\Stok;
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
     * UANG YANG MASUK hari berjalan, dipecah menurut tempat uangnya berada.
     *
     * Perhatikan: ini bukan omzet. Angka tunai & transfer di sini adalah
     * penjualan tunai/transfer hari ini DITAMBAH cicilan piutang yang dibayar
     * hari ini — karena yang dicari tutup kas adalah uang di laci, bukan
     * barang yang keluar dari rak. Sebaliknya penjualan HUTANG tidak masuk
     * ke sini sama sekali: barangnya memang keluar, tetapi uangnya belum ada.
     *
     * Tunai dan cashless sengaja TIDAK dijumlahkan jadi satu: keduanya berada
     * di tempat yang berbeda — satu di laci Mart, satu di kas sekolah.
     */
    private function ringkasHari(Tenant $t, string $tgl): array
    {
        $mulai = WaktuIso::awalHariIni();
        $jual = fn (string $metode) => MartJual::sah()->where('tenant_id', $t->id)
            ->where('created_at', '>=', $mulai)->where('metode', $metode)
            ->selectRaw('COALESCE(SUM(total),0) total, COUNT(*) n')->first();
        $cicil = fn (string $metode) => MartPiutangBayar::where('tenant_id', $t->id)
            ->where('created_at', '>=', $mulai)->where('metode', $metode)
            ->selectRaw('COALESCE(SUM(jumlah),0) total, COUNT(*) n')->first();

        $tunai = $jual('tunai');
        $transfer = $jual('transfer');
        $hutang = $jual('hutang');
        $cicilTunai = $cicil('tunai');
        $cicilTransfer = $cicil('transfer');
        $cashless = Transaksi::where('tenant_id', $t->id)->where('tipe', 'beli')
            ->where('created_at', '>=', $mulai)
            ->selectRaw('COALESCE(SUM(-jumlah),0) total, COUNT(*) n')->first();

        return [
            'tunai' => ['total' => (int) $tunai->total + (int) $cicilTunai->total,
                'n' => (int) $tunai->n + (int) $cicilTunai->n,
                'penjualan' => (int) $tunai->total, 'cicilan' => (int) $cicilTunai->total],
            'transfer' => ['total' => (int) $transfer->total + (int) $cicilTransfer->total,
                'n' => (int) $transfer->n + (int) $cicilTransfer->n,
                'penjualan' => (int) $transfer->total, 'cicilan' => (int) $cicilTransfer->total],
            'cashless' => ['total' => (int) $cashless->total, 'n' => (int) $cashless->n],
            // Barang keluar hari ini tanpa uang: bukan pemasukan, melainkan
            // piutang baru. Ditampilkan supaya kasir tidak mencarinya di laci.
            'hutang' => ['total' => (int) $hutang->total, 'n' => (int) $hutang->n],
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

        $metode = in_array($r->input('metode'), ['transfer', 'hutang'], true)
            ? $r->input('metode') : 'tunai';
        $subtotal = $x['total'];
        $diskon = max(0, (int) floor((float) $r->input('diskon', 0)));
        if ($diskon > $subtotal) {
            throw new ApiError(400, 'Diskon tidak boleh melebihi total belanja');
        }
        $total = $subtotal - $diskon;

        $bank = '';
        $pengirim = '';
        $member = null;
        if ($metode === 'hutang') {
            // Hutang hanya atas nama member terdaftar: piutang tanpa identitas
            // yang pasti adalah piutang yang tidak bisa ditagih.
            $member = MartMember::where('id', (int) $r->input('member_id'))
                ->where('tenant_id', $t->id)->first();
            if (! $member) {
                throw new ApiError(400, 'Pilih dulu membernya — hutang hanya boleh atas nama member terdaftar');
            }
            if (! $member->aktif) {
                throw new ApiError(409, "Member \"{$member->nama}\" sedang nonaktif");
            }
            if ($member->batas_hutang <= 0) {
                throw new ApiError(409, "Member \"{$member->nama}\" belum diberi plafon hutang — " .
                    'atur dulu batas hutangnya di layar Member & Piutang');
            }
            $sisa = $this->sisaPiutang($t, $member->id);
            if ($sisa + $total > $member->batas_hutang) {
                throw new ApiError(409, "Melebihi plafon hutang {$member->nama}: sisa piutang " .
                    Rupiah::f($sisa) . ' + belanja ' . Rupiah::f($total) . ' > batas ' .
                    Rupiah::f($member->batas_hutang));
            }
            // Tidak ada uang yang berpindah hari ini; yang lahir adalah piutang.
            $uang = 0;
            $kembalian = 0;
        } elseif ($metode === 'transfer') {
            // Sistem tidak terhubung ke bank mana pun: dua kolom ini semata
            // catatan kasir supaya mutasi rekening bisa dicocokkan nanti.
            $bank = mb_substr(trim((string) $r->input('bank', '')), 0, 40);
            $pengirim = mb_substr(trim((string) $r->input('pengirim', '')), 0, 80);
            if ($bank === '' || mb_strlen($pengirim) < 3) {
                throw new ApiError(400, 'Transfer wajib menyebut bank dan nama pengirim (minimal 3 huruf)');
            }
            $uang = $total;          // transfer selalu pas, tidak ada kembalian
            $kembalian = 0;
        } else {
            $uang = (int) floor((float) $r->input('uang_diterima', 0));
            if ($uang < $total) {
                throw new ApiError(400, 'Uang diterima (' . Rupiah::f($uang) . ') kurang dari total ' .
                    Rupiah::f($total));
            }
            $kembalian = $uang - $total;
        }

        $op = $this->operator;
        $memberId = $member?->id;
        $hasil = DB::transaction(function () use ($t, $x, $uang, $kembalian, $txId, $metode,
            $bank, $pengirim, $diskon, $subtotal, $total, $op, $memberId) {
            $jual = MartJual::create([
                'tenant_id' => $t->id, 'member_id' => $memberId,
                'tx_id' => $txId, 'metode' => $metode,
                'subtotal' => $subtotal, 'diskon' => $diskon, 'total' => $total,
                'uang_diterima' => $uang, 'kembalian' => $kembalian,
                'bank' => $bank, 'pengirim' => $pengirim,
                'n_item' => array_sum(array_map(fn ($b) => (int) ($b['qty'] ?? 1), $x['rincian'])),
                'operator' => $op,
                'created_at' => WaktuIso::sekarang(),
            ]);
            foreach ($x['rincian'] as $b) {
                MartJualItem::create([
                    'jual_id' => $jual->id, 'produk_id' => $b['produk_id'] ?? null,
                    'nama' => $b['nama'], 'kategori' => $b['kategori'] ?? 'makanan',
                    'harga' => (int) $b['harga'], 'qty' => (int) ($b['qty'] ?? 1),
                    'subtotal' => (int) $b['subtotal'],
                ]);
                if (! empty($b['produk_id'])) {
                    Stok::jual((int) $b['produk_id'], (int) ($b['qty'] ?? 1), (string) $txId, $op);
                }
            }

            return $jual;
        });

        return response()->json([
            'ok' => true, 'jual' => $hasil->fresh(),
            'item' => MartJualItem::where('jual_id', $hasil->id)->get(),
            'member' => $member ? ['nama' => $member->nama, 'kode' => $member->kode,
                'sisa_piutang' => $this->sisaPiutang($t, $member->id),
                'batas_hutang' => (int) $member->batas_hutang] : null,
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

        // Dibaca dari ringkasHari(), bukan dari tabel penjualan langsung: yang
        // dicari tutup kas adalah UANG YANG MASUK, dan itu termasuk cicilan
        // piutang yang dibayar hari ini — sekaligus TIDAK termasuk penjualan
        // hutang hari ini, yang barangnya keluar tanpa uangnya ikut.
        $hari = $this->ringkasHari($t, $tgl);
        $tunai = (int) $hari['tunai']['total'];
        $transfer = (int) $hari['transfer']['total'];
        $seharusnya = $kasAwal + $tunai;

        // Transfer dicocokkan ke mutasi rekening, bukan ke laci. Kalau kasir
        // tidak mengisinya, dianggap sama dengan catatan sistem — bukan nol,
        // sebab nol akan terbaca seolah seluruh transfer hilang.
        $fisikTransfer = $r->input('hitung_transfer') === null
            ? $transfer : (int) floor((float) $r->input('hitung_transfer'));

        $kas = MartKas::create([
            'tenant_id' => $t->id, 'tanggal' => $tgl, 'kas_awal' => $kasAwal,
            'tunai' => $tunai, 'transfer' => $transfer,
            'hitung_fisik' => $fisik, 'hitung_transfer' => $fisikTransfer,
            'selisih' => $fisik - $seharusnya, 'selisih_transfer' => $fisikTransfer - $transfer,
            'catatan' => mb_substr(trim((string) $r->input('catatan', '')), 0, 300),
            'operator' => $this->operator,
            'created_at' => WaktuIso::sekarang(),
        ]);

        return response()->json(['ok' => true, 'kas' => $kas->fresh(), 'seharusnya' => $seharusnya]);
    }

    /** Riwayat penutupan kas & penjualan tunai terakhir. */
    /**
     * Riwayat penjualan Mart — DUA BUKU disatukan di satu layar.
     *
     * Penjualan tunai & transfer hidup di `mart_jual` (uangnya di laci Mart),
     * penjualan kartu di `transaksi` (uangnya di kas sekolah). Pemisahan itu
     * yang menjaga neraca sekolah tetap nol, dan tidak boleh dilebur. Tetapi
     * bagi penjaga Mart keduanya satu hal yang sama: "apa saja yang terjual
     * hari ini". Jadi yang disatukan TAMPILANNYA, bukan uangnya.
     *
     * Halaman diambil dengan cara mengambil $hal × $per teratas dari masing-
     * masing buku, menggabungkan, lalu memotong. Itu berarti halaman ke-20
     * membaca lebih banyak baris daripada halaman pertama — dan itu sengaja
     * diterima: satu toko sekolah menjual puluhan sampai ratusan kali sehari,
     * dan menggabungkan dua tabel di tingkat SQL menukar keterbacaan dengan
     * kecepatan yang tidak akan pernah terasa di ukuran segini.
     */
    public function riwayat(Request $r)
    {
        $t = $this->martKu($r);
        $hal = max(1, (int) $r->query('hal', 1));
        $per = min(100, max(10, (int) $r->query('per', 25)));
        $metode = in_array($r->query('metode'), ['tunai', 'transfer', 'hutang', 'kartu'], true)
            ? $r->query('metode') : null;
        $cari = mb_substr(trim((string) $r->query('cari', '')), 0, 60);
        $dari = $r->query('dari');
        $sampai = $r->query('sampai');
        $like = '%' . $cari . '%';

        $baris = [];
        $ringkas = ['tunai' => 0, 'transfer' => 0, 'hutang' => 0, 'kartu' => 0,
            'diskon' => 0, 'n' => 0];

        if ($metode !== 'kartu') {
            // Yang dibatalkan TETAP tampil di riwayat — justru di sinilah ia
            // harus terlihat — tetapi tidak pernah ikut dijumlahkan.
            $q = MartJual::where('tenant_id', $t->id)
                ->when($metode, fn ($w) => $w->where('metode', $metode))
                ->when($dari, fn ($w) => $w->where('created_at', '>=', $dari))
                ->when($sampai, fn ($w) => $w->where('created_at', '<=', $sampai))
                ->when($cari !== '', fn ($w) => $w->where(fn ($x) => $x
                    ->where('bank', 'like', $like)
                    ->orWhere('pengirim', 'like', $like)
                    ->orWhere('operator', 'like', $like)
                    ->orWhereExists(fn ($s) => $s->from('mart_jual_item')
                        ->whereColumn('mart_jual_item.jual_id', 'mart_jual.id')
                        ->where('mart_jual_item.nama', 'like', $like))));

            $agg = (clone $q)->where('status', 'sah')
                ->selectRaw("COALESCE(SUM(CASE WHEN metode='tunai' THEN total END),0) tunai")
                ->selectRaw("COALESCE(SUM(CASE WHEN metode='transfer' THEN total END),0) transfer")
                ->selectRaw("COALESCE(SUM(CASE WHEN metode='hutang' THEN total END),0) hutang")
                ->selectRaw('COALESCE(SUM(diskon),0) diskon, COUNT(*) n')->first();
            $ringkas['tunai'] = (int) $agg->tunai;
            $ringkas['transfer'] = (int) $agg->transfer;
            $ringkas['hutang'] = (int) $agg->hutang;
            $ringkas['diskon'] = (int) $agg->diskon;
            $ringkas['n'] += (int) $agg->n;

            foreach ((clone $q)->orderByDesc('id')->limit($hal * $per)->get() as $j) {
                $baris[] = [
                    'jenis' => 'jual', 'id' => (int) $j->id, 'no' => self::noStruk('jual', $j->id),
                    'waktu' => $j->created_at, 'metode' => $j->metode, 'total' => (int) $j->total,
                    'diskon' => (int) $j->diskon, 'n_item' => (int) $j->n_item,
                    'operator' => $j->operator, 'status' => $j->status, 'n_ubah' => (int) $j->n_ubah,
                    'ket' => $this->ketJual($j),
                    'terkunci' => $this->kasSudahDitutup($t, (string) $j->created_at),
                ];
            }
        }

        if ($metode === null || $metode === 'kartu') {
            $q = Transaksi::where('tenant_id', $t->id)->where('tipe', 'beli')
                ->when($dari, fn ($w) => $w->where('created_at', '>=', $dari))
                ->when($sampai, fn ($w) => $w->where('created_at', '<=', $sampai))
                ->when($cari !== '', fn ($w) => $w->where(fn ($x) => $x
                    ->where('siswa_nama', 'like', $like)
                    ->orWhere('kelas', 'like', $like)
                    ->orWhere('keterangan', 'like', $like)
                    ->orWhere('operator', 'like', $like)));

            $agg = (clone $q)->selectRaw('COALESCE(SUM(-jumlah),0) total, COUNT(*) n')->first();
            $ringkas['kartu'] = (int) $agg->total;
            $ringkas['n'] += (int) $agg->n;

            foreach ((clone $q)->orderByDesc('id')->limit($hal * $per)->get() as $x) {
                $baris[] = [
                    'jenis' => 'kartu', 'id' => (int) $x->id, 'no' => self::noStruk('kartu', $x->id),
                    'waktu' => $x->created_at, 'metode' => 'kartu', 'total' => (int) -$x->jumlah,
                    'diskon' => 0, 'n_item' => null, 'operator' => $x->operator,
                    'status' => 'sah', 'n_ubah' => 0, 'terkunci' => true,
                    'ket' => trim($x->siswa_nama . ($x->kelas ? ' · ' . $x->kelas : '')),
                ];
            }
        }

        usort($baris, fn ($a, $b) => strcmp((string) $b['waktu'], (string) $a['waktu'])
            ?: ($b['id'] <=> $a['id']));

        return response()->json([
            'baris' => array_slice($baris, ($hal - 1) * $per, $per),
            // Omzet menghitung penjualan hutang juga: barangnya memang sudah
            // keluar dari rak. Yang belum datang hanya uangnya.
            'ringkas' => array_merge($ringkas, ['omzet' => $ringkas['tunai']
                + $ringkas['transfer'] + $ringkas['hutang'] + $ringkas['kartu']]),
            'hal' => $hal, 'per' => $per, 'n_halaman' => (int) max(1, ceil($ringkas['n'] / $per)),
        ]);
    }

    /**
     * Sudahkah kas hari transaksi itu ditutup?
     *
     * Jawabannya menentukan boleh-tidaknya transaksi dibetulkan: setelah uang
     * laci dihitung, angkanya tidak boleh bergerak lagi di belakang.
     */
    private function kasSudahDitutup(Tenant $t, string $waktu): bool
    {
        return MartKas::where('tenant_id', $t->id)
            ->where('tanggal', WaktuIso::tanggalLokal($waktu))->exists();
    }

    /**
     * Sisa piutang satu member: seluruh penjualan hutang yang masih sah,
     * dikurangi cicilan yang sudah masuk. Dipakai menegakkan plafon.
     */
    private function sisaPiutang(Tenant $t, int $memberId): int
    {
        $hutang = (int) MartJual::sah()->where('tenant_id', $t->id)
            ->where('member_id', $memberId)->where('metode', 'hutang')->sum('total');
        $bayar = (int) MartPiutangBayar::where('tenant_id', $t->id)
            ->where('member_id', $memberId)->sum('jumlah');

        return max(0, $hutang - $bayar);
    }

    /** Keterangan satu baris penjualan: lawan bicaranya, siapa pun ia. */
    private function ketJual(MartJual $j): string
    {
        if ($j->metode === 'transfer') {
            return trim($j->bank . ' · ' . $j->pengirim);
        }
        if ($j->metode === 'hutang') {
            $m = $j->member_id ? MartMember::find($j->member_id) : null;

            return 'Hutang · ' . ($m ? $m->nama : 'member terhapus');
        }

        return '';
    }

    /** Nomor struk yang dilihat manusia. Dua buku, dua awalan, tidak pernah bentrok. */
    private static function noStruk(string $jenis, int $id): string
    {
        return ($jenis === 'kartu' ? 'KRT-' : 'MRT-') . str_pad((string) $id, 6, '0', STR_PAD_LEFT);
    }

    /**
     * Satu transaksi beserta rinciannya — untuk layar rincian dan cetak ulang
     * struk. Rinciannya dibaca ulang dari basis data, bukan dari apa yang
     * kebetulan masih tersimpan di layar kasir: struk cetak ulang harus
     * menyebut barang yang benar-benar tercatat terjual.
     */
    public function riwayatDetail(Request $r, string $jenis, int $id)
    {
        $t = $this->martKu($r);

        if ($jenis === 'jual') {
            $j = MartJual::where('id', $id)->where('tenant_id', $t->id)->first();
            if (! $j) {
                throw new ApiError(404, 'Transaksi tidak ditemukan');
            }

            return response()->json([
                'transaksi' => array_merge($j->toArray(), [
                    'jenis' => 'jual', 'no' => self::noStruk('jual', $j->id),
                    'ket' => $this->ketJual($j),
                    'terkunci' => $this->kasSudahDitutup($t, (string) $j->created_at),
                ]),
                'item' => MartJualItem::where('jual_id', $j->id)->get(),
                'ubah' => MartJualUbah::where('jual_id', $j->id)->orderByDesc('id')->get(),
            ]);
        }

        $x = Transaksi::where('id', $id)->where('tenant_id', $t->id)->where('tipe', 'beli')->first();
        if (! $x) {
            throw new ApiError(404, 'Transaksi tidak ditemukan');
        }
        $item = DB::table('transaksi_item')->where('transaksi_id', $x->id)
            ->get(['produk_id', 'nama', 'kategori', 'harga', 'qty', 'subtotal']);

        return response()->json([
            'transaksi' => [
                'jenis' => 'kartu', 'id' => (int) $x->id, 'no' => self::noStruk('kartu', $x->id),
                'metode' => 'kartu', 'created_at' => $x->created_at,
                'subtotal' => (int) -$x->jumlah, 'diskon' => 0, 'total' => (int) -$x->jumlah,
                'uang_diterima' => (int) -$x->jumlah, 'kembalian' => 0,
                'n_item' => $item->sum('qty'), 'operator' => $x->operator,
                'ket' => trim($x->siswa_nama . ($x->kelas ? ' · ' . $x->kelas : '')),
                // Rincian bisa kosong untuk transaksi lama yang dicatat sebelum
                // kasir mengirim rincian; keterangannya yang menggantikan.
                'keterangan' => $x->keterangan,
            ],
            'item' => $item,
        ]);
    }
}
