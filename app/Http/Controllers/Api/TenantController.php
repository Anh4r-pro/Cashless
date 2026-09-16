<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\ApiError;
use App\Models\Produk;
use App\Models\ProdukAjuan;
use App\Models\SesiStaf;
use App\Models\Tenant;
use App\Models\Transaksi;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/** Kantin & koperasi yang berjualan di sekolah, beserta akun stafnya. */
class TenantController extends Controller
{
    public function index(Request $r)
    {
        $this->wajibPeran($r, 'superadmin');

        return response()->json([
            'tenants' => Tenant::orderBy('id')
                ->selectRaw("tenant.*, (SELECT GROUP_CONCAT(username) FROM users u WHERE u.tenant_id = tenant.id) AS usernames")
                ->get(),
        ]);
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
            if ($u = User::where('tenant_id', $t->id)->first()) {
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
}
