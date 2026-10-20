<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\ApiError;
use App\Models\User;
use App\Services\Izin;
use Illuminate\Http\Request;

/**
 * Dasar seluruh controller API.
 *
 * Menyediakan akses ke staf yang sedang masuk beserta pagar perannya. Pagar
 * ini adalah lapis kedua — pembatasan yang sebenarnya ada di kueri masing-
 * masing controller, karena menyembunyikan menu bukan pengamanan.
 */
abstract class Controller
{
    protected function staf(Request $r): User
    {
        $u = $r->attributes->get('staf');
        if (! $u) {
            throw new ApiError(401, 'Belum masuk');
        }

        return $u;
    }

    protected function wajibPeran(Request $r, string ...$peran): User
    {
        $u = $this->staf($r);
        if (! in_array($u->role, $peran, true)) {
            throw new ApiError(403, 'Tidak berhak');
        }

        return $u;
    }

    /**
     * Pagar per aksi untuk endpoint yang melayani lebih dari satu layar,
     * sehingga fitur yang dibutuhkan baru ketahuan setelah isi permintaan
     * dibaca — misalnya endpoint pemegang kartu yang dipakai layar Santri
     * maupun layar SDM.
     */
    protected function wajibIzin(Request $r, string $kode): User
    {
        $u = $this->wajibPeran($r, 'superadmin');
        Izin::wajib($u, $kode);

        return $u;
    }

    /**
     * Pagar untuk aksi yang hanya boleh admin toko, bukan akun kasir serve-only.
     *
     * Akun kasir (peran_tenant='kasir') melayani penjualan tapi tidak mengolah
     * data — stok, member, pembelian, dan opname tetap milik admin toko.
     * Superadmin selalu lolos: kondisinya hanya menyala untuk role 'tenant'.
     */
    protected function wajibAdminToko(Request $r, string ...$peran): User
    {
        $u = $this->wajibPeran($r, ...$peran);
        if ($u->role === 'tenant' && $u->peran_tenant === 'kasir') {
            throw new ApiError(403, 'Akun ini hanya bisa melayani penjualan — fitur ini khusus admin toko.');
        }

        return $u;
    }

    /**
     * Pagar untuk endpoint yang dipakai BERSAMA oleh kasir dan admin.
     *
     * Akun kasir tidak punya kategori akses sama sekali, sehingga memasang
     * `izin:` di rutenya akan menolak kasir dari layarnya sendiri. Di sini
     * izin hanya dituntut bila yang memanggil memang akun sisi admin —
     * jangkauan data kasir tetap dipagari tenant_id di kueri masing-masing.
     */
    protected function izinJikaAdmin(Request $r, User $u, string $kode): void
    {
        if ($u->role === 'superadmin') {
            Izin::wajib($u, $kode);
        }
    }
}
