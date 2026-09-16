<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\ApiError;
use App\Models\DonasiNominal;
use App\Models\DonasiProgram;
use App\Models\KiosConfig;
use App\Models\Setting;
use App\Models\Transaksi;
use App\Services\Pengaturan;
use App\Services\Rupiah;
use App\Support\WaktuIso;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Pengelolaan donasi oleh superadmin.
 *
 * Nominal donasi TIDAK pernah datang bebas dari klien: yang diterima server
 * hanya angka yang persis ada di daftar nominal milik program itu. Besaran
 * donasi dengan begitu sepenuhnya keputusan sekolah, bukan ketikan siswa.
 */
class DonasiController extends Controller
{
    private const MAX_PROGRAM = 20;

    private const MAX_NOMINAL_PER_PROGRAM = 8;

    private const MAX_NOMINAL = 500000;

    /** Pengaturan kios & batas donasi — kelompok tersendiri di layar admin. */
    public const KIOS_LABELS = [
        'kios_aktif' => ['label' => 'Kios siswa melayani tap', 'unit' => 'onoff'],
        'donasi_min' => ['label' => 'Nominal donasi terkecil', 'unit' => 'rp'],
        'donasi_maks_harian' => ['label' => 'Max donasi siswa / hari', 'unit' => 'rp'],
    ];

    public function program(Request $r)
    {
        $this->wajibPeran($r, 'superadmin');
        $program = self::daftar($r->query('semua') === '1');

        // "terpakai" dipakai layar admin untuk memberi tahu program mana yang
        // tidak bisa dihapus lagi (sudah ada donasi tercatat atas namanya).
        $pakai = Transaksi::where('tipe', 'donasi')->whereNotNull('donasi_id')
            ->selectRaw('donasi_id, COUNT(*) AS n, COALESCE(SUM(-jumlah),0) AS total')
            ->groupBy('donasi_id')->get()->keyBy('donasi_id');

        return response()->json([
            'program' => array_map(fn ($p) => array_merge($p, [
                'terpakai' => (int) ($pakai[$p['id']]->n ?? 0),
                'terkumpul' => (int) ($pakai[$p['id']]->total ?? 0),
            ]), $program),
        ]);
    }

    /**
     * Simpan seluruh daftar sekaligus: urutan mengikuti urutan array, dan
     * program yang hilang dari kiriman berarti dihapus — KECUALI kalau sudah
     * pernah menerima donasi, karena jejak keuangan tidak boleh putus.
     */
    public function simpanProgram(Request $r)
    {
        $this->wajibPeran($r, 'superadmin');
        $kiriman = $r->input('program');
        if (! is_array($kiriman)) {
            throw new ApiError(400, 'Format daftar jenis donasi tidak valid');
        }
        if (count($kiriman) > self::MAX_PROGRAM) {
            throw new ApiError(400, 'Maksimal ' . self::MAX_PROGRAM . ' jenis donasi');
        }
        $minimal = Pengaturan::sekolah()['donasi_min'] ?? 0;

        $bersih = [];
        $terlihat = [];
        foreach ($kiriman as $p) {
            $nama = mb_substr(preg_replace('/\s+/', ' ', trim((string) ($p['nama'] ?? ''))), 0, 60);
            if (mb_strlen($nama) < 3) {
                throw new ApiError(400, 'Nama jenis donasi minimal 3 huruf');
            }
            $kunci = mb_strtolower($nama);
            if (isset($terlihat[$kunci])) {
                throw new ApiError(409, "Jenis donasi \"{$nama}\" tertulis dua kali");
            }
            $terlihat[$kunci] = true;

            $nominal = [];
            foreach ((array) ($p['nominal'] ?? []) as $n) {
                $v = (int) floor((float) $n);
                if ($v <= 0) {
                    throw new ApiError(400, "Ada nominal tidak valid di \"{$nama}\"");
                }
                if ($v < $minimal) {
                    throw new ApiError(400, 'Nominal ' . Rupiah::f($v) . " di \"{$nama}\" di bawah minimal " . Rupiah::f($minimal));
                }
                if ($v > self::MAX_NOMINAL) {
                    throw new ApiError(400, 'Nominal ' . Rupiah::f($v) . " di \"{$nama}\" melebihi " . Rupiah::f(self::MAX_NOMINAL));
                }
                if (in_array($v, $nominal, true)) {
                    throw new ApiError(409, 'Nominal ' . Rupiah::f($v) . " di \"{$nama}\" tertulis dua kali");
                }
                $nominal[] = $v;
            }
            if (! $nominal) {
                throw new ApiError(400, "\"{$nama}\" belum punya pilihan nominal — siswa tidak akan bisa memilih apa pun");
            }
            if (count($nominal) > self::MAX_NOMINAL_PER_PROGRAM) {
                throw new ApiError(400, 'Maksimal ' . self::MAX_NOMINAL_PER_PROGRAM . ' pilihan nominal per jenis donasi');
            }

            $bersih[] = [
                'id' => isset($p['id']) && is_int($p['id']) ? $p['id'] : null,
                'nama' => $nama, 'nominal' => $nominal,
                'deskripsi' => mb_substr(preg_replace('/\s+/', ' ', trim((string) ($p['deskripsi'] ?? ''))), 0, 200),
                'aktif' => ($p['aktif'] ?? true) !== false,
            ];
        }

        $hasil = DB::transaction(function () use ($bersih) {
            $adaId = DonasiProgram::pluck('id')->all();
            $simpan = [];

            foreach ($bersih as $i => $p) {
                if ($p['id'] !== null && in_array($p['id'], $adaId, true)) {
                    DonasiProgram::where('id', $p['id'])->update([
                        'nama' => $p['nama'], 'deskripsi' => $p['deskripsi'],
                        'urutan' => $i, 'aktif' => $p['aktif'],
                    ]);
                    $id = $p['id'];
                } else {
                    $id = DonasiProgram::create([
                        'nama' => $p['nama'], 'deskripsi' => $p['deskripsi'],
                        'urutan' => $i, 'aktif' => $p['aktif'],
                    ])->id;
                }
                $simpan[] = $id;

                // Daftar nominal ditulis ulang tiap simpan. Riwayat donasi tidak
                // ikut berubah: yang tercatat di transaksi adalah angka
                // rupiahnya, bukan acuan ke baris nominal ini.
                DonasiNominal::where('program_id', $id)->delete();
                foreach ($p['nominal'] as $k => $j) {
                    DonasiNominal::create(['program_id' => $id, 'jumlah' => $j, 'urutan' => $k]);
                }
            }

            foreach (array_diff($adaId, $simpan) as $id) {
                $n = Transaksi::where('donasi_id', $id)->count();
                if ($n) {
                    $nama = DonasiProgram::where('id', $id)->value('nama') ?? '';
                    throw new ApiError(409, "\"{$nama}\" sudah menerima {$n} donasi — " .
                        'nonaktifkan saja (hilangkan centang tampil), jangan dihapus');
                }
                DonasiNominal::where('program_id', $id)->delete();
                DonasiProgram::where('id', $id)->delete();
            }

            return self::daftar(true);
        });

        return response()->json(['ok' => true, 'program' => $hasil]);
    }

    public function pengaturan(Request $r)
    {
        $this->wajibPeran($r, 'superadmin');
        $cfg = Pengaturan::sekolah();
        $p = [];
        foreach (array_keys(self::KIOS_LABELS) as $k) {
            $p[$k] = $cfg[$k] ?? 0;
        }

        return response()->json([
            'pengaturan' => $p, 'labels' => self::KIOS_LABELS,
            // Hanya PENANDA "masih sandi bawaan" — sandinya sendiri tidak pernah dikirim.
            'sandi_bawaan' => KiosConfig::ambil('sandi_bawaan') === '1',
        ]);
    }

    public function simpanPengaturan(Request $r)
    {
        $this->wajibPeran($r, 'superadmin');
        $b = $r->all();
        $nilai = Pengaturan::sekolah();

        foreach (self::KIOS_LABELS as $key => $def) {
            if (! array_key_exists($key, $b)) {
                continue;
            }
            if (! is_numeric($b[$key])) {
                throw new ApiError(400, "Nilai \"{$def['label']}\" tidak valid");
            }
            $v = (int) floor((float) $b[$key]);
            if ($key === 'kios_aktif') {
                if ($v !== 0 && $v !== 1) {
                    throw new ApiError(400, 'Kios siswa hanya bisa aktif (1) atau tutup (0)');
                }
            } elseif ($v <= 0) {
                throw new ApiError(400, "Nilai \"{$def['label']}\" harus lebih dari nol");
            }
            $nilai[$key] = $v;
        }

        if ($nilai['donasi_maks_harian'] < $nilai['donasi_min']) {
            throw new ApiError(400, 'Batas donasi harian tidak boleh lebih kecil dari nominal donasi terkecil');
        }

        // Menaikkan nominal terkecil tidak boleh diam-diam membuat pilihan yang
        // sudah tayang di kios jadi tidak sah — admin harus membereskan dulu.
        $terlaluKecil = DonasiNominal::query()
            ->join('donasi_program', 'donasi_program.id', '=', 'donasi_nominal.program_id')
            ->where('donasi_nominal.jumlah', '<', $nilai['donasi_min'])
            ->selectRaw('donasi_program.nama, MIN(donasi_nominal.jumlah) AS j')
            ->groupBy('donasi_program.id', 'donasi_program.nama')->first();
        if ($terlaluKecil) {
            throw new ApiError(409, "\"{$terlaluKecil->nama}\" masih punya pilihan " .
                Rupiah::f((int) $terlaluKecil->j) . ' — di bawah nominal terkecil yang baru');
        }

        DB::transaction(function () use ($nilai) {
            foreach (array_keys(self::KIOS_LABELS) as $key) {
                Setting::simpan($key, $nilai[$key]);
            }
        });
        Pengaturan::lupakanCache();

        $sesudah = Pengaturan::sekolah();
        $p = [];
        foreach (array_keys(self::KIOS_LABELS) as $k) {
            $p[$k] = $sesudah[$k];
        }

        return response()->json(['ok' => true, 'pengaturan' => $p]);
    }

    public function rekap(Request $r)
    {
        $this->wajibPeran($r, 'superadmin');
        $hari = min(365, max(7, (int) $r->query('hari', 30) ?: 30));
        $sejak = WaktuIso::awalHari($hari);
        $hariIni = WaktuIso::awalHariIni();

        $perProgram = DonasiProgram::query()
            ->leftJoin('transaksi', function ($j) {
                $j->on('transaksi.donasi_id', '=', 'donasi_program.id')->where('transaksi.tipe', 'donasi');
            })
            ->selectRaw('donasi_program.id, donasi_program.nama, donasi_program.aktif')
            ->selectRaw('COUNT(transaksi.id) AS n, COALESCE(SUM(-transaksi.jumlah),0) AS total')
            ->selectRaw('COUNT(DISTINCT transaksi.siswa_id) AS donatur')
            ->selectRaw('COALESCE(SUM(CASE WHEN transaksi.created_at >= ? THEN -transaksi.jumlah END),0) AS periode', [$sejak])
            ->selectRaw('COALESCE(SUM(CASE WHEN transaksi.created_at >= ? THEN -transaksi.jumlah END),0) AS hari_ini', [$hariIni])
            ->groupBy('donasi_program.id', 'donasi_program.nama', 'donasi_program.aktif',
                'donasi_program.urutan')
            ->orderBy('donasi_program.urutan')->orderBy('donasi_program.id')->get()
            // SUM()/COUNT() di MySQL kembali sebagai teks lewat PDO; layar rekap
            // menjumlahkannya, jadi dikembalikan sebagai angka seperti dulu.
            ->map(fn ($p) => [
                'id' => (int) $p->id, 'nama' => $p->nama, 'aktif' => (int) $p->aktif,
                'n' => (int) $p->n, 'total' => (int) $p->total, 'donatur' => (int) $p->donatur,
                'periode' => (int) $p->periode, 'hari_ini' => (int) $p->hari_ini,
            ]);

        $total = Transaksi::where('tipe', 'donasi')
            ->selectRaw('COUNT(*) AS n, COALESCE(SUM(-jumlah),0) AS total, COUNT(DISTINCT siswa_id) AS donatur')
            ->selectRaw('COALESCE(SUM(CASE WHEN created_at >= ? THEN -jumlah END),0) AS hari_ini', [$hariIni])
            ->selectRaw('COALESCE(SUM(CASE WHEN created_at >= ? THEN -jumlah END),0) AS periode', [$sejak])
            ->first();

        $perKelas = Transaksi::where('tipe', 'donasi')->where('created_at', '>=', $sejak)
            ->selectRaw('kelas, COUNT(*) AS n, COALESCE(SUM(-jumlah),0) AS total')
            ->groupBy('kelas')->orderByDesc('total')->limit(10)->get()
            ->map(fn ($x) => ['kelas' => $x->kelas, 'n' => (int) $x->n, 'total' => (int) $x->total]);

        $terakhir = Transaksi::where('tipe', 'donasi')
            ->select('siswa_nama', 'kelas', 'jumlah', 'keterangan', 'created_at')
            ->orderByDesc('created_at')->orderByDesc('id')->limit(20)->get();

        return response()->json(array_merge([
            'hari' => $hari, 'per_program' => $perProgram,
            'per_kelas' => $perKelas, 'terakhir' => $terakhir,
        ], array_map('intval', (array) $total->getAttributes())));
    }

    /** Program beserta pilihan nominalnya, urut sesuai tampilan di kios. */
    public static function daftar(bool $semua): array
    {
        return DonasiProgram::query()
            ->when(! $semua, fn ($q) => $q->where('aktif', true))
            ->orderBy('urutan')->orderBy('id')->get()
            ->map(fn ($p) => array_merge($p->toArray(), [
                'nominal' => DonasiNominal::where('program_id', $p->id)
                    ->orderBy('urutan')->orderBy('id')->pluck('jumlah')
                    ->map(fn ($j) => (int) $j)->all(),
            ]))->all();
    }
}
