<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\ApiError;
use App\Models\Produk;
use App\Models\ProdukAjuan;
use App\Models\Tenant;
use App\Models\User;
use App\Services\FormAjuan;
use App\Services\ImporMenu;
use App\Services\ImporTopup;
use App\Services\Pengaturan;
use App\Services\Rupiah;
use App\Support\WaktuIso;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Menu & barang jualan, beserta alur pengajuan perubahannya.
 *
 * Tenant MENGUSULKAN, superadmin MEMUTUSKAN. Daftar menu yang dipakai kasir
 * tidak pernah berubah sampai ada putusan setuju — selama menunggu, santri tetap
 * membayar dengan harga lama, jadi tidak ada kenaikan harga diam-diam.
 *
 * Yang sengaja TIDAK lewat persetujuan: menandai menu habis/tersedia. Itu
 * keputusan harian tenant; kalau harus menunggu admin, menu yang kehabisan stok
 * akan tetap terjual sepanjang pagi.
 */
class ProdukController extends Controller
{
    private const MAKS_AJUAN_MENUNGGU = 20;   // rem anti-banjir per tenant

    public function index(Request $r)
    {
        $u = $this->wajibPeran($r, 'tenant', 'superadmin');
        $tid = $u->role === 'tenant' ? $u->tenant_id : ((int) $r->query('tenant_id') ?: null);

        $rows = $tid
            ? Produk::where('tenant_id', $tid)->orderBy('nama')->get()
            : Produk::orderBy('tenant_id')->orderBy('nama')->get();

        // Halaman kasir perlu tahu jenis tenantnya untuk memutuskan apakah
        // pilihan kategori pada input manual boleh ditampilkan, dan mode
        // kasirnya untuk memutuskan apakah daftar menu ditampilkan sama sekali.
        $tenant = $tid ? Tenant::select('id', 'nama', 'jenis', 'status', 'mode_kasir', 'input_manual')->find($tid) : null;

        return response()->json(['produk' => $rows, 'tenant' => $tenant]);
    }

    /**
     * Kategori makanan/non-makanan — HANYA superadmin, dan tidak lewat
     * pengajuan: ini keputusan sekolah atas barang yang sudah ada, bukan usulan
     * tenant. Tenant yang bisa menandai barangnya sendiri akan menandai semuanya
     * "makanan" supaya lolos dari batas khusus.
     */
    public function kategori(Request $r, int $id)
    {
        $this->wajibPeran($r, 'superadmin');
        $kategori = $r->input('kategori');
        if (! in_array($kategori, ['makanan', 'non_makanan'], true)) {
            throw new ApiError(400, 'Kategori hanya boleh makanan atau non_makanan');
        }
        $pr = Produk::find($id);
        if (! $pr) {
            throw new ApiError(404, 'Menu tidak ditemukan');
        }
        $t = Tenant::find($pr->tenant_id);
        if ($kategori === 'non_makanan' && $t?->jenis !== 'koperasi') {
            throw new ApiError(400, '"' . ($t->nama ?? 'Tenant ini') . '" bukan koperasi — ubah jenisnya dulu ' .
                'di menu Tenant sebelum menandai barang non-makanan');
        }
        $pr->update(['kategori' => $kategori]);

        return response()->json(['ok' => true, 'produk' => $pr->fresh()]);
    }

    /**
     * Superadmin mengubah nama/harga menu LANGSUNG, tanpa menunggu ajuan
     * tenant. Tetap meninggalkan jejak di produk_ajuan (sudah berstatus
     * disetujui) supaya riwayat revisi harga tidak hilang hanya karena yang
     * mengubah kali ini admin, bukan tenant.
     */
    public function ubahLangsung(Request $r, int $id)
    {
        $u = $this->wajibPeran($r, 'superadmin');
        $pr = Produk::find($id);
        if (! $pr) {
            throw new ApiError(404, 'Menu tidak ditemukan');
        }
        $namaBaru = trim((string) ($r->input('nama') ?? $pr->nama));
        [$nama, $harga] = $this->periksaNamaHarga($namaBaru, $r->input('harga') ?? $pr->harga);

        if ($nama === $pr->nama && $harga === (int) $pr->harga) {
            throw new ApiError(400, 'Tidak ada yang berubah dari menu ini');
        }
        if (mb_strtolower($nama) !== mb_strtolower($pr->nama)) {
            $this->tolakNamaKembar($pr->tenant_id, $nama, $pr->id);
        }
        $namaLama = $pr->nama;
        $hargaLama = (int) $pr->harga;
        $pr->update(['nama' => $nama, 'harga' => $harga]);

        $a = ProdukAjuan::create([
            'tenant_id' => $pr->tenant_id, 'produk_id' => $pr->id, 'jenis' => 'ubah',
            'nama' => $nama, 'harga' => $harga, 'nama_lama' => $namaLama, 'harga_lama' => $hargaLama,
            'status' => 'disetujui', 'pengaju' => $u->nama, 'penilai' => $u->nama,
            'catatan' => 'Diubah langsung oleh admin, tanpa menunggu ajuan',
            'decided_at' => WaktuIso::sekarang(),
        ]);

        return response()->json(['ok' => true, 'produk' => $pr->fresh(), 'ajuan' => $a]);
    }

    /** Superadmin menghapus menu LANGSUNG, tanpa menunggu ajuan tenant. */
    public function hapusLangsung(Request $r, int $id)
    {
        $u = $this->wajibPeran($r, 'superadmin');
        $pr = Produk::find($id);
        if (! $pr) {
            throw new ApiError(404, 'Menu tidak ditemukan');
        }
        $namaLama = $pr->nama;
        $hargaLama = (int) $pr->harga;
        $tenantId = $pr->tenant_id;
        $pr->delete();

        $a = ProdukAjuan::create([
            'tenant_id' => $tenantId, 'produk_id' => null, 'jenis' => 'hapus',
            'nama_lama' => $namaLama, 'harga_lama' => $hargaLama,
            'status' => 'disetujui', 'pengaju' => $u->nama, 'penilai' => $u->nama,
            'catatan' => 'Dihapus langsung oleh admin, tanpa menunggu ajuan',
            'decided_at' => WaktuIso::sekarang(),
        ]);

        return response()->json(['ok' => true, 'ajuan' => $a]);
    }

    // ------------------------------------------------ tenant mengusulkan

    public function store(Request $r)
    {
        $u = $this->wajibPeran($r, 'tenant');
        $this->tenantHarusAktif($u->tenant_id);
        [$nama, $harga] = $this->periksaNamaHarga($r->input('nama'), $r->input('harga'));
        $this->tolakNamaKembar($u->tenant_id, $nama);

        $kembar = ProdukAjuan::where('tenant_id', $u->tenant_id)->where('status', 'menunggu')
            ->where('jenis', 'baru')->whereRaw('lower(nama) = lower(?)', [$nama])->exists();
        if ($kembar) {
            throw new ApiError(409, "Menu \"{$nama}\" sudah Anda ajukan dan masih menunggu putusan");
        }
        $this->tolakAjuanGanda($u->tenant_id, null);

        return response()->json(['ok' => true, 'ajuan' => $this->buatAjuan($u, [
            'jenis' => 'baru', 'nama' => $nama, 'harga' => $harga,
        ])]);
    }

    public function update(Request $r, int $id)
    {
        $u = $this->wajibPeran($r, 'tenant');
        $this->tenantHarusAktif($u->tenant_id);
        $pr = Produk::where('id', $id)->where('tenant_id', $u->tenant_id)->first();
        if (! $pr) {
            throw new ApiError(404, 'Menu tidak ditemukan');
        }
        $namaBaru = trim((string) ($r->input('nama') ?? $pr->nama));
        [$nama, $harga] = $this->periksaNamaHarga($namaBaru, $r->input('harga') ?? $pr->harga);

        if ($nama === $pr->nama && $harga === (int) $pr->harga) {
            throw new ApiError(400, 'Tidak ada yang berubah dari menu ini');
        }
        if (mb_strtolower($nama) !== mb_strtolower($pr->nama)) {
            $this->tolakNamaKembar($u->tenant_id, $nama, $pr->id);
        }
        $this->tolakAjuanGanda($u->tenant_id, $pr->id);

        return response()->json(['ok' => true, 'ajuan' => $this->buatAjuan($u, [
            'jenis' => 'ubah', 'produkId' => $pr->id, 'nama' => $nama, 'harga' => $harga,
            'namaLama' => $pr->nama, 'hargaLama' => (int) $pr->harga,
        ])]);
    }

    /** Tandai habis / tersedia — LANGSUNG, tanpa persetujuan. */
    public function ketersediaan(Request $r, int $id)
    {
        $u = $this->wajibPeran($r, 'tenant');
        $pr = Produk::where('id', $id)->where('tenant_id', $u->tenant_id)->first();
        if (! $pr) {
            throw new ApiError(404, 'Menu tidak ditemukan');
        }
        $pr->update(['aktif' => (bool) $r->input('aktif')]);

        return response()->json(['ok' => true, 'produk' => $pr->fresh()]);
    }

    public function destroy(Request $r, int $id)
    {
        $u = $this->wajibPeran($r, 'tenant');
        $this->tenantHarusAktif($u->tenant_id);
        $pr = Produk::where('id', $id)->where('tenant_id', $u->tenant_id)->first();
        if (! $pr) {
            throw new ApiError(404, 'Menu tidak ditemukan');
        }
        $this->tolakAjuanGanda($u->tenant_id, $pr->id);

        return response()->json(['ok' => true, 'ajuan' => $this->buatAjuan($u, [
            'jenis' => 'hapus', 'produkId' => $pr->id,
            'namaLama' => $pr->nama, 'hargaLama' => (int) $pr->harga,
        ])]);
    }

    // ------------------------------------------------ tenant mengusulkan lewat Excel

    public function templateImpor(Request $r)
    {
        $u = $this->wajibPeran($r, 'tenant');
        $t = $this->tenantHarusAktif($u->tenant_id);
        $stamp = date('Y-m-d');

        return response(ImporMenu::template($t), 200, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Content-Disposition' => "attachment; filename=\"template-pengajuan-barang-{$stamp}.xlsx\"",
        ]);
    }

    /** Langkah 1: pratinjau — belum ada ajuan yang dibuat. */
    public function pratinjauImpor(Request $r)
    {
        $u = $this->wajibPeran($r, 'tenant');
        $t = $this->tenantHarusAktif($u->tenant_id);
        ['isi' => $isi, 'nama' => $nama] = ImporTopup::berkasDariBody($r->all());

        return response()->json(array_merge(
            ['ok' => true, 'nama_berkas' => $nama, 'sidik' => ImporTopup::sidik($isi),
                'koperasi' => $t->jenis === 'koperasi'],
            ImporMenu::urai($isi, $nama, $t),
        ));
    }

    /**
     * Langkah 2: kirim. Baris yang siap menjadi ajuan 'baru' dalam satu paket;
     * baris yang gagal tidak dikirim sama sekali.
     */
    public function terapkanImpor(Request $r)
    {
        $u = $this->wajibPeran($r, 'tenant');
        $t = $this->tenantHarusAktif($u->tenant_id);
        ['isi' => $isi, 'nama' => $nama] = ImporTopup::berkasDariBody($r->all());

        $paket = trim((string) $r->input('paket', ''));
        if (! preg_match('/^[A-Za-z0-9-]{6,40}$/', $paket)) {
            throw new ApiError(400, 'Kode paket tidak valid');
        }
        if ($r->input('sidik') && (string) $r->input('sidik') !== ImporTopup::sidik($isi)) {
            throw new ApiError(409, 'Berkas berubah sejak dipratinjau — ulangi pratinjau dulu');
        }
        // Klik ganda / koneksi putus: paket yang sama tidak dibuat dua kali.
        $lama = ProdukAjuan::where('paket', $paket)->where('tenant_id', $t->id)->count();
        if ($lama > 0) {
            return response()->json(['ok' => true, 'duplikat' => true, 'paket' => $paket, 'n' => $lama]);
        }

        $urai = ImporMenu::urai($isi, $nama, $t);
        $siap = array_values(array_filter($urai['baris'], fn ($x) => $x['status'] === 'siap'));
        if (! $siap) {
            throw new ApiError(400, 'Tidak ada barang yang siap diajukan — perbaiki berkasnya dulu');
        }
        // Satu berkas dihitung satu antrean (lihat hitungAntrean).
        $this->tolakAjuanGanda($t->id, null);

        DB::transaction(function () use ($siap, $u, $paket) {
            foreach ($siap as $b) {
                $this->buatAjuan($u, [
                    'jenis' => 'baru', 'nama' => $b['nama'], 'harga' => $b['harga'],
                    'paket' => $paket, 'kategoriUsulan' => $b['kategori'],
                ]);
            }
        });

        return response()->json(['ok' => true, 'paket' => $paket, 'n' => count($siap),
            'n_dilewati' => count($urai['baris']) - count($siap)]);
    }

    // ------------------------------------------------ admin: putusan per paket

    /** Paket impor yang masih punya ajuan menunggu. */
    public function daftarPaket(Request $r)
    {
        $this->wajibPeran($r, 'superadmin');
        $rows = ProdukAjuan::query()
            ->join('tenant', 'tenant.id', '=', 'produk_ajuan.tenant_id')
            ->whereNotNull('produk_ajuan.paket')->where('produk_ajuan.status', 'menunggu')
            ->groupBy('produk_ajuan.paket', 'produk_ajuan.tenant_id', 'tenant.nama', 'tenant.jenis')
            ->selectRaw('produk_ajuan.paket, produk_ajuan.tenant_id, tenant.nama AS tenant_nama, tenant.jenis AS tenant_jenis')
            ->selectRaw('COUNT(*) AS n, MIN(produk_ajuan.created_at) AS created_at, MAX(produk_ajuan.pengaju) AS pengaju')
            ->selectRaw("SUM(CASE WHEN produk_ajuan.kategori_usulan = 'non_makanan' THEN 1 ELSE 0 END) AS n_non_makanan")
            ->orderBy('created_at')->get();

        return response()->json(['paket' => $rows]);
    }

    /** Seluruh isi satu paket — tanpa batas 200 baris milik daftar ajuan biasa. */
    public function isiPaket(Request $r, string $paket)
    {
        $this->wajibPeran($r, 'superadmin');
        $rows = ProdukAjuan::where('paket', $paket)->orderBy('id')->get();
        if ($rows->isEmpty()) {
            throw new ApiError(404, 'Paket tidak ditemukan');
        }
        $t = Tenant::find($rows->first()->tenant_id);

        return response()->json(['paket' => $paket, 'tenant' => $t?->only(['id', 'nama', 'jenis']), 'ajuan' => $rows]);
    }

    /**
     * Setujui sebagian atau seluruh isi paket dalam sekali tekan.
     *
     * body: ids = ajuan yang disetujui (kosong = semua yang menunggu),
     *       kategori = {id: 'makanan'|'non_makanan'} — keputusan admin; yang
     *       tidak disebut memakai usulan tenant.
     * Ajuan yang namanya keburu dipakai menu lain tidak menggagalkan seluruh
     * paket: ia ditolak sendiri dengan alasan yang jelas.
     */
    public function setujuiPaket(Request $r, string $paket)
    {
        $u = $this->wajibPeran($r, 'superadmin');
        $ids = array_map('intval', (array) $r->input('ids', []));
        $kat = (array) $r->input('kategori', []);
        $catatan = mb_substr(trim((string) $r->input('catatan', '')), 0, 300);

        $hasil = DB::transaction(function () use ($paket, $ids, $kat, $catatan, $u) {
            $q = ProdukAjuan::where('paket', $paket)->where('status', 'menunggu')->lockForUpdate();
            if ($ids) {
                $q->whereIn('id', $ids);
            }
            $rows = $q->orderBy('id')->get();
            if ($rows->isEmpty()) {
                throw new ApiError(409, 'Tidak ada ajuan menunggu dalam paket ini');
            }
            $t = Tenant::find($rows->first()->tenant_id);
            $koperasi = $t?->jenis === 'koperasi';
            $sekarang = WaktuIso::sekarang();
            $n = 0;
            $kembar = [];

            foreach ($rows as $a) {
                $ada = Produk::where('tenant_id', $a->tenant_id)
                    ->whereRaw('lower(nama) = lower(?)', [$a->nama])->exists();
                if ($ada) {
                    $a->update(['status' => 'ditolak', 'catatan' => 'Nama sudah ada di daftar menu',
                        'penilai' => $u->nama, 'decided_at' => $sekarang]);
                    $kembar[] = $a->nama;

                    continue;
                }
                $minta = $kat[$a->id] ?? $kat[(string) $a->id] ?? $a->kategori_usulan;
                $kategori = ($minta === 'non_makanan' && $koperasi) ? 'non_makanan' : 'makanan';
                $pr = Produk::create([
                    'tenant_id' => $a->tenant_id, 'nama' => $a->nama,
                    'harga' => $a->harga, 'kategori' => $kategori,
                ]);
                $a->update(['produk_id' => $pr->id, 'status' => 'disetujui', 'catatan' => $catatan,
                    'penilai' => $u->nama, 'decided_at' => $sekarang]);
                $n++;
            }

            return ['n_disetujui' => $n, 'n_kembar' => count($kembar), 'kembar' => $kembar];
        });

        return response()->json(array_merge(['ok' => true,
            'sisa' => ProdukAjuan::where('paket', $paket)->where('status', 'menunggu')->count()], $hasil));
    }

    /** Tolak seluruh ajuan yang masih menunggu dalam paket. */
    public function tolakPaket(Request $r, string $paket)
    {
        $u = $this->wajibPeran($r, 'superadmin');
        $catatan = trim((string) $r->input('catatan', ''));
        if ($catatan === '') {
            throw new ApiError(400, 'Alasan penolakan wajib diisi');
        }
        $n = ProdukAjuan::where('paket', $paket)->where('status', 'menunggu')->update([
            'status' => 'ditolak', 'catatan' => mb_substr($catatan, 0, 300),
            'penilai' => $u->nama, 'decided_at' => WaktuIso::sekarang(),
        ]);
        if ($n === 0) {
            throw new ApiError(409, 'Tidak ada ajuan menunggu dalam paket ini');
        }

        return response()->json(['ok' => true, 'n_ditolak' => $n]);
    }

    // ------------------------------------------------ daftar & putusan

    /**
     * Form pengajuan menu yang bisa dibawa keluar sistem.
     *
     * Keputusannya sering diambil di rapat, bukan di depan layar — berkasnya
     * dicetak, ditandatangani, lalu disimpan sebagai lampiran. Karena itu
     * ekspornya memakai penyaring yang SAMA PERSIS dengan layar antrean: yang
     * dilihat admin dan yang dibaca pimpinan harus berisi baris yang sama,
     * kalau tidak, rapatnya memutuskan daftar yang berbeda dari yang ada di
     * sistem.
     */
    public function eksporAjuan(Request $r)
    {
        [$rows, $meta] = $this->bahanFormAjuan($r);
        $stamp = date('Y-m-d');
        $format = (string) $r->query('format', 'xls');

        if ($format === 'csv') {
            return response(FormAjuan::csv($rows), 200, [
                'Content-Type' => 'text/csv; charset=utf-8',
                'Content-Disposition' => "attachment; filename=\"form-usulan-menu-{$stamp}.csv\"",
            ]);
        }

        // Blok tanda tangan sengaja tidak ikut ke lembar kerja: di Excel ia
        // hanya menjadi sel kosong yang mengacau saat disaring atau diurutkan.
        $html = '<style>' . FormAjuan::gaya() . '</style>' . FormAjuan::html($rows, $meta, false);

        return response($html, 200, [
            'Content-Type' => 'application/vnd.ms-excel',
            'Content-Disposition' => "attachment; filename=\"form-usulan-menu-{$stamp}.xls\"",
        ]);
    }

    /** Versi cetak: peramban yang mengubahnya jadi PDF, tanpa pustaka tambahan. */
    public function cetakAjuan(Request $r)
    {
        [$rows, $meta] = $this->bahanFormAjuan($r);
        $isi = FormAjuan::html($rows, $meta, true);
        $gaya = FormAjuan::gaya();

        $html = <<<HTML
        <!DOCTYPE html><html lang="id"><head><meta charset="utf-8">
        <title>Form Pengajuan Perubahan Menu</title>
        <style>{$gaya}</style></head><body>
        <button class="noprint" onclick="window.print()"
          style="padding:8px 16px;margin-bottom:14px">🖨 Cetak / Simpan PDF</button>
        {$isi}
        <script>window.addEventListener('load',()=>setTimeout(()=>window.print(),400))</script>
        </body></html>
        HTML;

        return response($html, 200, ['Content-Type' => 'text/html; charset=utf-8']);
    }

    /** Penyaring dan meta yang dipakai bersama oleh ekspor dan cetak. */
    private function bahanFormAjuan(Request $r): array
    {
        $u = $this->wajibPeran($r, 'tenant', 'superadmin');
        $this->izinJikaAdmin($r, $u, 'ajuan.lihat');

        $q = ProdukAjuan::query()
            ->join('tenant', 'tenant.id', '=', 'produk_ajuan.tenant_id')
            ->select('produk_ajuan.*', 'tenant.nama as tenant_nama');

        $namaTenant = null;
        if ($u->role === 'tenant') {
            $q->where('produk_ajuan.tenant_id', $u->tenant_id);
            $namaTenant = $u->tenant?->nama;
        } elseif ($tid = (int) $r->query('tenant_id')) {
            $q->where('produk_ajuan.tenant_id', $tid);
            $namaTenant = Tenant::find($tid)?->nama;
        }

        $status = $r->query('status');
        if (in_array($status, ['menunggu', 'disetujui', 'ditolak', 'batal'], true)) {
            $q->where('produk_ajuan.status', $status);
        } else {
            $status = null;
        }

        $rows = $q->orderByRaw("(produk_ajuan.status='menunggu') DESC")
            ->orderBy('tenant.nama')
            ->orderByDesc('produk_ajuan.created_at')->limit(500)->get();

        $tgl = $rows->pluck('created_at')->filter()->sort()->values();
        $wib = fn ($iso) => $iso
            ? (new \DateTime($iso, new \DateTimeZone('UTC')))
                ->setTimezone(new \DateTimeZone('Asia/Jakarta'))->format('d/m/Y')
            : null;
        $periode = $tgl->isEmpty() ? '—'
            : ($wib($tgl->first()) === $wib($tgl->last())
                ? $wib($tgl->first())
                : $wib($tgl->first()) . ' s.d. ' . $wib($tgl->last()));

        $saring = array_filter([
            $namaTenant ? 'Tenant: ' . $namaTenant : 'Semua tenant',
            $status ? 'Status: ' . $status : 'Semua status',
        ]);

        return [$rows, [
            // Belum ada pengaturan nama sekolah; namanya tetap seperti di
            // seluruh sistem. Dibaca dari satu tempat supaya kelak cukup
            // diubah di sini kalau pengaturannya jadi dibuat.
            'sekolah' => FormAjuan::SEKOLAH,
            'judul' => 'Form Pengajuan Perubahan Menu Kantin',
            'periode' => $periode,
            'penyaring' => implode(' · ', $saring),
        ]];
    }

    public function daftarAjuan(Request $r)
    {
        $u = $this->wajibPeran($r, 'tenant', 'superadmin');
        $this->izinJikaAdmin($r, $u, 'ajuan.lihat');
        $q = ProdukAjuan::query()
            ->join('tenant', 'tenant.id', '=', 'produk_ajuan.tenant_id')
            ->select('produk_ajuan.*', 'tenant.nama as tenant_nama');

        if ($u->role === 'tenant') {
            $q->where('produk_ajuan.tenant_id', $u->tenant_id);
        } elseif ($tid = (int) $r->query('tenant_id')) {
            $q->where('produk_ajuan.tenant_id', $tid);
        }
        if (in_array($r->query('status'), ['menunggu', 'disetujui', 'ditolak', 'batal'], true)) {
            $q->where('produk_ajuan.status', $r->query('status'));
        }

        $rows = $q->orderByRaw("(produk_ajuan.status='menunggu') DESC")
            ->orderByDesc('produk_ajuan.created_at')->limit(200)->get();

        $menunggu = ProdukAjuan::where('status', 'menunggu')
            ->when($u->role === 'tenant', fn ($w) => $w->where('tenant_id', $u->tenant_id))
            ->count();

        return response()->json(['ajuan' => $rows, 'menunggu' => $menunggu]);
    }

    /** Tenant membatalkan pengajuannya sendiri. */
    public function batalAjuan(Request $r, int $id)
    {
        $u = $this->wajibPeran($r, 'tenant');
        $a = ProdukAjuan::find($id);
        if (! $a || $a->tenant_id !== $u->tenant_id) {
            throw new ApiError(404, 'Pengajuan tidak ditemukan');
        }
        if ($a->status !== 'menunggu') {
            throw new ApiError(409, 'Pengajuan ini sudah diputus admin');
        }
        $a->update(['status' => 'batal', 'decided_at' => WaktuIso::sekarang()]);

        return response()->json(['ok' => true, 'ajuan' => $a->fresh()]);
    }

    public function setujui(Request $r, int $id)
    {
        $u = $this->wajibPeran($r, 'superadmin');
        $a = $this->ajuanMenunggu($id);
        $catatan = mb_substr(trim((string) $r->input('catatan', '')), 0, 300);
        $kategoriMinta = $r->input('kategori');

        $hasil = DB::transaction(function () use ($a, $kategoriMinta) {
            if ($a->jenis === 'baru') {
                // Nama bisa saja keburu dipakai menu lain sejak pengajuan dibuat.
                $this->tolakNamaKembar($a->tenant_id, $a->nama);
                // Kategori ditetapkan admin saat menyetujui, bukan diusulkan
                // tenant. Bawaannya 'makanan': salah ke arah ini hanya membuat
                // barang memakai jatah makanan, sedangkan salah ke arah
                // sebaliknya bisa membuat nasi bungkus tertolak karena jatah
                // non-makanan sudah habis.
                $t = Tenant::find($a->tenant_id);
                if ($kategoriMinta === 'non_makanan' && $t?->jenis !== 'koperasi') {
                    throw new ApiError(400, 'Hanya tenant koperasi yang boleh punya barang non-makanan');
                }
                $kategori = ($kategoriMinta === 'non_makanan' && $t?->jenis === 'koperasi') ? 'non_makanan' : 'makanan';
                $pr = Produk::create([
                    'tenant_id' => $a->tenant_id, 'nama' => $a->nama,
                    'harga' => $a->harga, 'kategori' => $kategori,
                ])->fresh();
                $a->update(['produk_id' => $pr->id]);

                return ['produk' => $pr];
            }

            // Menu bisa sudah lenyap lewat pengajuan hapus yang disetujui lebih dulu.
            $pr = $a->produk_id ? Produk::find($a->produk_id) : null;
            if (! $pr) {
                throw new ApiError(409, "Menu \"{$a->nama_lama}\" sudah tidak ada — pengajuan ini tidak bisa disetujui, silakan tolak");
            }
            if ($a->jenis === 'ubah') {
                $this->tolakNamaKembar($a->tenant_id, $a->nama, $pr->id);
                $pr->update(['nama' => $a->nama, 'harga' => $a->harga]);

                return ['produk' => $pr->fresh()];
            }
            $pr->delete();   // produk_id ajuan menjadi NULL sendiri (ON DELETE SET NULL)

            return ['produk' => null];
        });

        $a->update(['status' => 'disetujui', 'catatan' => $catatan,
            'penilai' => $u->nama, 'decided_at' => WaktuIso::sekarang()]);

        return response()->json(array_merge(['ok' => true, 'ajuan' => $a->fresh()], $hasil));
    }

    public function tolak(Request $r, int $id)
    {
        $u = $this->wajibPeran($r, 'superadmin');
        $a = $this->ajuanMenunggu($id);
        // Alasan diwajibkan: tenant yang ditolak tanpa keterangan akan
        // mengajukan hal yang sama lagi minggu depan.
        $catatan = trim((string) $r->input('catatan', ''));
        if ($catatan === '') {
            throw new ApiError(400, 'Alasan penolakan wajib diisi');
        }
        $a->update(['status' => 'ditolak', 'catatan' => mb_substr($catatan, 0, 300),
            'penilai' => $u->nama, 'decided_at' => WaktuIso::sekarang()]);

        return response()->json(['ok' => true, 'ajuan' => $a->fresh()]);
    }

    // ------------------------------------------------ penolong

    private function tenantHarusAktif(?int $tenantId): Tenant
    {
        $t = Tenant::find($tenantId);
        if (! $t) {
            throw new ApiError(404, 'Tenant tidak ditemukan');
        }
        if ($t->status !== 'aktif') {
            throw new ApiError(403, 'Tenant sedang nonaktif — hubungi admin kantin');
        }

        return $t;
    }

    /** Satu menu cukup satu usulan berjalan: usulan bertumpuk membuat putusan admin ambigu. */
    private function tolakAjuanGanda(int $tenantId, ?int $produkId): void
    {
        if ($produkId && ProdukAjuan::where('produk_id', $produkId)->where('status', 'menunggu')->exists()) {
            throw new ApiError(409, 'Menu ini masih punya pengajuan yang menunggu putusan admin');
        }
        $n = self::hitungAntrean($tenantId);
        if ($n >= self::MAKS_AJUAN_MENUNGGU) {
            throw new ApiError(429, "Sudah ada {$n} pengajuan menunggu — tunggu putusan admin dulu");
        }
    }

    /**
     * Banyaknya antrean menunggu milik satu tenant. Satu berkas impor dihitung
     * SATU, berapa pun barangnya — kalau tidak, rem anti-banjir akan menolak
     * setiap berkas koperasi yang barangnya lebih dari dua puluh.
     */
    private static function hitungAntrean(int $tenantId): int
    {
        $q = ProdukAjuan::where('tenant_id', $tenantId)->where('status', 'menunggu');
        $lepas = (clone $q)->whereNull('paket')->count();
        $paket = (clone $q)->whereNotNull('paket')->distinct()->count('paket');

        return $lepas + $paket;
    }

    private function periksaNamaHarga($namaRaw, $hargaRaw): array
    {
        $nama = trim((string) $namaRaw);
        if ($nama === '') {
            throw new ApiError(400, 'Nama menu wajib diisi');
        }
        if (mb_strlen($nama) > 60) {
            throw new ApiError(400, 'Nama menu maksimal 60 karakter');
        }
        $harga = (int) floor((float) $hargaRaw);
        if ($harga <= 0) {
            throw new ApiError(400, 'Harga tidak valid');
        }
        $maks = Pengaturan::sekolah()['max_per_transaksi'] ?? 0;
        if ($harga > $maks) {
            throw new ApiError(400, 'Harga melebihi batas satu transaksi (' . Rupiah::f($maks) . ')');
        }

        return [$nama, $harga];
    }

    /** Nama kembar dalam satu tenant bikin kasir salah pencet — ditolak sejak diusulkan. */
    private function tolakNamaKembar(int $tenantId, string $nama, ?int $kecualiId = null): void
    {
        $q = Produk::where('tenant_id', $tenantId)->whereRaw('lower(nama) = lower(?)', [$nama]);
        if ($kecualiId) {
            $q->where('id', '!=', $kecualiId);
        }
        if ($q->exists()) {
            throw new ApiError(409, "Menu bernama \"{$nama}\" sudah ada di daftar Anda");
        }
    }

    /** Putusan hanya boleh sekali. Dipisah agar setujui & tolak tidak beda aturan. */
    private function ajuanMenunggu(int $id): ProdukAjuan
    {
        $a = ProdukAjuan::find($id);
        if (! $a) {
            throw new ApiError(404, 'Pengajuan tidak ditemukan');
        }
        if ($a->status !== 'menunggu') {
            throw new ApiError(409, 'Pengajuan ini sudah ' .
                ($a->status === 'batal' ? 'dibatalkan tenant' : $a->status));
        }

        return $a;
    }

    private function buatAjuan(User $u, array $x): ProdukAjuan
    {
        return ProdukAjuan::create([
            'tenant_id' => $u->tenant_id,
            'produk_id' => $x['produkId'] ?? null,
            'jenis' => $x['jenis'],
            'paket' => $x['paket'] ?? null,
            'kategori_usulan' => $x['kategoriUsulan'] ?? null,
            'nama' => $x['nama'] ?? '',
            'harga' => $x['harga'] ?? null,
            'nama_lama' => $x['namaLama'] ?? '',
            'harga_lama' => $x['hargaLama'] ?? null,
            'pengaju' => $u->nama,
        ])->fresh();
    }
}
