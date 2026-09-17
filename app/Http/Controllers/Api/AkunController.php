<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\ApiError;
use App\Models\KategoriAkses;
use App\Models\SesiStaf;
use App\Models\User;
use App\Services\Izin;
use App\Support\WaktuIso;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/**
 * Pengelolaan akun pengguna sisi admin.
 *
 * Akun KASIR sengaja tidak diurus di sini: satu akun kasir melekat pada satu
 * tenant dan dibuat bersama tenantnya (lihat TenantController). Mencampur
 * keduanya di satu layar membuat "hapus akun" berarti dua hal yang berbeda.
 *
 * Yang bisa dilakukan di sini hanya menyentuh baris `users` — tidak ada satu
 * pun jalan untuk menaikkan hak diri sendiri, karena kategori yang dipilih
 * harus sudah ada dan kategori itu sendiri hanya bisa disetel oleh pemegang
 * `akses.kelola`.
 */
class AkunController extends Controller
{
    private const MIN_SANDI = 8;

    public function index(Request $r)
    {
        $this->wajibIzin($r, 'akun.lihat');

        $rows = User::with(['kategori', 'tenant'])->orderBy('role')->orderBy('username')->get()
            ->map(fn ($u) => [
                'id' => $u->id,
                'username' => $u->username,
                'nama' => $u->nama,
                'role' => $u->role,
                'kategori_id' => $u->kategori_id,
                'kategori_nama' => $u->kategori?->nama
                    ?? ($u->role === 'superadmin' ? 'Akses Penuh (bawaan)' : null),
                'tenant_nama' => $u->tenant?->nama,
                'created_at' => $u->created_at,
                // Akun bawaan tanpa kategori: ditandai supaya admin tahu mengapa
                // baris ini memegang semuanya padahal tak satu kategori pun dipilih.
                'warisan' => $u->role === 'superadmin' && $u->kategori_id === null ? 1 : 0,
            ]);

        return response()->json([
            'akun' => $rows,
            'kategori' => KategoriAkses::orderBy('urutan')->orderBy('id')->get(['id', 'nama', 'terkunci']),
        ]);
    }

    public function store(Request $r)
    {
        $this->wajibIzin($r, 'akun.kelola');

        $username = $this->username($r->input('username'), null);
        $sandi = $this->sandi($r->input('password'));
        $nama = $this->namaOrang($r->input('nama'));
        $kategori = $this->kategori($r->input('kategori_id'));

        $u = User::create([
            'username' => $username,
            'password' => $sandi,
            'nama' => $nama,
            'role' => 'superadmin',
            'kategori_id' => $kategori->id,
            'created_at' => WaktuIso::sekarang(),
        ])->fresh();

        return response()->json(['ok' => true, 'akun' => [
            'id' => $u->id, 'username' => $u->username, 'nama' => $u->nama,
            'role' => $u->role, 'kategori_id' => $u->kategori_id, 'kategori_nama' => $kategori->nama,
        ]]);
    }

    public function update(Request $r, int $id)
    {
        $aku = $this->wajibIzin($r, 'akun.kelola');
        $u = User::find($id);
        if (! $u) {
            throw new ApiError(404, 'Akun tidak ditemukan');
        }
        if ($u->role === 'tenant') {
            throw new ApiError(409, 'Akun kasir diubah lewat menu Tenant, bukan di sini');
        }

        $isi = [];
        if ($r->exists('username')) {
            $isi['username'] = $this->username($r->input('username'), $u->id);
        }
        if ($r->exists('nama')) {
            $isi['nama'] = $this->namaOrang($r->input('nama'));
        }
        if ($r->filled('password')) {
            $isi['password'] = $this->sandi($r->input('password'));
        }
        if ($r->exists('kategori_id')) {
            $kat = $this->kategori($r->input('kategori_id'));
            // Pengaman kunci-diri-sendiri, sama seperti di AksesController:
            // memindahkan diri ke kategori tanpa `akun.kelola` membuat menu ini
            // tidak bisa dibuka lagi oleh orang yang baru saja memakainya.
            if ($u->id === $aku->id && ! in_array('akun.kelola', $this->fiturKategori($kat), true)) {
                throw new ApiError(409, 'Kategori itu tidak punya hak mengelola akun — ' .
                    'memindahkan akun Anda sendiri ke sana akan mengunci Anda keluar dari menu ini');
            }
            $isi['kategori_id'] = $kat->id;
        }

        if ($isi) {
            $u->update($isi);
            // Ganti sandi / username / kategori → sesi lama digugurkan, supaya
            // peramban yang masih terbuka di tempat lain tidak terus memakai
            // hak yang baru saja dicabut.
            if (isset($isi['password']) || isset($isi['username']) || isset($isi['kategori_id'])) {
                SesiStaf::where('user_id', $u->id)->delete();
            }
        }

        return response()->json(['ok' => true, 'akun' => [
            'id' => $u->id, 'username' => $u->username, 'nama' => $u->nama,
            'role' => $u->role, 'kategori_id' => $u->kategori_id,
        ]]);
    }

    public function destroy(Request $r, int $id)
    {
        $aku = $this->wajibIzin($r, 'akun.kelola');
        $u = User::find($id);
        if (! $u) {
            throw new ApiError(404, 'Akun tidak ditemukan');
        }
        if ($u->id === $aku->id) {
            throw new ApiError(409, 'Tidak bisa menghapus akun yang sedang Anda pakai');
        }
        if ($u->role === 'tenant') {
            throw new ApiError(409, 'Akun kasir dihapus bersama tenantnya lewat menu Tenant');
        }
        // Selalu harus tersisa seorang yang bisa membuka menu ini.
        if ($this->penjagaTerakhir($u)) {
            throw new ApiError(409, 'Ini satu-satunya akun yang masih bisa mengelola akun — ' .
                'buat penggantinya dulu sebelum menghapus yang ini');
        }

        DB::transaction(function () use ($u) {
            SesiStaf::where('user_id', $u->id)->delete();
            $u->delete();
        });

        return response()->json(['ok' => true]);
    }

    /**
     * Ganti sandi sendiri — satu-satunya jalan bagi pemegang akun untuk
     * mengganti sandinya tanpa menunggu admin lain, termasuk bagi superadmin
     * bawaan yang sandinya masih sandi pemasangan.
     */
    public function gantiSandi(Request $r)
    {
        $u = $this->staf($r);

        if (! Hash::check((string) $r->input('password_lama'), $u->password)) {
            throw new ApiError(401, 'Sandi lama salah');
        }
        $baru = $this->sandi($r->input('password_baru'));
        if (Hash::check($baru, $u->password)) {
            throw new ApiError(400, 'Sandi baru sama dengan sandi lama');
        }

        $u->update(['password' => $baru]);
        // Sesi lain digugurkan, sesi yang sedang dipakai dipertahankan: mengganti
        // sandi karena curiga sandinya bocor tidak ada gunanya kalau sesi yang
        // terlanjur dipegang orang lain tetap hidup.
        SesiStaf::where('user_id', $u->id)->where('token', '!=', (string) $r->cookie('sid'))->delete();

        return response()->json(['ok' => true, 'pesan' => 'Sandi berhasil diganti']);
    }

    // ------------------------------------------------------------ pembantu

    private function fiturKategori(KategoriAkses $k): array
    {
        return $k->terkunci ? Izin::semua() : $k->fitur()->pluck('fitur')->all();
    }

    /**
     * Apakah akun ini satu-satunya yang tersisa dengan hak `akun.kelola`?
     * Dihitung dari data, bukan diasumsikan dari peran — kategori bisa disetel
     * bebas, jadi jumlah penjaganya hanya bisa diketahui dengan menghitung.
     */
    private function penjagaTerakhir(User $calon): bool
    {
        $lain = User::where('role', 'superadmin')->where('id', '!=', $calon->id)->get();
        foreach ($lain as $u) {
            if (Izin::boleh($u, 'akun.kelola')) {
                return false;
            }
        }

        return Izin::boleh($calon, 'akun.kelola');
    }

    private function kategori($raw): KategoriAkses
    {
        $id = (int) $raw;
        if (! $id) {
            throw new ApiError(400, 'Kategori akses wajib dipilih');
        }
        $k = KategoriAkses::find($id);
        if (! $k) {
            throw new ApiError(404, 'Kategori akses tidak ditemukan');
        }

        return $k;
    }

    private function username($raw, ?int $kecualiId): string
    {
        $v = strtolower(trim((string) $raw));
        if (! preg_match('/^[a-z0-9._-]{3,30}$/', $v)) {
            throw new ApiError(400, 'Username 3–30 karakter, hanya huruf kecil, angka, titik, strip, garis bawah');
        }
        $q = User::where('username', $v);
        if ($kecualiId) {
            $q->where('id', '!=', $kecualiId);
        }
        if ($q->exists()) {
            throw new ApiError(409, "Username \"{$v}\" sudah dipakai");
        }

        return $v;
    }

    private function namaOrang($raw): string
    {
        $v = trim((string) $raw);
        if ($v === '') {
            throw new ApiError(400, 'Nama lengkap wajib diisi');
        }

        return mb_substr($v, 0, 120);
    }

    /**
     * Sandi akun admin wajib minimal 8 karakter. Sengaja LEBIH ketat daripada
     * sandi kasir warisan yang sudah terlanjur pendek: yang dibuat lewat layar
     * ini semuanya akun baru, jadi tidak ada data lama yang dirugikan.
     */
    private function sandi($raw): string
    {
        $v = (string) $raw;
        if (strlen($v) < self::MIN_SANDI) {
            throw new ApiError(400, 'Sandi minimal ' . self::MIN_SANDI . ' karakter');
        }
        if (strlen($v) > 200) {
            throw new ApiError(400, 'Sandi terlalu panjang');
        }

        return $v;
    }
}
