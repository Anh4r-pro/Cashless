<?php

namespace App\Services;

use App\Models\Siswa;
use App\Models\Transaksi;

/**
 * Biaya admin yang dipungut saat wali melakukan top up mandiri lewat portal.
 *
 * Hanya jalur portal wali yang dipungut. Top up massal dari Excel, top up
 * manual oleh admin di loket, isi ulang otomatis dari titipan, dan transfer
 * antar teman TIDAK dipungut — tiga yang terakhir bahkan bukan uang baru,
 * uangnya sudah dihitung saat pertama kali disetorkan.
 *
 * Besarannya nominal tetap, bukan persentase: yang membacanya adalah wali
 * murid, dan "Rp2.500 setiap kali mengisi" bisa dipahami tanpa penjelasan,
 * sementara persentase selalu menuntut contoh perhitungan.
 */
class BiayaAdmin
{
    public const KUNCI = 'biaya_admin_topup_ortu';

    /** 0 berarti fitur ini mati. */
    public static function tarif(): int
    {
        return max(0, (int) (Pengaturan::sekolah()[self::KUNCI] ?? 0));
    }

    public static function aktif(): bool
    {
        return self::tarif() > 0;
    }

    /**
     * Biaya untuk satu pengajuan.
     *
     * Dibatasi agar tidak pernah melebihi nominal yang disetor: kalau tarifnya
     * pernah dinaikkan melampaui nominal terkecil, pengajuan lama yang masih
     * menunggu di antrean tidak boleh berubah menjadi saldo negatif.
     */
    public static function untuk(int $jumlah): int
    {
        return min(self::tarif(), max(0, $jumlah));
    }

    /**
     * Catat biayanya di buku besar.
     *
     * Barisnya TIDAK mengubah saldo kartu — nominalnya memang tidak pernah
     * masuk ke sana. `saldo_sesudah` karena itu diisi saldo yang berlaku
     * sesudah top up, sehingga rantai saldo di buku besar tetap runut dan
     * pemeriksaan drift tidak melaporkan ketidakcocokan palsu.
     *
     * Yang dicatat tetap ditautkan ke santri agar wali bisa melihatnya di
     * riwayat: uang yang ia bayar berkurang, dan ia berhak tahu ke mana.
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
