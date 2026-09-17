<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\ApiError;
use App\Models\KategoriAkses;
use App\Models\KategoriAksesFitur;
use App\Models\User;
use App\Services\Izin;
use App\Support\WaktuIso;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Kategori akses — "peran" yang dibuat dan disetel admin sendiri.
 *
 * Seluruh layar ini hanya menyusun daftar sakelar; yang benar-benar memagari
 * adalah middleware `izin:` di rute dan Izin::wajib() di controller. Sakelar
 * yang dimatikan di sini karenanya menutup endpoint-nya, bukan sekadar
 * menyembunyikan tombolnya.
 */
class AksesController extends Controller
{
    public function katalog(Request $r)
    {
        $this->wajibIzin($r, 'akses.kelola');

        $grup = [];
        foreach (Izin::KATALOG as $nama => $fitur) {
            $grup[] = [
                'grup' => $nama,
                'fitur' => array_map(fn ($k, $v) => ['kode' => $k, 'label' => $v],
                    array_keys($fitur), array_values($fitur)),
            ];
        }

        return response()->json(['katalog' => $grup]);
    }

    public function index(Request $r)
    {
        $this->wajibIzin($r, 'akses.kelola');

        $jumlah = User::whereNotNull('kategori_id')->selectRaw('kategori_id, COUNT(*) n')
            ->groupBy('kategori_id')->pluck('n', 'kategori_id');

        $rows = KategoriAkses::with('fitur')->orderBy('urutan')->orderBy('id')->get()
            ->map(function ($k) use ($jumlah) {
                // Kategori terkunci selalu memegang seluruh katalog — termasuk
                // fitur yang baru ditambahkan setelah barisnya dibuat.
                $fitur = $k->terkunci ? Izin::semua() : $k->fitur->pluck('fitur')->all();

                return [
                    'id' => $k->id, 'nama' => $k->nama, 'deskripsi' => $k->deskripsi,
                    'terkunci' => $k->terkunci, 'bawaan' => $k->bawaan, 'urutan' => $k->urutan,
                    'fitur' => array_values($fitur),
                    'n_akun' => (int) ($jumlah[$k->id] ?? 0),
                ];
            });

        return response()->json(['kategori' => $rows]);
    }

    public function store(Request $r)
    {
        $this->wajibIzin($r, 'akses.kelola');
        $nama = $this->nama($r->input('nama'), null);

        $k = DB::transaction(function () use ($r, $nama) {
            $k = KategoriAkses::create([
                'nama' => $nama,
                'deskripsi' => mb_substr(trim((string) $r->input('deskripsi', '')), 0, 300),
                'urutan' => (int) (KategoriAkses::max('urutan') + 1),
                'created_at' => WaktuIso::sekarang(),
            ]);
            $this->simpanFitur($k, $this->bersihkanFitur($r->input('fitur')));

            return $k;
        });

        return response()->json(['ok' => true, 'kategori' => $k->fresh()]);
    }

    public function update(Request $r, int $id)
    {
        $u = $this->wajibIzin($r, 'akses.kelola');
        $k = KategoriAkses::find($id);
        if (! $k) {
            throw new ApiError(404, 'Kategori akses tidak ditemukan');
        }
        if ($k->terkunci) {
            throw new ApiError(403, 'Kategori "' . $k->nama . '" dikunci dan tidak bisa diubah — ' .
                'ia yang menjamin selalu ada akun yang bisa membuka pengelolaan akses');
        }

        $fitur = $this->bersihkanFitur($r->input('fitur'));

        // Pengaman kunci-diri-sendiri: admin tidak boleh mencabut hak mengelola
        // akses/akun dari kategorinya sendiri. Kalau dibiarkan, satu klik bisa
        // membuat menu ini mustahil dibuka lagi oleh siapa pun yang tersisa.
        if ($u->kategori_id === $k->id) {
            foreach (['akses.kelola', 'akun.kelola'] as $kunci) {
                if (Izin::boleh($u, $kunci) && ! in_array($kunci, $fitur, true)) {
                    throw new ApiError(409, 'Tidak bisa mencabut "' . Izin::label($kunci) .
                        '" dari kategori Anda sendiri — mintalah akun berkategori lain yang melakukannya');
                }
            }
        }

        DB::transaction(function () use ($r, $k, $fitur) {
            $k->update([
                'nama' => $this->nama($r->input('nama', $k->nama), $k->id),
                'deskripsi' => mb_substr(trim((string) $r->input('deskripsi', $k->deskripsi)), 0, 300),
            ]);
            $this->simpanFitur($k, $fitur);
        });

        return response()->json(['ok' => true, 'kategori' => $k->fresh()]);
    }

    public function destroy(Request $r, int $id)
    {
        $this->wajibIzin($r, 'akses.kelola');
        $k = KategoriAkses::find($id);
        if (! $k) {
            throw new ApiError(404, 'Kategori akses tidak ditemukan');
        }
        if ($k->terkunci) {
            throw new ApiError(403, 'Kategori "' . $k->nama . '" dikunci dan tidak bisa dihapus');
        }
        // Akun yatim akan naik menjadi akses penuh (lihat Izin::milik), jadi
        // menghapus kategori yang masih dipakai justru MELEBARKAN hak — persis
        // kebalikan dari yang dimaksud admin.
        if ($n = User::where('kategori_id', $k->id)->count()) {
            throw new ApiError(409, "Masih ada {$n} akun memakai kategori ini — " .
                'pindahkan akunnya dulu ke kategori lain');
        }

        DB::transaction(function () use ($k) {
            KategoriAksesFitur::where('kategori_id', $k->id)->delete();
            $k->delete();
        });

        return response()->json(['ok' => true]);
    }

    // ------------------------------------------------------------ pembantu

    private function nama($raw, ?int $kecualiId): string
    {
        $nama = trim((string) $raw);
        if ($nama === '') {
            throw new ApiError(400, 'Nama kategori wajib diisi');
        }
        if (mb_strlen($nama) > 60) {
            throw new ApiError(400, 'Nama kategori maksimal 60 karakter');
        }
        $q = KategoriAkses::where('nama', $nama);
        if ($kecualiId) {
            $q->where('id', '!=', $kecualiId);
        }
        if ($q->exists()) {
            throw new ApiError(409, "Kategori \"{$nama}\" sudah ada");
        }

        return $nama;
    }

    /**
     * Kode yang tidak dikenal DIBUANG, bukan ditolak: katalog bisa menyusut
     * saat fitur dihapus dari kode, dan layar lama yang masih mengirim kode
     * usang tidak boleh membuat penyimpanan gagal seluruhnya.
     */
    private function bersihkanFitur($raw): array
    {
        $daftar = is_array($raw) ? $raw : [];

        return array_values(array_unique(array_filter(
            array_map(fn ($x) => (string) $x, $daftar),
            fn ($k) => Izin::terdaftar($k),
        )));
    }

    private function simpanFitur(KategoriAkses $k, array $fitur): void
    {
        KategoriAksesFitur::where('kategori_id', $k->id)->delete();
        foreach ($fitur as $f) {
            KategoriAksesFitur::create(['kategori_id' => $k->id, 'fitur' => $f]);
        }
    }
}
