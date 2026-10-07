<?php

namespace App\Services;

use App\Exceptions\ApiError;
use App\Models\Siswa;
use App\Models\TopupImpor;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;

/**
 * Pembacaan berkas top up massal (Excel/CSV) dari bendahara.
 *
 * Server TIDAK PERNAH mempercayai hasil bacaan klien: berkasnya dikirim dua kali
 * (pratinjau lalu terapkan) dan diurai ulang di sini kedua kalinya, dengan sidik
 * SHA-256 sebagai pengikat bahwa keduanya berkas yang sama. Dengan begitu admin
 * tidak mungkin memeriksa satu berkas lalu menerapkan berkas lain.
 */
class ImporTopup
{
    use \App\Support\KolomTemplate;

    public const MAKS_BARIS = 1000;

    /** Tanggal terima lebih tua dari ini hampir pasti salah ketik. */
    public const MUNDUR_MAKS_HARI = 365;

    public const METODE = ['tunai' => 'Tunai', 'transfer' => 'Transfer Bank', 'qris' => 'QRIS'];

    public const KOLOM = [
        ['kunci' => 'no', 'judul' => 'No', 'lebar' => 6],
        ['kunci' => 'nis', 'judul' => 'NIS', 'lebar' => 16],
        ['kunci' => 'nama', 'judul' => 'Nama Santri', 'lebar' => 30],
        // Kelas ikut dituliskan walau TIDAK dibaca saat mengurai: ia untuk
        // yang mengisi berkasnya — menyaring, mengurutkan, dan memecah per
        // kelas di Excel. Tidak diperiksa karena santri bisa naik kelas antara
        // template diunduh dan berkasnya diunggah, dan menolak baris karena
        // itu hanya akan membuat orang curiga pada sistemnya, bukan pada
        // berkasnya.
        ['kunci' => 'kelas', 'judul' => 'Kelas', 'lebar' => 12],
        ['kunci' => 'nominal', 'judul' => 'Nominal Topup', 'lebar' => 18],
        ['kunci' => 'tanggal', 'judul' => 'Tanggal Uang Diterima (Format DD/MM/YYYY)', 'lebar' => 26],
        ['kunci' => 'metode', 'judul' => 'Metode Pembayaran (Tunai, Transfer Bank, dan QRIS)', 'lebar' => 30],
    ];

    /** Kolom dikenali dari JUDULNYA, bukan posisinya — bendahara sering menggeser kolom. */
    private const PETUNJUK = [
        'nis' => '/\bnis\b|nisn|induk/i',
        'nama' => '/nama/i',
        'kelas' => '/\bkelas\b|\bunit\b/i',
        'nominal' => '/nominal|jumlah|rupiah|nilai|saldo/i',
        'tanggal' => '/tanggal|tgl|diterima/i',
        'metode' => '/metode|cara|bayar|pembayaran/i',
        'no' => '/^no\.?$|nomor|urut/i',
    ];

    public static function berkasDariBody(array $b): array
    {
        $mentah = (string) ($b['data'] ?? $b['berkas_data'] ?? '');
        $base64 = str_contains($mentah, ',') ? substr($mentah, strpos($mentah, ',') + 1) : $mentah;
        if (trim($base64) === '') {
            throw new ApiError(400, 'Berkas belum dipilih');
        }
        $isi = base64_decode($base64, true);
        if ($isi === false || $isi === '') {
            throw new ApiError(400, 'Berkas tidak terbaca');
        }
        if (strlen($isi) > 4 * 1024 * 1024) {
            throw new ApiError(413, 'Berkas maksimal 4MB');
        }

        return ['isi' => $isi, 'nama' => mb_substr(trim((string) ($b['nama_berkas'] ?? 'topup.xlsx')), 0, 120)];
    }

    public static function sidik(string $isi): string
    {
        return hash('sha256', $isi);
    }

    /**
     * Impor terdahulu yang isinya sama persis.
     *
     * batch_id saja tidak cukup melindungi: halaman admin membuat batch_id baru
     * setiap kali berkas diunggah, jadi berkas yang sama diunggah dua kali akan
     * lolos sebagai dua batch berbeda dan menambah saldo dua kali. Sidik isi
     * berkaslah yang mengenali kejadian itu.
     */
    public static function seisiSama(string $sidik): ?TopupImpor
    {
        return TopupImpor::where('sidik', $sidik)->orderByDesc('id')->first();
    }

    // -------------------------------------------------------------- baca

    private static function barisDariBerkas(string $isi, string $nama): array
    {
        $tmp = tempnam(sys_get_temp_dir(), 'topup');
        file_put_contents($tmp, $isi);

        try {
            // "PK" = arsip ZIP, yang berarti .xlsx.
            $xlsx = strlen($isi) > 2 && $isi[0] === 'P' && $isi[1] === 'K';
            if (! $xlsx && preg_match('/\.xls$/i', $nama)) {
                throw new ApiError(400, 'Format .xls lama belum didukung — simpan ulang sebagai .xlsx atau CSV');
            }

            $reader = IOFactory::createReader($xlsx ? 'Xlsx' : 'Csv');
            $reader->setReadDataOnly(false);
            $book = $reader->load($tmp);

            if (! $xlsx) {
                return ['baris' => $book->getSheet(0)->toArray(null, true, false, false), 'lembar' => 'CSV'];
            }

            // Lembar "Petunjuk" pada template tidak berisi data; yang dipakai
            // adalah lembar pertama yang punya kolom NIS.
            foreach ($book->getAllSheets() as $sheet) {
                $baris = $sheet->toArray(null, true, false, false);
                foreach ($baris as $row) {
                    foreach ($row as $sel) {
                        if (preg_match(self::PETUNJUK['nis'], self::teks($sel))) {
                            return ['baris' => $baris, 'lembar' => $sheet->getTitle()];
                        }
                    }
                }
            }
            $s0 = $book->getSheet(0);

            return ['baris' => $s0->toArray(null, true, false, false), 'lembar' => $s0->getTitle()];
        } finally {
            @unlink($tmp);
        }
    }

    /** Cari baris judul dan petakan tiap kolom yang dikenali ke indeksnya. */
    private static function petakanKolom(array $baris): array
    {
        $batas = min(count($baris), 25);
        for ($i = 0; $i < $batas; $i++) {
            $teks = array_map([self::class, 'teks'], $baris[$i] ?? []);

            $adaNis = false;
            $adaNominal = false;
            foreach ($teks as $t) {
                $adaNis = $adaNis || preg_match(self::PETUNJUK['nis'], $t);
                $adaNominal = $adaNominal || preg_match(self::PETUNJUK['nominal'], $t);
            }
            if (! $adaNis || ! $adaNominal) {
                continue;
            }

            $peta = [];
            foreach (self::PETUNJUK as $kunci => $pola) {
                foreach ($teks as $k => $t) {
                    if ($t !== '' && preg_match($pola, $t) && ! in_array($k, $peta, true)) {
                        $peta[$kunci] = $k;
                        break;
                    }
                }
            }

            return ['peta' => $peta, 'barisKepala' => $i];
        }

        throw new ApiError(400, 'Judul kolom tidak ditemukan — pastikan ada kolom NIS dan Nominal Topup');
    }

    // ------------------------------------------------------------- urai

    /**
     * @param bool $pungutBiaya admin memilih "biaya admin" untuk seluruh berkas:
     *   tiap baris dipotong tarif manual (BiayaAdmin::untukManual) sebelum
     *   sisanya dibagi ke kartu/titipan. Satu pilihan per berkas, bukan kolom
     *   per baris — lebih sedikit peluang salah isi di Excel.
     */
    public static function urai(string $isi, string $nama, bool $abaikanNama, bool $keTitipan, bool $pungutBiaya = false): array
    {
        ['baris' => $baris, 'lembar' => $lembar] = self::barisDariBerkas($isi, $nama);
        ['peta' => $peta, 'barisKepala' => $barisKepala] = self::petakanKolom($baris);

        $cfgSekolah = Pengaturan::sekolah();
        $hariIni = Kartu::hariIni();
        $paling = (new \DateTime($hariIni))->modify('-' . self::MUNDUR_MAKS_HARI . ' days')->format('Y-m-d');

        // Simulasi saldo & titipan per santri: dua baris untuk santri yang sama
        // harus menampilkan pembagian yang benar, bukan dua kali perhitungan
        // dari saldo awal.
        $bayangan = [];
        $hasil = [];
        $nisTerlihat = [];

        for ($i = $barisKepala + 1; $i < count($baris); $i++) {
            $row = $baris[$i] ?? [];
            $sel = fn (string $k) => isset($peta[$k]) ? ($row[$peta[$k]] ?? null) : null;

            $nisTeks = preg_replace('/\s+/', '', self::teks($sel('nis')));
            $namaBerkas = self::teks($sel('nama'));
            $nominalTeks = self::teks($sel('nominal'));

            // Baris kosong (termasuk sisa baris di bawah tabel) dilewati diam-diam.
            if ($nisTeks === '' && $namaBerkas === '' && $nominalTeks === '') {
                continue;
            }
            if (count($hasil) >= self::MAKS_BARIS) {
                throw new ApiError(400, 'Maksimal ' . self::MAKS_BARIS . ' baris per berkas — pecah berkasnya');
            }

            $b = [
                'baris' => $i + 1,        // nomor baris seperti terlihat di Excel
                'nis' => $nisTeks, 'nama_berkas' => $namaBerkas,
                'jumlah' => 0, 'metode' => '', 'tgl_terima' => '',
                'siswa_id' => null, 'nama_db' => '', 'kelas' => '',
                'saldo' => null, 'titipan' => null,
                'ke_saldo' => 0, 'ke_titipan' => 0, 'biaya' => 0, 'bersih' => 0,
                'status' => 'gagal', 'pesan' => '',
            ];
            $gagal = function (string $pesan) use (&$b, &$hasil) {
                $b['status'] = 'gagal';
                $b['pesan'] = $pesan;
                $hasil[] = $b;
            };

            if ($nisTeks === '') {
                $gagal('NIS kosong');

                continue;
            }
            $s = self::cariSiswa($nisTeks);
            if (! $s) {
                $gagal("NIS {$nisTeks} tidak terdaftar");

                continue;
            }
            $b['siswa_id'] = $s->id;
            $b['nama_db'] = $s->nama;
            $b['kelas'] = $s->kelas;
            // Batas DITIMPA golongan santrinya — persis seperti jalur top up
            // satuan (Setoran::terima). Memakai angka sekolah untuk semua baris
            // pernah membuat guru/SDM yang golongannya diberi plafon lebih besar
            // (mis. max_topup_harian Rp1.000.000) tetap dipotong di angka
            // sekolah (Rp300.000), dan kelebihannya nyasar ke titipan padahal
            // golongannya mengizinkan seluruhnya masuk kartu.
            $cfg = Pengaturan::untuk($s, $cfgSekolah);

            $jumlah = self::nominal($sel('nominal'));
            if ($jumlah === null || $jumlah <= 0) {
                $gagal("Nominal \"{$nominalTeks}\" tidak valid");

                continue;
            }
            if ($jumlah % 100 !== 0) {
                $gagal('Nominal ' . Rupiah::f($jumlah) . ' bukan kelipatan Rp100');

                continue;
            }
            $b['jumlah'] = $jumlah;

            $tgl = self::tanggal($sel('tanggal'));
            if ($tgl === '') {
                $gagal('Tanggal "' . self::teks($sel('tanggal')) . '" tidak terbaca — pakai format DD/MM/YYYY');

                continue;
            }
            if ($tgl > $hariIni) {
                $gagal("Tanggal {$tgl} masih di masa depan");

                continue;
            }
            if ($tgl < $paling) {
                $gagal("Tanggal {$tgl} lebih dari " . self::MUNDUR_MAKS_HARI . ' hari yang lalu');

                continue;
            }
            $b['tgl_terima'] = $tgl;

            $metode = self::metode($sel('metode'));
            if ($metode === '') {
                $gagal('Metode "' . self::teks($sel('metode')) . '" tidak dikenal — tulis Tunai, Transfer Bank, atau QRIS');

                continue;
            }
            $b['metode'] = $metode;

            if ($namaBerkas !== '' && self::seragam($namaBerkas) !== self::seragam($s->nama)) {
                $b['nama_beda'] = true;
                if (! $abaikanNama) {
                    $gagal("Nama pada berkas \"{$namaBerkas}\" berbeda dengan data NIS {$s->nis} (\"{$s->nama}\")");

                    continue;
                }
                $b['pesan'] = "Nama berbeda (\"{$namaBerkas}\" vs \"{$s->nama}\") — diterapkan atas persetujuan admin";
            }

            if ($s->status !== 'aktif') {
                // Kartu blokir tetap boleh MENERIMA uang: justru harus, supaya
                // setoran yang sudah diterima tercatat. Bedanya, semuanya masuk
                // titipan karena kartunya tidak boleh dipakai.
                $b['pesan'] = $b['pesan'] ?: 'Kartu diblokir — seluruh nominal masuk titipan';
            }

            $biaya = $pungutBiaya ? BiayaAdmin::untukManual($s, $jumlah) : 0;
            if ($biaya > 0 && $biaya >= $jumlah) {
                $gagal('Nominal ' . Rupiah::f($jumlah) . ' tidak lebih besar dari biaya admin ' . Rupiah::f($biaya));

                continue;
            }
            $b['biaya'] = $biaya;
            $b['bersih'] = $bersih = $jumlah - $biaya;

            if (! isset($bayangan[$s->id])) {
                $bayangan[$s->id] = [
                    'saldo' => (int) $s->saldo, 'titipan' => (int) $s->saldo_titipan,
                    'topupHariIni' => Rekap::topupHariIni($s->id),
                ];
            }
            $bay = &$bayangan[$s->id];

            $ruang = $s->status === 'aktif'
                ? max(0, min($cfg['max_saldo'] - $bay['saldo'], $cfg['max_topup_harian'] - $bay['topupHariIni']))
                : 0;
            $bolehTitipan = $keTitipan && ($cfg['titipan_aktif'] ?? 0) === 1;

            $b['saldo'] = $bay['saldo'];
            $b['titipan'] = $bay['titipan'];
            $b['ke_saldo'] = $bolehTitipan ? min($bersih, $ruang) : $bersih;
            $b['ke_titipan'] = $bersih - $b['ke_saldo'];

            if (! $bolehTitipan && $b['ke_saldo'] > $ruang) {
                $gagal('Melebihi kapasitas kartu (ruang ' . Rupiah::f($ruang) . ') — ' .
                    'nyalakan penyimpanan titipan agar kelebihannya diterima');

                continue;
            }
            if ($b['ke_titipan'] > 0 && $bay['titipan'] + $b['ke_titipan'] > $cfg['max_titipan']) {
                $gagal('Titipan akan menjadi ' . Rupiah::f($bay['titipan'] + $b['ke_titipan']) .
                    ', melebihi batas ' . Rupiah::f($cfg['max_titipan']));

                continue;
            }

            $bay['saldo'] += $b['ke_saldo'];
            $bay['titipan'] += $b['ke_titipan'];
            $bay['topupHariIni'] += $b['ke_saldo'];
            unset($bay);

            // NIS kembar bukan kesalahan (orang tua bisa menyetor dua kali dalam
            // satu rekap), tapi ditandai supaya admin bisa memastikan itu memang
            // disengaja.
            if (isset($nisTerlihat[$nisTeks])) {
                $b['nis_kembar'] = $nisTerlihat[$nisTeks];
            } else {
                $nisTerlihat[$nisTeks] = $b['baris'];
            }

            $b['status'] = 'siap';
            $hasil[] = $b;
        }

        if (! $hasil) {
            throw new ApiError(400, 'Berkas tidak memuat satu pun baris data di bawah judul kolom');
        }

        $siap = array_values(array_filter($hasil, fn ($r) => $r['status'] === 'siap'));
        $perMetode = [];
        foreach (array_keys(self::METODE) as $k) {
            $perMetode[$k] = ['n' => 0, 'total' => 0];
        }
        foreach ($siap as $r) {
            $perMetode[$r['metode']]['n']++;
            $perMetode[$r['metode']]['total'] += $r['jumlah'];
        }

        return [
            'lembar' => $lembar,
            'baris' => $hasil,
            'ringkas' => [
                'n_baris' => count($hasil),
                'n_siap' => count($siap),
                'n_gagal' => count($hasil) - count($siap),
                'n_nama_beda' => count(array_filter($hasil, fn ($r) => $r['nama_beda'] ?? false)),
                'n_kembar' => count(array_filter($hasil, fn ($r) => $r['nis_kembar'] ?? false)),
                'total' => array_sum(array_column($siap, 'jumlah')),
                'total_saldo' => array_sum(array_column($siap, 'ke_saldo')),
                'total_titipan' => array_sum(array_column($siap, 'ke_titipan')),
                'total_biaya' => array_sum(array_column($siap, 'biaya')),
                'per_metode' => $perMetode,
            ],
        ];
    }

    // ---------------------------------------------------------- pembaca sel

    public static function teks($v): string
    {
        return $v === null ? '' : trim((string) $v);
    }

    /**
     * Nominal dari sel: menerima angka asli maupun teks "Rp50.000", "50,000",
     * "50.000,00". Titik & spasi dianggap pemisah ribuan; apa pun setelah koma
     * dianggap sen dan dibuang — kantin tidak pernah memakai pecahan rupiah.
     */
    public static function nominal($v): ?int
    {
        if (is_int($v) || is_float($v)) {
            return (int) floor($v);
        }
        $s = trim(preg_replace('/rp/i', '', self::teks($v)));
        if ($s === '') {
            return null;
        }
        $s = preg_replace('/[.\s]/', '', explode(',', $s)[0]);

        return preg_match('/^-?\d+$/', $s) ? (int) $s : null;
    }

    private static function tanggal($v): string
    {
        // Excel menyimpan tanggal sebagai angka hari sejak 1899-12-30.
        if (is_int($v) || is_float($v)) {
            if ($v < 1 || $v > 100000) {
                return '';
            }

            return ExcelDate::excelToDateTimeObject(round($v))->format('Y-m-d');
        }

        $s = self::teks($v);
        if ($s === '') {
            return '';
        }
        if (preg_match('/^(\d{4})-(\d{1,2})-(\d{1,2})/', $s, $m)) {
            return sprintf('%s-%02d-%02d', $m[1], (int) $m[2], (int) $m[3]);
        }
        if (! preg_match('#^(\d{1,2})[/.-](\d{1,2})[/.-](\d{2,4})$#', $s, $m)) {
            return '';
        }
        [$hari, $bulan, $th] = [(int) $m[1], (int) $m[2], $m[3]];
        $tahun = strlen($th) === 2 ? '20' . $th : $th;
        if ($hari < 1 || $hari > 31 || $bulan < 1 || $bulan > 12) {
            return '';
        }
        // Tanggal seperti 31/02/2026 lolos pemeriksaan di atas tapi tidak ada di kalender.
        if (! checkdate($bulan, $hari, (int) $tahun)) {
            return '';
        }

        return sprintf('%s-%02d-%02d', $tahun, $bulan, $hari);
    }

    private static function metode($v): string
    {
        $s = mb_strtolower(self::teks($v));
        if ($s === '') {
            return '';
        }
        if (preg_match('/tunai|cash|kas/', $s)) {
            return 'tunai';
        }
        if (preg_match('/qris|qr/', $s)) {
            return 'qris';
        }
        if (preg_match('/transfer|tf|bank|rekening/', $s)) {
            return 'transfer';
        }

        return '';
    }

    /** Bandingkan nama tanpa mempersoalkan gelar, spasi ganda, huruf besar, atau titik. */
    private static function seragam(?string $s): string
    {
        return trim(preg_replace('/[^a-z0-9]+/', ' ', mb_strtolower((string) $s)));
    }

    private static function cariSiswa(string $nis): ?Siswa
    {
        $tepat = Siswa::where('nis', $nis)->first() ?? Siswa::where('nisn', $nis)->first();
        if ($tepat) {
            return $tepat;
        }
        if (! preg_match('/^\d+$/', $nis)) {
            return null;
        }
        // Nol di depan sering hilang saat Excel menyimpan NIS sebagai angka.
        $kandidat = Siswa::whereRaw('CAST(nis AS UNSIGNED) = ?', [(int) $nis])
            ->where('nis', '!=', '')->limit(2)->get();

        // Dua kandidat = ambigu, jangan menebak: uangnya bisa masuk ke kartu anak lain.
        return $kandidat->count() === 1 ? $kandidat->first() : null;
    }
}
