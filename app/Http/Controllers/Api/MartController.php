<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\ApiError;
use App\Models\MartBeliBayar;
use App\Models\MartJual;
use App\Models\MartJualItem;
use App\Models\MartJualUbah;
use App\Models\MartKas;
use App\Models\MartKategori;
use App\Models\MartMember;
use App\Models\MartMemberKategori;
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
            'tenant' => ['id' => $t->id, 'nama' => $t->nama, 'jenis' => $t->jenis,
                'input_manual' => (bool) $t->input_manual],
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
        // Uang KELUAR: pembayaran ke pemasok. Membayar dari laci membuat isi
        // laci berkurang, dan laci yang berkurang tanpa catatan hanya akan
        // terbaca sebagai selisih yang tidak bisa dijelaskan.
        $bayarPemasok = fn (string $metode) => MartBeliBayar::where('tenant_id', $t->id)
            ->where('created_at', '>=', $mulai)->where('metode', $metode)
            ->selectRaw('COALESCE(SUM(jumlah),0) total, COUNT(*) n')->first();

        $tunai = $jual('tunai');
        $transfer = $jual('transfer');
        $hutang = $jual('hutang');
        $cicilTunai = $cicil('tunai');
        $cicilTransfer = $cicil('transfer');
        $keluarTunai = $bayarPemasok('tunai');
        $keluarTransfer = $bayarPemasok('transfer');
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
            'keluar' => ['total' => (int) $keluarTunai->total, 'n' => (int) $keluarTunai->n],
            'keluar_transfer' => ['total' => (int) $keluarTransfer->total,
                'n' => (int) $keluarTransfer->n],
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
                return response()->json(['ok' => true, 'satuan' => 'ecer',
                    'cocok' => $this->ringkasProduk($persis), 'daftar' => []]);
            }
            /*
             * Barcode DUS. Dus punya kode sendiri (ITF-14 di karton) yang
             * berbeda dari kode bungkusnya, jadi memindainya harus langsung
             * menjadi satu dus — bukan satu pcs yang lalu ditukar manual.
             *
             * Syarat grosirnya ikut dituntut di sini, bukan hanya dipercayakan
             * pada validasi saat menyimpan: kalau setelan grosirnya terlanjur
             * dikosongkan sementara kodenya tertinggal, pindaian lebih baik
             * jatuh ke "tidak ketemu" daripada melahirkan baris dus tanpa
             * harga dan tanpa isi.
             */
            $dus = (clone $dasar())->where('barcode_grosir', $kode)
                ->where('isi_grosir', '>=', 2)->whereNotNull('harga_grosir')->first();
            if ($dus) {
                return response()->json(['ok' => true, 'satuan' => 'grosir',
                    'cocok' => $this->ringkasProduk($dus), 'daftar' => []]);
            }
        }

        $daftar = (clone $dasar())->where('nama', 'like', '%' . $q . '%')
            ->orderBy('nama')->limit(8)->get()->map(fn ($p) => $this->ringkasProduk($p));

        return response()->json([
            'ok' => true,
            'satuan' => 'ecer',
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
            'kategori' => $p->kategori, 'barcode' => $p->barcode,
            'barcode_grosir' => $p->barcode_grosir,
            // Stok & grosir ikut dibawa: hasil pindaian masuk keranjang
            // langsung, jadi layar perlu tahu sisanya dan apakah barang ini
            // bisa dijual per kemasan — tanpa memuat ulang seluruh katalog.
            'stok' => $p->stok === null ? null : (int) $p->stok,
            'isi_grosir' => $p->isi_grosir === null ? null : (int) $p->isi_grosir,
            'harga_grosir' => $p->harga_grosir === null ? null : (int) $p->harga_grosir];
    }

    /**
     * Tempelkan barcode ke sebuah barang — LANGSUNG, tanpa pengajuan.
     *
     * Berbeda dari nama & harga yang harus disetujui admin karena menentukan
     * berapa santri dibebani, barcode hanya penanda: salah tempel pun tidak
     * mengubah harga sepeser pun, dan bisa dibetulkan seketika.
     *
     * `satuan` memilih SLOT-nya: `ecer` mengisi barcode bungkusnya, `grosir`
     * mengisi barcode kartonnya. Keduanya hidup berdampingan pada satu barang
     * supaya kasir boleh memindai mana pun yang ada di tangannya.
     */
    public function pasangBarcode(Request $r, int $id)
    {
        $t = $this->martKu($r);
        // Pasang barcode = setup katalog, bukan melayani — admin toko saja.
        $this->wajibAdminToko($r, 'tenant');
        $p = Produk::where('id', $id)->where('tenant_id', $t->id)->first();
        if (! $p) {
            throw new ApiError(404, 'Barang tidak ditemukan');
        }

        $grosir = $r->input('satuan') === 'grosir';
        $kolom = $grosir ? 'barcode_grosir' : 'barcode';
        /*
         * Barang yang belum disiapkan grosir tidak boleh punya barcode dus:
         * isi dan harga dusnya tidak diketahui, jadi pindaian kodenya akan
         * melahirkan baris keranjang yang tidak bisa dihargai. Ditolak di sini
         * supaya sebabnya terbaca saat menempel, bukan nanti saat melayani.
         */
        if ($grosir && ! $p->siapGrosir()) {
            throw new ApiError(409, "\"{$p->nama}\" belum disiapkan dijual per dus — " .
                'isi dulu "Isi per grosir" dan "Harga jual grosir" di Barang & Stok.');
        }

        $kode = preg_replace('/[^0-9A-Za-z]/', '', (string) $r->input('barcode', ''));
        if ($kode === '') {
            $p->update([$kolom => null]);

            return response()->json(['ok' => true, 'produk' => $this->ringkasProduk($p->fresh())]);
        }
        if (strlen($kode) < 6 || strlen($kode) > 32) {
            throw new ApiError(400, 'Barcode 6–32 karakter angka/huruf');
        }
        // Satu kode = satu (barang, satuan), jadi slot seberang pada barang
        // yang SAMA pun tidak boleh memakai kode yang sama.
        if ($kode === (string) ($grosir ? $p->barcode : $p->barcode_grosir)) {
            throw new ApiError(409, 'Kode itu sudah dipakai sebagai barcode ' .
                ($grosir ? 'satuan' : 'dus') . " barang ini sendiri — pindaiannya akan ambigu.");
        }
        $bentrok = Produk::bentrokBarcode($t->id, $kode, $p->id);
        if ($bentrok) {
            throw new ApiError(409, "Barcode ini sudah dipakai \"{$bentrok->nama}\"");
        }
        $p->update([$kolom => $kode]);

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
        // Catatan bebas — terutama berguna pada hutang: saat menagih berminggu
        // kemudian, "hutang Rp150.000" tanpa keterangan apa pun sering berakhir
        // jadi perdebatan tentang untuk apa dan atas nama siapa barang diambil.
        $catatan = mb_substr(trim(preg_replace('/\s+/u', ' ', (string) $r->input('catatan', ''))), 0, 200);

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
            /*
             * Kategori menyaring lebih dulu daripada plafon.
             *
             * Urutannya disengaja: kalau kategorinya memang tidak boleh
             * berhutang, kasir harus mendengar ALASAN ITU — bukan dikirim
             * mengurus plafon yang tidak akan menolong.
             *
             * Member tanpa kategori (kategori_id NULL) tidak tersentuh aturan
             * ini sama sekali: itu keadaan seluruh member yang sudah ada.
             */
            if ($member->kategori_id) {
                $kat = MartMemberKategori::where('id', $member->kategori_id)
                    ->where('tenant_id', $t->id)->first();
                if ($kat && ! $kat->boleh_hutang) {
                    throw new ApiError(409, "Member \"{$member->nama}\" masuk kategori " .
                        "\"{$kat->nama}\" yang tidak boleh berhutang. Pilih metode bayar lain.");
                }
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
            $bank, $pengirim, $diskon, $subtotal, $total, $op, $memberId, $catatan) {
            $jual = MartJual::create([
                'tenant_id' => $t->id, 'member_id' => $memberId,
                'tx_id' => $txId, 'metode' => $metode,
                'subtotal' => $subtotal, 'diskon' => $diskon, 'total' => $total,
                'uang_diterima' => $uang, 'kembalian' => $kembalian,
                'bank' => $bank, 'pengirim' => $pengirim, 'catatan' => $catatan,
                'n_item' => array_sum(array_map(fn ($b) => (int) ($b['qty'] ?? 1), $x['rincian'])),
                'operator' => $op,
                'created_at' => WaktuIso::sekarang(),
            ]);
            foreach ($x['rincian'] as $b) {
                MartJualItem::create([
                    'jual_id' => $jual->id, 'produk_id' => $b['produk_id'] ?? null,
                    'nama' => $b['nama'], 'kategori' => $b['kategori'] ?? 'makanan',
                    'harga' => (int) $b['harga'], 'hpp' => $b['hpp'] ?? null,
                    'qty' => (int) ($b['qty'] ?? 1),
                    // Satuan & isi DIPOTRET: kemasan pemasok bisa berubah, dan
                    // nota yang sudah tercatat tidak boleh ikut berubah.
                    'satuan' => $b['satuan'] ?? 'ecer', 'isi' => (int) ($b['isi'] ?? 1),
                    'subtotal' => (int) $b['subtotal'],
                ]);
                if (! empty($b['produk_id'])) {
                    // Stok selalu dalam satuan ECERAN: menjual 2 dus berisi 12
                    // memotong 24 pcs. Hanya ada satu stok, jadi tidak ada dua
                    // angka yang bisa saling menyimpang.
                    Stok::jual((int) $b['produk_id'],
                        (int) ($b['qty'] ?? 1) * max(1, (int) ($b['isi'] ?? 1)), (string) $txId, $op);
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
        $keluar = (int) $hari['keluar']['total'];
        $keluarTransfer = (int) $hari['keluar_transfer']['total'];
        $seharusnya = $kasAwal + $tunai - $keluar;

        // Transfer dicocokkan ke mutasi rekening, bukan ke laci. Kalau kasir
        // tidak mengisinya, dianggap sama dengan catatan sistem — bukan nol,
        // sebab nol akan terbaca seolah seluruh transfer hilang.
        $transferBersih = $transfer - $keluarTransfer;
        $fisikTransfer = $r->input('hitung_transfer') === null
            ? $transferBersih : (int) floor((float) $r->input('hitung_transfer'));

        $kas = MartKas::create([
            'tenant_id' => $t->id, 'tanggal' => $tgl, 'kas_awal' => $kasAwal,
            'tunai' => $tunai, 'transfer' => $transfer,
            'keluar' => $keluar, 'keluar_transfer' => $keluarTransfer,
            'hitung_fisik' => $fisik, 'hitung_transfer' => $fisikTransfer,
            'selisih' => $fisik - $seharusnya,
            'selisih_transfer' => $fisikTransfer - $transferBersih,
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
    /**
     * Nomor struk yang diketik kasir dicari sebagai ID.
     *
     * Yang dipegang pembeli saat protes adalah kertas bertuliskan "MRT-000123",
     * bukan id basis data — dan nomor itu tidak pernah disimpan, ia disusun saat
     * ditampilkan (lihat noStruk). Dicari apa adanya sebagai teks, ia tidak akan
     * pernah ketemu.
     *
     * Tiga bentuk yang sama-sama wajar diketik diterima: "MRT-000123", "000123",
     * dan "123". Awalannya menentukan buku mana yang dicari; tanpa awalan,
     * dua-duanya — kasir tidak harus hafal struk mana milik buku yang mana.
     */
    private static function idDariStruk(string $cari): array
    {
        if (! preg_match('/^\s*(mrt|krt)?[\s-]*0*(\d{1,12})\s*$/i', $cari, $m)) {
            return ['jual' => null, 'kartu' => null];
        }
        $id = (int) $m[2];
        $awalan = strtolower($m[1] ?? '');

        return [
            'jual' => $awalan === 'krt' ? null : $id,
            'kartu' => $awalan === 'mrt' ? null : $id,
        ];
    }

    public function riwayat(Request $r)
    {
        $t = $this->martKu($r);
        $hal = max(1, (int) $r->query('hal', 1));
        $per = min(100, max(10, (int) $r->query('per', 25)));
        $metode = in_array($r->query('metode'), ['tunai', 'transfer', 'hutang', 'kartu', 'pelunasan'], true)
            ? $r->query('metode') : null;
        $cari = mb_substr(trim((string) $r->query('cari', '')), 0, 60);
        $dari = $r->query('dari');
        $sampai = $r->query('sampai');
        $like = '%' . $cari . '%';
        $struk = self::idDariStruk($cari);

        $baris = [];
        $ringkas = ['tunai' => 0, 'transfer' => 0, 'hutang' => 0, 'kartu' => 0,
            'pelunasan' => 0, 'diskon' => 0, 'hpp' => 0, 'n' => 0];
        // Dihitung terpisah dari $ringkas['n'] — lihat alasannya di bawah,
        // di tempat n_halaman disusun.
        $nSemua = 0;

        if ($metode !== 'kartu' && $metode !== 'pelunasan') {
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
                    ->when($struk['jual'], fn ($y) => $y->orWhere('mart_jual.id', $struk['jual']))
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
            $nSemua += (clone $q)->count();

            // Modal barang yang benar-benar terjual pada rentang & saringan yang
            // SAMA dengan daftarnya. Memakai potret `i.hpp` lebih dulu: harga
            // modal di master berubah tiap nota masuk, dan laba hari lalu tidak
            // boleh ikut bergerak karenanya.
            $ringkas['hpp'] += (int) DB::table('mart_jual_item as i')
                ->joinSub((clone $q)->where('status', 'sah')->select('mart_jual.id'),
                    'j', 'j.id', '=', 'i.jual_id')
                ->leftJoin('produk as p', 'p.id', '=', 'i.produk_id')
                ->selectRaw('COALESCE(SUM(i.qty * COALESCE(i.hpp, p.hpp, 0)),0) n')->value('n');

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
                    ->orWhere('operator', 'like', $like)
                    ->when($struk['kartu'], fn ($y) => $y->orWhere('transaksi.id', $struk['kartu']))));

            $agg = (clone $q)->selectRaw('COALESCE(SUM(-jumlah),0) total, COUNT(*) n')->first();
            $ringkas['kartu'] = (int) $agg->total;
            $ringkas['n'] += (int) $agg->n;
            $nSemua += (int) $agg->n;

            $ringkas['hpp'] += (int) DB::table('transaksi_item as i')
                ->joinSub((clone $q)->select('transaksi.id'), 'x', 'x.id', '=', 'i.transaksi_id')
                ->leftJoin('produk as p', 'p.id', '=', 'i.produk_id')
                ->selectRaw('COALESCE(SUM(i.qty * COALESCE(i.hpp, p.hpp, 0)),0) n')->value('n');

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

        /*
         * Buku ketiga: CICILAN PIUTANG yang masuk hari itu.
         *
         * Sebelumnya uang ini tidak pernah muncul di Riwayat sama sekali —
         * padahal ia uang sungguhan yang masuk ke laci dan ikut dihitung saat
         * tutup kas. Kasir yang mencocokkan laci dengan layar Riwayat selalu
         * menemukan lebih, tanpa penjelasan di layar mana pun.
         *
         * Yang TIDAK boleh terjadi: ikut dijumlahkan ke omzet. Barangnya sudah
         * keluar rak (dan sudah dihitung) pada hari penjualan hutangnya; yang
         * datang belakangan hanya uangnya. Karena itu ia punya pos sendiri.
         */
        if ($metode === null || $metode === 'pelunasan') {
            $q = MartPiutangBayar::where('mart_piutang_bayar.tenant_id', $t->id)
                ->leftJoin('mart_member as m', 'm.id', '=', 'mart_piutang_bayar.member_id')
                ->when($dari, fn ($w) => $w->where('mart_piutang_bayar.created_at', '>=', $dari))
                ->when($sampai, fn ($w) => $w->where('mart_piutang_bayar.created_at', '<=', $sampai))
                ->when($cari !== '', fn ($w) => $w->where(fn ($x) => $x
                    ->where('m.nama', 'like', $like)
                    ->orWhere('mart_piutang_bayar.catatan', 'like', $like)
                    ->orWhere('mart_piutang_bayar.operator', 'like', $like)
                    ->orWhere('mart_piutang_bayar.bank', 'like', $like)
                    ->orWhere('mart_piutang_bayar.pengirim', 'like', $like)
                    ->when($struk['jual'], fn ($y) => $y
                        ->orWhere('mart_piutang_bayar.jual_id', $struk['jual']))));

            $agg = (clone $q)->selectRaw('COALESCE(SUM(mart_piutang_bayar.jumlah),0) total, COUNT(*) n')->first();
            $ringkas['pelunasan'] = (int) $agg->total;
            $ringkas['n'] += (int) $agg->n;
            $nSemua += (int) $agg->n;

            foreach ((clone $q)->select('mart_piutang_bayar.*', 'm.nama as member_nama')
                ->orderByDesc('mart_piutang_bayar.id')->limit($hal * $per)->get() as $b) {
                $noAsal = self::noStruk('jual', (int) $b->jual_id);
                $baris[] = [
                    'jenis' => 'pelunasan', 'id' => (int) $b->id, 'jual_id' => (int) $b->jual_id,
                    'no' => $noAsal, 'waktu' => $b->created_at, 'metode' => 'pelunasan',
                    'total' => (int) $b->jumlah, 'diskon' => 0, 'n_item' => null,
                    'operator' => $b->operator, 'status' => 'sah', 'n_ubah' => 0, 'terkunci' => true,
                    'ket' => trim(($b->member_nama ?: 'member terhapus') . ' · bayar hutang ' . $noAsal
                        . ($b->catatan ? ' — ' . $b->catatan : '')),
                ];
            }
        }

        usort($baris, fn ($a, $b) => strcmp((string) $b['waktu'], (string) $a['waktu'])
            ?: ($b['id'] <=> $a['id']));

        $omzet = $ringkas['tunai'] + $ringkas['transfer'] + $ringkas['hutang'] + $ringkas['kartu'];

        return response()->json([
            'baris' => array_slice($baris, ($hal - 1) * $per, $per),
            // Omzet menghitung penjualan hutang juga: barangnya memang sudah
            // keluar dari rak. Yang belum datang hanya uangnya. Cicilan piutang
            // sengaja TIDAK ikut — ia bukan barang keluar yang baru.
            'ringkas' => array_merge($ringkas, [
                'omzet' => $omzet,
                // Laba kotor: omzet (sudah bersih diskon) dikurangi modal barang
                // yang terjual. Belum dikurangi biaya operasional.
                'laba' => $omzet - $ringkas['hpp'],
            ]),
            'hal' => $hal, 'per' => $per,
            /*
             * Jumlah halaman dihitung dari SELURUH baris yang tampil, bukan dari
             * $ringkas['n'] yang hanya menghitung baris sah.
             *
             * Dulu keduanya dipakai bergantian, dan akibatnya nyata: begitu ada
             * satu transaksi batal, n_halaman kurang satu baris dari isi
             * sebenarnya, dan halaman terakhir tidak bisa dibuka sama sekali.
             */
            'n_halaman' => (int) max(1, ceil($nSemua / $per)),
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
        // Catatan kasir disertakan apa pun metodenya — ia ditulis justru supaya
        // terbaca di riwayat, bukan hanya tersimpan di basis data.
        $catatan = trim((string) $j->catatan);

        if ($j->metode === 'transfer') {
            return trim(trim($j->bank . ' · ' . $j->pengirim) . ($catatan ? ' — ' . $catatan : ''));
        }
        if ($j->metode === 'hutang') {
            $m = $j->member_id ? MartMember::find($j->member_id) : null;

            return 'Hutang · ' . ($m ? $m->nama : 'member terhapus')
                . ($catatan ? ' — ' . $catatan : '');
        }

        return $catatan;
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
