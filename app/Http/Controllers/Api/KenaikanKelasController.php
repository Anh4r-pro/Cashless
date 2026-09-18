<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\ApiError;
use App\Models\KenaikanKelas;
use App\Models\KenaikanKelasBaris;
use App\Models\Siswa;
use App\Services\Arsip;
use App\Services\Kelas;
use App\Support\WaktuIso;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Kenaikan kelas massal, sekali setahun.
 *
 * Hanya menyentuh golongan santri — Unit SDM tidak naik tiap tahun.
 *
 * Tiga pengaman yang membedakannya dari sekadar satu UPDATE:
 *
 *   1. DUA LANGKAH. Pratinjau menunjukkan pemetaan beserta jumlah santri per
 *      baris, dan untuk kelas tertinggi sekaligus menunjukkan siapa yang
 *      tertahan karena saldonya belum nol. Tidak ada yang berubah sampai
 *      Terapkan ditekan.
 *   2. SATU TAHUN SEKALI. `tahun_ajaran` UNIQUE di basis data — bukan hanya
 *      diperiksa di sini — sehingga dua admin yang menekan tombolnya bersamaan
 *      tidak mungkin menaikkan angkatan dua tingkat.
 *   3. BISA DIBATALKAN. Kelas dan status sebelum disimpan per santri, jadi
 *      pemetaan yang keliru bisa dipulihkan utuh.
 *
 * Kelas pada TRANSAKSI tidak disentuh: nilainya sudah didenormalisasi di sana,
 * sehingga laporan tahun lalu tetap menunjukkan kelas yang berlaku saat itu.
 */
class KenaikanKelasController extends Controller
{
    private const GOL = 'siswa';

    public function pratinjau(Request $r)
    {
        $this->wajibIzin($r, 'santri.kenaikan');

        $kelas = Siswa::where('golongan', self::GOL)
            ->whereIn('status', ['aktif', 'blokir'])
            ->where('kelas', '!=', '')
            ->selectRaw('kelas, COUNT(*) n')
            ->groupBy('kelas')->get();

        $baris = [];
        foreach ($kelas as $k) {
            $saran = Kelas::naik($k->kelas);
            $entri = [
                'kelas' => $k->kelas,
                'n' => (int) $k->n,
                'saran' => $saran,
                'bentuk_sah' => Kelas::sah($k->kelas),
            ];

            if ($saran === null) {
                // Bentuknya tidak terbaca — TIDAK ditebak. Menaikkan kelas yang
                // salah ketik hanya memindahkan santri ke kelas yang lebih salah.
                $entri['jenis'] = 'menyimpang';
                $entri['pesan'] = 'Bentuk kelas tidak terbaca (harus angka + huruf, mis. 7A) — '
                    . 'betulkan dulu kelas santrinya, atau tentukan tujuannya sendiri';
            } elseif (in_array($saran, Kelas::ARSIP, true)) {
                $entri['jenis'] = 'arsip';
                // Kelas tertinggi seluruhnya keluar, jadi penahan saldo harus
                // terlihat SEBELUM tombol ditekan — bukan sebagai kegagalan
                // sesudahnya.
                $orang = Siswa::where('golongan', self::GOL)->where('kelas', $k->kelas)
                    ->whereIn('status', ['aktif', 'blokir'])->get();
                $tertahan = $orang->filter(fn ($s) => ! Arsip::siap($s));
                $entri['n_tertahan'] = $tertahan->count();
                $entri['sisa_saldo'] = (int) $tertahan->sum('saldo');
                $entri['sisa_titipan'] = (int) $tertahan->sum('saldo_titipan');
            } else {
                $entri['jenis'] = 'naik';
            }
            $baris[] = $entri;
        }

        usort($baris, function ($a, $b) {
            $ta = Kelas::urai($a['kelas'])['tingkat'] ?? 99;
            $tb = Kelas::urai($b['kelas'])['tingkat'] ?? 99;

            return $ta === $tb ? strcmp($a['kelas'], $b['kelas']) : $ta <=> $tb;
        });

        // Saran tahun ajaran mengikuti kalender: tahun ajaran di Indonesia
        // dimulai Juli, jadi sebelum Juli yang berjalan masih tahun sebelumnya.
        $th = (int) date('Y');
        $saranTahun = ((int) date('n') >= 7) ? "{$th}/" . ($th + 1) : ($th - 1) . "/{$th}";

        return response()->json([
            'baris' => $baris,
            'tingkat_awal' => Kelas::tingkatAwal(),
            'tingkat_lulus' => Kelas::tingkatLulus(),
            'tahun_ajaran_saran' => $saranTahun,
            'status_arsip' => Kelas::LABEL_ARSIP,
            'riwayat' => KenaikanKelas::orderByDesc('id')->limit(20)->get(),
        ]);
    }

    public function terapkan(Request $r)
    {
        $u = $this->wajibIzin($r, 'santri.kenaikan');
        $tahun = Kelas::wajibTahunAjaran($r->input('tahun_ajaran'));

        if ($lama = KenaikanKelas::where('tahun_ajaran', $tahun)->whereNull('dibatalkan_pada')->first()) {
            throw new ApiError(409, "Tahun ajaran {$tahun} sudah pernah dinaikkan pada "
                . substr((string) $lama->created_at, 0, 10) . " oleh {$lama->operator} "
                . "({$lama->n_naik} naik, {$lama->n_arsip} diarsipkan). "
                . 'Batalkan batch itu dulu kalau memang mau diulang.');
        }

        $peta = $r->input('peta');
        if (! is_array($peta) || ! $peta) {
            throw new ApiError(400, 'Pemetaan kelas kosong');
        }

        // Seluruh tujuan diperiksa LEBIH DULU, sebelum satu baris pun disentuh:
        // pemetaan yang separuh salah tidak boleh menghasilkan angkatan yang
        // separuh naik.
        $bersih = [];
        foreach ($peta as $dari => $ke) {
            $dari = strtoupper(trim((string) $dari));
            $ke = trim((string) $ke);
            if ($dari === '' || $ke === '') {
                continue;                       // baris yang sengaja dilewati admin
            }
            if (! in_array($ke, Kelas::ARSIP, true) && Kelas::urai($ke) === null) {
                throw new ApiError(400, "Tujuan \"{$ke}\" untuk kelas {$dari} tidak dikenali — "
                    . 'harus berupa kelas (mis. 8A) atau salah satu dari: ' . implode(', ', Kelas::ARSIP));
            }
            $bersih[$dari] = in_array($ke, Kelas::ARSIP, true) ? $ke : strtoupper($ke);
        }
        if (! $bersih) {
            throw new ApiError(400, 'Tidak ada satu pun kelas yang dipetakan');
        }

        $hasil = DB::transaction(function () use ($bersih, $tahun, $u, $r) {
            // Percobaan berikutnya untuk tahun ajaran ini. Batch yang sudah
            // dibatalkan tetap tersimpan sebagai riwayat, jadi nomornya maju —
            // bukan menimpa jejak yang lama.
            $percobaan = (int) KenaikanKelas::where('tahun_ajaran', $tahun)->max('percobaan') + 1;

            $batch = KenaikanKelas::create([
                'tahun_ajaran' => $tahun,
                'percobaan' => $percobaan,
                'operator' => $u->nama,
                'catatan' => mb_substr(trim((string) $r->input('catatan', '')), 0, 300),
                'created_at' => WaktuIso::sekarang(),
            ]);

            $naik = 0;
            $arsip = 0;
            $tertahan = [];

            /*
             * SELURUH sasaran dikumpulkan LEBIH DULU, sebelum satu baris pun
             * diubah — dan dikunci per siswa_id sehingga tiap santri disentuh
             * paling banyak sekali.
             *
             * Tanpa ini, pemetaannya beruntun: begitu 7A→8A dijalankan, santri
             * itu berada di 8A, dan baris 8A→9A berikutnya membawanya naik
             * sekali lagi. Pada pemetaan 7→12 yang lengkap, santri kelas 7 akan
             * berakhir sebagai Alumni dalam satu kali tekan. Terbukti di
             * pengujian: enam santri menghasilkan sepuluh kenaikan.
             *
             * Mengumpulkan dulu membuat hasilnya tidak bergantung pada urutan
             * pemetaan sama sekali.
             */
            $rencana = [];
            foreach ($bersih as $dari => $ke) {
                $orang = Siswa::where('golongan', self::GOL)->where('kelas', $dari)
                    ->whereIn('status', ['aktif', 'blokir'])->orderBy('nama')->get();
                foreach ($orang as $s) {
                    $rencana[$s->id] = ['siswa' => $s, 'ke' => $ke];
                }
            }

            foreach ($rencana as $langkah) {
                $s = $langkah['siswa'];
                $ke = $langkah['ke'];
                $kelasLama = $s->kelas;
                $statusLama = $s->status;

                if (in_array($ke, Kelas::ARSIP, true)) {
                    if (! Arsip::siap($s)) {
                        // Dilewati, bukan menggagalkan seluruh batch: satu santri
                        // yang saldonya belum diselesaikan tidak boleh menahan
                        // seangkatan.
                        $tertahan[] = [
                            'id' => $s->id, 'nis' => $s->nis, 'nama' => $s->nama,
                            'kelas' => $kelasLama, 'penahan' => Arsip::penahan($s),
                        ];

                        continue;
                    }
                    Arsip::arsipkan($s, $ke, "kenaikan kelas {$tahun}", $u->nama);
                    $arsip++;
                    KenaikanKelasBaris::create([
                        'batch_id' => $batch->id, 'siswa_id' => $s->id,
                        'kelas_lama' => $kelasLama, 'kelas_baru' => $kelasLama,
                        'status_lama' => $statusLama, 'status_baru' => $ke,
                    ]);

                    continue;
                }

                $s->update(['kelas' => $ke]);
                $naik++;
                KenaikanKelasBaris::create([
                    'batch_id' => $batch->id, 'siswa_id' => $s->id,
                    'kelas_lama' => $kelasLama, 'kelas_baru' => $ke,
                    'status_lama' => $statusLama, 'status_baru' => $statusLama,
                ]);
            }

            $batch->update(['n_naik' => $naik, 'n_arsip' => $arsip]);

            return ['batch' => $batch->fresh(), 'tertahan' => $tertahan];
        });

        return response()->json([
            'ok' => true,
            'batch' => $hasil['batch'],
            'n_tertahan' => count($hasil['tertahan']),
            'tertahan' => $hasil['tertahan'],
        ]);
    }

    public function riwayat(Request $r)
    {
        $this->wajibIzin($r, 'santri.kenaikan');

        if ($id = $r->query('id')) {
            $b = KenaikanKelas::find((int) $id);
            if (! $b) {
                throw new ApiError(404, 'Batch kenaikan tidak ditemukan');
            }

            return response()->json([
                'batch' => $b,
                'baris' => KenaikanKelasBaris::where('batch_id', $b->id)
                    ->join('siswa', 'siswa.id', '=', 'kenaikan_kelas_baris.siswa_id')
                    ->select('kenaikan_kelas_baris.*', 'siswa.nama', 'siswa.nis')
                    ->orderBy('kelas_lama')->orderBy('siswa.nama')->get(),
            ]);
        }

        return response()->json(['riwayat' => KenaikanKelas::orderByDesc('id')->limit(50)->get()]);
    }

    /**
     * Pulihkan satu batch.
     *
     * Kartu fisik TIDAK dipulihkan untuk santri yang sudah diarsipkan — UID-nya
     * sudah dilepas dan bisa saja sudah dipakai orang lain, jadi memaksanya
     * kembali berisiko menabrak kartu yang sekarang sah. Kartu baru didaftarkan
     * lewat tombol "kartu". Ini disebutkan di tanggapan supaya admin tahu.
     */
    public function batalkan(Request $r, int $id)
    {
        $u = $this->wajibIzin($r, 'santri.kenaikan');
        $b = KenaikanKelas::find($id);
        if (! $b) {
            throw new ApiError(404, 'Batch kenaikan tidak ditemukan');
        }
        if (! $b->aktif()) {
            throw new ApiError(409, "Batch {$b->tahun_ajaran} sudah dibatalkan pada "
                . substr((string) $b->dibatalkan_pada, 0, 10));
        }
        // Membatalkan batch lama sementara yang lebih baru masih berlaku akan
        // menumpuk dua pemulihan di atas satu sama lain dan hasilnya tidak bisa
        // ditebak. Yang terbaru harus dibatalkan lebih dulu.
        if ($lebihBaru = KenaikanKelas::where('id', '>', $b->id)->whereNull('dibatalkan_pada')->first()) {
            throw new ApiError(409, "Ada batch yang lebih baru ({$lebihBaru->tahun_ajaran}) "
                . 'yang masih berlaku — batalkan yang itu lebih dulu');
        }

        $nKartu = 0;
        DB::transaction(function () use ($b, $u, &$nKartu) {
            foreach (KenaikanKelasBaris::where('batch_id', $b->id)->get() as $baris) {
                $s = Siswa::find($baris->siswa_id);
                if (! $s) {
                    continue;
                }
                $isi = ['kelas' => $baris->kelas_lama, 'status' => $baris->status_lama];
                if (in_array($baris->status_baru, Kelas::ARSIP, true)) {
                    $isi['arsip_pada'] = null;
                    $isi['arsip_catatan'] = mb_substr(
                        "kenaikan {$b->tahun_ajaran} dibatalkan oleh {$u->nama}", 0, 300);
                    $nKartu++;
                }
                $s->update($isi);
            }
            $b->update([
                'dibatalkan_pada' => WaktuIso::sekarang(),
                'dibatalkan_oleh' => $u->nama,
            ]);
        });

        return response()->json([
            'ok' => true,
            'batch' => $b->fresh(),
            'pesan' => "Kenaikan tahun ajaran {$b->tahun_ajaran} dibatalkan. "
                . ($nKartu > 0
                    ? "{$nKartu} santri dikembalikan dari arsip, tetapi UID kartunya sudah dilepas "
                      . 'dan harus didaftarkan ulang lewat tombol "kartu".'
                    : 'Seluruh kelas dipulihkan ke keadaan sebelumnya.'),
        ]);
    }
}
