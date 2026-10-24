<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\ApiError;
use App\Models\DonasiNominal;
use App\Models\DonasiProgram;
use App\Models\Siswa;
use App\Models\TabunganJenis;
use App\Models\Transaksi;
use App\Services\DonasiSetor;
use App\Services\Pengaturan;
use App\Services\Pin;
use App\Services\Rupiah;
use App\Services\SmartTopup;
use App\Services\Tabungan;
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

        return response()->json([
            'tabungan_jenis' => TabunganController::daftar(false),
            'tabungan_saldo' => Tabungan::daftarSaldo($s->id),
            'tabungan_min' => $cfg['tabungan_min'] ?? 0,
            'sisa_tabungan_harian' => max(0, ($cfg['tabungan_maks_harian'] ?? 0) - Tabungan::hariIni($s->id)),
            'donasi_program' => DonasiController::daftar(false),
            'sisa_donasi' => max(0, $cfg['donasi_maks_harian'] - DonasiSetor::hariIni($s->id)),
            'saldo' => $s->saldo,
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

        $jenis = TabunganJenis::where('id', (int) $r->input('jenis_id'))->where('aktif', true)->first();
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
