<?php

namespace App\Services;

use App\Models\Setting;
use App\Models\Settlement;
use App\Models\Siswa;
use App\Models\TitipanMutasi;
use App\Models\TopupImporBaris;
use App\Models\TopupRequest;
use App\Models\Transaksi;
use Illuminate\Support\Facades\DB;

/**
 * Neraca kas sekolah — pemeriksa bahwa "tidak ada pintu lain untuk uang".
 *
 * Persamaannya sederhana: uang yang pernah MASUK, dikurangi yang sudah KELUAR,
 * harus sama dengan seluruh KEWAJIBAN yang masih dipegang sekolah (saldo kartu,
 * titipan, utang ke tenant, dan donasi yang belum disalurkan).
 *
 * Selisih bukan sekadar angka laporan: selisih negatif berarti ada saldo yang
 * muncul tanpa uangnya pernah masuk. Karena itu daftar `periksa` di bawah
 * menyebut setiap penyebab yang mungkin, satu per satu, alih-alih hanya
 * melaporkan totalnya.
 */
class Neraca
{
    public static function hitung(): array
    {
        $cfg = Pengaturan::sekolah();
        $t = self::ringkasTransaksi();
        $tp = self::ringkasTitipan();

        $pos = Siswa::selectRaw('COALESCE(SUM(saldo),0) AS saldo, COALESCE(SUM(saldo_titipan),0) AS titipan')->first();
        $posSaldo = (int) $pos->saldo;
        $posTitipan = (int) $pos->titipan;

        $disetor = (int) Settlement::sum('jumlah');
        $antre = TopupRequest::where('status', 'pending')
            ->selectRaw('COUNT(*) AS n, COALESCE(SUM(jumlah),0) AS rp')->first();

        // Uang masuk: top up yang benar-benar uang BARU, ditambah setoran titipan.
        // Isi ulang otomatis dari titipan bukan uang masuk — uangnya sudah
        // dihitung saat disetorkan, jadi harus dikurangkan supaya tidak dobel.
        $topupAsli = $t['topup'] - $tp['isi_saldo'];
        $masuk = $topupAsli + $tp['setor'] + $t['koreksi_masuk'];

        $keluar = ['tenant' => $disetor, 'titipan' => $tp['tarik'], 'koreksi' => $t['koreksi_keluar']];
        $kas = $masuk - $keluar['tenant'] - $keluar['titipan'] - $keluar['koreksi'];

        $hutangTenant = $t['belanja'] - $disetor;
        $kewajiban = $posSaldo + $posTitipan + $hutangTenant + $t['donasi'];

        // Saldo yang sudah ada sebelum sistem mencatat apa pun (data lama/dummy).
        // Yang DIAKUI admin dipakai menutup persamaan; yang TERUKUR dihitung
        // ulang dari buku besar, jadi keduanya bisa dibandingkan.
        $awalDiakui = ($cfg['neraca_saldo_awal'] ?? 0) + ($cfg['neraca_titipan_awal'] ?? 0);
        $awalTerukur = ($posSaldo - $t['mutasi_bersih']) + ($posTitipan - $tp['mutasi_bersih']);
        $selisih = $kas - $kewajiban + $awalDiakui;

        $drift = self::driftSaldo();
        $driftT = self::driftTitipan();

        return [
            'masuk' => [
                'total' => $masuk, 'topup' => $topupAsli, 'titipan' => $tp['setor'],
                'koreksi' => $t['koreksi_masuk'], 'internal_isi_ulang' => $tp['isi_saldo'],
                'per_metode' => self::metodeUangMasuk(),
            ],
            'keluar' => $keluar,
            'kas' => $kas,
            'kewajiban' => [
                'total' => $kewajiban, 'saldo_kartu' => $posSaldo, 'saldo_titipan' => $posTitipan,
                'hutang_tenant' => $hutangTenant, 'donasi' => $t['donasi'],
            ],
            'penjualan_tenant' => $t['belanja'],
            'awal' => [
                'diakui' => $awalDiakui, 'terukur' => $awalTerukur,
                'saldo' => $cfg['neraca_saldo_awal'] ?? 0,
                'titipan' => $cfg['neraca_titipan_awal'] ?? 0,
                'pada' => $cfg['neraca_awal_pada'] ?? 0,
            ],
            'selisih' => $selisih,
            'cukup' => $kewajiban > 0 ? round(($kas + $awalDiakui) / $kewajiban * 1000) / 10 : null,
            'tertunda' => ['n' => (int) $antre->n, 'rp' => (int) $antre->rp],
            'periksa' => self::periksa($cfg, $selisih, $awalDiakui, $awalTerukur, $drift, $driftT),
        ];
    }

    /** Tetapkan saldo tanpa catatan mutasi sebagai saldo awal yang diakui. */
    public static function tetapkanSaldoAwal(): array
    {
        return DB::transaction(function () {
            $t = self::ringkasTransaksi();
            $tp = self::ringkasTitipan();
            $pos = Siswa::selectRaw('COALESCE(SUM(saldo),0) AS saldo, COALESCE(SUM(saldo_titipan),0) AS titipan')->first();

            // Angkanya TIDAK diketik admin — diambil dari buku besar, supaya
            // tombol ini tidak bisa dipakai menutupi selisih yang timbul kemudian.
            $awalSaldo = (int) $pos->saldo - $t['mutasi_bersih'];
            $awalTitipan = (int) $pos->titipan - $tp['mutasi_bersih'];

            Setting::simpan('neraca_saldo_awal', $awalSaldo);
            Setting::simpan('neraca_titipan_awal', $awalTitipan);
            Setting::simpan('neraca_awal_pada', time());
            Pengaturan::lupakanCache();

            return ['awal_saldo' => $awalSaldo, 'awal_titipan' => $awalTitipan];
        });
    }

    // ------------------------------------------------------------ ringkasan

    private static function ringkasTransaksi(): array
    {
        $r = Transaksi::query()
            ->selectRaw("COALESCE(SUM(CASE WHEN tipe='topup' THEN jumlah END),0) AS topup")
            ->selectRaw("COALESCE(SUM(CASE WHEN tipe='beli' THEN -jumlah END),0) AS belanja")
            ->selectRaw("COALESCE(SUM(CASE WHEN tipe='donasi' THEN -jumlah END),0) AS donasi")
            ->selectRaw("COALESCE(SUM(CASE WHEN tipe='koreksi' AND jumlah < 0 THEN -jumlah END),0) AS koreksi_keluar")
            ->selectRaw("COALESCE(SUM(CASE WHEN tipe='koreksi' AND jumlah > 0 THEN jumlah END),0) AS koreksi_masuk")
            ->selectRaw('COALESCE(SUM(jumlah),0) AS mutasi_bersih')
            ->first();

        return array_map('intval', (array) $r->getAttributes());
    }

    private static function ringkasTitipan(): array
    {
        $r = TitipanMutasi::query()
            ->selectRaw("COALESCE(SUM(CASE WHEN jenis='setor' THEN jumlah END),0) AS setor")
            ->selectRaw("COALESCE(SUM(CASE WHEN jenis='tarik' THEN -jumlah END),0) AS tarik")
            ->selectRaw("COALESCE(SUM(CASE WHEN jenis='isi_saldo' THEN -jumlah END),0) AS isi_saldo")
            ->selectRaw("COALESCE(SUM(CASE WHEN jenis='koreksi' THEN jumlah END),0) AS koreksi")
            ->selectRaw('COALESCE(SUM(jumlah),0) AS mutasi_bersih')
            ->first();

        return array_map('intval', (array) $r->getAttributes());
    }

    /**
     * Siswa yang saldonya tidak sama dengan baris TERAKHIR buku besarnya.
     *
     * Ini pemeriksa perubahan saldo di luar jalur aplikasi — sunting langsung
     * di basis data, atau bug yang lupa mencatat. Tiap transaksi menyimpan
     * saldo_sesudah, jadi baris terakhir seorang siswa wajib sama dengan
     * saldonya sekarang.
     */
    private static function driftSaldo(): array
    {
        $r = DB::selectOne("
            WITH akhir AS (
                SELECT siswa_id, saldo_sesudah,
                       ROW_NUMBER() OVER (PARTITION BY siswa_id ORDER BY created_at DESC, id DESC) AS urut
                  FROM transaksi)
            SELECT COUNT(*) AS n, COALESCE(SUM(s.saldo - a.saldo_sesudah),0) AS selisih
              FROM akhir a JOIN siswa s ON s.id = a.siswa_id
             WHERE a.urut = 1 AND s.saldo != a.saldo_sesudah");

        return ['n' => (int) $r->n, 'selisih' => (int) $r->selisih];
    }

    private static function driftTitipan(): array
    {
        $r = DB::selectOne("
            WITH akhir AS (
                SELECT siswa_id, titipan_sesudah,
                       ROW_NUMBER() OVER (PARTITION BY siswa_id ORDER BY created_at DESC, id DESC) AS urut
                  FROM titipan_mutasi)
            SELECT COUNT(*) AS n, COALESCE(SUM(s.saldo_titipan - a.titipan_sesudah),0) AS selisih
              FROM akhir a JOIN siswa s ON s.id = a.siswa_id
             WHERE a.urut = 1 AND s.saldo_titipan != a.titipan_sesudah");

        return ['n' => (int) $r->n, 'selisih' => (int) $r->selisih];
    }

    /** Rincian metode uang masuk — hanya untuk setoran yang metodenya tercatat. */
    private static function metodeUangMasuk(): array
    {
        $out = ['tunai' => 0, 'transfer' => 0, 'qris' => 0];

        $sumber = [
            TopupImporBaris::where('status', 'berhasil')
                ->selectRaw('metode, COALESCE(SUM(jumlah),0) AS rp')->groupBy('metode')->get(),
            TopupRequest::where('status', 'disetujui')
                ->selectRaw('metode, COALESCE(SUM(jumlah),0) AS rp')->groupBy('metode')->get(),
        ];
        foreach ($sumber as $baris) {
            foreach ($baris as $r) {
                if (array_key_exists($r->metode, $out)) {
                    $out[$r->metode] += (int) $r->rp;
                }
            }
        }

        return $out;
    }

    private static function periksa(array $cfg, int $selisih, int $awalDiakui, int $awalTerukur, array $drift, array $driftT): array
    {
        $lebihSetor = (int) DB::selectOne("
            SELECT COUNT(*) AS n FROM tenant t
             WHERE (SELECT COALESCE(SUM(jumlah),0) FROM settlement WHERE tenant_id=t.id)
                 > (SELECT COALESCE(SUM(-jumlah),0) FROM transaksi WHERE tenant_id=t.id AND tipe='beli')")->n;

        $beliTanpaTenant = Transaksi::where('tipe', 'beli')->whereNull('tenant_id')->count();

        $ortuTanpaJejak = (int) DB::selectOne("
            SELECT COUNT(*) AS n FROM topup_request r
             WHERE r.status='disetujui' AND r.transaksi_id IS NULL
               AND NOT EXISTS (SELECT 1 FROM titipan_mutasi m WHERE m.ref = CONCAT('ortu-topup-', r.id))")->n;

        $imporTanpaTx = (int) DB::selectOne("
            SELECT COUNT(*) AS n FROM topup_impor_baris b JOIN topup_impor i ON i.id = b.impor_id
             WHERE b.status='berhasil' AND b.ke_saldo > 0
               AND NOT EXISTS (SELECT 1 FROM transaksi t
                                WHERE t.tx_id = CONCAT('impor-', i.batch_id, '-b', b.baris))")->n;

        $saldoMinus = Siswa::where('saldo', '<', 0)->orWhere('saldo_titipan', '<', 0)->count();

        $daftar = [[
            'kode' => 'selisih', 'label' => 'Neraca seimbang (kas = kewajiban)',
            'ok' => $selisih === 0, 'nilai' => $selisih,
            'catatan' => $selisih === 0 ? '' : ($selisih < 0
                ? 'Kewajiban lebih besar dari uang yang pernah masuk. Kalau ini pemasangan baru di ' .
                  'atas data lama/dummy, tetapkan saldo awal; kalau bukan, ada saldo yang muncul ' .
                  'tanpa uangnya masuk.'
                : 'Uang masuk lebih besar dari kewajiban yang tercatat — mungkin ada saldo siswa ' .
                  'yang dikurangi tanpa catatan, atau saldo awal yang diakui terlalu besar.'),
        ]];

        // Hanya berarti setelah saldo awal pernah ditetapkan; sebelum itu
        // angkanya sama saja dengan selisih di atas dan cuma menambah bising.
        if (($cfg['neraca_awal_pada'] ?? 0) > 0) {
            $daftar[] = [
                'kode' => 'saldo_awal', 'label' => 'Saldo awal yang diakui sesuai buku besar',
                'ok' => $awalDiakui === $awalTerukur, 'nilai' => $awalTerukur - $awalDiakui,
                'catatan' => $awalDiakui === $awalTerukur ? '' :
                    'Saldo tanpa catatan mutasi berubah sejak saldo awal ditetapkan — berarti ada saldo ' .
                    'yang disetel di luar aplikasi sesudah itu.',
            ];
        }

        return array_merge($daftar, [
            [
                'kode' => 'drift_saldo', 'label' => 'Saldo kartu cocok dengan baris terakhir buku besar',
                'ok' => $drift['n'] === 0, 'nilai' => $drift['selisih'], 'jumlah_siswa' => $drift['n'],
                'catatan' => $drift['n'] === 0 ? '' :
                    "{$drift['n']} siswa saldonya tidak sama dengan catatan transaksi terakhirnya. " .
                    'Berarti saldo pernah diubah di luar aplikasi.',
            ],
            ['kode' => 'drift_titipan', 'label' => 'Saldo titipan cocok dengan buku besar titipan',
                'ok' => $driftT['n'] === 0, 'nilai' => $driftT['selisih'], 'jumlah_siswa' => $driftT['n'], 'catatan' => ''],
            ['kode' => 'setoran_lebih', 'label' => 'Tidak ada tenant yang disetor melebihi penjualannya',
                'ok' => $lebihSetor === 0, 'nilai' => $lebihSetor, 'catatan' => ''],
            ['kode' => 'beli_tanpa_tenant', 'label' => 'Semua transaksi belanja punya tenant',
                'ok' => $beliTanpaTenant === 0, 'nilai' => $beliTanpaTenant, 'catatan' => ''],
            ['kode' => 'ortu_tanpa_jejak', 'label' => 'Pengajuan ortu yang disetujui punya jejak mutasi',
                'ok' => $ortuTanpaJejak === 0, 'nilai' => $ortuTanpaJejak, 'catatan' => ''],
            ['kode' => 'impor_tanpa_tx', 'label' => 'Semua baris impor massal punya transaksinya',
                'ok' => $imporTanpaTx === 0, 'nilai' => $imporTanpaTx, 'catatan' => ''],
            ['kode' => 'saldo_minus', 'label' => 'Tidak ada saldo atau titipan bernilai minus',
                'ok' => $saldoMinus === 0, 'nilai' => $saldoMinus, 'catatan' => ''],
        ]);
    }
}
