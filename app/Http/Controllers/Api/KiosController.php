<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\ApiError;
use App\Models\DonasiNominal;
use App\Models\DonasiProgram;
use App\Models\KiosConfig;
use App\Models\Siswa;
use App\Models\TabunganJenis;
use App\Models\Transaksi;
use App\Services\BidangSiswa;
use App\Services\DonasiSetor;
use App\Services\Iuran;
use App\Services\Kartu;
use App\Services\Pengaturan;
use App\Services\Pin;
use App\Services\Rekap;
use App\Services\Rem;
use App\Services\Rupiah;
use App\Services\SesiKios;
use App\Services\SmartTopup;
use App\Services\SqlWaktu;
use App\Services\Tabungan;
use App\Services\Transfer;
use App\Support\WaktuIso;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Layar kios santri (/kios) — dipasang di depan reader kartu, tanpa login.
 *
 * Santri tap kartu untuk cek saldo, dan tap + PIN untuk berdonasi, mengirim
 * saldo ke teman, melihat riwayat, atau mengganti PIN sendiri.
 *
 * Layar ini terbaca antrean di belakang, dan satu-satunya bukti identitas adalah
 * kartu yang bisa saja sedang dipegang orang lain. Karena itu setiap tindakan
 * yang menyentuh uang atau data pribadi meminta PIN, dan satu tap hanya berlaku
 * untuk satu transaksi.
 */
class KiosController extends Controller
{
    private const RIWAYAT_LIMIT = 25;

    private const RIWAYAT_MAKS_HAL = 20;   // 500 baris — kios untuk melihat, bukan mengunduh massal

    /** Kios sehat paling banyak beberapa tap per menit; ini membendung skrip penyisir UID. */
    private const TAP_PER_MENIT = 60;

    /**
     * Penanda peringatan di panel admin berarti "sandinya memang masih
     * rahasia123", bukan "belum pernah diganti" — admin yang mengembalikannya
     * ke bawaan harus tetap diperingatkan.
     */
    private const SANDI_BAWAAN = 'rahasia123';

    public function info()
    {
        $cfg = Pengaturan::sekolah();
        $aktif = ($cfg['kios_aktif'] ?? 0) === 1;

        return response()->json([
            'aktif' => $aktif,
            'program' => $aktif ? DonasiController::daftar(false) : [],
            'maks_donasi_harian' => $cfg['donasi_maks_harian'] ?? 0,
            'tabungan_jenis' => $aktif ? TabunganController::daftar(false) : [],
        ]);
    }

    public function tap(Request $r)
    {
        $this->harusAktif();
        $this->kuotaTap($r);

        $uid = BidangSiswa::uid($r->input('uid'));
        if (! $uid) {
            throw new ApiError(400, 'UID kartu kosong');
        }
        $s = Siswa::where('card_uid', $uid)->first();
        if (! $s) {
            return response()->json(['ok' => false, 'terdaftar' => false]);
        }

        // Sebagian golongan sengaja tidak dilayani kios (mis. kartu tamu sekali
        // pakai yang tidak punya PIN). Ditolak dengan ALASANNYA, bukan dianggap
        // tak terdaftar — pemegangnya berhak tahu kartunya sah tetapi layar ini
        // bukan untuknya.
        $gol = Pengaturan::golongan($s->golongan);
        if (! $gol?->kios) {
            return response()->json(['ok' => false, 'terdaftar' => true,
                'ditolak' => "Kartu {$gol?->nama} tidak dilayani di kios"]);
        }
        if (Kartu::kedaluwarsa($s)) {
            return response()->json(['ok' => false, 'terdaftar' => true,
                'ditolak' => "Masa berlaku kartu habis ({$s->berlaku_sampai})"]);
        }

        // Santri mengecek saldo di kios justru ketika merasa saldonya tipis —
        // isi ulang dari titipan dikerjakan lebih dulu supaya angka di layar
        // sudah yang terbaru.
        $smart = SmartTopup::diam($s->id, 'tap kios');
        if ($smart) {
            $s = Siswa::find($s->id);
        }

        return response()->json(array_merge(
            ['ok' => true, 'terdaftar' => true],
            $this->bekal($s, SesiKios::buka($s->id)),
            ['smart' => $smart ? ['isi' => $smart['isi'], 'sisa_titipan' => $smart['sisa_titipan']] : null],
        ));
    }

    public function donasi(Request $r)
    {
        $this->harusAktif();
        $txId = $r->input('tx_id') ? mb_substr((string) $r->input('tx_id'), 0, 80) : null;

        if ($jawab = $this->jawabUlang($r, $txId, 'donasi')) {
            return $jawab;
        }

        ['token' => $token, 'sesi' => $sesi, 'siswa' => $s] = SesiKios::ambil($r->input('token'));

        $p = DonasiProgram::where('id', (int) $r->input('program_id'))->where('aktif', true)->first();
        if (! $p) {
            throw new ApiError(404, 'Jenis donasi tidak tersedia');
        }
        $jumlah = (int) floor((float) $r->input('jumlah'));
        if ($jumlah <= 0) {
            throw new ApiError(400, 'Nominal donasi tidak valid');
        }
        // Besaran donasi ditentukan sekolah: hanya angka yang persis ada di
        // daftar nominal program ini yang diterima, berapa pun yang dikirim klien.
        if (! DonasiNominal::where('program_id', $p->id)->where('jumlah', $jumlah)->exists()) {
            throw new ApiError(400, 'Nominal donasi tidak sesuai pilihan yang disediakan sekolah');
        }

        Pin::verifikasi($s, $r->input('pin'), $r);
        if ($s->status !== 'aktif') {
            throw new ApiError(403, 'Kartu sedang diblokir — hubungi admin kantin');
        }

        $cfg = Pengaturan::sekolah();

        // Jaring pengaman terakhir untuk tx_id yang sudah tercatat (mis. sesi
        // lamanya sudah kedaluwarsa): jawab apa adanya, jangan mencatat dua kali.
        if ($txId && $dupe = Transaksi::where('tx_id', $txId)->first()) {
            $s2 = Siswa::find($s->id);

            return response()->json([
                'ok' => true, 'duplikat' => true, 'program' => $p->nama,
                'jumlah' => -$dupe->jumlah, 'saldo' => $s2->saldo, 'waktu' => $dupe->created_at,
                'sisa_donasi' => max(0, $cfg['donasi_maks_harian'] - DonasiSetor::hariIni($s->id)),
            ]);
        }

        $sudah = DonasiSetor::hariIni($s->id);
        if ($sudah + $jumlah > $cfg['donasi_maks_harian']) {
            throw new ApiError(429, 'Melebihi batas donasi harian (hari ini sudah ' . Rupiah::f($sudah) .
                ', batas ' . Rupiah::f($cfg['donasi_maks_harian']) . ')');
        }

        $hasil = DB::transaction(fn () => DonasiSetor::setor($s, $p, $jumlah, 'Kios Santri', $txId));

        // Donasi bisa saja membuat saldo menyentuh ambang — titipan diteteskan
        // SEKARANG, di luar transaksi di atas, sama seperti setelah belanja
        // kasir (lihat KasirController::beli). Tanpa ini santri harus tap
        // ulang kartunya dulu sebelum titipannya ikut terlihat.
        if ($smart = SmartTopup::diam($s->id, 'donasi kios')) {
            $hasil['saldo'] = $smart['siswa']->saldo;
        }

        $jawab = [
            'ok' => true, 'program' => $p->nama, 'jumlah' => $jumlah,
            'saldo' => $hasil['saldo'], 'waktu' => $hasil['waktu'],
            'sisa_donasi' => max(0, $cfg['donasi_maks_harian'] - ($sudah + $jumlah)),
        ];
        $this->tandaiSelesai($token, $sesi, $txId, $jawab);   // satu tap = satu donasi

        return response()->json($jawab);
    }

    /**
     * Menabung sendiri lewat kios — SELALU dari saldo kartu saja (tidak
     * menyentuh titipan). Kontribusi besar yang perlu mengambil dari titipan
     * dilakukan admin lewat TabunganController::setor(), bukan di sini.
     */
    public function tabungan(Request $r)
    {
        $this->harusAktif();
        $txId = $r->input('tx_id') ? mb_substr((string) $r->input('tx_id'), 0, 80) : null;

        if ($jawab = $this->jawabUlang($r, $txId, 'tabungan')) {
            return $jawab;
        }

        ['token' => $token, 'sesi' => $sesi, 'siswa' => $s] = SesiKios::ambil($r->input('token'));

        $jenis = TabunganJenis::where('id', (int) $r->input('jenis_id'))->where('aktif', true)->first();
        if (! $jenis) {
            throw new ApiError(404, 'Jenis tabungan tidak tersedia');
        }
        $cfg = Pengaturan::sekolah();
        $jumlah = (int) floor((float) $r->input('jumlah'));
        if ($jumlah < ($cfg['tabungan_min'] ?? 0)) {
            throw new ApiError(400, 'Nominal menabung minimal ' . Rupiah::f($cfg['tabungan_min'] ?? 0));
        }

        Pin::verifikasi($s, $r->input('pin'), $r);
        if ($s->status !== 'aktif') {
            throw new ApiError(403, 'Kartu sedang diblokir — hubungi admin kantin');
        }

        if ($txId && $dupe = Transaksi::where('tx_id', $txId)->first()) {
            $s2 = Siswa::find($s->id);
            $baris = \App\Models\TabunganSaldo::where('siswa_id', $s->id)->where('jenis_id', $jenis->id)->first();

            return response()->json([
                'ok' => true, 'duplikat' => true, 'jenis' => $jenis->nama,
                'jumlah' => -$dupe->jumlah, 'saldo' => $s2->saldo,
                'saldo_tabungan' => $baris ? (int) $baris->saldo : 0, 'waktu' => $dupe->created_at,
            ]);
        }

        $sudah = Tabungan::hariIni($s->id);
        if ($sudah + $jumlah > ($cfg['tabungan_maks_harian'] ?? 0)) {
            throw new ApiError(429, 'Melebihi batas menabung harian (hari ini sudah ' . Rupiah::f($sudah) .
                ', batas ' . Rupiah::f($cfg['tabungan_maks_harian'] ?? 0) . ')');
        }

        $hasil = DB::transaction(fn () => Tabungan::setor($s, $jenis, $jumlah, 'kartu', 'Kios Santri',
            'Tabungan ' . $jenis->nama, $txId));

        // Sisa saldo bisa saja menyentuh ambang — lihat catatan yang sama di
        // donasi() di atas.
        if ($smart = SmartTopup::diam($s->id, 'tabungan kios')) {
            $hasil['siswa'] = $smart['siswa'];
        }

        $jawab = [
            'ok' => true, 'jenis' => $jenis->nama, 'jumlah' => $jumlah,
            'saldo' => $hasil['siswa']->saldo, 'saldo_tabungan' => $hasil['saldo_tabungan']->saldo,
            'sisa_tabungan_harian' => max(0, ($cfg['tabungan_maks_harian'] ?? 0) - ($sudah + $jumlah)),
        ];
        $this->tandaiSelesai($token, $sesi, $txId, $jawab);   // satu tap = satu setoran

        return response()->json($jawab);
    }

    public function cariPenerima(Request $r)
    {
        $this->harusAktif();
        Transfer::harusAktif();

        ['token' => $token, 'sesi' => $sesi, 'siswa' => $s] = SesiKios::ambil($r->input('token'));
        $g = Pengaturan::golongan($s->golongan);
        if (! $g?->transfer) {
            throw new ApiError(403, "Kartu {$g?->nama} tidak bisa mengirim saldo");
        }

        // Tanpa batas ini, satu tap kartu sendiri cukup untuk menyisir seluruh
        // NIS sekolah dan memanen daftar nama — dan NIS adalah PIN bawaan kios.
        $sesi['cari']++;
        SesiKios::simpan($token, $sesi);
        if ($sesi['cari'] > SesiKios::CARI_MAKS) {
            throw new ApiError(429, 'Terlalu banyak pencarian NIS dalam satu tap — tempelkan kartu lagi');
        }

        $p = Transfer::cariPenerima($r->input('nis'), $s);
        Transfer::wajibSamaGenderAtauKeluarga($s, $p);
        $cfg = Pengaturan::sekolah();
        $hari = Transfer::hariIni($s->id);

        return response()->json([
            'ok' => true,
            'penerima' => Transfer::penerimaUntukKios($p),
            // Sisa jatah dikirim bersama hasil pencarian supaya layar nominal
            // bisa langsung menutup pilihan yang pasti ditolak, bukan
            // menolaknya belakangan.
            'min' => $cfg['transfer_min'],
            'maks' => min($cfg['transfer_maks'], max(0, $cfg['transfer_maks_harian'] - $hari['kirim']), $s->saldo),
            'sisa_harian' => max(0, $cfg['transfer_maks_harian'] - $hari['kirim']),
            'sisa_kali' => max(0, $cfg['transfer_maks_kali_harian'] - $hari['n_kirim']),
            'saldo' => $s->saldo,
        ]);
    }

    public function transfer(Request $r)
    {
        $this->harusAktif();
        Transfer::harusAktif();
        $txId = $r->input('tx_id') ? mb_substr((string) $r->input('tx_id'), 0, 80) : null;

        if ($jawab = $this->jawabUlang($r, $txId, 'transfer')) {
            return $jawab;
        }

        ['token' => $token, 'sesi' => $sesi, 'siswa' => $s] = SesiKios::ambil($r->input('token'));
        $g = Pengaturan::golongan($s->golongan);
        if (! $g?->transfer) {
            throw new ApiError(403, "Kartu {$g?->nama} tidak bisa mengirim saldo");
        }

        $penerima = Transfer::cariPenerima($r->input('nis'), $s);
        // Diperiksa ulang di sini, bukan percaya hasil langkah cariPenerima
        // tadi — kios bisa saja mengirim 'nis' baru tanpa lewat cariPenerima lagi.
        Transfer::wajibSamaGenderAtauKeluarga($s, $penerima);
        $jumlah = (int) floor((float) $r->input('jumlah'));
        // Catatan OPSIONAL — beda dari deskripsi iuran yang wajib. Transfer
        // pertemanan biasa tidak boleh dipersulit oleh kolom wajib; catatan
        // ini sekadar pengingat tambahan ("buat jajan besok"), bukan syarat.
        $catatan = mb_substr(trim((string) $r->input('catatan', '')), 0, 60);
        $cfg = Pengaturan::sekolah();

        if ($jumlah <= 0) {
            throw new ApiError(400, 'Nominal transfer tidak valid');
        }
        if ($jumlah < $cfg['transfer_min']) {
            throw new ApiError(400, 'Minimal kirim ' . Rupiah::f($cfg['transfer_min']));
        }
        if ($jumlah > $cfg['transfer_maks']) {
            throw new ApiError(400, 'Maksimal sekali kirim ' . Rupiah::f($cfg['transfer_maks']));
        }

        Pin::verifikasi($s, $r->input('pin'), $r);
        Kartu::pastikanBerlaku($s);

        if ($txId && $dupe = Transaksi::where('tx_id', $txId)->first()) {
            return response()->json([
                'ok' => true, 'duplikat' => true, 'jumlah' => -$dupe->jumlah,
                'saldo' => Siswa::find($s->id)->saldo,
                'penerima' => ['nama' => $dupe->lawan_nama], 'waktu' => $dupe->created_at,
            ]);
        }

        $hari = Transfer::hariIni($s->id);
        if ($hari['n_kirim'] >= $cfg['transfer_maks_kali_harian']) {
            throw new ApiError(429, "Sudah {$hari['n_kirim']}x kirim hari ini (batas {$cfg['transfer_maks_kali_harian']}x/hari)");
        }
        if ($hari['kirim'] + $jumlah > $cfg['transfer_maks_harian']) {
            throw new ApiError(429, 'Melebihi batas kirim harian (hari ini sudah ' . Rupiah::f($hari['kirim']) .
                ', batas ' . Rupiah::f($cfg['transfer_maks_harian']) . ')');
        }
        $hariP = Transfer::hariIni($penerima->id);
        if ($hariP['terima'] + $jumlah > $cfg['transfer_terima_harian']) {
            throw new ApiError(429, "{$penerima->nama} sudah menerima " . Rupiah::f($hariP['terima']) .
                ' hari ini — batasnya ' . Rupiah::f($cfg['transfer_terima_harian']) . '/hari');
        }

        $maksPenerima = Pengaturan::untuk($penerima, $cfg)['max_saldo'];
        if ($penerima->saldo + $jumlah > $maksPenerima) {
            throw new ApiError(400, "Saldo {$penerima->nama} akan melebihi batas " .
                Rupiah::f($maksPenerima) . ' — kirim nominal lebih kecil');
        }

        $hasil = DB::transaction(function () use ($s, $penerima, $jumlah, $txId, $maksPenerima, $catatan) {
            // Sisi keluar: bersyarat, jadi dua permintaan bersamaan tidak bisa
            // mengirimkan saldo yang sama dua kali.
            $keluar = Siswa::where('id', $s->id)->where('saldo', '>=', $jumlah)->where('status', 'aktif')
                ->update(['saldo' => DB::raw("saldo - {$jumlah}")]);
            if ($keluar === 0) {
                $cek = Siswa::find($s->id);
                if ($cek->status !== 'aktif') {
                    throw new ApiError(403, 'Kartu sedang diblokir');
                }
                throw new ApiError(402, 'Saldo tidak cukup (saldo ' . Rupiah::f($cek->saldo) . ')');
            }
            // Sisi masuk: juga bersyarat pada batas saldo penerima, supaya
            // pemeriksaan di atas tidak bisa dilangkahi oleh dua transfer yang
            // tiba bersamaan.
            $masuk = Siswa::where('id', $penerima->id)->where('status', 'aktif')
                ->whereRaw('saldo + ? <= ?', [$jumlah, $maksPenerima])
                ->update(['saldo' => DB::raw("saldo + {$jumlah}")]);
            if ($masuk === 0) {
                throw new ApiError(409, "Saldo {$penerima->nama} sudah penuh — coba nominal lebih kecil");
            }

            $sBaru = Siswa::find($s->id);
            $pBaru = Siswa::find($penerima->id);
            $waktu = WaktuIso::sekarang();

            Transaksi::create([
                'tx_id' => $txId, 'tipe' => 'transfer_keluar', 'siswa_id' => $sBaru->id,
                'siswa_nama' => $sBaru->nama, 'kelas' => $sBaru->kelas,
                'jumlah' => -$jumlah, 'saldo_sesudah' => $sBaru->saldo, 'operator' => 'Kios Santri',
                'keterangan' => "Kirim ke {$pBaru->nama} (NIS {$pBaru->nis})" . ($catatan ? " — {$catatan}" : ''),
                'lawan_id' => $pBaru->id, 'lawan_nama' => $pBaru->nama, 'created_at' => $waktu,
            ]);
            // tx_id sisi penerima diberi akhiran: kunci UNIQUE tetap menolak
            // kiriman ulang, tanpa kedua baris berebut nilai yang sama.
            Transaksi::create([
                'tx_id' => $txId ? $txId . '-r' : null, 'tipe' => 'transfer_masuk', 'siswa_id' => $pBaru->id,
                'siswa_nama' => $pBaru->nama, 'kelas' => $pBaru->kelas,
                'jumlah' => $jumlah, 'saldo_sesudah' => $pBaru->saldo, 'operator' => 'Kios Santri',
                'keterangan' => "Terima dari {$sBaru->nama} (NIS {$sBaru->nis})" . ($catatan ? " — {$catatan}" : ''),
                'lawan_id' => $sBaru->id, 'lawan_nama' => $sBaru->nama, 'created_at' => $waktu,
            ]);

            return ['saldo' => $sBaru->saldo, 'waktu' => $waktu];
        });

        // Sisi pengirim bisa saja menyentuh ambang — lihat catatan yang sama
        // di donasi() di atas.
        if ($smart = SmartTopup::diam($s->id, 'transfer kios')) {
            $hasil['saldo'] = $smart['siswa']->saldo;
        }

        $jawab = [
            'ok' => true, 'jumlah' => $jumlah, 'saldo' => $hasil['saldo'], 'waktu' => $hasil['waktu'],
            'penerima' => Transfer::penerimaUntukKios($penerima),
            'sisa_harian' => max(0, $cfg['transfer_maks_harian'] - ($hari['kirim'] + $jumlah)),
        ];
        $this->tandaiSelesai($token, $sesi, $txId, $jawab);   // satu tap = satu transfer

        return response()->json($jawab);
    }

    /**
     * Bayar iuran ke PJ. Mesinnya sama persis dengan transfer() di atas —
     * dua baris buku besar, batas harian, kunci bersyarat pada UPDATE supaya
     * dua permintaan bersamaan tidak saling melangkahi — bedanya penerima
     * WAJIB berasal dari daftar PJ (Iuran::penerima, bukan ketik NIS) dan
     * deskripsi WAJIB diisi (identitas kelompok/keperluan iuran).
     */
    public function iuran(Request $r)
    {
        $this->harusAktif();
        Iuran::harusAktif();
        $txId = $r->input('tx_id') ? mb_substr((string) $r->input('tx_id'), 0, 80) : null;

        if ($jawab = $this->jawabUlang($r, $txId, 'iuran')) {
            return $jawab;
        }

        ['token' => $token, 'sesi' => $sesi, 'siswa' => $s] = SesiKios::ambil($r->input('token'));
        $g = Pengaturan::golongan($s->golongan);
        if (! $g?->transfer) {
            throw new ApiError(403, "Kartu {$g?->nama} tidak bisa membayar iuran");
        }

        $penerima = Iuran::penerima((int) $r->input('penerima_id'));
        if ($penerima->id === $s->id) {
            throw new ApiError(400, 'Tidak bisa membayar iuran ke kartu sendiri');
        }

        $jumlah = (int) floor((float) $r->input('jumlah'));
        $deskripsi = mb_substr(trim((string) $r->input('deskripsi', '')), 0, 80);
        $cfg = Pengaturan::sekolah();

        if ($jumlah <= 0) {
            throw new ApiError(400, 'Nominal tidak valid');
        }
        if ($jumlah < $cfg['iuran_min']) {
            throw new ApiError(400, 'Minimal bayar ' . Rupiah::f($cfg['iuran_min']));
        }
        if ($jumlah > $cfg['iuran_maks']) {
            throw new ApiError(400, 'Maksimal sekali bayar ' . Rupiah::f($cfg['iuran_maks']));
        }
        if (mb_strlen($deskripsi) < 3) {
            throw new ApiError(400, 'Deskripsi wajib diisi (minimal 3 huruf) — untuk apa iuran ini dibayarkan');
        }

        Pin::verifikasi($s, $r->input('pin'), $r);
        Kartu::pastikanBerlaku($s);

        if ($txId && $dupe = Transaksi::where('tx_id', $txId)->first()) {
            return response()->json([
                'ok' => true, 'duplikat' => true, 'jumlah' => -$dupe->jumlah,
                'saldo' => Siswa::find($s->id)->saldo,
                'penerima' => ['nama' => $dupe->lawan_nama], 'waktu' => $dupe->created_at,
            ]);
        }

        $hari = Iuran::hariIni($s->id);
        if ($hari['n_kirim'] >= $cfg['iuran_maks_kali_harian']) {
            throw new ApiError(429, "Sudah {$hari['n_kirim']}x bayar iuran hari ini (batas {$cfg['iuran_maks_kali_harian']}x/hari)");
        }
        if ($hari['kirim'] + $jumlah > $cfg['iuran_maks_harian']) {
            throw new ApiError(429, 'Melebihi batas bayar iuran harian (hari ini sudah ' . Rupiah::f($hari['kirim']) .
                ', batas ' . Rupiah::f($cfg['iuran_maks_harian']) . ')');
        }
        $hariP = Iuran::hariIni($penerima->id);
        if ($hariP['terima'] + $jumlah > $cfg['iuran_terima_harian']) {
            throw new ApiError(429, "{$penerima->nama} sudah menerima " . Rupiah::f($hariP['terima']) .
                ' iuran hari ini — batasnya ' . Rupiah::f($cfg['iuran_terima_harian']) . '/hari');
        }

        $maksPenerima = Pengaturan::untuk($penerima, $cfg)['max_saldo'];
        if ($penerima->saldo + $jumlah > $maksPenerima) {
            throw new ApiError(400, "Saldo {$penerima->nama} akan melebihi batas " .
                Rupiah::f($maksPenerima) . ' — bayar nominal lebih kecil, atau minta PJ mencairkan dulu');
        }

        $hasil = DB::transaction(function () use ($s, $penerima, $jumlah, $txId, $maksPenerima, $deskripsi) {
            $keluar = Siswa::where('id', $s->id)->where('saldo', '>=', $jumlah)->where('status', 'aktif')
                ->update(['saldo' => DB::raw("saldo - {$jumlah}")]);
            if ($keluar === 0) {
                $cek = Siswa::find($s->id);
                if ($cek->status !== 'aktif') {
                    throw new ApiError(403, 'Kartu sedang diblokir');
                }
                throw new ApiError(402, 'Saldo tidak cukup (saldo ' . Rupiah::f($cek->saldo) . ')');
            }
            $masuk = Siswa::where('id', $penerima->id)->where('status', 'aktif')
                ->whereRaw('saldo + ? <= ?', [$jumlah, $maksPenerima])
                ->update(['saldo' => DB::raw("saldo + {$jumlah}")]);
            if ($masuk === 0) {
                throw new ApiError(409, "Saldo {$penerima->nama} sudah penuh — coba nominal lebih kecil");
            }

            $sBaru = Siswa::find($s->id);
            $pBaru = Siswa::find($penerima->id);
            $waktu = WaktuIso::sekarang();

            Transaksi::create([
                'tx_id' => $txId, 'tipe' => 'iuran_keluar', 'siswa_id' => $sBaru->id,
                'siswa_nama' => $sBaru->nama, 'kelas' => $sBaru->kelas,
                'jumlah' => -$jumlah, 'saldo_sesudah' => $sBaru->saldo, 'operator' => 'Kios Santri',
                'keterangan' => "Bayar iuran ke {$pBaru->nama} — {$deskripsi}",
                'lawan_id' => $pBaru->id, 'lawan_nama' => $pBaru->nama, 'created_at' => $waktu,
            ]);
            Transaksi::create([
                'tx_id' => $txId ? $txId . '-r' : null, 'tipe' => 'iuran_masuk', 'siswa_id' => $pBaru->id,
                'siswa_nama' => $pBaru->nama, 'kelas' => $pBaru->kelas,
                'jumlah' => $jumlah, 'saldo_sesudah' => $pBaru->saldo, 'operator' => 'Kios Santri',
                'keterangan' => "Terima iuran dari {$sBaru->nama} — {$deskripsi}",
                'lawan_id' => $sBaru->id, 'lawan_nama' => $sBaru->nama, 'created_at' => $waktu,
            ]);

            return ['saldo' => $sBaru->saldo, 'waktu' => $waktu];
        });

        // Sisi pembayar bisa saja menyentuh ambang — lihat catatan yang sama
        // di donasi() di atas.
        if ($smart = SmartTopup::diam($s->id, 'iuran kios')) {
            $hasil['saldo'] = $smart['siswa']->saldo;
        }

        $jawab = [
            'ok' => true, 'jumlah' => $jumlah, 'saldo' => $hasil['saldo'], 'waktu' => $hasil['waktu'],
            'penerima' => Transfer::penerimaUntukKios($penerima),
            'deskripsi' => $deskripsi,
            'sisa_harian' => max(0, $cfg['iuran_maks_harian'] - ($hari['kirim'] + $jumlah)),
        ];
        $this->tandaiSelesai($token, $sesi, $txId, $jawab);   // satu tap = satu pembayaran iuran

        return response()->json($jawab);
    }

    public function gantiPin(Request $r)
    {
        $this->harusAktif();
        ['token' => $token, 'siswa' => $s] = SesiKios::ambil($r->input('token'));

        Pin::verifikasi($s, $r->input('pin_lama'), $r);
        $baru = Pin::periksaBaru($r->input('pin_baru'), $s);
        if (Pin::cocok($s, $baru)) {
            throw new ApiError(400, 'PIN baru sama dengan PIN lama');
        }
        Pin::ganti($s, $baru);
        SesiKios::tutup($token);

        return response()->json(['ok' => true]);
    }

    public function riwayat(Request $r)
    {
        $this->harusAktif();
        ['token' => $token, 'sesi' => $sesi, 'siswa' => $s] = SesiKios::ambil($r->input('token'));

        // PIN diminta SEKALI per tap. Riwayat lengkap jauh lebih peka daripada
        // saldo (pola jajan, siapa yang mengisi saldo) sementara layar kios
        // terbaca antrean di belakang — jadi kartu saja tidak cukup. Setelah
        // terbuka, ganti periode dan halaman berikutnya tidak meminta PIN lagi:
        // kalau tiap halaman meminta PIN, santri mengetik PIN berkali-kali di
        // depan orang banyak.
        if (! $sesi['riwayat']) {
            Pin::verifikasi($s, $r->input('pin'), $r);
            $sesi['riwayat'] = true;
            SesiKios::simpan($token, $sesi);
        }

        $periode = in_array($r->input('periode'), ['hari', '7', '30', 'bulan'], true) ? $r->input('periode') : 'hari';
        [$dari, $sampai] = $this->rentang($periode, $r->input('bulan'));
        $page = min(self::RIWAYAT_MAKS_HAL, max(1, (int) $r->input('page', 1)));

        $q = Transaksi::where('siswa_id', $s->id)->where('created_at', '>=', $dari);
        if ($sampai) {
            $q->where('created_at', '<', $sampai);
        }

        $agg = (clone $q)->selectRaw('COUNT(*) AS n')
            ->selectRaw('COALESCE(SUM(CASE WHEN jumlah > 0 THEN jumlah END),0) AS total_masuk')
            ->selectRaw('COALESCE(SUM(CASE WHEN jumlah < 0 THEN -jumlah END),0) AS total_keluar')
            ->first();

        $rows = (clone $q)->select('tipe', 'tenant_nama', 'jumlah', 'saldo_sesudah', 'keterangan', 'created_at')
            ->orderByDesc('created_at')->orderByDesc('id')
            ->limit(self::RIWAYAT_LIMIT)->offset(($page - 1) * self::RIWAYAT_LIMIT)->get();

        // Daftar bulan yang benar-benar punya transaksi — supaya santri tidak
        // memilih bulan kosong. Dipotong 12 bulan terakhir agar muat di layar.
        $bulanAda = Transaksi::where('siswa_id', $s->id)
            ->selectRaw('DISTINCT ' . SqlWaktu::periode('bulanan') . ' AS bulan')
            ->orderByDesc('bulan')->limit(12)->pluck('bulan');

        return response()->json([
            'ok' => true, 'periode' => $periode,
            'bulan' => $periode === 'bulan' ? $r->input('bulan') : null,
            'transaksi' => $rows, 'total' => (int) $agg->n,
            'total_masuk' => (int) $agg->total_masuk, 'total_keluar' => (int) $agg->total_keluar,
            'page' => $page, 'limit' => self::RIWAYAT_LIMIT,
            'ada_lagi' => $page * self::RIWAYAT_LIMIT < (int) $agg->n && $page < self::RIWAYAT_MAKS_HAL,
            'bulan_tersedia' => $bulanAda,
            'saldo' => $s->saldo,
            'sesi_detik' => SesiKios::detikSesi(),
        ]);
    }

    /**
     * Buka kunci mode layar penuh.
     *
     * Sandinya diperiksa DI SERVER, bukan di kios.js: halaman /kios boleh dibuka
     * siapa saja tanpa login, jadi sandi yang ditanam di berkas JS sama saja
     * dengan ditempel di dinding. Yang dikirim balik hanya "cocok / tidak".
     */
    public function bukaLayar(Request $r)
    {
        // Rem per alamat IP dan ketat, berbeda dari rem PIN santri yang sengaja
        // dilonggarkan: sandi ini dipakai staf dari satu perangkat, jadi
        // mengunci per perangkat tidak pernah mengganggu antrean santri.
        $kunci = 'kioslayar:' . Rem::ip($r);
        Rem::periksa($kunci);

        $hash = KiosConfig::ambil('sandi_hash');
        if (! $hash) {
            throw new ApiError(500, 'Sandi kios belum tersiapkan');
        }
        $kiriman = is_string($r->input('sandi')) ? $r->input('sandi') : '';   // bukan teks = pasti salah
        if (! Hash::check($kiriman, $hash)) {
            Rem::catatGagal($kunci);
            throw new ApiError(401, 'Sandi salah. Setelah ' . Rem::MAKS_GAGAL . ' kali salah, perangkat ini terkunci 15 menit.');
        }
        Rem::lupakan($kunci);

        return response()->json(['ok' => true]);
    }

    public function sandiLayar(Request $r)
    {
        $this->wajibPeran($r, 'superadmin');
        $s = is_string($r->input('sandi_baru')) ? $r->input('sandi_baru') : '';
        if (strlen($s) < 6) {
            throw new ApiError(400, 'Sandi kios minimal 6 karakter');
        }
        if (strlen($s) > 64) {
            throw new ApiError(400, 'Sandi kios maksimal 64 karakter');
        }
        DB::transaction(function () use ($s) {
            KiosConfig::simpan('sandi_hash', Hash::make($s));
            KiosConfig::simpan('sandi_bawaan', $s === self::SANDI_BAWAAN ? '1' : '0');
        });

        return response()->json(['ok' => true, 'bawaan' => $s === self::SANDI_BAWAAN]);
    }

    public function selesai(Request $r)
    {
        SesiKios::tutup($r->input('token'));

        return response()->json(['ok' => true]);
    }

    // ------------------------------------------------------------ penolong

    private function harusAktif(): void
    {
        if ((Pengaturan::sekolah()['kios_aktif'] ?? 0) !== 1) {
            throw new ApiError(503, 'Kios santri sedang ditutup admin');
        }
    }

    private function kuotaTap(Request $r): void
    {
        $kunci = 'kiostap:' . Rem::ip($r);
        if (RateLimiter::tooManyAttempts($kunci, self::TAP_PER_MENIT)) {
            throw new ApiError(429, 'Terlalu banyak tap dari perangkat ini — tunggu sebentar');
        }
        RateLimiter::hit($kunci, 60);
    }

    /**
     * Kalau jaringan kios putus tepat setelah transaksi tercatat, kiriman ulang
     * dengan tx_id yang sama harus menerima jawaban yang SAMA — bukan "sesi
     * berakhir" yang membuat santri mengira gagal lalu mengulang.
     */
    private function jawabUlang(Request $r, ?string $txId, string $apa)
    {
        $lama = SesiKios::mentah($r->input('token'));
        if (! $lama || ! $lama['selesai']) {
            return null;
        }
        if ($txId && ($lama['hasil']['tx_id'] ?? null) === $txId) {
            return response()->json(array_merge($lama['hasil']['jawab'], ['duplikat' => true]));
        }

        throw new ApiError(409, "Satu tap hanya untuk satu {$apa} — tempelkan kartu lagi");
    }

    private function tandaiSelesai(string $token, array $sesi, ?string $txId, array $jawab): void
    {
        $sesi['selesai'] = true;
        $sesi['hasil'] = ['tx_id' => $txId, 'jawab' => $jawab];
        SesiKios::simpan($token, $sesi);
    }

    /**
     * Rentang periode riwayat. Batas bulan dihitung pada 00:00 WIB, bukan UTC:
     * transaksi sore hari tanggal 31 tersimpan sebagai UTC hari yang sama tapi
     * jam mundur 7 — memakai batas UTC akan membuangnya ke bulan berikutnya.
     */
    private function rentang(string $periode, $bulan): array
    {
        if ($periode === 'bulan') {
            if (! preg_match('/^(\d{4})-(\d{2})$/', (string) $bulan, $m)) {
                throw new ApiError(400, 'Bulan tidak dikenali');
            }
            [$th, $bl] = [(int) $m[1], (int) $m[2]];
            if ($bl < 1 || $bl > 12 || $th < 2000 || $th > 2200) {
                throw new ApiError(400, 'Bulan tidak dikenali');
            }
            $wib = new \DateTimeZone('Asia/Jakarta');
            $utc = new \DateTimeZone('UTC');
            $mulai = (new \DateTime("{$m[1]}-{$m[2]}-01 00:00:00", $wib))->setTimezone($utc);
            $akhir = (new \DateTime("{$m[1]}-{$m[2]}-01 00:00:00", $wib))->modify('+1 month')->setTimezone($utc);

            return [$mulai->format('Y-m-d\TH:i:s.v\Z'), $akhir->format('Y-m-d\TH:i:s.v\Z')];
        }
        if ($periode === '7') {
            return [WaktuIso::awalHari(6), null];
        }
        if ($periode === '30') {
            return [WaktuIso::awalHari(29), null];
        }

        return [WaktuIso::awalHariIni(), null];
    }

    /** Bekal awal layar kios setelah tap: identitas, jatah, dan menu yang benar-benar bisa dipakai. */
    private function bekal(Siswa $s, string $token): array
    {
        // untuk($s), bukan sekolah() apa adanya: tidak ada kunci di sini yang
        // termasuk Pengaturan::BATAS_GOLONGAN hari ini (jadi perilakunya sama
        // persis), tapi memakai sekolah() langsung adalah jebakan yang sudah
        // dua kali jadi bug nyata di tempat lain — lebih aman dibiasakan
        // untuk($s) di sini juga sebelum kunci baru ditambahkan nanti.
        $cfg = Pengaturan::untuk($s);
        $sudah = DonasiSetor::hariIni($s->id);
        $g = Pengaturan::golongan($s->golongan);
        $hariT = Transfer::hariIni($s->id);

        // Menu transfer hanya muncul kalau memang bisa dipakai SEKARANG:
        // dimatikan admin, golongan yang tidak diizinkan, jatah harian habis,
        // atau saldo kurang dari nominal terkecil — semuanya berakhir sebagai
        // tombol yang TIDAK ADA, bukan tombol yang menolak sesudah santri
        // mengetik NIS temannya di depan antrean.
        $sisaKirim = max(0, $cfg['transfer_maks_harian'] - $hariT['kirim']);

        // Sama seperti transfer: daftar PJ dan status "bisa" dikirim di sini,
        // bukan lewat endpoint terpisah — kalau daftarnya kosong (belum ada PJ
        // ditandai admin) tombolnya memang harus hilang, bukan menampilkan
        // layar pilihan yang kosong.
        $daftarPJ = Iuran::daftarPJ();
        $hariI = Iuran::hariIni($s->id);
        $sisaIuranHarian = max(0, $cfg['iuran_maks_harian'] - $hariI['kirim']);

        $jenisTabungan = TabunganController::daftar(false);
        $sudahTabungan = Tabungan::hariIni($s->id);
        $sisaTabunganHarian = max(0, ($cfg['tabungan_maks_harian'] ?? 0) - $sudahTabungan);

        return [
            'token' => $token,
            'siswa' => ['nama' => $s->nama, 'kelas' => $s->kelas, 'saldo' => $s->saldo,
                'saldo_titipan' => $s->saldo_titipan, 'status' => $s->status, 'foto' => $s->foto],
            'golongan' => ['kode' => $g?->kode, 'nama' => $g?->nama, 'jenis' => $g?->jenis],
            'pin_bawaan' => Pin::tersimpan($s->id) === null,   // true = masih memakai NIS
            'pin_siap' => Pin::siap($s),
            // Sisa jatah jajan hari ini (bukan donasi/transfer/iuran/tabungan
            // di atas) — supaya santri tahu LEBIH DULU kenapa belanjanya bisa
            // ditolak kasir, bukan baru tahu sesudahnya.
            'sisa_jajan' => Rekap::sisaHarian($s),
            'program' => DonasiController::daftar(false),
            'donasi_hari_ini' => $sudah,
            'sisa_donasi' => max(0, $cfg['donasi_maks_harian'] - $sudah),
            'maks_donasi_harian' => $cfg['donasi_maks_harian'],
            'transfer' => [
                'bisa' => $cfg['transfer_aktif'] === 1 && (bool) $g?->transfer
                    && $hariT['n_kirim'] < $cfg['transfer_maks_kali_harian']
                    && $sisaKirim >= $cfg['transfer_min'] && $s->saldo >= $cfg['transfer_min'],
                'min' => $cfg['transfer_min'],
                'maks' => min($cfg['transfer_maks'], $sisaKirim, $s->saldo),
                'sisa_harian' => $sisaKirim,
                'sisa_kali' => max(0, $cfg['transfer_maks_kali_harian'] - $hariT['n_kirim']),
            ],
            'iuran' => [
                'bisa' => $cfg['iuran_aktif'] === 1 && (bool) $g?->transfer && count($daftarPJ) > 0
                    && $hariI['n_kirim'] < $cfg['iuran_maks_kali_harian']
                    && $sisaIuranHarian >= $cfg['iuran_min'] && $s->saldo >= $cfg['iuran_min'],
                'daftar' => $daftarPJ,
                'min' => $cfg['iuran_min'],
                'maks' => min($cfg['iuran_maks'], $sisaIuranHarian, $s->saldo),
                'sisa_harian' => $sisaIuranHarian,
                'sisa_kali' => max(0, $cfg['iuran_maks_kali_harian'] - $hariI['n_kirim']),
            ],
            'tabungan_jenis' => $jenisTabungan,
            'tabungan_saldo' => Tabungan::daftarSaldo($s->id),
            'tabungan' => [
                'bisa' => count($jenisTabungan) > 0
                    && $sisaTabunganHarian >= ($cfg['tabungan_min'] ?? 0)
                    && $s->saldo >= ($cfg['tabungan_min'] ?? 0),
                'min' => $cfg['tabungan_min'] ?? 0,
                'maks' => min($cfg['tabungan_maks_harian'] ?? 0, $sisaTabunganHarian, $s->saldo),
                'sisa_harian' => $sisaTabunganHarian,
            ],
            'sesi_detik' => SesiKios::detikSesi(),
            'transaksi' => Transaksi::where('siswa_id', $s->id)
                ->select('tipe', 'tenant_nama', 'jumlah', 'saldo_sesudah', 'keterangan', 'created_at')
                ->orderByDesc('created_at')->orderByDesc('id')->limit(5)->get(),
        ];
    }
}
