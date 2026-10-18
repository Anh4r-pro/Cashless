<?php

namespace App\Services;

use App\Exceptions\ApiError;
use App\Models\Siswa;
use App\Models\Transaksi;
use App\Support\WaktuIso;

/**
 * Transfer saldo antar teman lewat kios.
 *
 * Alamatnya NIS dan bukan tap kartu kedua, karena kartu penerima jelas tidak
 * ada di kios saat itu — justru itu gunanya fitur ini.
 *
 * Yang dijaga, dan alasannya:
 *   - Uang tidak boleh terbit atau lenyap. Dua sisi mutasi ditulis dalam SATU
 *     transaksi basis data. Neraca kas tidak perlu diubah sama sekali: transfer
 *     bukan uang masuk maupun keluar, dan jumlah kedua barisnya nol.
 *   - Kirim ke diri sendiri ditolak. Bukan karena berbahaya, tetapi karena itu
 *     tanda salah ketik NIS — dan diam-diam "berhasil" akan menyembunyikannya.
 *   - Batas TERIMA per hari ada supaya satu kartu tidak bisa dipakai menampung
 *     setoran dari banyak anak. Ini fitur yang paling mudah dipakai memeras uang
 *     jajan, dan batas kirim saja hanya membatasi korban satu per satu.
 */
class Transfer
{
    public static function harusAktif(): void
    {
        if ((Pengaturan::sekolah()['transfer_aktif'] ?? 0) !== 1) {
            throw new ApiError(503, 'Transfer antar teman sedang dimatikan admin');
        }
    }

    /** Rekap kiriman & terimaan seseorang hari ini (untuk batas harian). */
    public static function hariIni(int $siswaId): array
    {
        $r = Transaksi::where('siswa_id', $siswaId)
            ->whereIn('tipe', ['transfer_keluar', 'transfer_masuk'])
            ->where('created_at', '>=', WaktuIso::awalHariIni())
            ->selectRaw("COALESCE(SUM(CASE WHEN tipe='transfer_keluar' THEN -jumlah END),0) AS kirim")
            ->selectRaw("COALESCE(SUM(CASE WHEN tipe='transfer_keluar' THEN 1 ELSE 0 END),0) AS n_kirim")
            ->selectRaw("COALESCE(SUM(CASE WHEN tipe='transfer_masuk' THEN jumlah END),0) AS terima")
            ->first();

        return ['kirim' => (int) $r->kirim, 'n_kirim' => (int) $r->n_kirim, 'terima' => (int) $r->terima];
    }

    private const LABEL_GENDER = ['L' => 'putra', 'P' => 'putri'];

    /**
     * Transfer hanya boleh sesama gender, kecuali sesama keluarga.
     *
     * Dipanggil DUA KALI pada satu transfer — sekali saat penerima dicari
     * (supaya layar nominal tidak terbuka untuk transfer yang pasti ditolak),
     * sekali lagi saat benar-benar dieksekusi (jangan percaya langkah
     * sebelumnya, pola yang sama dipakai di seluruh berkas ini).
     *
     * Berhenti diam-diam (tidak memeriksa apa pun) dalam tiga keadaan yang
     * SENGAJA dibiarkan lewat:
     *   - Sakelarnya mati (`transfer_gender_aktif` = 0). Sampai admin selesai
     *     menetapkan gender massal per kelas dan yakin datanya terisi,
     *     perilaku transfer harus persis seperti sebelum fitur ini ada.
     *   - Salah satu pihak bukan santri (golongan != 'siswa'). SDM/tamu/VIP
     *     tidak diasramakan terpisah gender, aturan ini tidak relevan buat
     *     mereka.
     *
     * Begitu sakelarnya menyala dan kedua pihak santri, DATA YANG BOLONG
     * DITOLAK, bukan diloloskan diam-diam — gender NULL pada salah satu pihak
     * berarti admin belum menuntaskan pendataan, dan itu harus kelihatan.
     */
    public static function wajibSamaGenderAtauKeluarga(Siswa $pengirim, Siswa $penerima): void
    {
        if ((Pengaturan::sekolah()['transfer_gender_aktif'] ?? 0) !== 1) {
            return;
        }
        if ($pengirim->golongan !== 'siswa' || $penerima->golongan !== 'siswa') {
            return;
        }

        if ($pengirim->jenis_kelamin === null || $penerima->jenis_kelamin === null) {
            throw new ApiError(403, 'Data gender salah satu kartu belum diisi admin — ' .
                'transfer ditahan sampai dilengkapi. Hubungi admin.');
        }
        if ($pengirim->jenis_kelamin === $penerima->jenis_kelamin) {
            return;
        }

        // Lintas gender tetap boleh untuk sesama keluarga — kode harus terisi
        // DI KEDUA BELAH PIHAK dan sama persis. Kosong di salah satu sisi
        // bukan keluarga, sekalipun kosong-kosong (jangan sampai dua santri
        // yang sama-sama belum diberi kode malah dianggap satu keluarga).
        $kodeP = trim((string) $pengirim->kode_keluarga);
        $kodeT = trim((string) $penerima->kode_keluarga);
        if ($kodeP !== '' && $kodeP === $kodeT) {
            return;
        }

        $labelP = self::LABEL_GENDER[$pengirim->jenis_kelamin] ?? $pengirim->jenis_kelamin;
        $labelT = self::LABEL_GENDER[$penerima->jenis_kelamin] ?? $penerima->jenis_kelamin;
        throw new ApiError(403, "Kamu ({$labelP}) tidak bisa transfer ke {$penerima->nama} ({$labelT}) — " .
            'kirim hanya boleh sesama gender, kecuali ke keluarga. Kalau ini keluargamu, minta admin menandainya.');
    }

    /** Penerima menurut NIS. Dipakai layar konfirmasi maupun eksekusi transfernya. */
    public static function cariPenerima($nisMentah, Siswa $pengirim): Siswa
    {
        $nis = trim((string) $nisMentah);
        if (! preg_match('/^\d{1,20}$/', $nis)) {
            throw new ApiError(400, 'NIS penerima hanya boleh angka');
        }
        $rows = Siswa::where('nis', $nis)->where('nis', '!=', '')->get();
        if ($rows->isEmpty()) {
            throw new ApiError(404, "NIS {$nis} tidak terdaftar");
        }
        // Basis data lama boleh saja punya NIS kembar. Menebak salah satunya
        // berarti mengirim uang ke orang yang salah, jadi lebih baik berhenti
        // dan minta admin membereskannya.
        if ($rows->count() > 1) {
            throw new ApiError(409, "NIS {$nis} terdaftar pada lebih dari satu orang — lapor admin");
        }
        $p = $rows->first();
        if ($p->id === $pengirim->id) {
            throw new ApiError(400, 'Itu NIS kamu sendiri — masukkan NIS temanmu');
        }
        if ($p->status !== 'aktif') {
            throw new ApiError(403, "Kartu {$p->nama} sedang diblokir");
        }
        if (Kartu::kedaluwarsa($p)) {
            throw new ApiError(403, "Masa berlaku kartu {$p->nama} sudah habis");
        }

        return $p;
    }

    /** Yang boleh ditampilkan tentang penerima di layar kios yang terbaca antrean. */
    public static function penerimaUntukKios(Siswa $p): array
    {
        $g = Pengaturan::golongan($p->golongan);

        return ['nama' => $p->nama, 'kelas' => $p->kelas,
            'golongan' => $g?->nama, 'label_unit' => $g?->label_unit];
    }
}
