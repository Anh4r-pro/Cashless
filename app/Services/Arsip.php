<?php

namespace App\Services;

use App\Exceptions\ApiError;
use App\Models\SesiOrtu;
use App\Models\Siswa;
use App\Models\SiswaPin;
use App\Models\TopupRequest;
use App\Support\WaktuIso;
use Illuminate\Support\Facades\DB;

/**
 * Mengeluarkan pemegang kartu dari peredaran tanpa menghapus jejaknya.
 *
 * Aturan pokoknya satu: SALDO HARUS NOL LEBIH DULU. Neraca kas menghitung
 * kewajiban sekolah dari SUM(saldo) seluruh kartu, jadi mengarsipkan kartu
 * bersaldo akan menyembunyikan kewajiban yang uangnya masih ada di kas — dan
 * selisih itu muncul di neraca sebagai angka yang tidak bisa dijelaskan
 * siapa pun berbulan-bulan kemudian. Uangnya dikembalikan dulu lewat menu
 * Saldo Santri (alasan "Pengembalian saldo (santri keluar/lulus)" sudah
 * tersedia sebagai bawaan), baru kartunya diarsipkan.
 *
 * Pengajuan topup wali yang masih menggantung juga menahan pengarsipan:
 * kalau tidak, ada pengajuan yang tidak akan pernah bisa diputuskan dan uang
 * wali sudah berpindah di luar sistem.
 */
class Arsip
{
    /**
     * Sebab-sebab kartu ini belum boleh diarsipkan. Daftar kosong berarti siap.
     *
     * Dikembalikan sebagai DAFTAR, bukan satu pesan pertama yang ditemukan:
     * bendahara yang mengarsipkan sekelas perlu melihat seluruh pekerjaan yang
     * tersisa sekaligus, bukan menemukannya satu per satu sambil mencoba.
     */
    public static function penahan(Siswa $s): array
    {
        $sebab = [];

        if ((int) $s->saldo !== 0) {
            $sebab[] = 'saldo kartu masih ' . Rupiah::f((int) $s->saldo);
        }
        if ((int) $s->saldo_titipan !== 0) {
            $sebab[] = 'saldo titipan masih ' . Rupiah::f((int) $s->saldo_titipan);
        }
        if ($n = TopupRequest::where('siswa_id', $s->id)->where('status', 'pending')->count()) {
            $sebab[] = "{$n} pengajuan topup wali masih menunggu keputusan";
        }

        return $sebab;
    }

    public static function siap(Siswa $s): bool
    {
        return self::penahan($s) === [];
    }

    /**
     * Arsipkan satu kartu.
     *
     * UID kartu DILEPAS supaya kartu fisiknya bisa dipakai santri baru — itu
     * seluruh gunanya mengarsipkan, dan tanpa itu kartu menumpuk sebagai barang
     * yang tidak bisa dipakai lagi. UID lamanya dicatat di `arsip_catatan`,
     * jadi jejaknya tidak hilang.
     */
    public static function arsipkan(Siswa $s, string $status, string $catatan, string $operator): Siswa
    {
        $status = Kelas::wajibStatusArsip($status);

        if ($sebab = self::penahan($s)) {
            throw new ApiError(409, "{$s->nama} belum bisa diarsipkan — " . implode('; ', $sebab));
        }

        return DB::transaction(function () use ($s, $status, $catatan, $operator) {
            $jejak = array_filter([
                trim($catatan),
                $s->card_uid ? "kartu {$s->card_uid} dilepas" : null,
                'kelas terakhir ' . ($s->kelas ?: '—'),
                "oleh {$operator}",
            ]);

            $s->update([
                'status' => $status,
                'arsip_pada' => WaktuIso::sekarang(),
                'arsip_catatan' => mb_substr(implode(' · ', $jejak), 0, 300),
                'card_uid' => null,
            ]);

            // PIN kios dihapus dan seluruh sesi ditutup: kredensial wali (NIS +
            // tanggal lahir) tidak berubah saat santri keluar, jadi kalau sesinya
            // dibiarkan hidup, portal tetap bisa dibuka bertahun-tahun sesudahnya.
            SiswaPin::where('siswa_id', $s->id)->delete();
            SesiOrtu::where('siswa_id', $s->id)->delete();
            SesiKios::tutupSemua($s->id);
            Rem::lupakan('pin:' . $s->id);

            return $s->fresh();
        });
    }

    /**
     * Kembalikan kartu terarsip ke peredaran.
     *
     * Kartu fisiknya TIDAK dipulihkan — UID-nya sudah dilepas dan mungkin sudah
     * dipakai orang lain, jadi kartu baru harus didaftarkan lewat tombol
     * "kartu". Yang dipulihkan adalah identitas dan riwayatnya, supaya santri
     * yang kembali tidak kehilangan jejak lamanya hanya karena pernah keluar.
     */
    public static function aktifkan(Siswa $s, string $operator): Siswa
    {
        if (! in_array($s->status, Kelas::ARSIP, true)) {
            throw new ApiError(409, "{$s->nama} tidak sedang diarsipkan");
        }

        $s->update([
            'status' => 'aktif',
            'arsip_catatan' => mb_substr(
                trim("diaktifkan kembali oleh {$operator} · sebelumnya: {$s->arsip_catatan}"), 0, 300),
            'arsip_pada' => null,
        ]);

        return $s->fresh();
    }
}
