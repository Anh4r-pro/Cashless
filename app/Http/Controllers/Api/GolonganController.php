<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\ApiError;
use App\Models\Golongan;
use App\Models\Siswa;
use App\Services\Pengaturan;
use Illuminate\Http\Request;

/**
 * Golongan pemegang kartu.
 *
 * Menyunting daftar ini berbeda sifatnya dari menyunting daftar pilihan biasa:
 * mengubah satu angka di sini langsung mengubah batas belanja SEMUA orang di
 * golongan itu.
 */
class GolonganController extends Controller
{
    private const POLA_KODE = '/^[a-z][a-z0-9_]{1,19}$/';

    private const MAKS = 20;

    public const LABELS = [
        'nama' => ['label' => 'Nama golongan', 'unit' => 'teks'],
        'label_unit' => ['label' => 'Sebutan kolom kelas/unit', 'unit' => 'teks'],
        'portal_ortu' => ['label' => 'Bisa dibuka lewat portal wali', 'unit' => 'onoff'],
        'kios' => ['label' => 'Boleh memakai layar kios', 'unit' => 'onoff'],
        'transfer' => ['label' => 'Boleh mengirim saldo ke teman', 'unit' => 'onoff'],
    ];

    public function index(Request $r)
    {
        $u = $this->wajibPeran($r, 'superadmin', 'tenant');
        $q = Golongan::orderBy('urutan')->orderBy('id');
        if ($u->role !== 'superadmin') {
            $q->where('aktif', true);
        }
        // Jumlah pemegang ikut dikirim supaya admin tahu golongan mana yang
        // benar-benar terpakai sebelum menonaktifkannya.
        $n = Siswa::selectRaw('golongan, COUNT(*) n')->groupBy('golongan')->pluck('n', 'golongan');

        return response()->json([
            'golongan' => $q->get()->map(fn ($g) => array_merge($g->toArray(),
                ['jumlah' => (int) ($n[$g->kode] ?? 0)])),
            'labels' => self::LABELS,
            'batas' => Pengaturan::BATAS_GOLONGAN,
            'settings' => Pengaturan::sekolah(),   // angka sekolah saat penimpa kosong
        ]);
    }

    public function store(Request $r)
    {
        $this->wajibPeran($r, 'superadmin');
        $b = $r->all();
        $kode = strtolower(trim((string) ($b['kode'] ?? '')));
        if (! preg_match(self::POLA_KODE, $kode)) {
            throw new ApiError(400, 'Kode golongan: huruf kecil/angka/garis bawah, 2–20 karakter, diawali huruf');
        }
        if (Golongan::where('kode', $kode)->exists()) {
            throw new ApiError(409, "Golongan \"{$kode}\" sudah ada");
        }
        if (Golongan::count() >= self::MAKS) {
            throw new ApiError(409, 'Maksimal ' . self::MAKS . ' golongan');
        }
        $nama = mb_substr(trim((string) ($b['nama'] ?? '')), 0, 60);
        if ($nama === '') {
            throw new ApiError(400, 'Nama golongan wajib diisi');
        }

        $isi = [
            'kode' => $kode, 'nama' => $nama,
            'label_unit' => mb_substr(trim((string) ($b['label_unit'] ?? 'Unit')), 0, 30) ?: 'Unit',
            'jenis' => in_array($b['jenis'] ?? null, ['siswa', 'internal', 'tamu'], true) ? $b['jenis'] : 'internal',
            'bawaan' => false,
            'urutan' => (int) Golongan::max('urutan') + 1,
            'aktif' => true,
            'portal_ortu' => (bool) ($b['portal_ortu'] ?? false),
            'kios' => ! in_array($b['kios'] ?? true, [0, false], true),
            'transfer' => ! in_array($b['transfer'] ?? true, [0, false], true),
        ];
        foreach (Pengaturan::BATAS_GOLONGAN as $k) {
            $isi[$k] = $this->penimpa($b[$k] ?? null);
        }

        return response()->json(['ok' => true, 'golongan' => Golongan::create($isi)->fresh()]);
    }

    public function update(Request $r, int $id)
    {
        $this->wajibPeran($r, 'superadmin');
        $b = $r->all();
        $g = Golongan::find($id);
        if (! $g) {
            throw new ApiError(404, 'Golongan tidak ditemukan');
        }
        $nama = mb_substr(trim((string) ($b['nama'] ?? $g->nama)), 0, 60);
        if ($nama === '') {
            throw new ApiError(400, 'Nama golongan wajib diisi');
        }

        $isi = [
            'nama' => $nama,
            'label_unit' => mb_substr(trim((string) ($b['label_unit'] ?? $g->label_unit)), 0, 30) ?: 'Unit',
            // Golongan 'siswa' tidak boleh dinonaktifkan: seluruh data lama
            // menunjuk ke sana, dan mematikannya membuat ribuan kartu kehilangan
            // aturannya.
            'aktif' => $g->kode === 'siswa' ? true
                : (array_key_exists('aktif', $b) ? (bool) $b['aktif'] : $g->aktif),
            'urutan' => array_key_exists('urutan', $b) ? (int) $b['urutan'] : $g->urutan,
            'portal_ortu' => array_key_exists('portal_ortu', $b) ? (bool) $b['portal_ortu'] : $g->portal_ortu,
            'kios' => array_key_exists('kios', $b) ? (bool) $b['kios'] : $g->kios,
            'transfer' => array_key_exists('transfer', $b) ? (bool) $b['transfer'] : $g->transfer,
        ];
        foreach (Pengaturan::BATAS_GOLONGAN as $k) {
            $isi[$k] = array_key_exists($k, $b) ? $this->penimpa($b[$k]) : $g->{$k};
        }
        $g->update($isi);

        return response()->json(['ok' => true, 'golongan' => $g->fresh()]);
    }

    public function destroy(Request $r, int $id)
    {
        $this->wajibPeran($r, 'superadmin');
        $g = Golongan::find($id);
        if (! $g) {
            throw new ApiError(404, 'Golongan tidak ditemukan');
        }
        if ($g->bawaan) {
            throw new ApiError(409, "\"{$g->nama}\" golongan bawaan — nonaktifkan saja, jangan dihapus");
        }
        // Kartu yatim tanpa golongan akan diam-diam jatuh ke aturan 'siswa'.
        // Lebih baik menolak sekarang daripada menemukannya lewat batas belanja
        // yang aneh berbulan-bulan kemudian.
        $n = Siswa::where('golongan', $g->kode)->count();
        if ($n) {
            throw new ApiError(409, "Masih ada {$n} pemegang kartu di golongan ini — pindahkan dulu");
        }
        $g->delete();

        return response()->json(['ok' => true]);
    }

    /** Angka penimpa: kosong/null = ikut sekolah, BUKAN nol. */
    private function penimpa($v): ?int
    {
        if ($v === null || $v === '') {
            return null;
        }
        $n = (int) floor((float) $v);
        if ($n < 0) {
            throw new ApiError(400, 'Batas golongan harus angka 0 atau lebih');
        }
        if ($n > 100000000) {
            throw new ApiError(400, 'Batas golongan terlalu besar');
        }

        return $n;
    }
}
