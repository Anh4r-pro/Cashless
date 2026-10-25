<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\ApiError;
use App\Models\DonasiNominal;
use App\Models\DonasiProgram;
use App\Models\Siswa;
use App\Models\TabunganJenis;
use App\Models\Transaksi;
use App\Services\DonasiSetor;
use App\Services\Kartu;
use App\Services\Pengaturan;
use App\Services\Pin;
use App\Services\Rupiah;
use App\Services\SmartTopup;
use App\Services\Tabungan;
use App\Services\Transfer;
use App\Support\WaktuIso;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Portal pegawai (/ortu, pintu "pegawai"): tabungan, donasi, dan ganti PIN
 * dari perangkat sendiri — supaya staf tidak perlu antre di kios bersama
 * santri. Dipisah dari OrtuController yang SENGAJA hanya baca (lihat
 * docblock-nya); kontrak kelas ini berbeda total (menulis saldo), jadi
 * dipisah supaya invarian "OrtuController = baca saja" tetap benar apa adanya.
 *
 * Logika pemotongan saldo yang sebenarnya TIDAK diduplikasi dari KiosController
 * — keduanya memanggil service yang sama (DonasiSetor::setor, Tabungan::setor,
 * Pin::ganti) supaya kios dan portal ini selalu sepakat soal batas & pencatatan
 * buku besar. Yang berbeda hanya cara identitasnya didapat: KiosController
 * lewat SesiKios (tap kartu), di sini lewat sesi login /ortu (cookie osid).
 */
class OrtuPegawaiController extends Controller
{
    /** Sesi /ortu yang sedang aktif, dibatasi HANYA untuk pintu pegawai. */
    private function pegawai(Request $r): Siswa
    {
        $s = $r->attributes->get('ortu');
        if (! $s) {
            throw new ApiError(401, 'Sesi sudah berakhir — masuk lagi');
        }
        if (Pengaturan::golongan($s->golongan)?->jenis === 'siswa') {
            throw new ApiError(403, 'Fitur ini khusus pegawai — wali tidak bisa memakainya atas nama anak.');
        }

        return $s;
    }

    /** Jawaban untuk tx_id yang sudah tercatat (double-klik / koneksi putus-sambung). */
    private function dupe(?string $txId): ?Transaksi
    {
        return $txId ? Transaksi::where('tx_id', $txId)->first() : null;
    }

    /** Daftar jenis tabungan & program donasi yang bisa dipilih, plus batas yang berlaku. */
    public function opsi(Request $r)
    {
        $s = $this->pegawai($r);
        $cfg = Pengaturan::untuk($s);
        $g = Pengaturan::golongan($s->golongan);
        $hariT = Transfer::hariIni($s->id);

        return response()->json([
            // 'pegawai' langsung, bukan dihitung dari $g->jenis: pegawai($r)
            // sudah memastikan golongan ini bukan 'siswa'.
            'tabungan_jenis' => TabunganController::daftar(false, 'pegawai'),
            'tabungan_saldo' => Tabungan::daftarSaldo($s->id),
            'tabungan_min' => $cfg['tabungan_min'] ?? 0,
            'sisa_tabungan_harian' => max(0, ($cfg['tabungan_maks_harian'] ?? 0) - Tabungan::hariIni($s->id)),
            'donasi_program' => DonasiController::daftar(false),
            'sisa_donasi' => max(0, $cfg['donasi_maks_harian'] - DonasiSetor::hariIni($s->id)),
            // Golongan yang tidak diizinkan mengirim (lihat $g->transfer) membuat
            // tombol Transfer tidak pernah ditampilkan, bukan tombol yang ada
            // tapi selalu ditolak.
            'transfer_bisa' => (bool) $g?->transfer,
            'transfer_min' => $cfg['transfer_min'],
            'transfer_maks' => min($cfg['transfer_maks'], max(0, $cfg['transfer_maks_harian'] - $hariT['kirim']), $s->saldo),
            'sisa_transfer_harian' => max(0, $cfg['transfer_maks_harian'] - $hariT['kirim']),
            'sisa_transfer_kali' => max(0, $cfg['transfer_maks_kali_harian'] - $hariT['n_kirim']),
            'saldo' => $s->saldo,
        ]);
    }

    /** Cari calon penerima transfer lewat NIS — langkah konfirmasi sebelum layar nominal. */
    public function cariPenerima(Request $r)
    {
        $s = $this->pegawai($r);
        Transfer::harusAktif();
        $g = Pengaturan::golongan($s->golongan);
        if (! $g?->transfer) {
            throw new ApiError(403, "Kartu {$g?->nama} tidak bisa mengirim saldo");
        }

        $p = Transfer::cariPenerima($r->input('nis'), $s);
        Transfer::wajibSamaGenderAtauKeluarga($s, $p);
        $cfg = Pengaturan::untuk($s);
        $hari = Transfer::hariIni($s->id);

        return response()->json([
            'ok' => true,
            'penerima' => Transfer::penerimaUntukKios($p),
            'min' => $cfg['transfer_min'],
            'maks' => min($cfg['transfer_maks'], max(0, $cfg['transfer_maks_harian'] - $hari['kirim']), $s->saldo),
            'sisa_harian' => max(0, $cfg['transfer_maks_harian'] - $hari['kirim']),
            'sisa_kali' => max(0, $cfg['transfer_maks_kali_harian'] - $hari['n_kirim']),
            'saldo' => $s->saldo,
        ]);
    }

    public function transfer(Request $r)
    {
        $s = $this->pegawai($r);
        Transfer::harusAktif();
        $g = Pengaturan::golongan($s->golongan);
        if (! $g?->transfer) {
            throw new ApiError(403, "Kartu {$g?->nama} tidak bisa mengirim saldo");
        }
        $txId = $r->input('tx_id') ? mb_substr((string) $r->input('tx_id'), 0, 80) : null;
        if ($dupe = $this->dupe($txId)) {
            return response()->json(['ok' => true, 'duplikat' => true, 'jumlah' => -$dupe->jumlah,
                'saldo' => Siswa::find($s->id)->saldo,
                'penerima' => ['nama' => $dupe->lawan_nama], 'waktu' => $dupe->created_at]);
        }

        $penerima = Transfer::cariPenerima($r->input('nis'), $s);
        // Diperiksa ulang, bukan percaya hasil cariPenerima() tadi — portal
        // ini bisa saja mengirim 'nis' baru tanpa lewat cariPenerima() lagi,
        // pola yang sama dipakai KiosController::transfer().
        Transfer::wajibSamaGenderAtauKeluarga($s, $penerima);
        $jumlah = (int) floor((float) $r->input('jumlah'));
        $catatan = mb_substr(trim((string) $r->input('catatan', '')), 0, 60);
        $cfg = Pengaturan::untuk($s);

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
                'tx_id' => $txId, 'tipe' => 'transfer_keluar', 'siswa_id' => $sBaru->id,
                'siswa_nama' => $sBaru->nama, 'kelas' => $sBaru->kelas,
                'jumlah' => -$jumlah, 'saldo_sesudah' => $sBaru->saldo, 'operator' => 'Portal Pegawai',
                'keterangan' => "Kirim ke {$pBaru->nama} (NIS {$pBaru->nis})" . ($catatan ? " — {$catatan}" : ''),
                'lawan_id' => $pBaru->id, 'lawan_nama' => $pBaru->nama, 'created_at' => $waktu,
            ]);
            Transaksi::create([
                'tx_id' => $txId ? $txId . '-r' : null, 'tipe' => 'transfer_masuk', 'siswa_id' => $pBaru->id,
                'siswa_nama' => $pBaru->nama, 'kelas' => $pBaru->kelas,
                'jumlah' => $jumlah, 'saldo_sesudah' => $pBaru->saldo, 'operator' => 'Portal Pegawai',
                'keterangan' => "Terima dari {$sBaru->nama} (NIS {$sBaru->nis})" . ($catatan ? " — {$catatan}" : ''),
                'lawan_id' => $sBaru->id, 'lawan_nama' => $sBaru->nama, 'created_at' => $waktu,
            ]);

            return ['saldo' => $sBaru->saldo, 'waktu' => $waktu];
        });

        if ($smart = SmartTopup::diam($s->id, 'transfer portal pegawai')) {
            $hasil['saldo'] = $smart['siswa']->saldo;
        }

        return response()->json([
            'ok' => true, 'jumlah' => $jumlah, 'saldo' => $hasil['saldo'], 'waktu' => $hasil['waktu'],
            'penerima' => Transfer::penerimaUntukKios($penerima),
            'sisa_harian' => max(0, $cfg['transfer_maks_harian'] - ($hari['kirim'] + $jumlah)),
        ]);
    }

    public function tabungan(Request $r)
    {
        $s = $this->pegawai($r);
        $txId = $r->input('tx_id') ? mb_substr((string) $r->input('tx_id'), 0, 80) : null;
        if ($dupe = $this->dupe($txId)) {
            return response()->json(['ok' => true, 'duplikat' => true,
                'jumlah' => -$dupe->jumlah, 'saldo' => Siswa::find($s->id)->saldo, 'waktu' => $dupe->created_at]);
        }

        // Diperiksa ulang, bukan percaya daftar yang sudah ditampilkan opsi()
        // tadi — jenis khusus santri tidak boleh bisa diisi lewat sini hanya
        // dengan mengirim jenis_id-nya langsung.
        $jenis = TabunganJenis::where('id', (int) $r->input('jenis_id'))->where('aktif', true)
            ->whereIn('untuk', ['semua', 'pegawai'])->first();
        if (! $jenis) {
            throw new ApiError(404, 'Jenis tabungan tidak tersedia');
        }
        $cfg = Pengaturan::untuk($s);
        $jumlah = (int) floor((float) $r->input('jumlah'));
        if ($jumlah < ($cfg['tabungan_min'] ?? 0)) {
            throw new ApiError(400, 'Nominal menabung minimal ' . Rupiah::f($cfg['tabungan_min'] ?? 0));
        }

        Pin::verifikasi($s, $r->input('pin'), $r);
        if ($s->status !== 'aktif') {
            throw new ApiError(403, 'Kartu sedang diblokir — hubungi admin kantin');
        }

        $sudah = Tabungan::hariIni($s->id);
        if ($sudah + $jumlah > ($cfg['tabungan_maks_harian'] ?? 0)) {
            throw new ApiError(429, 'Melebihi batas menabung harian (hari ini sudah ' . Rupiah::f($sudah) .
                ', batas ' . Rupiah::f($cfg['tabungan_maks_harian'] ?? 0) . ')');
        }

        $hasil = DB::transaction(fn () => Tabungan::setor($s, $jenis, $jumlah, 'kartu', 'Portal Pegawai',
            'Tabungan ' . $jenis->nama, $txId));

        if ($smart = SmartTopup::diam($s->id, 'tabungan portal pegawai')) {
            $hasil['siswa'] = $smart['siswa'];
        }

        return response()->json([
            'ok' => true, 'jenis' => $jenis->nama, 'jumlah' => $jumlah,
            'saldo' => $hasil['siswa']->saldo, 'saldo_tabungan' => $hasil['saldo_tabungan']->saldo,
            'sisa_tabungan_harian' => max(0, ($cfg['tabungan_maks_harian'] ?? 0) - ($sudah + $jumlah)),
        ]);
    }

    public function donasi(Request $r)
    {
        $s = $this->pegawai($r);
        $txId = $r->input('tx_id') ? mb_substr((string) $r->input('tx_id'), 0, 80) : null;
        if ($dupe = $this->dupe($txId)) {
            return response()->json(['ok' => true, 'duplikat' => true,
                'program' => DonasiProgram::find($dupe->donasi_id)?->nama,
                'jumlah' => -$dupe->jumlah, 'saldo' => Siswa::find($s->id)->saldo, 'waktu' => $dupe->created_at]);
        }

        $p = DonasiProgram::where('id', (int) $r->input('program_id'))->where('aktif', true)->first();
        if (! $p) {
            throw new ApiError(404, 'Jenis donasi tidak tersedia');
        }
        $jumlah = (int) floor((float) $r->input('jumlah'));
        if ($jumlah <= 0) {
            throw new ApiError(400, 'Nominal donasi tidak valid');
        }
        if (! DonasiNominal::where('program_id', $p->id)->where('jumlah', $jumlah)->exists()) {
            throw new ApiError(400, 'Nominal donasi tidak sesuai pilihan yang disediakan sekolah');
        }

        Pin::verifikasi($s, $r->input('pin'), $r);
        if ($s->status !== 'aktif') {
            throw new ApiError(403, 'Kartu sedang diblokir — hubungi admin kantin');
        }

        $cfg = Pengaturan::untuk($s);
        $sudah = DonasiSetor::hariIni($s->id);
        if ($sudah + $jumlah > $cfg['donasi_maks_harian']) {
            throw new ApiError(429, 'Melebihi batas donasi harian (hari ini sudah ' . Rupiah::f($sudah) .
                ', batas ' . Rupiah::f($cfg['donasi_maks_harian']) . ')');
        }

        $hasil = DB::transaction(fn () => DonasiSetor::setor($s, $p, $jumlah, 'Portal Pegawai', $txId));

        if ($smart = SmartTopup::diam($s->id, 'donasi portal pegawai')) {
            $hasil['saldo'] = $smart['siswa']->saldo;
        }

        return response()->json([
            'ok' => true, 'program' => $p->nama, 'jumlah' => $jumlah,
            'saldo' => $hasil['saldo'], 'waktu' => $hasil['waktu'],
            'sisa_donasi' => max(0, $cfg['donasi_maks_harian'] - ($sudah + $jumlah)),
        ]);
    }

    public function gantiPin(Request $r)
    {
        $s = $this->pegawai($r);
        Pin::verifikasi($s, $r->input('pin_lama'), $r);
        $baru = Pin::periksaBaru($r->input('pin_baru'), $s);
        if (Pin::cocok($s, $baru)) {
            throw new ApiError(400, 'PIN baru sama dengan PIN lama');
        }
        Pin::ganti($s, $baru);

        return response()->json(['ok' => true]);
    }
}
