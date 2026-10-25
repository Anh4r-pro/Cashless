<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\ApiError;
use App\Models\Setting;
use App\Models\Siswa;
use App\Models\TabunganJenis;
use App\Models\TabunganMutasi;
use App\Models\TabunganSaldo;
use App\Services\Ekspor;
use App\Services\Pengaturan;
use App\Services\PotongSaldo;
use App\Services\Tabungan;
use App\Support\WaktuIso;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Pengelolaan Tabungan oleh superadmin — jenis & target, pengaturan kios,
 * rekap, dan dua aksi yang DIBANTU ADMIN (bukan lewat kios): menyetor
 * kontribusi besar sekali waktu (boleh dari titipan), dan mencairkan.
 *
 * Mencairkan sengaja dipisah dari menyetor lewat izin yang berbeda
 * (tabungan.cairkan vs tabungan.setor) — lihat Izin::KATALOG.
 */
class TabunganController extends Controller
{
    private const MAX_JENIS = 20;

    public const LABELS = [
        'tabungan_min' => ['label' => 'Nominal menabung terkecil (kios)', 'unit' => 'rp'],
        'tabungan_maks_harian' => ['label' => 'Max menabung santri / hari (kios)', 'unit' => 'rp'],
    ];

    public function jenis(Request $r)
    {
        $this->wajibPeran($r, 'superadmin');
        $jenis = self::daftar($r->query('semua') === '1');

        // "terpakai" dipakai layar admin: jenis yang sudah pernah dipakai
        // (punya saldo tabungan ATAU riwayat mutasi) tidak bisa dihapus.
        $pakai = TabunganSaldo::selectRaw('jenis_id, COUNT(*) AS n, COALESCE(SUM(saldo),0) AS total')
            ->groupBy('jenis_id')->get()->keyBy('jenis_id');

        return response()->json([
            'jenis' => array_map(fn ($j) => array_merge($j, [
                'terpakai' => (int) ($pakai[$j['id']]->n ?? 0),
                'terkumpul' => (int) ($pakai[$j['id']]->total ?? 0),
            ]), $jenis),
        ]);
    }

    /** Simpan seluruh daftar sekaligus — urutan ikut urutan array, sama seperti Donasi. */
    public function simpanJenis(Request $r)
    {
        $this->wajibPeran($r, 'superadmin');
        $kiriman = $r->input('jenis');
        if (! is_array($kiriman)) {
            throw new ApiError(400, 'Format daftar jenis tabungan tidak valid');
        }
        if (count($kiriman) > self::MAX_JENIS) {
            throw new ApiError(400, 'Maksimal ' . self::MAX_JENIS . ' jenis tabungan');
        }

        $bersih = [];
        $terlihat = [];
        foreach ($kiriman as $j) {
            $nama = mb_substr(preg_replace('/\s+/', ' ', trim((string) ($j['nama'] ?? ''))), 0, 60);
            if (mb_strlen($nama) < 3) {
                throw new ApiError(400, 'Nama jenis tabungan minimal 3 huruf');
            }
            $kunci = mb_strtolower($nama);
            if (isset($terlihat[$kunci])) {
                throw new ApiError(409, "Jenis tabungan \"{$nama}\" tertulis dua kali");
            }
            $terlihat[$kunci] = true;

            $target = null;
            if (array_key_exists('target', $j) && $j['target'] !== null && $j['target'] !== '') {
                $target = (int) floor((float) $j['target']);
                if ($target <= 0) {
                    throw new ApiError(400, "Target \"{$nama}\" harus lebih dari nol, atau dikosongkan untuk bebas tanpa batas");
                }
            }

            $untukKiriman = $j['untuk'] ?? 'semua';
            $untuk = in_array($untukKiriman, ['semua', 'siswa', 'pegawai'], true) ? $untukKiriman : 'semua';

            $bersih[] = [
                'id' => isset($j['id']) && is_int($j['id']) ? $j['id'] : null,
                'nama' => $nama, 'target' => $target, 'untuk' => $untuk,
                'deskripsi' => mb_substr(preg_replace('/\s+/', ' ', trim((string) ($j['deskripsi'] ?? ''))), 0, 200),
                'aktif' => ($j['aktif'] ?? true) !== false,
            ];
        }

        $hasil = DB::transaction(function () use ($bersih) {
            $adaId = TabunganJenis::pluck('id')->all();
            $simpan = [];

            foreach ($bersih as $i => $j) {
                if ($j['id'] !== null && in_array($j['id'], $adaId, true)) {
                    TabunganJenis::where('id', $j['id'])->update([
                        'nama' => $j['nama'], 'deskripsi' => $j['deskripsi'], 'untuk' => $j['untuk'],
                        'target' => $j['target'], 'urutan' => $i, 'aktif' => $j['aktif'],
                    ]);
                    $id = $j['id'];
                } else {
                    $id = TabunganJenis::create([
                        'nama' => $j['nama'], 'deskripsi' => $j['deskripsi'], 'untuk' => $j['untuk'],
                        'target' => $j['target'], 'urutan' => $i, 'aktif' => $j['aktif'],
                    ])->id;
                }
                $simpan[] = $id;
            }

            foreach (array_diff($adaId, $simpan) as $id) {
                $n = TabunganSaldo::where('jenis_id', $id)->where('saldo', '>', 0)->count();
                $riwayat = TabunganMutasi::where('jenis_id', $id)->count();
                if ($n || $riwayat) {
                    $nama = TabunganJenis::where('id', $id)->value('nama') ?? '';
                    throw new ApiError(409, "\"{$nama}\" masih punya saldo tersimpan atau riwayat — " .
                        'nonaktifkan saja (hilangkan centang tampil), jangan dihapus');
                }
                TabunganJenis::where('id', $id)->delete();
            }

            return self::daftar(true);
        });

        return response()->json(['ok' => true, 'jenis' => $hasil]);
    }

    public function pengaturan(Request $r)
    {
        $this->wajibPeran($r, 'superadmin');
        $cfg = Pengaturan::sekolah();
        $p = [];
        foreach (array_keys(self::LABELS) as $k) {
            $p[$k] = $cfg[$k] ?? 0;
        }

        return response()->json(['pengaturan' => $p, 'labels' => self::LABELS]);
    }

    public function simpanPengaturan(Request $r)
    {
        $this->wajibPeran($r, 'superadmin');
        $b = $r->all();
        $nilai = Pengaturan::sekolah();

        foreach (self::LABELS as $key => $def) {
            if (! array_key_exists($key, $b)) {
                continue;
            }
            if (! is_numeric($b[$key]) || (int) floor((float) $b[$key]) <= 0) {
                throw new ApiError(400, "Nilai \"{$def['label']}\" harus lebih dari nol");
            }
            $nilai[$key] = (int) floor((float) $b[$key]);
        }
        if ($nilai['tabungan_maks_harian'] < $nilai['tabungan_min']) {
            throw new ApiError(400, 'Batas menabung harian tidak boleh lebih kecil dari nominal terkecil');
        }

        DB::transaction(function () use ($nilai) {
            foreach (array_keys(self::LABELS) as $key) {
                Setting::simpan($key, $nilai[$key]);
            }
        });
        Pengaturan::lupakanCache();

        $sesudah = Pengaturan::sekolah();
        $p = [];
        foreach (array_keys(self::LABELS) as $k) {
            $p[$k] = $sesudah[$k];
        }

        return response()->json(['ok' => true, 'pengaturan' => $p]);
    }

    /** Santri teratas per jenis (dihitung dari total SETORAN, bukan saldo kini — pencairan tidak mengurangi "keaktifan"). */
    private const TOP_PENABUNG = 5;

    private function topPenabungPerJenis(string $sejak): array
    {
        $baris = TabunganMutasi::where('jenis_mutasi', 'masuk')
            ->where('tabungan_mutasi.created_at', '>=', $sejak)
            ->join('siswa', 'siswa.id', '=', 'tabungan_mutasi.siswa_id')
            ->selectRaw('tabungan_mutasi.jenis_id, tabungan_mutasi.siswa_id, siswa.nama AS siswa_nama, siswa.kelas')
            ->selectRaw('SUM(tabungan_mutasi.jumlah) AS total, COUNT(*) AS n')
            ->groupBy('tabungan_mutasi.jenis_id', 'tabungan_mutasi.siswa_id', 'siswa.nama', 'siswa.kelas')
            ->orderByDesc('total')
            ->get();

        $per = [];
        foreach ($baris->groupBy('jenis_id') as $jenisId => $grup) {
            $per[(int) $jenisId] = $grup->take(self::TOP_PENABUNG)->values()->map(fn ($x) => [
                'siswa_id' => (int) $x->siswa_id, 'siswa_nama' => $x->siswa_nama, 'kelas' => $x->kelas,
                'total' => (int) $x->total, 'n' => (int) $x->n,
            ])->all();
        }

        return $per;
    }

    public function rekap(Request $r)
    {
        $this->wajibPeran($r, 'superadmin');
        // Bawaan 30 hari, pola sama dengan DonasiController::rekap — rentang cepat
        // yang sebelumnya tidak ada sama sekali di layar Tabungan.
        $hari = min(365, max(7, (int) $r->query('hari', 30) ?: 30));
        $sejak = WaktuIso::awalHari($hari);
        $topPenabung = $this->topPenabungPerJenis($sejak);

        $perJenis = TabunganJenis::query()
            ->leftJoin('tabungan_saldo', 'tabungan_saldo.jenis_id', '=', 'tabungan_jenis.id')
            ->selectRaw('tabungan_jenis.id, tabungan_jenis.nama, tabungan_jenis.target, tabungan_jenis.aktif, tabungan_jenis.untuk')
            ->selectRaw('COUNT(DISTINCT CASE WHEN tabungan_saldo.saldo > 0 THEN tabungan_saldo.siswa_id END) AS n_penabung')
            ->selectRaw('COALESCE(SUM(tabungan_saldo.saldo),0) AS total')
            ->groupBy('tabungan_jenis.id', 'tabungan_jenis.nama', 'tabungan_jenis.target',
                'tabungan_jenis.aktif', 'tabungan_jenis.untuk', 'tabungan_jenis.urutan')
            ->orderBy('tabungan_jenis.urutan')->orderBy('tabungan_jenis.id')->get()
            ->map(fn ($j) => ['id' => (int) $j->id, 'nama' => $j->nama, 'target' => $j->target === null ? null : (int) $j->target,
                'aktif' => (int) $j->aktif, 'untuk' => $j->untuk, 'n_penabung' => (int) $j->n_penabung, 'total' => (int) $j->total,
                'top_penabung' => $topPenabung[(int) $j->id] ?? []]);

        $total = TabunganSaldo::sum('saldo');

        return response()->json(['hari' => $hari, 'per_jenis' => $perJenis, 'total' => (int) $total]);
    }

    /**
     * Riwayat setoran/pencairan PENUH berpaginasi — dulu "20 Setoran
     * Terakhir" (terhitung di rekap() tapi TIDAK PERNAH ditampilkan layar).
     * Dipisah dari rekap() dengan pola persis DonasiController::riwayat.
     */
    public function riwayat(Request $r)
    {
        $this->wajibPeran($r, 'superadmin');
        $dari = $r->query('dari');
        $sampai = $r->query('sampai');
        $cari = mb_substr(trim((string) $r->query('cari', '')), 0, 60);
        $like = '%' . $cari . '%';

        $q = TabunganMutasi::join('siswa', 'siswa.id', '=', 'tabungan_mutasi.siswa_id')
            ->join('tabungan_jenis', 'tabungan_jenis.id', '=', 'tabungan_mutasi.jenis_id')
            ->when($dari, fn ($w) => $w->where('tabungan_mutasi.created_at', '>=', $dari))
            ->when($sampai, fn ($w) => $w->where('tabungan_mutasi.created_at', '<=', $sampai))
            ->when($cari !== '', fn ($w) => $w->where(fn ($x) => $x
                ->where('siswa.nama', 'like', $like)->orWhere('siswa.kelas', 'like', $like)));

        if ($r->query('format')) {
            return $this->riwayatUnduh((clone $q)->select('siswa.nama as siswa_nama', 'siswa.kelas',
                'tabungan_jenis.nama as jenis_nama', 'tabungan_mutasi.jenis_mutasi', 'tabungan_mutasi.jumlah',
                'tabungan_mutasi.keterangan', 'tabungan_mutasi.created_at')
                ->orderByDesc('tabungan_mutasi.created_at')->get(),
                Ekspor::formatSah($r->query('format')), $dari, $sampai);
        }

        $hal = max(1, (int) $r->query('hal', 1));
        $per = min(100, max(10, (int) $r->query('per', 10) ?: 10));
        $total = (clone $q)->count();
        $nHalaman = max(1, (int) ceil($total / $per));
        $baris = $q->select('siswa.nama as siswa_nama', 'siswa.kelas', 'tabungan_jenis.nama as jenis_nama',
            'tabungan_mutasi.jenis_mutasi', 'tabungan_mutasi.jumlah', 'tabungan_mutasi.keterangan',
            'tabungan_mutasi.created_at')
            ->orderByDesc('tabungan_mutasi.created_at')->orderByDesc('tabungan_mutasi.id')
            ->limit($per)->offset(($hal - 1) * $per)->get();

        return response()->json(['mutasi' => $baris, 'hal' => $hal, 'n_halaman' => $nHalaman]);
    }

    private function riwayatUnduh($rows, string $format, ?string $dari, ?string $sampai)
    {
        $kepala = ['Tanggal', 'Santri', 'Kelas', 'Jenis Tabungan', 'Jenis Mutasi', 'Jumlah (Rp)', 'Keterangan'];
        $isi = $rows->map(fn ($m) => [
            substr((string) $m->created_at, 0, 16), $m->siswa_nama, $m->kelas, $m->jenis_nama,
            $m->jenis_mutasi === 'masuk' ? 'setor' : 'cairkan', (int) $m->jumlah, $m->keterangan,
        ])->all();
        $judul = 'Riwayat Tabungan';
        $catatan = array_filter([($dari || $sampai) ? 'Periode: ' . ($dari ?: '…') . ' s/d ' . ($sampai ?: '…') : null]);
        $nama = 'riwayat-tabungan' . ($dari ? "-{$dari}" : '') . ($sampai ? "-sd-{$sampai}" : '') . ".{$format}";
        [$tipe] = Ekspor::TIPE[$format];

        $badan = $format === 'csv'
            ? Ekspor::csvUmum($kepala, array_merge($isi, [[]], [[$judul]], array_map(fn ($c) => [$c], $catatan)))
            : Ekspor::tabelUmum($judul, $kepala, $isi, $catatan, [5]);

        return response($badan, 200, [
            'Content-Type' => $tipe,
            'Content-Disposition' => "attachment; filename=\"{$nama}\"",
        ]);
    }

    /** Saldo tabungan satu santri, semua jenis — dipakai layar setor/cairkan. */
    public function saldoSiswa(Request $r, int $siswaId)
    {
        $this->wajibPeran($r, 'superadmin');
        $s = Siswa::find($siswaId);
        if (! $s) {
            throw new ApiError(404, 'Santri tidak ditemukan');
        }

        return response()->json([
            'siswa' => ['id' => $s->id, 'nama' => $s->nama, 'kelas' => $s->kelas,
                'saldo' => (int) $s->saldo, 'saldo_titipan' => (int) $s->saldo_titipan],
            'tabungan' => Tabungan::daftarSaldo($s->id),
        ]);
    }

    /** Menyetor — dibantu admin, boleh sumber kartu+titipan (kontribusi besar sekali waktu). */
    public function setor(Request $r)
    {
        $u = $this->wajibPeran($r, 'superadmin');
        $s = Siswa::find((int) $r->input('siswa_id'));
        if (! $s) {
            throw new ApiError(404, 'Santri tidak ditemukan');
        }
        $jenis = TabunganJenis::where('id', (int) $r->input('jenis_id'))->where('aktif', true)->first();
        if (! $jenis) {
            throw new ApiError(404, 'Jenis tabungan tidak ditemukan atau sedang disembunyikan');
        }
        $jumlah = (int) floor((float) $r->input('jumlah'));
        if ($jumlah <= 0) {
            throw new ApiError(400, 'Nominal tidak valid');
        }
        $keterangan = mb_substr(trim((string) $r->input('keterangan', '')), 0, 300) ?: ('Tabungan ' . $jenis->nama);
        $sumber = PotongSaldo::sumberSah($r->input('sumber'));
        $txId = $r->input('tx_id') ? (string) $r->input('tx_id') : null;

        $hasil = DB::transaction(fn () => Tabungan::setor($s, $jenis, $jumlah, $sumber, $u->nama, $keterangan, $txId));

        return response()->json(array_merge(['ok' => true, 'jenis' => $jenis->nama], $hasil));
    }

    /** Mencairkan — SELURUH saldo satu jenis, kembali ke kartu. Wajib alasan. */
    public function cairkan(Request $r)
    {
        $u = $this->wajibPeran($r, 'superadmin');
        $s = Siswa::find((int) $r->input('siswa_id'));
        if (! $s) {
            throw new ApiError(404, 'Santri tidak ditemukan');
        }
        $jenis = TabunganJenis::find((int) $r->input('jenis_id'));
        if (! $jenis) {
            throw new ApiError(404, 'Jenis tabungan tidak ditemukan');
        }
        $keterangan = trim((string) $r->input('keterangan', ''));
        if (mb_strlen($keterangan) < 3) {
            throw new ApiError(400, 'Alasan pencairan wajib diisi (minimal 3 huruf)');
        }
        $txId = $r->input('tx_id') ? (string) $r->input('tx_id') : null;

        $hasil = DB::transaction(fn () => Tabungan::cairkan($s, $jenis, $u->nama, $keterangan, $txId));

        return response()->json(array_merge(['ok' => true, 'jenis' => $jenis->nama], $hasil));
    }

    /** Jenis beserta flag aktifnya, urut sesuai tampilan di kios. */
    /**
     * $audiens ('siswa'/'pegawai') menyaring ke jenis yang boleh dilihat
     * golongan itu ('semua' + audiensnya sendiri) — dipakai kios & portal
     * pegawai. Dikosongkan (null) di layar ADMIN, yang harus melihat &
     * mengelola SELURUH jenis apa pun audiensnya.
     */
    public static function daftar(bool $semua, ?string $audiens = null): array
    {
        return TabunganJenis::query()
            ->when(! $semua, fn ($q) => $q->where('aktif', true))
            ->when($audiens, fn ($q) => $q->whereIn('untuk', ['semua', $audiens]))
            ->orderBy('urutan')->orderBy('id')->get()
            ->map(fn ($j) => $j->toArray())->all();
    }
}
