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

        /*
         * Biaya admin muncul di DUA sisi, dan itu bukan pembukuan ganda.
         *
         * Wali membayar Rp100.000; yang masuk kartu Rp97.500. Kas sekolah
         * bertambah Rp100.000 — seluruhnya — tetapi yang menjadi KEWAJIBAN
         * hanya Rp97.500. Sisanya sudah menjadi milik sekolah.
         *
         * Maka biaya itu dicatat sebagai uang MASUK (kas memang bertambah
         * sebanyak itu) sekaligus uang KELUAR (ia lepas dari kewajiban, persis
         * seperti setoran ke tenant). Tanpa sisi pertama, persamaan melaporkan
         * kas kurang; tanpa sisi kedua, melaporkan kewajiban yang tidak ada.
         */
        /*
         * Biaya admin masuk sebagai kas, lalu MENGENDAP di Dana Admin.
         *
         * Wali membayar Rp50.000; kas sekolah bertambah seluruhnya, tetapi yang
         * menjadi kewajiban hanya Rp47.500. Sisanya sudah menjadi hak sekolah —
         * dan sejak ada Dana Admin, uang itu MASIH ADA di kas sampai benar-benar
         * ditarik. Karena itu ia tidak lagi dicatat sebagai uang keluar; yang
         * keluar adalah penarikannya.
         *
         * Konsekuensinya kas terbagi dua sisi klaim:
         *
         *     kas = kewajiban (milik orang lain) + dana admin (milik sekolah)
         *
         * Tanpa memasukkan dana admin ke sisi klaim, kas akan melebihi
         * kewajiban sebesar akumulasi biaya, dan neraca melaporkan selisih yang
         * tidak bisa dijelaskan siapa pun.
         */
        $biayaAdmin = $t['biaya_admin'];
        $dana = self::ringkasDana();
        $masuk = $topupAsli + $tp['setor'] + $t['koreksi_masuk'] + $biayaAdmin;

        $keluar = ['tenant' => $disetor, 'titipan' => $tp['tarik'],
            'koreksi' => $t['koreksi_keluar'], 'dana_admin' => $dana['tarik']];
        $kas = $masuk - $keluar['tenant'] - $keluar['titipan'] - $keluar['koreksi'] - $keluar['dana_admin'];

        $hutangTenant = $t['belanja'] - $disetor;
        $kewajiban = $posSaldo + $posTitipan + $hutangTenant + $t['donasi'];
        // Dana Admin bukan kewajiban — ia milik sekolah — tetapi tetap sebuah
        // KLAIM atas kas yang dipegang, jadi ia berdiri sendiri di sebelah
        // kewajiban, bukan dilebur ke dalamnya.
        $danaSaldo = $dana['saldo'];

        // Saldo yang sudah ada sebelum sistem mencatat apa pun (data lama/dummy).
        // Yang DIAKUI admin dipakai menutup persamaan; yang TERUKUR dihitung
        // ulang dari buku besar, jadi keduanya bisa dibandingkan.
        $awalDiakui = ($cfg['neraca_saldo_awal'] ?? 0) + ($cfg['neraca_titipan_awal'] ?? 0);
        $awalTerukur = ($posSaldo - $t['mutasi_bersih']) + ($posTitipan - $tp['mutasi_bersih']);
        $selisih = $kas - $kewajiban - $danaSaldo + $awalDiakui;

        $drift = self::driftSaldo();
        $driftT = self::driftTitipan();

        return [
            'masuk' => [
                'total' => $masuk, 'topup' => $topupAsli, 'titipan' => $tp['setor'],
                'koreksi' => $t['koreksi_masuk'], 'biaya_admin' => $biayaAdmin,
                'internal_isi_ulang' => $tp['isi_saldo'],
                'per_metode' => self::metodeUangMasuk(),
            ],
            'keluar' => $keluar,
            'kas' => $kas,
            'kewajiban' => [
                'total' => $kewajiban, 'saldo_kartu' => $posSaldo, 'saldo_titipan' => $posTitipan,
                'hutang_tenant' => $hutangTenant, 'donasi' => $t['donasi'],
            ],
            'penjualan_tenant' => $t['belanja'],
            // Pendapatan sekolah dari biaya admin — bukan kewajiban, jadi
            // ditampilkan tersendiri, bukan disembunyikan di dalam "keluar".
            'pendapatan_biaya_admin' => $biayaAdmin,
            'dana_admin' => [
                'saldo' => $danaSaldo, 'masuk' => $dana['masuk'], 'tarik' => $dana['tarik'],
                'n_masuk' => $dana['n_masuk'], 'n_tarik' => $dana['n_tarik'],
            ],
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
            ->selectRaw("COALESCE(SUM(CASE WHEN tipe='biaya_admin' THEN -jumlah END),0) AS biaya_admin")
            /*
             * mutasi_bersih berarti "seberapa jauh SALDO KARTU bergerak", dan
             * dipakai untuk menurunkan saldo awal: posisi sekarang dikurangi
             * seluruh pergerakan yang tercatat. Baris biaya admin karena itu
             * DIKECUALIKAN — ia bernilai negatif tetapi tidak pernah
             * menggerakkan saldo siapa pun; uangnya dipotong sebelum masuk
             * kartu.
             *
             * Tanpa pengecualian ini, tiap biaya yang dipungut memunculkan
             * "saldo awal terukur" sebesar biaya itu — saldo yang tidak pernah
             * ada. Dan bila admin lalu menekan "tetapkan saldo awal", angka
             * hantu itu ikut diakui dan neraca selisih selamanya. Ditemukan
             * oleh suite uji neraca, bukan oleh perhitungan tangan.
             */
            ->selectRaw("COALESCE(SUM(CASE WHEN tipe <> 'biaya_admin' THEN jumlah END),0) AS mutasi_bersih")
            ->first();

        return array_map('intval', (array) $r->getAttributes());
    }

    private static function ringkasDana(): array
    {
        return \App\Services\DanaAdmin::ringkas();
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
     * Santri yang saldonya tidak sama dengan baris TERAKHIR buku besarnya.
     *
     * Ini pemeriksa perubahan saldo di luar jalur aplikasi — sunting langsung
     * di basis data, atau bug yang lupa mencatat. Tiap transaksi menyimpan
     * saldo_sesudah, jadi baris terakhir seorang santri wajib sama dengan
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

        /*
         * Setiap baris biaya admin harus menempel pada satu pengajuan wali yang
         * BENAR-BENAR disetujui. Biaya yang berdiri sendiri berarti uang
         * berpindah dari kewajiban menjadi milik sekolah tanpa ada persetujuan
         * yang bisa ditunjuk — persis jenis kejadian yang tidak boleh lolos
         * diam-diam dari sebuah neraca.
         */
        $biayaYatim = (int) DB::selectOne("
            SELECT COUNT(*) AS n FROM transaksi t
             WHERE t.tipe='biaya_admin'
               AND (t.tx_id IS NULL
                 OR NOT EXISTS (SELECT 1 FROM topup_request r
                                 WHERE r.status='disetujui'
                                   AND CONCAT('ortu-biaya-', r.id) = t.tx_id))")->n;

        $biayaPositif = Transaksi::where('tipe', 'biaya_admin')->where('jumlah', '>=', 0)->count();

        /*
         * Setiap rupiah biaya yang dipungut harus mengalir ke Dana Admin, tanpa
         * kecuali. Kalau jumlah yang dipungut dan yang masuk dana pernah
         * berbeda, ada uang yang diambil dari wali tetapi tidak tercatat
         * menjadi hak sekolah — dan tidak ada satu pun laporan yang akan
         * menunjukkan ke mana perginya.
         */
        $danaR = \App\Services\DanaAdmin::ringkas();
        $biayaDipungut = (int) Transaksi::where('tipe', 'biaya_admin')->sum(DB::raw('-jumlah'));
        $danaTakCocok = $biayaDipungut !== $danaR['masuk'];
        $danaMinus = $danaR['saldo'] < 0;

        $daftar = [[
            'kode' => 'selisih', 'label' => 'Neraca seimbang (kas = kewajiban)',
            'ok' => $selisih === 0, 'nilai' => $selisih,
            'catatan' => $selisih === 0 ? '' : ($selisih < 0
                ? 'Kewajiban lebih besar dari uang yang pernah masuk. Kalau ini pemasangan baru di ' .
                  'atas data lama/dummy, tetapkan saldo awal; kalau bukan, ada saldo yang muncul ' .
                  'tanpa uangnya masuk.'
                : 'Uang masuk lebih besar dari kewajiban yang tercatat — mungkin ada saldo santri ' .
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
                    "{$drift['n']} santri saldonya tidak sama dengan catatan transaksi terakhirnya. " .
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
            ['kode' => 'biaya_yatim', 'label' => 'Setiap biaya admin menempel pada pengajuan wali yang disetujui',
                'ok' => $biayaYatim === 0, 'nilai' => $biayaYatim,
                'catatan' => $biayaYatim === 0 ? '' :
                    "{$biayaYatim} baris biaya admin tidak punya pengajuan yang bisa ditunjuk. " .
                    'Berarti uang berpindah dari kewajiban menjadi milik sekolah tanpa persetujuan.'],
            ['kode' => 'biaya_arah', 'label' => 'Biaya admin selalu tercatat sebagai pengurang',
                'ok' => $biayaPositif === 0, 'nilai' => $biayaPositif, 'catatan' => ''],
            ['kode' => 'dana_cocok', 'label' => 'Seluruh biaya yang dipungut mengalir ke Dana Admin',
                'ok' => ! $danaTakCocok, 'nilai' => $biayaDipungut - $danaR['masuk'],
                'catatan' => $danaTakCocok
                    ? 'Ada biaya yang dipungut dari wali tetapi tidak tercatat masuk ke Dana Admin.'
                    : ''],
            ['kode' => 'dana_minus', 'label' => 'Dana Admin tidak pernah bernilai minus',
                'ok' => ! $danaMinus, 'nilai' => $danaR['saldo'],
                'catatan' => $danaMinus
                    ? 'Penarikan melebihi yang terkumpul — berarti uang titipan wali ikut terambil.'
                    : ''],
        ]);
    }
}
