<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\ApiError;
use App\Models\Produk;
use App\Models\ProdukAjuan;
use App\Models\SesiStaf;
use App\Models\Tenant;
use App\Services\Keranjang;
use App\Services\StatistikTenant;
use App\Models\Transaksi;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/** Kantin & koperasi yang berjualan di sekolah, beserta akun stafnya. */
class TenantController extends Controller
{
    /**
     * Daftar tenant. Dengan ?stat=1 ikut membawa penjualan & tagihan berjalan.
     *
     * Statistiknya OPSIONAL, dan itu disengaja: ia dihitung dua kueri per
     * tenant, sementara sebagian besar pemanggil — isian saringan di layar
     * Riwayat dan Analitik — hanya butuh nama untuk mengisi dropdown.
     * Memaksakan hitungan itu ke semua pemanggil berarti setiap kali layar
     * Riwayat dibuka, dua puluh delapan kueri dijalankan untuk menghasilkan
     * sebaris nama.
     */
    public function index(Request $r)
    {
        $this->wajibPeran($r, 'superadmin');

        $tenants = Tenant::orderBy('id')
            ->selectRaw("tenant.*, (SELECT GROUP_CONCAT(username) FROM users u WHERE u.tenant_id = tenant.id) AS usernames")
            ->get();

        if ($r->boolean('stat')) {
            $tenants = $tenants->map(fn ($t) => array_merge($t->toArray(), StatistikTenant::untuk($t->id)));
        }

        return response()->json(['tenants' => $tenants]);
    }

    public function store(Request $r)
    {
        $this->wajibPeran($r, 'superadmin');
        $nama = trim((string) $r->input('nama'));
        $username = strtolower(trim((string) $r->input('username')));
        $password = (string) $r->input('password');

        if ($nama === '') {
            throw new ApiError(400, 'Nama tenant wajib diisi');
        }
        if ($username === '' || $password === '') {
            throw new ApiError(400, 'Username & password akun tenant wajib diisi');
        }
        if (User::where('username', $username)->exists()) {
            throw new ApiError(409, 'Username sudah dipakai');
        }

        $t = DB::transaction(function () use ($r, $nama, $username, $password) {
            $t = Tenant::create([
                'nama' => $nama,
                'pemilik' => trim((string) $r->input('pemilik', '')),
                'jenis' => in_array($r->input('jenis'), ['kantin', 'koperasi'], true) ? $r->input('jenis') : 'kantin',
                'mode_kasir' => in_array($r->input('mode_kasir'), Keranjang::MODE_KASIR, true) ? $r->input('mode_kasir') : 'menu',
                'input_manual' => $r->input('input_manual') === null ? true : (bool) $r->input('input_manual'),
                'target_rab_bulanan' => $r->input('target_rab_bulanan') !== null ? (int) $r->input('target_rab_bulanan') : null,
            ]);
            User::create([
                'username' => $username, 'password' => $password,
                'nama' => trim((string) $r->input('pemilik')) ?: $nama,
                'role' => 'tenant', 'tenant_id' => $t->id,
            ]);

            return $t->fresh();
        });

        return response()->json(['ok' => true, 'tenant' => $t]);
    }

    public function update(Request $r, int $id)
    {
        $this->wajibPeran($r, 'superadmin');
        $t = Tenant::find($id);
        if (! $t) {
            throw new ApiError(404, 'Tenant tidak ditemukan');
        }
        $jenisLama = $t->jenis;
        $jenis = in_array($r->input('jenis'), ['kantin', 'koperasi'], true) ? $r->input('jenis') : $t->jenis;
        $direset = 0;

        DB::transaction(function () use ($r, $t, $jenis, $jenisLama, &$direset) {
            $t->update([
                'nama' => trim((string) $r->input('nama', $t->nama)),
                'pemilik' => trim((string) $r->input('pemilik', $t->pemilik)),
                'status' => in_array($r->input('status'), ['aktif', 'nonaktif'], true) ? $r->input('status') : $t->status,
                'jenis' => $jenis,
                'mode_kasir' => in_array($r->input('mode_kasir'), Keranjang::MODE_KASIR, true)
                    ? $r->input('mode_kasir') : ($t->mode_kasir ?? 'menu'),
                // Tidak disebut = tidak diubah. Layar lain ikut memanggil
                // endpoint ini (mis. menonaktifkan tenant), dan sakelar yang
                // sudah dimatikan tidak boleh menyala lagi karena itu.
                'input_manual' => $r->input('input_manual') === null
                    ? $t->input_manual : (bool) $r->input('input_manual'),
                // Sama seperti input_manual di atas: tidak disebut = tidak diubah.
                'target_rab_bulanan' => $r->has('target_rab_bulanan')
                    ? ($r->input('target_rab_bulanan') !== null ? (int) $r->input('target_rab_bulanan') : null)
                    : $t->target_rab_bulanan,
            ]);
            // Koperasi → kantin: barang non-makanan miliknya dikembalikan ke
            // 'makanan'. Kalau tidak, tenant ini punya barang berkategori
            // non-makanan yang tidak bisa lagi dilihat maupun disunting di layar mana pun.
            if ($jenis !== 'koperasi' && $jenisLama === 'koperasi') {
                $direset = Produk::where('tenant_id', $t->id)->where('kategori', 'non_makanan')
                    ->update(['kategori' => 'makanan']);
            }
        });

        if ($baru = $r->input('password_baru')) {
            // 'admin' disebut eksplisit: tenant yang sudah punya akun kasir
            // tambahan tidak boleh ikut ganti sandi akun kasirnya di sini.
            if ($u = User::where('tenant_id', $t->id)->where('peran_tenant', 'admin')->first()) {
                $u->update(['password' => (string) $baru]);
                // Sesi lama digugurkan: sandi diganti biasanya justru karena
                // sandi lamanya sudah tidak dipercaya.
                SesiStaf::where('user_id', $u->id)->delete();
            }
        }

        return response()->json(['ok' => true, 'tenant' => $t->fresh(), 'kategori_direset' => $direset]);
    }

    public function destroy(Request $r, int $id)
    {
        $this->wajibPeran($r, 'superadmin');
        if (Transaksi::where('tenant_id', $id)->exists()) {
            Tenant::where('id', $id)->update(['status' => 'nonaktif']);

            return response()->json(['ok' => true, 'nonaktif' => true,
                'pesan' => 'Tenant punya riwayat — dinonaktifkan, tidak dihapus']);
        }

        DB::transaction(function () use ($id) {
            $uid = User::where('tenant_id', $id)->pluck('id');
            SesiStaf::whereIn('user_id', $uid)->delete();
            User::where('tenant_id', $id)->delete();
            // Pengajuan menu juga menunjuk tenant ini. Tanpa baris ini, tenant
            // yang pernah mengajukan menu — walau pengajuannya ditolak dan ia
            // tidak pernah berjualan — gagal dihapus dengan galat kunci asing
            // yang muncul sebagai 500, bukan penolakan yang bisa dimengerti.
            ProdukAjuan::where('tenant_id', $id)->delete();
            Produk::where('tenant_id', $id)->delete();
            Tenant::where('id', $id)->delete();
        });

        return response()->json(['ok' => true]);
    }

    // --------------------------------------------- akun kasir (serve-only)

    /** Daftar akun kasir (serve-only) milik satu tenant. */
    public function kasirIndex(Request $r, int $id)
    {
        $this->wajibPeran($r, 'superadmin');
        $t = Tenant::find($id);
        if (! $t) {
            throw new ApiError(404, 'Tenant tidak ditemukan');
        }

        return response()->json(['kasir' => User::where('tenant_id', $t->id)
            ->where('peran_tenant', 'kasir')->orderBy('nama')
            ->get(['id', 'nama', 'username'])]);
    }

    /**
     * Tambah akun kasir baru untuk satu tenant — login terpisah dari akun
     * admin toko, serve-only lewat `Controller::wajibAdminToko()`.
     */
    public function kasirStore(Request $r, int $id)
    {
        $this->wajibPeran($r, 'superadmin');
        $t = Tenant::find($id);
        if (! $t) {
            throw new ApiError(404, 'Tenant tidak ditemukan');
        }
        $nama = trim((string) $r->input('nama'));
        $username = strtolower(trim((string) $r->input('username')));
        $password = (string) $r->input('password');
        if ($nama === '') {
            throw new ApiError(400, 'Nama akun kasir wajib diisi');
        }
        if ($username === '' || $password === '') {
            throw new ApiError(400, 'Username & password akun kasir wajib diisi');
        }
        if (User::where('username', $username)->exists()) {
            throw new ApiError(409, 'Username sudah dipakai');
        }

        $u = User::create([
            'username' => $username, 'password' => $password, 'nama' => $nama,
            'role' => 'tenant', 'tenant_id' => $t->id, 'peran_tenant' => 'kasir',
        ]);

        return response()->json(['ok' => true, 'kasir' => $u->only(['id', 'nama', 'username'])]);
    }

    /** Ubah nama dan/atau reset sandi satu akun kasir. */
    public function kasirUpdate(Request $r, int $id, int $userId)
    {
        $this->wajibPeran($r, 'superadmin');
        $u = User::where('id', $userId)->where('tenant_id', $id)->where('peran_tenant', 'kasir')->first();
        if (! $u) {
            throw new ApiError(404, 'Akun kasir tidak ditemukan');
        }

        $isi = [];
        if ($r->filled('nama')) {
            $isi['nama'] = trim((string) $r->input('nama'));
        }
        $baru = $r->input('password_baru');
        if ($baru) {
            $isi['password'] = (string) $baru;
        }
        if ($isi) {
            $u->update($isi);
        }
        if ($baru) {
            // Sesi lama digugurkan: sandi diganti biasanya justru karena
            // sandi lamanya sudah tidak dipercaya.
            SesiStaf::where('user_id', $u->id)->delete();
        }

        return response()->json(['ok' => true, 'kasir' => $u->fresh()->only(['id', 'nama', 'username'])]);
    }

    /** Hapus satu akun kasir — tidak menyentuh akun admin toko maupun kasir lain. */
    public function kasirHapus(Request $r, int $id, int $userId)
    {
        $this->wajibPeran($r, 'superadmin');
        $u = User::where('id', $userId)->where('tenant_id', $id)->where('peran_tenant', 'kasir')->first();
        if (! $u) {
            throw new ApiError(404, 'Akun kasir tidak ditemukan');
        }
        SesiStaf::where('user_id', $u->id)->delete();
        $u->delete();

        return response()->json(['ok' => true]);
    }
}
