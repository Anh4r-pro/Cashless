<?php

namespace App\Console\Commands;

use App\Models\Siswa;
use App\Services\Neraca;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Kosongkan data pemegang kartu supaya bisa diisi ulang dari awal.
 *
 * INI BUKAN FITUR ARSIP. Arsip dipakai untuk santri yang benar-benar keluar:
 * riwayatnya dijaga, kartunya dibebaskan, dan namanya tetap bisa ditelusuri.
 * Perintah ini justru menghapus jejaknya sampai habis — gunanya hanya satu,
 * yaitu membuang data CONTOH sebelum data sungguhan masuk, atau mengulang
 * percobaan impor selama masa uji coba. Untuk santri sungguhan yang lulus atau
 * pindah, tetap pakai arsip.
 *
 * Tiga pengaman yang sengaja dipasang:
 *
 *   1. Tidak melakukan apa pun tanpa --paksa. Menjalankannya tanpa itu hanya
 *      menampilkan apa yang AKAN terhapus.
 *   2. Selalu menulis cadangan CSV lebih dulu. Kalau cadangannya gagal ditulis,
 *      penghapusan tidak dimulai sama sekali.
 *   3. Seluruhnya di dalam satu transaksi basis data, lalu neraca dihitung
 *      ulang. Kalau selisihnya tidak nol, semuanya dibatalkan — kas sekolah
 *      tidak boleh jadi tidak seimbang gara-gara pembersihan data.
 */
class ResetSantri extends Command
{
    protected $signature = 'kantin:reset-santri
        {--golongan=siswa : Kode golongan yang dikosongkan; "semua" untuk seluruh kartu}
        {--cadangan= : Path berkas CSV cadangan (bawaan: storage/app/cadangan-santri-<waktu>.csv)}
        {--paksa : Benar-benar hapus. Tanpa ini perintah hanya menampilkan rencana}';

    protected $description = 'Kosongkan data pemegang kartu beserta riwayatnya, untuk pengisian ulang dari awal';

    public function handle(): int
    {
        $gol = (string) $this->option('golongan');
        $q = fn () => $gol === 'semua' ? Siswa::query() : Siswa::where('golongan', $gol);

        $jml = $q()->count();
        if ($jml === 0) {
            $this->info('Tidak ada kartu yang cocok — tidak ada yang perlu dikosongkan.');

            return self::SUCCESS;
        }

        $saldo = (int) $q()->sum('saldo');
        $titipan = (int) $q()->sum('saldo_titipan');
        $ids = $q()->pluck('id')->all();

        // Dihitung lebih dulu supaya angkanya bisa ditampilkan SEBELUM
        // siapa pun menekan --paksa, bukan dilaporkan sesudah terlambat.
        $rincian = [
            'transaksi' => DB::table('transaksi')
                ->whereIn('siswa_id', $ids)->orWhereIn('lawan_id', $ids)->count(),
            'pengajuan top up' => DB::table('topup_request')->whereIn('siswa_id', $ids)->count(),
            'mutasi titipan' => DB::table('titipan_mutasi')->whereIn('siswa_id', $ids)->count(),
            'baris kenaikan kelas' => DB::table('kenaikan_kelas_baris')->whereIn('siswa_id', $ids)->count(),
            'baris impor top up' => DB::table('topup_impor_baris')->whereIn('siswa_id', $ids)->count(),
            'PIN kios' => DB::table('siswa_pin')->whereIn('siswa_id', $ids)->count(),
            'sesi wali aktif' => DB::table('ortu_sessions')->whereIn('siswa_id', $ids)->count(),
        ];

        $this->line('');
        $this->line("Kartu yang akan DIHAPUS : <fg=yellow>{$jml}</> (golongan: {$gol})");
        $this->line('Saldo yang ikut hilang  : <fg=yellow>Rp' . number_format($saldo, 0, ',', '.') . '</>');
        $this->line('Titipan yang ikut hilang: <fg=yellow>Rp' . number_format($titipan, 0, ',', '.') . '</>');
        foreach ($rincian as $nama => $n) {
            if ($n > 0) {
                $this->line('  + ' . str_pad($nama, 22) . $n . ' baris');
            }
        }

        if (! $this->option('paksa')) {
            $this->line('');
            $this->warn('Ini baru RENCANA. Tambahkan --paksa untuk benar-benar menghapus.');

            return self::SUCCESS;
        }

        // ---- cadangan dulu, baru hapus ----
        $path = $this->option('cadangan')
            ?: storage_path('app/cadangan-santri-' . date('Ymd-His') . '.csv');
        if (! $this->tulisCadangan($q(), $path)) {
            $this->error('Cadangan gagal ditulis — penghapusan DIBATALKAN.');

            return self::FAILURE;
        }
        $this->info('Cadangan ditulis: ' . $path);

        $selisih = null;
        DB::transaction(function () use ($ids, &$selisih) {
            /*
             * URUTANNYA PENTING. Beberapa tabel menunjuk `transaksi` dengan
             * aturan NO ACTION, jadi barisnya harus dilepas SEBELUM transaksi
             * yang ditunjuknya dihapus — kalau tidak, basis data menolak dan
             * seluruh pembersihan gagal di tengah jalan.
             */
            $txIds = DB::table('transaksi')
                ->whereIn('siswa_id', $ids)->orWhereIn('lawan_id', $ids)
                ->pluck('id')->all();

            // Dana Admin TIDAK ikut dihapus: uang yang sudah menjadi milik
            // sekolah tetap miliknya walau kartu penyetornya hilang. Yang
            // dilepas hanya tautannya, supaya jejak nominalnya tetap utuh.
            DB::table('dana_admin_mutasi')->whereIn('siswa_id', $ids)->update(['siswa_id' => null]);
            if ($txIds) {
                DB::table('dana_admin_mutasi')->whereIn('transaksi_id', $txIds)
                    ->update(['transaksi_id' => null]);
            }

            DB::table('topup_request')->whereIn('siswa_id', $ids)->delete();
            DB::table('titipan_mutasi')->whereIn('siswa_id', $ids)->delete();
            if ($txIds) {
                DB::table('topup_request')->whereIn('transaksi_id', $txIds)
                    ->update(['transaksi_id' => null]);
                DB::table('titipan_mutasi')->whereIn('transaksi_id', $txIds)
                    ->update(['transaksi_id' => null]);
            }

            DB::table('kenaikan_kelas_baris')->whereIn('siswa_id', $ids)->delete();
            DB::table('topup_impor_baris')->whereIn('siswa_id', $ids)->delete();
            DB::table('siswa_pin')->whereIn('siswa_id', $ids)->delete();
            DB::table('ortu_sessions')->whereIn('siswa_id', $ids)->delete();

            /*
             * Transfer antar kartu punya DUA sisi. Baris milik kartu yang
             * dihapus ikut hilang; baris milik kartu yang DIPERTAHANKAN tidak
             * boleh ikut — uangnya benar-benar berpindah, dan menghapusnya
             * akan membuat saldo lawannya tidak lagi cocok dengan riwayatnya.
             * Yang dilepas cuma penunjuk ke kartu yang sudah tiada.
             */
            DB::table('transaksi_item')->whereIn('siswa_id', $ids)->delete();
            DB::table('transaksi')->whereIn('siswa_id', $ids)->delete();
            DB::table('transaksi')->whereIn('lawan_id', $ids)->update(['lawan_id' => null]);

            DB::table('siswa')->whereIn('id', $ids)->delete();

            /*
             * Penjaga terakhir, dan ia memang kadang MENOLAK dengan benar.
             *
             * Selama kartu hanya pernah menerima top up, menghapusnya menghapus
             * kedua sisi sekaligus — saldonya di kewajiban dan top up-nya di kas
             * — sehingga bukunya tetap tertutup. Tapi begitu kartu itu pernah
             * BELANJA dan uangnya sudah DISETOR ke tenant, uang tersebut sudah
             * benar-benar meninggalkan kas sekolah. Menghapus transaksinya
             * membuang asal-usul uang itu, sementara setorannya tetap tercatat
             * keluar. Tidak ada urutan penghapusan yang bisa memperbaikinya:
             * yang hilang bukan catatan, melainkan penjelasan ke mana uang
             * sungguhan sudah pergi.
             */
            $selisih = Neraca::hitung()['selisih'];
            if ($selisih !== 0) {
                throw new \RuntimeException(
                    "Neraca jadi tidak seimbang (selisih " . number_format($selisih, 0, ',', '.') . ") — "
                    . "SEMUA PERUBAHAN SUDAH DIBATALKAN, data Anda utuh.\n\n"
                    . "Sebabnya: kartu yang hendak dihapus sudah punya riwayat belanja yang\n"
                    . "uangnya terlanjur disetor ke tenant. Menghapus kartunya menghapus asal-usul\n"
                    . "uang yang sudah keluar, sehingga pembukuan tidak lagi bisa dijelaskan.\n\n"
                    . "Yang bisa dilakukan:\n"
                    . "  - Untuk santri yang keluar/lulus: pakai ARSIP, bukan perintah ini.\n"
                    . "    Riwayatnya dijaga, kartunya dibebaskan, pembukuan tetap utuh.\n"
                    . "  - Untuk membuang data UJI COBA sesudah sempat ada belanja: pembukuannya\n"
                    . "    harus ikut dinolkan, dan itu keputusan tersendiri — minta admin sistem."
                );
            }
        });

        $this->line('');
        $this->info("✔ {$jml} kartu dihapus. Neraca tetap seimbang (selisih {$selisih}).");
        $this->line('Data siap diisi ulang lewat Pemegang Kartu → Tambah Santri → impor Excel.');

        return self::SUCCESS;
    }

    /** Cadangan ditulis apa adanya dari basis data, bukan hasil olahan. */
    private function tulisCadangan($kueri, string $path): bool
    {
        try {
            @mkdir(dirname($path), 0775, true);
            $f = fopen($path, 'w');
            if (! $f) {
                return false;
            }
            fwrite($f, "\xEF\xBB\xBF");   // BOM supaya Excel membaca UTF-8
            $kolom = null;
            foreach ($kueri->orderBy('id')->cursor() as $s) {
                $baris = $s->getAttributes();
                if ($kolom === null) {
                    $kolom = array_keys($baris);
                    fputcsv($f, $kolom);
                }
                fputcsv($f, array_map(fn ($k) => $baris[$k] ?? '', $kolom));
            }
            fclose($f);

            return true;
        } catch (\Throwable $e) {
            $this->error($e->getMessage());

            return false;
        }
    }
}
