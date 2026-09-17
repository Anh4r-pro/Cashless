<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\ApiError;
use App\Models\SesiOrtu;
use App\Models\Siswa;
use App\Models\Transaksi;
use App\Services\KunciWali;
use App\Services\Pengaturan;
use App\Services\Rem;
use App\Services\Sesi;
use App\Services\SqlWaktu;
use App\Support\WaktuIso;
use Illuminate\Http\Request;

/**
 * Portal wali (/ortu): orang tua memantau saldo & jajan anaknya tanpa punya akun.
 *
 * Seluruh endpoint di sini hanya BACA, dan selalu dipagari siswa_id dari SESI —
 * tidak pernah ada parameter siswa dari klien. Itu yang membuat wali tidak bisa
 * membuka data anak lain hanya dengan mengganti angka di URL.
 */
class OrtuController extends Controller
{
    public function login(Request $r)
    {
        // Klien lama masih mengirim bidang "nisn"; keduanya diterima supaya
        // halaman yang belum ter-muat-ulang tidak mendadak gagal masuk.
        $nis = KunciWali::digitSaja($r->input('nis') ?? $r->input('nisn'));
        $lahir = KunciWali::digitSaja($r->input('lahir'));      // ddmmyy
        $hp4 = KunciWali::digitSaja($r->input('hp4'));
        $mintaHp = (Pengaturan::sekolah()['ortu_minta_hp'] ?? 0) === 1;

        $kIp = 'ortu-ip:' . Rem::ip($r);
        $kNis = 'ortu-nis:' . $nis;

        Rem::periksa($kIp);
        if ($nis !== '') {
            Rem::periksa($kNis);
        }

        if (strlen($nis) < 3 || strlen($nis) > 20) {
            throw new ApiError(400, 'NIS tidak valid');
        }
        if (strlen($lahir) !== 6) {
            throw new ApiError(400, 'Tanggal lahir harus 6 angka (ddmmyy), contoh 170412');
        }
        if ($mintaHp && strlen($hp4) !== 4) {
            throw new ApiError(400, 'Isi 4 digit terakhir nomor HP');
        }

        $gagal = function () use ($kIp, $kNis, $mintaHp) {
            Rem::catatGagal($kIp, $kNis);

            return new ApiError(401, 'Data tidak cocok. Periksa lagi NIS, tanggal lahir' .
                ($mintaHp ? ', dan 4 digit nomor HP.' : ' anak Anda.'));
        };

        $kandidat = Siswa::where('nis', $nis)->where('nis', '!=', '')->get();
        // NIS kembar pada data lama: menebak salah satunya berarti menampilkan
        // data anak orang lain. Berhenti, dan minta sekolah membereskan datanya.
        if ($kandidat->count() > 1) {
            throw new ApiError(409, 'NIS ini terdaftar pada lebih dari satu anak. Hubungi admin kantin.');
        }
        $s = $kandidat->first();
        if (! $s) {
            throw $gagal();
        }

        // Golongan yang bukan santri (SDM, tamu) tidak punya wali yang perlu
        // memantau, dan datanya justru lebih peka. Ditutup di sini, bukan
        // diserahkan ke kecocokan data — kartu guru pun punya tanggal lahir.
        $gol = Pengaturan::golongan($s->golongan);
        if (! $gol?->portal_ortu) {
            throw new ApiError(403, "Kartu {$gol?->nama} tidak dilayani portal wali.");
        }

        // Data belum dilengkapi sekolah → pesan yang jelas, bukan "tidak cocok",
        // supaya orang tua tahu harus menghubungi admin dan tidak menebak terus.
        if (! $s->tgl_lahir || ($mintaHp && ! $s->hp_ortu)) {
            throw new ApiError(409, 'Data tanggal lahir' . ($mintaHp ? ' / nomor HP wali' : '') .
                ' belum dilengkapi sekolah. Hubungi admin kantin.');
        }

        $ddmmyy = KunciWali::ddmmyy($s->tgl_lahir);
        $hpAkhir = substr(KunciWali::digitSaja($s->hp_ortu), -4);
        $cocok = $ddmmyy && KunciWali::sama($lahir, $ddmmyy)
            && (! $mintaHp || KunciWali::sama($hp4, $hpAkhir));
        if (! $cocok) {
            throw $gagal();
        }

        Rem::lupakan($kIp, $kNis);
        $token = Sesi::buatOrtu($s);

        return Sesi::pasangCookie(
            response()->json(['ok' => true, 'siswa' => $this->untukOrtu($s)]),
            'osid', $token, (int) (Sesi::TTL_ORTU_MS / 1000),
        );
    }

    public function logout(Request $r)
    {
        if ($token = $r->cookie('osid')) {
            SesiOrtu::where('token', $token)->delete();
        }

        return Sesi::hapusCookie(response()->json(['ok' => true]), 'osid');
    }

    public function me(Request $r)
    {
        // minta_hp ikut dikirim saat BELUM masuk: halaman masuk perlu tahu
        // berapa kotak isian yang harus ditampilkan, dan itu keputusan sekolah
        // yang bisa berubah kapan saja tanpa halaman ini di-deploy ulang.
        $mintaHp = (Pengaturan::sekolah()['ortu_minta_hp'] ?? 0) === 1;
        $s = $r->attributes->get('ortu');

        if (! $s) {
            return response()->json(['authed' => false, 'minta_hp' => $mintaHp]);
        }

        return response()->json(['authed' => true, 'minta_hp' => $mintaHp, 'siswa' => $this->untukOrtu($s)]);
    }

    public function ringkasan(Request $r)
    {
        $s = $this->ortu($r);
        $hari = min(90, max(7, (int) $r->query('hari', 30) ?: 30));
        $sejak = WaktuIso::awalHari($hari);
        $awalHariIni = WaktuIso::awalHariIni();
        $awal7 = WaktuIso::awalHari(7);

        $total = Transaksi::where('siswa_id', $s->id)
            ->selectRaw("COALESCE(SUM(CASE WHEN tipe='beli' AND created_at >= ? THEN -jumlah END),0) AS belanja_hari_ini", [$awalHariIni])
            ->selectRaw("SUM(CASE WHEN tipe='beli' AND created_at >= ? THEN 1 ELSE 0 END) AS n_hari_ini", [$awalHariIni])
            ->selectRaw("COALESCE(SUM(CASE WHEN tipe='beli' AND created_at >= ? THEN -jumlah END),0) AS belanja_7hari", [$awal7])
            ->selectRaw("COALESCE(SUM(CASE WHEN tipe='beli' AND created_at >= ? THEN -jumlah END),0) AS belanja_periode", [$sejak])
            ->selectRaw("SUM(CASE WHEN tipe='beli' AND created_at >= ? THEN 1 ELSE 0 END) AS n_periode", [$sejak])
            ->selectRaw("COALESCE(SUM(CASE WHEN tipe='topup' AND created_at >= ? THEN jumlah END),0) AS topup_periode", [$sejak])
            ->first();

        $perTenant = Transaksi::where('siswa_id', $s->id)->where('tipe', 'beli')
            ->where('created_at', '>=', $sejak)
            ->selectRaw("COALESCE(tenant_nama,'—') AS nama, COUNT(*) AS n, COALESCE(SUM(-jumlah),0) AS total")
            ->groupBy('tenant_id', 'nama')->orderByDesc('total')->get()
            ->map(fn ($x) => ['nama' => $x->nama, 'n' => (int) $x->n, 'total' => (int) $x->total]);

        // Jajanan tersering: keterangan transaksi beli berisi daftar menu
        // dipisah koma. Dipecah di sini supaya orang tua tahu anaknya sebenarnya
        // jajan apa, bukan cuma angka.
        $hitung = [];
        foreach (Transaksi::where('siswa_id', $s->id)->where('tipe', 'beli')
            ->where('created_at', '>=', $sejak)->pluck('keterangan') as $ket) {
            foreach (explode(',', (string) $ket) as $nama) {
                $k = trim($nama);
                if ($k !== '') {
                    $hitung[$k] = ($hitung[$k] ?? 0) + 1;
                }
            }
        }
        arsort($hitung);
        $menuFavorit = [];
        foreach (array_slice($hitung, 0, 8, true) as $nama => $n) {
            $menuFavorit[] = ['nama' => $nama, 'n' => $n];
        }

        $harian = $this->deretHarian($s->id, min($hari, 30));
        $hariAda = count(array_filter($harian, fn ($d) => $d['belanja'] > 0));

        return response()->json(array_merge([
            'siswa' => $this->untukOrtu($s),
            'hari' => $hari,
        ], array_map('intval', (array) $total->getAttributes()), [
            'rata2_per_hari_aktif' => $hariAda ? (int) round(array_sum(array_column($harian, 'belanja')) / $hariAda) : 0,
            'hari_jajan' => $hariAda,
            'harian' => $harian,
            'per_tenant' => $perTenant,
            'menu_favorit' => $menuFavorit,
            'terakhir' => Transaksi::where('siswa_id', $s->id)
                ->orderByDesc('created_at')->value('created_at'),
        ]));
    }

    public function transaksi(Request $r)
    {
        $s = $this->ortu($r);
        $page = max(1, (int) $r->query('page', 1));
        $limit = min(50, (int) $r->query('limit', 20) ?: 20);

        $q = Transaksi::where('siswa_id', $s->id);
        if (in_array($r->query('tipe'), ['topup', 'beli', 'koreksi', 'donasi', 'transfer_keluar', 'transfer_masuk'], true)) {
            $q->where('tipe', $r->query('tipe'));
        }
        if ($dari = $r->query('dari')) {
            $q->where('created_at', '>=', $dari);
        }
        if ($sampai = $r->query('sampai')) {
            $q->where('created_at', '<=', $sampai);
        }

        $agg = (clone $q)->selectRaw('COUNT(*) AS n')
            ->selectRaw('COALESCE(SUM(CASE WHEN jumlah > 0 THEN jumlah END),0) AS total_masuk')
            ->selectRaw('COALESCE(SUM(CASE WHEN jumlah < 0 THEN -jumlah END),0) AS total_keluar')
            ->first();

        $rows = (clone $q)
            ->select('tipe', 'tenant_nama', 'jumlah', 'saldo_sesudah', 'keterangan', 'created_at')
            ->orderByDesc('created_at')->orderByDesc('id')
            ->limit($limit)->offset(($page - 1) * $limit)->get();

        return response()->json([
            'transaksi' => $rows, 'total' => (int) $agg->n,
            'total_masuk' => (int) $agg->total_masuk, 'total_keluar' => (int) $agg->total_keluar,
            'page' => $page, 'limit' => $limit,
        ]);
    }

    // ------------------------------------------------------------ penolong

    private function ortu(Request $r): Siswa
    {
        $s = $r->attributes->get('ortu');
        if (! $s) {
            throw new ApiError(401, 'Sesi wali sudah berakhir — masuk lagi');
        }

        return $s;
    }

    /** Bidang yang boleh dilihat wali. Kunci masuknya sendiri TIDAK termasuk. */
    private function untukOrtu(Siswa $s): array
    {
        return [
            'nama' => $s->nama, 'kelas' => $s->kelas, 'nis' => $s->nis, 'nisn' => $s->nisn,
            // saldo_titipan ikut ditampilkan: orang tua yang menyetor sekaligus
            // banyak harus bisa melihat sisa uangnya masih tercatat, bukan
            // hilang entah ke mana.
            'saldo' => $s->saldo, 'saldo_titipan' => $s->saldo_titipan,
            'status' => $s->status, 'foto' => $s->foto,
            'hp_ortu_samar' => KunciWali::samarkanHp($s->hp_ortu),
            'punya_kartu' => (bool) $s->card_uid,
        ];
    }

    /**
     * Deret harian LENGKAP — hari tanpa transaksi tetap muncul sebagai 0, supaya
     * grafik tidak memampatkan akhir pekan dan libur menjadi tidak terbaca.
     */
    private function deretHarian(int $siswaId, int $hari): array
    {
        $rows = Transaksi::where('siswa_id', $siswaId)
            ->where('created_at', '>=', WaktuIso::awalHari($hari - 1))
            ->selectRaw(SqlWaktu::periode('harian') . ' AS tgl')
            ->selectRaw("COALESCE(SUM(CASE WHEN tipe='beli' THEN -jumlah END),0) AS belanja")
            ->selectRaw("SUM(CASE WHEN tipe='beli' THEN 1 ELSE 0 END) AS n")
            ->groupBy('tgl')->get()->keyBy('tgl');

        $tz = new \DateTimeZone(config('app.timezone', 'Asia/Jakarta'));
        $out = [];
        for ($i = $hari - 1; $i >= 0; $i--) {
            $d = (new \DateTime("-{$i} days", $tz))->format('Y-m-d');
            $r = $rows[$d] ?? null;
            $out[] = ['tgl' => $d, 'belanja' => (int) ($r->belanja ?? 0), 'n' => (int) ($r->n ?? 0)];
        }

        return $out;
    }
}
