<?php

namespace App\Services;

use App\Models\Siswa;
use App\Models\Transaksi;

/**
 * Biaya admin yang dipungut saat wali melakukan top up mandiri lewat portal.
 *
 * TARIFNYA BERLAPIS, dan urutannya sengaja dibuat "yang paling khusus menang":
 *
 *     santri.biaya_admin terisi?          → pakai itu   (0 = dibebaskan)
 *     golongan dicentang "kena biaya"?    → pakai tarif sekolah
 *     selain itu                          → TIDAK dipungut
 *
 * Perhatikan lapisan tengahnya: golongan hanya punya SAKELAR hidup/mati, bukan
 * nominal sendiri. Ini berbeda dari tujuh kolom batas di sebelahnya yang
 * memakai aturan "kosong = ikut sekolah" — dan perbedaan itu disengaja. Kalau
 * biaya ikut aturan itu, menyalakan portal wali bagi sebuah golongan akan
 * langsung memungut uang dari mereka tanpa keputusan terpisah. Sakelar yang
 * mati sejak lahir membuat golongan mana pun aman sampai dicentang dengan sadar.
 *
 * Jalur portal wali dipungut otomatis menurut lapisan di atas. Top up manual
 * admin di loket dan top up massal dari Excel TIDAK dipungut, kecuali admin
 * memilih "biaya admin" pada top up itu (lihat untukManual). Isi ulang otomatis
 * dari titipan dan transfer antar teman tidak pernah dipungut — keduanya
 * bahkan bukan uang baru.
 */
class BiayaAdmin
{
    public const KUNCI = 'biaya_admin_topup_ortu';

    /**
     * Metode pembayaran yang boleh dipungut.
     *
     * Daftar putih, bukan daftar hitam: kalau kelak ada metode ketiga (mis.
     * tunai di loket lewat portal), ia TIDAK ikut dipungut hanya karena
     * kebetulan lewat jalur yang sama. Memungut orang harus keputusan sadar,
     * termasuk saat ada jalur baru.
     */
    public const METODE_DIPUNGUT = ['qris', 'transfer'];

    /** Tarif dasar sekolah. 0 berarti fitur ini mati untuk semua orang. */
    public static function tarifSekolah(): int
    {
        return max(0, (int) (Pengaturan::sekolah()[self::KUNCI] ?? 0));
    }

    public static function metodeDipungut(?string $metode): bool
    {
        return in_array((string) $metode, self::METODE_DIPUNGUT, true);
    }

    /**
     * Tarif yang berlaku untuk satu santri, sesudah seluruh lapisan diterapkan.
     */
    public static function tarifUntuk(?Siswa $s): int
    {
        if (! $s) {
            return 0;
        }
        // Penimpa per orang menang atas apa pun — termasuk atas angka 0, yang
        // di sini berarti "dibebaskan" dan bukan "belum diisi".
        if ($s->biaya_admin !== null) {
            return max(0, (int) $s->biaya_admin);
        }

        $g = Pengaturan::golongan($s->golongan);

        return ($g && (int) $g->biaya_admin_kena === 1) ? self::tarifSekolah() : 0;
    }

    /**
     * Tarif untuk top up manual/massal yang DIPILIH admin sebagai "biaya admin".
     *
     * Berbeda dari portal wali, sakelar golongan tidak ikut menentukan: admin
     * sudah memutuskan memungut dengan memilih opsinya, dan sakelar golongan
     * yang mati sejak lahir akan membuat pilihan itu diam-diam bernilai Rp0.
     * Penimpa per santri tetap menang — santri yang dibebaskan (0) tetap bebas.
     */
    public static function tarifManual(?Siswa $s): int
    {
        if ($s && $s->biaya_admin !== null) {
            return max(0, (int) $s->biaya_admin);
        }

        return self::tarifSekolah();
    }

    /** Biaya top up manual, tidak pernah melebihi nominal yang disetor. */
    public static function untukManual(?Siswa $s, int $jumlah): int
    {
        return min(self::tarifManual($s), max(0, $jumlah));
    }

    /**
     * Biaya untuk satu pengajuan.
     *
     * Dibatasi agar tidak pernah melebihi nominal yang disetor: kalau tarifnya
     * pernah dinaikkan melampaui nominal terkecil, pengajuan lama yang masih
     * menunggu di antrean tidak boleh berubah menjadi saldo negatif.
     */
    public static function untuk(?Siswa $s, int $jumlah, ?string $metode = 'transfer'): int
    {
        if (! self::metodeDipungut($metode)) {
            return 0;
        }

        return min(self::tarifUntuk($s), max(0, $jumlah));
    }

    /**
     * Biaya yang benar-benar dipungut untuk sebuah pengajuan yang menunggu.
     *
     * Diambil yang TERKECIL antara tarif yang terkunci saat wali mengajukan
     * dan tarif yang berlaku sekarang. Aturannya mudah dijelaskan ke siapa
     * pun: tidak pernah lebih mahal dari yang dijanjikan, tapi ikut turun
     * kalau tarifnya turun. Ini sekaligus menangani santri yang baru
     * ditetapkan bebas biaya selagi pengajuannya masih di antrean — ia
     * langsung menikmatinya tanpa harus mengajukan ulang.
     */
    public static function untukPengajuan(?Siswa $s, int $jumlah, ?string $metode, ?int $terkunci): int
    {
        $berlaku = self::untuk($s, $jumlah, $metode);
        if ($terkunci === null) {
            return $berlaku;
        }

        return min(max(0, (int) $terkunci), $berlaku);
    }

    /**
     * Siapa yang sebenarnya dipungut dengan tarif yang berlaku sekarang.
     *
     * Ada karena tarif dan sasaran diatur di DUA layar berbeda: nominalnya di
     * Topup Ortu, sakelar golongannya di menu Golongan. Admin yang hanya
     * mengisi nominal lalu menutup layar akan mengira biaya sudah berjalan,
     * padahal belum memungut siapa pun. Ringkasan ini dipakai layar Topup Ortu
     * untuk mengatakannya terus terang alih-alih membiarkannya diam.
     *
     * Kartu yang sudah diarsipkan tidak dihitung — ia tidak bisa top up lagi.
     */
    public static function sasaran(): array
    {
        $golongan = \App\Models\Golongan::where('biaya_admin_kena', 1)
            ->where('aktif', 1)->orderBy('urutan')->orderBy('nama')
            ->pluck('nama')->all();

        $penimpa = Siswa::whereNotNull('biaya_admin')->whereNull('arsip_pada')
            ->selectRaw('SUM(CASE WHEN biaya_admin = 0 THEN 1 ELSE 0 END) AS bebas')
            ->selectRaw('SUM(CASE WHEN biaya_admin > 0 THEN 1 ELSE 0 END) AS khusus')
            ->first();

        return [
            'tarif' => self::tarifSekolah(),
            'golongan' => $golongan,
            'bebas' => (int) ($penimpa->bebas ?? 0),
            'khusus' => (int) ($penimpa->khusus ?? 0),
        ];
    }

    /**
     * Catat biayanya di buku besar.
     *
     * Barisnya TIDAK mengubah saldo kartu — nominalnya memang tidak pernah
     * masuk ke sana. `saldo_sesudah` karena itu diisi saldo yang berlaku
     * sesudah top up, sehingga rantai saldo tetap runut dan pemeriksaan drift
     * tidak melaporkan ketidakcocokan palsu.
     *
     * Tetap ditautkan ke santri agar wali bisa melihatnya di riwayat: uang
     * yang ia bayar berkurang, dan ia berhak tahu ke mana.
     */
    public static function catat(Siswa $s, int $biaya, string $operator, string $keterangan, ?string $txId = null): ?Transaksi
    {
        if ($biaya <= 0) {
            return null;
        }

        return Transaksi::create([
            'tx_id' => $txId,
            'tipe' => 'biaya_admin',
            'siswa_id' => $s->id,
            'siswa_nama' => $s->nama,
            'kelas' => $s->kelas,
            'tenant_id' => null,
            'tenant_nama' => null,
            'jumlah' => -$biaya,
            'saldo_sesudah' => $s->saldo,
            'operator' => $operator,
            'keterangan' => mb_substr($keterangan, 0, 300),
            'created_at' => \App\Support\WaktuIso::sekarang(),
        ]);
    }
}
