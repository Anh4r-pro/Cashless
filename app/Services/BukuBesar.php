<?php

namespace App\Services;

use App\Models\Siswa;
use App\Models\TitipanMutasi;
use App\Models\Transaksi;
use App\Support\WaktuIso;

/**
 * Pencatatan dua buku besar sekolah.
 *
 * `transaksi` adalah satu-satunya sumber kebenaran saldo KARTU; `titipan_mutasi`
 * mencatat uang yang sudah diterima sekolah tetapi belum ada di kartu. Keduanya
 * dipisah supaya tidak ada satu pun angka di riwayat lama yang berubah artinya
 * ketika fitur titipan ditambahkan.
 *
 * Baris yang memindahkan titipan → saldo menyebut transaksi pasangannya lewat
 * transaksi_id, jadi dua sisi mutasi selalu bisa dicocokkan.
 */
class BukuBesar
{
    /**
     * Catat mutasi saldo kartu. Nilai saldo_sesudah diambil dari baris santri
     * yang SUDAH diperbarui, jadi pemanggil wajib memperbarui saldo lebih dulu
     * di dalam transaksi basis data yang sama.
     */
    public static function catatTransaksi(
        ?string $txId,
        string $tipe,
        Siswa $s,
        int $jumlah,
        string $operator,
        string $keterangan,
        array $tambahan = [],
    ): Transaksi {
        return Transaksi::create(array_merge([
            'tx_id' => $txId,
            'tipe' => $tipe,
            'siswa_id' => $s->id,
            'siswa_nama' => $s->nama,
            'kelas' => $s->kelas,
            'tenant_id' => null,
            'tenant_nama' => null,
            'jumlah' => $jumlah,
            'saldo_sesudah' => $s->saldo,
            'operator' => $operator,
            'keterangan' => mb_substr($keterangan, 0, 300),
            'created_at' => WaktuIso::sekarang(),
        ], $tambahan))->fresh();
    }

    public static function catatTitipan(
        Siswa $s,
        string $jenis,
        int $jumlah,
        string $operator,
        string $keterangan,
        ?string $ref = null,
        ?int $transaksiId = null,
    ): TitipanMutasi {
        return TitipanMutasi::create([
            'siswa_id' => $s->id,
            'jenis' => $jenis,
            'jumlah' => $jumlah,
            'titipan_sesudah' => $s->saldo_titipan,
            'transaksi_id' => $transaksiId,
            'operator' => $operator,
            'keterangan' => mb_substr($keterangan, 0, 300),
            'ref' => $ref,
            'created_at' => WaktuIso::sekarang(),
        ])->fresh();
    }
}
