<?php

namespace App\Services;

use App\Exceptions\ApiError;
use App\Models\Siswa;
use PhpOffice\PhpSpreadsheet\IOFactory;

/**
 * Potong saldo kolektif lewat berkas Excel, dengan nominal BERBEDA per santri.
 *
 * Potong massal yang sudah ada memakai satu angka untuk semua orang —
 * layarnya pun berbunyi "Rp50.000 per santri". Itu cukup untuk denda seragam,
 * tetapi tidak untuk hal yang jumlahnya memang berbeda-beda: sisa biaya kitab,
 * ganti barang yang rusak, potongan yang dihitung per anak.
 *
 * ── Kenapa berkas ini lebih galak daripada impor top up ──────────────────
 * Impor top up yang keliru MENAMBAH uang yang tidak pernah ada; keliru di
 * sini MENGAMBIL uang yang ada di kartu santri. Karena itu tiga hal yang di
 * tempat lain opsional menjadi wajib:
 *
 *   1. NAMA DIPERIKSA, bukan sekadar hiasan. NIS tetap kuncinya, tetapi satu
 *      digit yang salah ketik bisa menunjuk santri lain yang benar-benar ada —
 *      dan tanpa pemeriksaan nama, saldonya terpotong tanpa satu pun tanda.
 *      Nama yang kosong pun ditolak: pengaman yang boleh dikosongkan bukan
 *      pengaman.
 *   2. SALDO KURANG BERARTI DILEWATI, bukan dipotong sebisanya. Kalau dipotong
 *      sebagian, hasilnya tidak lagi sama dengan berkas yang disetujui, dan
 *      selisihnya mudah terlewat.
 *   3. NIS KEMBAR dalam satu berkas ditolak keduanya. Dua baris atas nama
 *      orang yang sama hampir selalu salah salin, dan memotongnya dua kali
 *      jauh lebih mahal daripada meminta berkasnya dibetulkan.
 */
class ImporPotong
{
    use \App\Support\KolomTemplate;

    public const MAKS_BARIS = 1000;

    public const KOLOM = [
        ['kunci' => 'no', 'judul' => 'No', 'lebar' => 6],
        ['kunci' => 'nis', 'judul' => 'NIS', 'lebar' => 16],
        ['kunci' => 'nama', 'judul' => 'Nama Santri', 'lebar' => 30],
        // Ikut dituliskan tetapi TIDAK dibaca saat mengurai — ia untuk yang
        // mengisi berkasnya. Santri bisa naik kelas antara template diunduh
        // dan berkasnya diunggah; menolak baris karena itu akan terasa seperti
        // kesalahan sistem, padahal datanya benar.
        ['kunci' => 'kelas', 'judul' => 'Kelas', 'lebar' => 12],
        ['kunci' => 'nominal', 'judul' => 'Nominal Potong', 'lebar' => 18],
        ['kunci' => 'keterangan', 'judul' => 'Keterangan', 'lebar' => 40],
    ];

    /** Kolom dikenali dari JUDULNYA, bukan posisinya. */
    private const PETUNJUK = [
        'nis' => '/\bnis\b|nisn|induk/i',
        'kelas' => '/\bkelas\b|\bunit\b/i',
        'nominal' => '/nominal|potong|jumlah|rupiah|nilai/i',
        'keterangan' => '/keterangan|alasan|catatan|sebab/i',
        'nama' => '/nama/i',
        'no' => '/^no\.?$|nomor|urut/i',
    ];

    /**
     * Nama dibandingkan setelah diratakan: huruf kecil, tanda baca dibuang,
     * spasi ganda dirapatkan. "Cahyo  Nugroho," dan "cahyo nugroho" orang yang
     * sama; yang ingin ditangkap adalah nama yang benar-benar BERBEDA.
     */
    public static function ratakanNama(string $n): string
    {
        return trim(preg_replace('/\s+/u', ' ',
            preg_replace('/[^\p{L}\p{N} ]/u', '', mb_strtolower($n))));
    }

    /**
     * @return array{baris: array, ringkas: array}
     */
    public static function urai(string $isi, string $namaBerkas, string $sumber = 'kartu'): array
    {
        $sumber = PotongSaldo::sumberSah($sumber);
        $baris = self::barisDariBerkas($isi, $namaBerkas);
        ['peta' => $peta, 'kepala' => $kepala] = self::petakan($baris);

        $diBerkas = [];
        $hasil = [];
        for ($i = $kepala + 1; $i < count($baris); $i++) {
            $row = $baris[$i] ?? [];
            $sel = fn (string $k) => isset($peta[$k]) ? ($row[$peta[$k]] ?? null) : null;

            // NIS dibersihkan dari apa pun yang bukan angka: Excel kerap
            // menyimpannya sebagai bilangan dan menuliskannya "2024001.0".
            $nis = preg_replace('/\D/', '', ImporTopup::teks($sel('nis')));
            $namaBerkasBaris = trim(preg_replace('/\s+/u', ' ', ImporTopup::teks($sel('nama'))));
            $nominalTeks = ImporTopup::teks($sel('nominal'));
            if ($nis === '' && $namaBerkasBaris === '' && $nominalTeks === '') {
                continue;                       // baris kosong, dilewati diam-diam
            }
            if (count($hasil) >= self::MAKS_BARIS) {
                throw new ApiError(400, 'Maksimal ' . self::MAKS_BARIS . ' baris per berkas — pecah berkasnya');
            }

            $nominal = ImporTopup::nominal($sel('nominal'));
            $b = [
                'baris' => $i + 1, 'nis' => $nis, 'nama_berkas' => $namaBerkasBaris,
                'nama' => '', 'kelas' => '', 'siswa_id' => null,
                'nominal' => (int) ($nominal ?? 0), 'saldo' => 0, 'titipan' => 0,
                'dari_kartu' => 0, 'dari_titipan' => 0,
                'keterangan' => mb_substr(trim(ImporTopup::teks($sel('keterangan'))), 0, 200),
                'status' => 'gagal', 'pesan' => '',
            ];

            $s = $nis === '' ? null : Siswa::where('nis', $nis)->first();
            if ($s) {
                $b['siswa_id'] = $s->id;
                $b['nama'] = $s->nama;
                $b['kelas'] = $s->kelas;
                $b['saldo'] = (int) $s->saldo;
                $b['titipan'] = (int) $s->saldo_titipan;
            }

            if ($nis === '') {
                $b['pesan'] = 'NIS kosong';
            } elseif (! $s) {
                $b['pesan'] = "NIS {$nis} tidak terdaftar";
            } elseif ($namaBerkasBaris === '') {
                $b['pesan'] = 'Nama santri kosong — nama dipakai memeriksa NIS, jadi wajib diisi';
            } elseif (self::ratakanNama($namaBerkasBaris) !== self::ratakanNama((string) $s->nama)) {
                $b['pesan'] = "Nama tidak cocok — di sistem \"{$s->nama}\"";
            } elseif (isset($diBerkas[$nis])) {
                $b['pesan'] = "NIS kembar dengan baris {$diBerkas[$nis]}";
            } elseif ($nominal === null || $nominal <= 0) {
                $b['pesan'] = $nominalTeks === ''
                    ? 'Nominal potong kosong' : "Nominal \"{$nominalTeks}\" tidak valid";
            } else {
                // Pecahan kartu/titipan dihitung DI SINI juga, bukan hanya saat
                // diterapkan: angka yang disetujui orang di layar harus angka
                // yang benar-benar dijalankan.
                $bagi = PotongSaldo::bagi($s, $nominal, $sumber, false);
                if (! $bagi['cukup']) {
                    $punya = (int) $s->saldo + ($sumber === 'kartu' ? 0 : (int) $s->saldo_titipan);
                    $b['pesan'] = ($sumber === 'kartu' ? 'Saldo ' : 'Kartu + titipan ')
                        . Rupiah::f($punya) . ' — kurang ' . Rupiah::f($nominal - $punya);
                } else {
                    $b['dari_kartu'] = $bagi['kartu'];
                    $b['dari_titipan'] = $bagi['titipan'];
                    $b['status'] = 'siap';
                    $diBerkas[$nis] = $b['baris'];
                }
            }
            $hasil[] = $b;
        }

        if (! $hasil) {
            throw new ApiError(400, 'Berkas tidak memuat satu pun baris di bawah judul kolom');
        }

        // Baris kembar: yang PERTAMA pun ikut digagalkan. Kalau hanya yang
        // kedua ditolak, orang akan menyangka berkasnya sudah benar padahal
        // ada baris yang sengaja dihapus diam-diam oleh sistem.
        $kembar = [];
        foreach ($hasil as $b) {
            if ($b['status'] === 'gagal' && str_starts_with($b['pesan'], 'NIS kembar')) {
                $kembar[$b['nis']] = true;
            }
        }
        foreach ($hasil as &$b) {
            if ($b['status'] === 'siap' && isset($kembar[$b['nis']])) {
                $b['status'] = 'gagal';
                $b['pesan'] = 'NIS ini muncul lebih dari sekali di berkas';
            }
        }
        unset($b);

        $siap = array_filter($hasil, fn ($x) => $x['status'] === 'siap');

        return [
            'baris' => $hasil,
            'ringkas' => [
                'n_baris' => count($hasil),
                'n_siap' => count($siap),
                'n_gagal' => count($hasil) - count($siap),
                'total_potong' => array_sum(array_map(fn ($x) => $x['nominal'], $siap)),
                'total_kartu' => array_sum(array_map(fn ($x) => $x['dari_kartu'], $siap)),
                'total_titipan' => array_sum(array_map(fn ($x) => $x['dari_titipan'], $siap)),
                'sumber' => $sumber,
                'n_saldo_kurang' => count(array_filter($hasil,
                    fn ($x) => $x['status'] === 'gagal' && str_contains($x['pesan'], 'kurang'))),
            ],
        ];
    }

    // --------------------------------------------------------------- baca

    private static function barisDariBerkas(string $isi, string $nama): array
    {
        $tmp = tempnam(sys_get_temp_dir(), 'potong');
        file_put_contents($tmp, $isi);
        try {
            $xlsx = strlen($isi) > 2 && $isi[0] === 'P' && $isi[1] === 'K';
            if (! $xlsx && preg_match('/\.xls$/i', $nama)) {
                throw new ApiError(400, 'Format .xls lama belum didukung — simpan ulang sebagai .xlsx atau CSV');
            }
            $book = IOFactory::createReader($xlsx ? 'Xlsx' : 'Csv')->load($tmp);
            // Lembar "Petunjuk" dilewati: yang dipakai lembar pertama yang
            // punya judul kolom NIS & nominal.
            foreach ($book->getAllSheets() as $sheet) {
                $baris = $sheet->toArray(null, true, false, false);
                try {
                    self::petakan($baris);

                    return $baris;
                } catch (ApiError) {
                    continue;
                }
            }

            return $book->getSheet(0)->toArray(null, true, false, false);
        } catch (ApiError $e) {
            throw $e;
        } catch (\Throwable) {
            throw new ApiError(400, 'Berkas tidak terbaca — pastikan formatnya .xlsx atau CSV');
        } finally {
            @unlink($tmp);
        }
    }

    private static function petakan(array $baris): array
    {
        for ($i = 0; $i < min(count($baris), 25); $i++) {
            $teks = array_map([ImporTopup::class, 'teks'], $baris[$i] ?? []);
            $peta = [];
            foreach (self::PETUNJUK as $kunci => $pola) {
                foreach ($teks as $k => $t) {
                    if ($t !== '' && preg_match($pola, $t) && ! in_array($k, $peta, true)) {
                        $peta[$kunci] = $k;
                        break;
                    }
                }
            }
            if (isset($peta['nis'], $peta['nominal'])) {
                return ['peta' => $peta, 'kepala' => $i];
            }
        }

        throw new ApiError(400, 'Judul kolom tidak ditemukan — pastikan ada kolom NIS dan Nominal Potong');
    }
}
