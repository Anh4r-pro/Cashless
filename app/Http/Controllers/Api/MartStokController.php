<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\ApiError;
use App\Models\MartKategori;
use App\Models\MartStokMutasi;
use App\Models\Produk;
use App\Models\Tenant;
use App\Services\Ekspor;
use App\Services\ImporBarangMart;
use App\Services\ImporTopup;
use App\Services\Kartu;
use App\Services\Rupiah;
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
 *
 * Pagar peran dipasang PER-METHOD, bukan sekaligus di martKu(): hampir semua
 * method di sini khusus admin toko, TAPI index() tidak boleh ikut dipagari —
 * itulah sumber katalog etalase POS, yang justru dipakai kasir serve-only
 * setiap kali melayani. Memagari martKu() membuat layar Kasir kosong.
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
        $this->wajibAdminToko($r, 'tenant');
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
        $this->wajibAdminToko($r, 'tenant');
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
        $this->wajibAdminToko($r, 'tenant');
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

    /**
     * Daftar barang. SENGAJA tidak dipagari wajibAdminToko(): ini katalog
     * yang dimuat layar Kasir (martMuatKatalog di app.js), jadi kasir
     * serve-only wajib bisa membacanya.
     */
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

    /**
     * Laporan persediaan: nilai barang di rak menurut HARGA MODAL.
     *
     * Dinilai dengan modal, bukan harga jual — yang ditanyakan bendahara adalah
     * "uang sekolah yang sedang berwujud barang", dan menilainya dengan harga
     * jual akan memasukkan laba yang belum terjadi ke dalam neraca.
     *
     * Barang yang stoknya TIDAK dilacak dilewati: ia tidak punya nilai
     * persediaan, dan membariskannya sebagai nol hanya memanjangkan daftar.
     * Barang nonaktif tetap dihitung — ia berhenti dijual, tetapi barangnya
     * masih ada di rak dan uangnya masih tertanam di sana.
     */
    public function persediaan(Request $r)
    {
        $this->wajibAdminToko($r, 'tenant');
        $t = $this->martKu($r);
        $kat = MartKategori::where('tenant_id', $t->id)->pluck('nama', 'id');

        $produk = Produk::where('tenant_id', $t->id)->whereNotNull('stok')
            ->orderBy('nama')->get();

        $baris = $produk->map(function (Produk $p) use ($kat) {
            $hpp = (int) ($p->hpp ?? 0);
            $stok = (int) $p->stok;

            return [
                'id' => $p->id, 'nama' => $p->nama, 'barcode' => $p->barcode,
                'kategori_nama' => $kat[$p->kategori_id] ?? '',
                'stok' => $stok, 'hpp' => $hpp, 'nilai' => $stok * $hpp,
                'aktif' => (int) $p->aktif,
                'status' => Stok::status($p->stok, $p->stok_min),
            ];
        })->sortByDesc('nilai')->values()->all();

        $tanpaHpp = array_values(array_filter($baris, fn ($b) => $b['hpp'] <= 0 && $b['stok'] > 0));
        $total = [
            'n_barang' => count($baris),
            'stok' => array_sum(array_column($baris, 'stok')),
            'nilai' => array_sum(array_column($baris, 'nilai')),
            // Barang bertstok yang modalnya belum diisi menyumbang NOL ke nilai
            // persediaan. Tanpa angka ini, laporannya tampak lengkap padahal
            // ada rak yang nilainya tidak pernah masuk hitungan.
            'n_tanpa_hpp' => count($tanpaHpp),
            'stok_tanpa_hpp' => array_sum(array_column($tanpaHpp, 'stok')),
        ];

        if ($r->query('format')) {
            return $this->persediaanUnduhan($t, $baris, $total, Ekspor::formatSah($r->query('format')));
        }

        return response()->json([
            'tenant' => ['id' => $t->id, 'nama' => $t->nama],
            'baris' => $baris, 'total' => $total,
        ]);
    }

    /** Persediaan sebagai berkas unduhan (.xls / .doc / .csv). */
    private function persediaanUnduhan(Tenant $t, array $baris, array $total, string $format)
    {
        $kepala = ['Barang', 'SKU/Barcode', 'Kategori', 'Stok', 'Harga modal (Rp)',
            'Nilai persediaan (Rp)', 'Status'];
        $isi = array_map(fn ($b) => [
            $b['nama'] . ($b['aktif'] ? '' : ' (nonaktif)'), $b['barcode'] ?: '', $b['kategori_nama'],
            $b['stok'], $b['hpp'], $b['nilai'], $b['status'],
        ], $baris);
        $isi[] = ['TOTAL', '', '', $total['stok'], '', $total['nilai'], $total['n_barang'] . ' barang'];

        $judul = 'Laporan Persediaan (nilai modal) — ' . $t->nama;
        $catatan = ['Dinilai dengan HARGA MODAL (HPP), bukan harga jual.',
            'Dicetak: ' . WaktuIso::tanggalLokal(WaktuIso::sekarang())];
        if ($total['n_tanpa_hpp'] > 0) {
            $catatan[] = "Perhatian: {$total['n_tanpa_hpp']} barang (total {$total['stok_tanpa_hpp']} "
                . 'satuan) belum punya harga modal, sehingga menyumbang nol ke nilai di atas — '
                . 'nilai persediaan sebenarnya LEBIH BESAR daripada angka ini.';
        }
        $nama = 'persediaan-mart-' . WaktuIso::tanggalLokal(WaktuIso::sekarang()) . '.' . $format;
        [$tipe] = Ekspor::TIPE[$format];

        $badan = $format === 'csv'
            ? Ekspor::csvUmum($kepala, array_merge($isi, [[]], [[$judul]],
                array_map(fn ($c) => [$c], $catatan)))
            : Ekspor::tabelUmum($judul, $kepala, $isi, $catatan, [3, 4, 5]);

        return response($badan, 200, [
            'Content-Type' => $tipe,
            'Content-Disposition' => "attachment; filename=\"{$nama}\"",
        ]);
    }

    /** Sisa hari menuju tanggal expired; null kalau tanggalnya tidak diisi. */
    public static function sisaHariExpired(?string $tgl): ?int
    {
        if (! $tgl || ! strtotime($tgl)) {
            return null;
        }
        $hariIni = strtotime(Kartu::hariIni());

        return (int) floor((strtotime($tgl) - $hariIni) / 86400);
    }

    /**
     * Lampu expired, ambangnya dari rapat tim kasir:
     * kuning mulai H-50, merah mulai H-30, dan "lewat" setelah tanggalnya.
     *
     * Dihitung di SERVER supaya layar, unduhan, dan cetakan memberi warna yang
     * sama — ambang yang disalin ke tiga tempat pasti akan berbeda suatu hari.
     */
    public static function statusExpired(?string $tgl): ?string
    {
        $sisa = self::sisaHariExpired($tgl);
        if ($sisa === null) {
            return null;
        }
        if ($sisa < 0) {
            return 'lewat';
        }

        return $sisa <= 30 ? 'merah' : ($sisa <= 50 ? 'kuning' : 'hijau');
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
            // Grosir (NULL = barang ini tidak dijual per kemasan).
            // `barcode_grosir` ikut dibawa karena layar nota pembelian memakai
            // daftar ini untuk mencocokkan pindaian karton.
            'barcode_grosir' => $p->barcode_grosir,
            'isi_grosir' => $p->isi_grosir === null ? null : (int) $p->isi_grosir,
            'hpp_grosir' => $p->hpp_grosir === null ? null : (int) $p->hpp_grosir,
            'harga_grosir' => $p->harga_grosir === null ? null : (int) $p->harga_grosir,
            'expired' => $p->expired,
            'expired_status' => self::statusExpired($p->expired),
            'expired_hari' => self::sisaHariExpired($p->expired),
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
        $this->wajibAdminToko($r, 'tenant');
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
        /*
         * Harga beli DI ATAS harga jual ditolak (keputusan rapat tim kasir).
         *
         * Sebelumnya ini hanya diperingatkan di layar dengan alasan barang
         * promo sesekali memang dijual rugi. Yang terjadi di lapangan justru
         * sebaliknya: peringatan terlewat, barang tersimpan rugi, dan baru
         * ketahuan dari laporan laba berminggu-minggu kemudian.
         *
         * Batasnya SENGAJA "lebih tinggi", bukan "lebih tinggi atau sama":
         * menjual pas di harga modal (laba nol) masih sah dan tetap boleh
         * disimpan — yang ditolak hanya yang benar-benar rugi per penjualan.
         */
        if ($hpp !== null && $hpp > $harga) {
            throw new ApiError(400, 'Harga beli (' . Rupiah::f($hpp) . ') lebih tinggi daripada harga jual (' .
                Rupiah::f($harga) . ') — barang ini akan rugi tiap kali terjual. ' .
                'Betulkan salah satunya dulu.');
        }

        /*
         * Grosir: satu barcode, dua satuan.
         *
         * Diisi bertiga atau tidak sama sekali. Setengah terisi adalah keadaan
         * yang paling berbahaya: kasir melihat pilihan "grosir" di layar, lalu
         * ditolak saat menjual karena harganya belum ada — di depan pembeli.
         */
        $isiGrosir = ($b['isi_grosir'] ?? null) === null || $b['isi_grosir'] === ''
            ? null : (int) floor((float) $b['isi_grosir']);
        $hppGrosir = ($b['hpp_grosir'] ?? null) === null || $b['hpp_grosir'] === ''
            ? null : (int) floor((float) $b['hpp_grosir']);
        $hargaGrosir = ($b['harga_grosir'] ?? null) === null || $b['harga_grosir'] === ''
            ? null : (int) floor((float) $b['harga_grosir']);

        if ($isiGrosir !== null || $hargaGrosir !== null) {
            if ($isiGrosir === null || $isiGrosir < 2) {
                throw new ApiError(400, 'Isi per grosir minimal 2 — kalau isinya satu, ' .
                    'itu bukan kemasan grosir, cukup dijual eceran saja.');
            }
            if ($hargaGrosir === null || $hargaGrosir <= 0) {
                throw new ApiError(400, 'Harga jual grosir harus diisi kalau barang ini dijual per kemasan');
            }
            // Pagar yang sama dengan eceran (keputusan rapat): tidak boleh rugi.
            if ($hppGrosir !== null && $hppGrosir > $hargaGrosir) {
                throw new ApiError(400, 'Harga beli grosir (' . Rupiah::f($hppGrosir) .
                    ') lebih tinggi daripada harga jual grosirnya (' . Rupiah::f($hargaGrosir) . ')');
            }
            /*
             * Grosir yang TIDAK lebih murah per satuan bukan grosir.
             *
             * Ditolak, bukan sekadar diperingatkan: pembeli yang memborong satu
             * dus justru membayar lebih mahal daripada membeli satuan, dan itu
             * kekeliruan yang tidak pernah ketahuan sampai ada yang menghitung.
             */
            if ($hargaGrosir >= $harga * $isiGrosir) {
                throw new ApiError(400, 'Harga grosir (' . Rupiah::f($hargaGrosir) . ' per ' .
                    $isiGrosir . ') tidak lebih murah daripada membeli satuan (' .
                    Rupiah::f($harga * $isiGrosir) . '). Periksa lagi angkanya.');
            }
        }

        // Tanggal kedaluwarsa: satu per barang, boleh dikosongkan.
        $expired = trim((string) ($b['expired'] ?? ''));
        if ($expired !== '' && (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $expired) || ! strtotime($expired))) {
            throw new ApiError(400, 'Tanggal expired tidak valid (format YYYY-MM-DD)');
        }

        $barcode = preg_replace('/[^0-9A-Za-z]/', '', (string) ($b['barcode'] ?? ''));
        if ($barcode !== '') {
            if (strlen($barcode) < 4 || strlen($barcode) > 32) {
                throw new ApiError(400, 'SKU/barcode 4–32 karakter angka/huruf');
            }
            $bentrok = Produk::bentrokBarcode($t->id, $barcode, $id);
            if ($bentrok) {
                throw new ApiError(409, "SKU/barcode itu sudah dipakai \"{$bentrok->nama}\"");
            }
        }

        /*
         * Barcode DUS — kode kedua, khusus satuan grosir.
         *
         * Karton punya kode sendiri (ITF-14) yang berbeda dari kode bungkusnya,
         * jadi kasir yang memindai karton harus mendapat satu dus, bukan satu
         * pcs yang lalu ditukar manual.
         *
         * Hanya boleh terisi kalau barangnya memang disiapkan grosir: tanpa isi
         * dan harga dus, pindaian kodenya melahirkan baris yang tidak bisa
         * dihargai. Sebaliknya, setelan grosir yang dikosongkan ikut menghapus
         * kodenya — kode yatim yang tertinggal akan terbaca sebagai pcs dan
         * membingungkan kasir yang mengira ia memindai satu dus.
         */
        $barcodeGrosir = preg_replace('/[^0-9A-Za-z]/', '', (string) ($b['barcode_grosir'] ?? ''));
        if ($barcodeGrosir !== '') {
            /*
             * DITOLAK, bukan dikosongkan diam-diam. Menghapusnya sendiri akan
             * membuang kode yang mungkin baru saja diketik petugas tanpa ada
             * yang memberitahunya; menolaknya menyebutkan persis apa yang
             * kurang. Layar ikut mengosongkan kotak ini saat setelan grosirnya
             * dikosongkan, jadi alur "barang ini tidak lagi dijual per dus"
             * tidak pernah menabrak galat ini — tetapi pagarnya tetap di sini,
             * bukan di layar.
             */
            if ($isiGrosir === null || $hargaGrosir === null) {
                throw new ApiError(400, 'Barcode dus hanya bisa diisi kalau barang ini dijual per ' .
                    'kemasan — isi dulu "Isi per grosir" dan "Harga jual grosir", ' .
                    'atau kosongkan barcode dusnya.');
            }
            if (strlen($barcodeGrosir) < 4 || strlen($barcodeGrosir) > 32) {
                throw new ApiError(400, 'Barcode dus 4–32 karakter angka/huruf');
            }
            if ($barcodeGrosir === $barcode) {
                throw new ApiError(400, 'Barcode dus tidak boleh sama dengan barcode satuannya — ' .
                    'pindaiannya tidak akan bisa dibedakan.');
            }
            $bentrokDus = Produk::bentrokBarcode($t->id, $barcodeGrosir, $id);
            if ($bentrokDus) {
                throw new ApiError(409, "Barcode dus itu sudah dipakai \"{$bentrokDus->nama}\"");
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
            'barcode' => $barcode ?: null, 'barcode_grosir' => $barcodeGrosir ?: null,
            'kategori_id' => $katId ?: null, 'kategori' => $jatah,
            'stok_min' => max(0, (int) ($b['stok_min'] ?? 5)),
            'isi_grosir' => $isiGrosir, 'hpp_grosir' => $hppGrosir, 'harga_grosir' => $hargaGrosir,
            'expired' => $expired ?: null,
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
        $this->wajibAdminToko($r, 'tenant');
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
        $this->wajibAdminToko($r, 'tenant');
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

    // ------------------------------------------------------- impor kolektif

    /** Template Excel, lengkap dengan daftar kategori tenant ini sebagai dropdown. */
    public function imporTemplate(Request $r)
    {
        $this->wajibAdminToko($r, 'tenant');
        $t = $this->martKu($r);
        $stamp = date('Y-m-d');

        return response(ImporBarangMart::template($t), 200, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Content-Disposition' => "attachment; filename=\"template-barang-mart-{$stamp}.xlsx\"",
        ]);
    }

    /** Langkah 1: pratinjau — belum ada satu pun barang yang tersimpan. */
    public function imporPratinjau(Request $r)
    {
        $this->wajibAdminToko($r, 'tenant');
        $t = $this->martKu($r);
        ['isi' => $isi, 'nama' => $nama] = ImporTopup::berkasDariBody($r->all());

        return response()->json(array_merge(
            ['ok' => true, 'nama_berkas' => $nama, 'sidik' => ImporTopup::sidik($isi)],
            ImporBarangMart::urai($isi, $nama, $t),
        ));
    }

    /**
     * Langkah 2: simpan. Berkasnya diurai ULANG dari nol di sini — yang dipakai
     * hasil uraian kedua, bukan baris yang dikirim balik oleh layar.
     */
    public function imporTerapkan(Request $r)
    {
        $this->wajibAdminToko($r, 'tenant');
        $t = $this->martKu($r);
        ['isi' => $isi, 'nama' => $nama] = ImporTopup::berkasDariBody($r->all());
        if ($r->input('sidik') && (string) $r->input('sidik') !== ImporTopup::sidik($isi)) {
            throw new ApiError(409, 'Berkas berubah sejak dipratinjau — ulangi pratinjau dulu');
        }

        $urai = ImporBarangMart::urai($isi, $nama, $t);
        $n = ImporBarangMart::terapkan($urai, $t, $this->operator);

        return response()->json(['ok' => true, 'n' => $n, 'ringkas' => $urai['ringkas']]);
    }

    /** Riwayat pergerakan stok satu barang. */
    public function mutasi(Request $r, int $id)
    {
        $this->wajibAdminToko($r, 'tenant');
        $t = $this->martKu($r);

        return response()->json(['mutasi' => MartStokMutasi::where('tenant_id', $t->id)
            ->where('produk_id', $id)->orderByDesc('id')->limit(60)->get()]);
    }
}
