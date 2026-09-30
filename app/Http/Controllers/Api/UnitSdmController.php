<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\ApiError;
use App\Models\Siswa;
use App\Models\UnitSdm;
use App\Support\WaktuIso;
use Illuminate\Http\Request;

/**
 * Daftar Unit untuk SDM & personil.
 *
 * Bukan seperti golongan (kode tetap yang dirujuk kolom lain): unit tersimpan
 * apa adanya di siswa.kelas untuk baris SDM, jadi mengganti NAMA di sini
 * TIDAK ikut mengubah data lama yang sudah memakai nama sebelumnya — sama
 * seperti endpoint satu-satu yang sudah lama menerima unit di luar daftar
 * (lihat BidangSiswa::UNIT_SDM).
 */
class UnitSdmController extends Controller
{
    private const MAKS = 30;

    public function index(Request $r)
    {
        $this->wajibPeran($r, 'superadmin');
        $n = Siswa::where('golongan', '!=', 'siswa')
            ->selectRaw('kelas, COUNT(*) n')->groupBy('kelas')->pluck('n', 'kelas');

        return response()->json([
            'unit' => UnitSdm::orderBy('urutan')->orderBy('id')->get()
                ->map(fn (UnitSdm $u) => array_merge($u->toArray(), ['jumlah' => (int) ($n[$u->nama] ?? 0)])),
        ]);
    }

    public function store(Request $r)
    {
        $this->wajibPeran($r, 'superadmin');
        $nama = mb_substr(trim((string) $r->input('nama', '')), 0, 30);
        if ($nama === '') {
            throw new ApiError(400, 'Nama unit wajib diisi');
        }
        if (UnitSdm::whereRaw('LOWER(nama) = ?', [mb_strtolower($nama)])->exists()) {
            throw new ApiError(409, "Unit \"{$nama}\" sudah ada");
        }
        if (UnitSdm::count() >= self::MAKS) {
            throw new ApiError(409, 'Maksimal ' . self::MAKS . ' unit');
        }

        $u = UnitSdm::create([
            'nama' => $nama, 'urutan' => (int) UnitSdm::max('urutan') + 1,
            'aktif' => true, 'created_at' => WaktuIso::sekarang(),
        ]);

        return response()->json(['ok' => true, 'unit' => $u->fresh()]);
    }

    public function update(Request $r, int $id)
    {
        $this->wajibPeran($r, 'superadmin');
        $u = UnitSdm::find($id);
        if (! $u) {
            throw new ApiError(404, 'Unit tidak ditemukan');
        }
        $b = $r->all();
        $nama = mb_substr(trim((string) ($b['nama'] ?? $u->nama)), 0, 30);
        if ($nama === '') {
            throw new ApiError(400, 'Nama unit wajib diisi');
        }
        if (mb_strtolower($nama) !== mb_strtolower($u->nama)
            && UnitSdm::whereRaw('LOWER(nama) = ?', [mb_strtolower($nama)])->where('id', '!=', $id)->exists()) {
            throw new ApiError(409, "Unit \"{$nama}\" sudah ada");
        }

        $u->update([
            'nama' => $nama,
            'urutan' => array_key_exists('urutan', $b) ? (int) $b['urutan'] : $u->urutan,
            'aktif' => array_key_exists('aktif', $b) ? (bool) $b['aktif'] : $u->aktif,
        ]);

        return response()->json(['ok' => true, 'unit' => $u->fresh()]);
    }

    public function destroy(Request $r, int $id)
    {
        $this->wajibPeran($r, 'superadmin');
        $u = UnitSdm::find($id);
        if (! $u) {
            throw new ApiError(404, 'Unit tidak ditemukan');
        }
        // Personil yang masih memakai unit ini akan kehilangan rujukannya di
        // daftar pilihan (walau datanya sendiri tidak berubah) — lebih baik
        // admin memindahkan mereka dulu, atau nonaktifkan saja unitnya
        // supaya tidak ditawarkan lagi tanpa mengubah data yang sudah ada.
        $n = Siswa::where('golongan', '!=', 'siswa')->where('kelas', $u->nama)->count();
        if ($n) {
            throw new ApiError(409, "Masih ada {$n} personil di unit ini — pindahkan dulu, atau nonaktifkan saja unitnya");
        }
        $u->delete();

        return response()->json(['ok' => true]);
    }
}
