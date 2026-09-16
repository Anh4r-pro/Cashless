<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\ApiError;
use App\Models\Produk;
use App\Models\ProdukAjuan;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Pengaturan;
use App\Services\Rupiah;
use App\Support\WaktuIso;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Menu & barang jualan, beserta alur pengajuan perubahannya.
 *
 * Tenant MENGUSULKAN, superadmin MEMUTUSKAN. Daftar menu yang dipakai kasir
 * tidak pernah berubah sampai ada putusan setuju — selama menunggu, siswa tetap
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
        // pilihan kategori pada input manual boleh ditampilkan.
        $tenant = $tid ? Tenant::select('id', 'nama', 'jenis', 'status')->find($tid) : null;

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

    // ------------------------------------------------ daftar & putusan

    public function daftarAjuan(Request $r)
    {
        $u = $this->wajibPeran($r, 'tenant', 'superadmin');
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
        $n = ProdukAjuan::where('tenant_id', $tenantId)->where('status', 'menunggu')->count();
        if ($n >= self::MAKS_AJUAN_MENUNGGU) {
            throw new ApiError(429, "Sudah ada {$n} pengajuan menunggu — tunggu putusan admin dulu");
        }
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
            'nama' => $x['nama'] ?? '',
            'harga' => $x['harga'] ?? null,
            'nama_lama' => $x['namaLama'] ?? '',
            'harga_lama' => $x['hargaLama'] ?? null,
            'pengaju' => $u->nama,
        ])->fresh();
    }
}
