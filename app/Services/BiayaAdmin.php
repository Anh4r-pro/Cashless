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
 * Hanya jalur portal wali yang dipungut. Top up massal dari Excel, top up
 * manual admin di loket, isi ulang otomatis dari titipan, dan transfer antar
 * teman tidak — tiga yang terakhir bahkan bukan uang baru.
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
