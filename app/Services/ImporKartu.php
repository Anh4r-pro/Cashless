<?php

namespace App\Services;

use App\Exceptions\ApiError;
use App\Models\Golongan;
use App\Models\Siswa;
use App\Models\UnitSdm;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;

/**
 * Pembacaan berkas pendaftaran kolektif pemegang kartu (Excel/CSV).
 *
 * Polanya sengaja disamakan dengan top up massal: dua langkah (pratinjau lalu
 * terapkan), berkas diurai ULANG di server pada langkah kedua, dan sidik
 * SHA-256 mengikat bahwa yang diterapkan memang berkas yang tadi diperiksa.
 *
 * Perlindungan dari penerapan ganda datang dari NIS/NIY itu sendiri: nomor yang
 * sudah terdaftar ditolak sebagai baris gagal, sehingga berkas yang sama
 * diunggah dua kali menghasilkan nol baris baru — bukan ratusan kartu kembar.
 * Karena itu NIS/NIY di sini WAJIB, tidak seperti pada penambahan satuan.
 */
class ImporKartu
{
    public const MAKS_BARIS = 1000;

    public const JENIS = ['santri', 'sdm'];

    /**
     * Susunan kolom per jenis berkas. `judul` inilah yang dicetak di template
     * dan dicari saat membaca — kolom dikenali dari judulnya, bukan posisinya,
     * karena operator sering menyisipkan atau menggeser kolom.
     */
    public const KOLOM = [
        'santri' => [
            ['kunci' => 'no', 'judul' => 'No', 'lebar' => 6],
            ['kunci' => 'nis', 'judul' => 'NIS', 'lebar' => 18],
            ['kunci' => 'nama', 'judul' => 'Nama Santri', 'lebar' => 32],
            ['kunci' => 'kelas', 'judul' => 'Kelas', 'lebar' => 14],
            ['kunci' => 'nisn', 'judul' => 'NISN (opsional)', 'lebar' => 18],
            ['kunci' => 'lahir', 'judul' => 'Tanggal Lahir (Format DD/MM/YYYY)', 'lebar' => 28],
            ['kunci' => 'hp', 'judul' => 'No HP Wali (opsional)', 'lebar' => 22],
        ],
        'sdm' => [
            ['kunci' => 'no', 'judul' => 'No', 'lebar' => 6],
            ['kunci' => 'nis', 'judul' => 'NIY/Nomor Pegawai', 'lebar' => 24],
            ['kunci' => 'nama', 'judul' => 'Nama Personil', 'lebar' => 32],
            ['kunci' => 'kelas', 'judul' => 'Unit', 'lebar' => 18],
            ['kunci' => 'golongan', 'judul' => 'Golongan', 'lebar' => 20],
            ['kunci' => 'lahir', 'judul' => 'Tanggal Lahir (Format DD/MM/YYYY)', 'lebar' => 28],
            ['kunci' => 'jabatan', 'judul' => 'Jabatan / Keterangan (opsional)', 'lebar' => 32],
            ['kunci' => 'berlaku', 'judul' => 'Berlaku Sampai (DD/MM/YYYY, opsional)', 'lebar' => 32],
        ],
    ];

    /**
     * Pola pengenal judul kolom. URUTANNYA BERARTI: kunci yang lebih khas
     * diperiksa lebih dulu, karena satu judul hanya boleh diklaim satu kunci.
     * Tanpa itu "NISN" akan direbut oleh pola NIS, dan "No HP Wali" oleh pola No.
     */
    private const PETUNJUK = [
        'santri' => [
            'nisn' => '/nisn/i',
            'lahir' => '/lahir/i',
            'hp' => '/\bhp\b|telepon|telp|ponsel|whatsapp|\bwa\b/i',
            'nis' => '/\bnis\b|induk/i',
            'nama' => '/nama/i',
            'kelas' => '/kelas|rombel/i',
            'no' => '/^no\.?$|nomor urut|urut/i',
        ],
        'sdm' => [
            'lahir' => '/lahir/i',
            'nis' => '/niy|pegawai|\bnip\b|\bnis\b|induk/i',
            'nama' => '/nama/i',
            'golongan' => '/golongan|kategori/i',
            'jabatan' => '/jabatan|keterangan/i',
            'berlaku' => '/berlaku|masa\s*aktif|sampai/i',
            'kelas' => '/unit|bagian|divisi|bidang/i',
            'no' => '/^no\.?$|nomor urut|urut/i',
        ],
    ];

    public static function wajibJenis(?string $j): string
    {
        $j = strtolower(trim((string) $j));
        if (! in_array($j, self::JENIS, true)) {
            throw new ApiError(400, 'Jenis impor harus "santri" atau "sdm"');
        }

        return $j;
    }

    /** Label yang dipakai di pesan galat, mengikuti istilah tiap layar. */
    public static function istilah(string $jenis): array
    {
        return $jenis === 'sdm'
            ? ['orang' => 'personil', 'nomor' => 'NIY/Nomor Pegawai', 'unit' => 'Unit']
            : ['orang' => 'santri', 'nomor' => 'NIS', 'unit' => 'Kelas'];
    }

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

        return ['isi' => $isi, 'nama' => mb_substr(trim((string) ($b['nama_berkas'] ?? 'kartu.xlsx')), 0, 120)];
    }

    public static function sidik(string $isi): string
    {
        return hash('sha256', $isi);
    }

    // -------------------------------------------------------------- baca

    private static function barisDariBerkas(string $isi, string $nama, string $jenis): array
    {
        $tmp = tempnam(sys_get_temp_dir(), 'kartu');
        file_put_contents($tmp, $isi);

        try {
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

            // Lembar "Petunjuk" tidak berisi data; yang dipakai adalah lembar
            // pertama yang benar-benar punya kolom nomor induk.
            foreach ($book->getAllSheets() as $sheet) {
                $baris = $sheet->toArray(null, true, false, false);
                foreach ($baris as $row) {
                    foreach ($row as $sel) {
                        if (preg_match(self::PETUNJUK[$jenis]['nis'], self::teks($sel))) {
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

    private static function petakanKolom(array $baris, string $jenis): array
    {
        $ist = self::istilah($jenis);
        $batas = min(count($baris), 25);

        for ($i = 0; $i < $batas; $i++) {
            $teks = array_map([self::class, 'teks'], $baris[$i] ?? []);

            $adaNis = false;
            $adaNama = false;
            foreach ($teks as $t) {
                $adaNis = $adaNis || preg_match(self::PETUNJUK[$jenis]['nis'], $t);
                $adaNama = $adaNama || preg_match(self::PETUNJUK[$jenis]['nama'], $t);
            }
            if (! $adaNis || ! $adaNama) {
                continue;
            }

            $peta = [];
            foreach (self::PETUNJUK[$jenis] as $kunci => $pola) {
                foreach ($teks as $k => $t) {
                    if ($t !== '' && preg_match($pola, $t) && ! in_array($k, $peta, true)) {
                        $peta[$kunci] = $k;
                        break;
                    }
                }
            }

            return ['peta' => $peta, 'barisKepala' => $i];
        }

        throw new ApiError(400, "Judul kolom tidak ditemukan — pastikan ada kolom {$ist['nomor']} dan Nama");
    }

    // ------------------------------------------------------------- urai

    /**
     * Memeriksa seluruh baris tanpa menyentuh basis data.
     *
     * Nomor induk diperiksa terhadap dua hal sekaligus: yang sudah ada di basis
     * data, dan yang muncul lebih dulu di berkas yang sama. Yang kedua mudah
     * terlewat dan akibatnya baru terlihat setelah ratusan kartu tercipta.
     */
    public static function urai(string $isi, string $nama, string $jenis): array
    {
        ['baris' => $baris, 'lembar' => $lembar] = self::barisDariBerkas($isi, $nama, $jenis);
        ['peta' => $peta, 'barisKepala' => $barisKepala] = self::petakanKolom($baris, $jenis);

        $ist = self::istilah($jenis);
        $golongan = Golongan::get()->keyBy('kode');
        $golBawaan = $jenis === 'sdm'
            ? ($golongan->first(fn ($g) => $g->kode !== 'siswa' && $g->aktif)?->kode ?? 'sdm')
            : 'siswa';
        $unitSdm = $jenis === 'sdm' ? UnitSdm::aktifNama() : [];

        $nisDipakai = [];      // nomor induk yang sudah muncul di berkas ini
        $nisnDipakai = [];
        $hasil = [];

        for ($i = $barisKepala + 1; $i < count($baris); $i++) {
            $row = $baris[$i] ?? [];
            $sel = fn (string $k) => isset($peta[$k]) ? ($row[$peta[$k]] ?? null) : null;

            $nis = preg_replace('/\s+/', '', self::teks($sel('nis')));
            $namaOrang = preg_replace('/\s+/', ' ', self::teks($sel('nama')));
            $kelas = strtoupper(self::teks($sel('kelas')));

            // Baris kosong — termasuk sisa baris di bawah tabel — dilewati diam-diam.
            if ($nis === '' && $namaOrang === '' && $kelas === '') {
                continue;
            }
            if (count($hasil) >= self::MAKS_BARIS) {
                throw new ApiError(400, 'Maksimal ' . self::MAKS_BARIS . ' baris per berkas — pecah berkasnya');
            }

            $b = [
                'baris' => $i + 1,          // nomor baris seperti terlihat di Excel
                'nis' => $nis, 'nama' => $namaOrang, 'kelas' => $kelas,
                'nisn' => '', 'tgl_lahir' => '', 'hp_ortu' => '',
                'golongan' => $golBawaan, 'jabatan' => '', 'berlaku_sampai' => '',
                'status' => 'gagal', 'pesan' => '',
            ];
            $gagal = function (string $pesan) use (&$b, &$hasil) {
                $b['status'] = 'gagal';
                $b['pesan'] = $pesan;
                $hasil[] = $b;
            };

            if ($namaOrang === '') {
                $gagal('Nama kosong');

                continue;
            }
            if ($nis === '') {
                $gagal("{$ist['nomor']} kosong — wajib diisi pada pendaftaran kolektif");

                continue;
            }
            if (mb_strlen($nis) > 20) {
                $gagal("{$ist['nomor']} lebih dari 20 karakter");

                continue;
            }
            if (isset($nisDipakai[$nis])) {
                $gagal("{$ist['nomor']} {$nis} kembar dengan baris {$nisDipakai[$nis]} di berkas ini");

                continue;
            }
            if ($bentrok = Siswa::where('nis', $nis)->first()) {
                $gagal("{$ist['nomor']} {$nis} sudah terdaftar atas nama {$bentrok->nama}");

                continue;
            }
            if ($kelas === '') {
                $gagal("{$ist['unit']} kosong");

                continue;
            }

            // Tanggal lahir = kunci masuk portal, untuk santri MAUPUN personil.
            $lahirTeks = self::teks($sel('lahir'));
            if ($lahirTeks !== '') {
                $tgl = self::tanggal($sel('lahir'));
                if ($tgl === '') {
                    $gagal("Tanggal lahir \"{$lahirTeks}\" tidak terbaca — pakai format DD/MM/YYYY");

                    continue;
                }
                if ($tgl > Kartu::hariIni()) {
                    $gagal("Tanggal lahir {$tgl} masih di masa depan");

                    continue;
                }
                $b['tgl_lahir'] = $tgl;
            }

            if ($jenis === 'sdm') {
                if (! in_array($kelas, array_map('strtoupper', $unitSdm), true)) {
                    $gagal("Unit \"{$kelas}\" tidak dikenal — pilih salah satu dari: " .
                        implode(', ', $unitSdm));

                    continue;
                }
                // Huruf besar dipulihkan ke bentuk resmi daftarnya, supaya tabel
                // tidak berisi campuran "SMP" dan "Smp" yang menyulitkan saringan.
                foreach ($unitSdm as $u) {
                    if (strtoupper($u) === $kelas) {
                        $b['kelas'] = $u;
                    }
                }

                $kodeGol = trim((string) self::teks($sel('golongan')));
                if ($kodeGol !== '') {
                    $g = $golongan->first(fn ($x) => strcasecmp($x->kode, $kodeGol) === 0
                        || strcasecmp($x->nama, $kodeGol) === 0);
                    if (! $g) {
                        $gagal("Golongan \"{$kodeGol}\" tidak terdaftar");

                        continue;
                    }
                    if ($g->kode === 'siswa') {
                        $gagal('Golongan "' . $g->nama . '" adalah golongan santri — ' .
                            'pakai impor menu Santri untuk itu');

                        continue;
                    }
                    if (! $g->aktif) {
                        $gagal("Golongan \"{$g->nama}\" sedang nonaktif");

                        continue;
                    }
                    $b['golongan'] = $g->kode;
                }

                $b['jabatan'] = mb_substr(self::teks($sel('jabatan')), 0, 120);

                $berlakuTeks = self::teks($sel('berlaku'));
                if ($berlakuTeks !== '') {
                    $tgl = self::tanggal($sel('berlaku'));
                    if ($tgl === '') {
                        $gagal("Berlaku sampai \"{$berlakuTeks}\" tidak terbaca — pakai format DD/MM/YYYY");

                        continue;
                    }
                    $b['berlaku_sampai'] = $tgl;
                }

                // Hanya golongan yang portalnya sudah dibuka yang butuh tanggal
                // lahir; golongan lain tak perlu diganggu peringatan.
                if ($b['tgl_lahir'] === '' && ($golongan[$b['golongan']]->portal_ortu ?? 0)) {
                    $b['pesan'] = 'Tanggal lahir kosong — belum bisa masuk portal SIMCASHTREN';
                }
            } else {
                $nisn = preg_replace('/\D/', '', self::teks($sel('nisn')));
                if ($nisn !== '') {
                    if (strlen($nisn) < 4 || strlen($nisn) > 20) {
                        $gagal("NISN {$nisn} harus 4–20 angka");

                        continue;
                    }
                    if (isset($nisnDipakai[$nisn])) {
                        $gagal("NISN {$nisn} kembar dengan baris {$nisnDipakai[$nisn]} di berkas ini");

                        continue;
                    }
                    if ($bentrok = Siswa::where('nisn', $nisn)->first()) {
                        $gagal("NISN {$nisn} sudah dipakai {$bentrok->nama}");

                        continue;
                    }
                    $b['nisn'] = $nisn;
                }

                $hp = preg_replace('/\D/', '', self::teks($sel('hp')));
                if ($hp !== '') {
                    if (strlen($hp) < 8 || strlen($hp) > 15) {
                        $gagal("No HP wali \"{$hp}\" harus 8–15 angka");

                        continue;
                    }
                    $b['hp_ortu'] = $hp;
                }

                // Tanpa tanggal lahir, wali tidak akan bisa masuk portal. Bukan
                // kesalahan — kartunya tetap berfungsi — tapi ditandai supaya
                // admin tahu ada pekerjaan susulan.
                if ($b['tgl_lahir'] === '') {
                    $b['pesan'] = 'Tanggal lahir kosong — wali belum bisa masuk portal orang tua';
                }
            }

            $nisDipakai[$nis] = $b['baris'];
            if (($b['nisn'] ?? '') !== '') {
                $nisnDipakai[$b['nisn']] = $b['baris'];
            }
            $b['status'] = 'siap';
            $hasil[] = $b;
        }

        if (! $hasil) {
            throw new ApiError(400, 'Berkas tidak memuat satu pun baris data di bawah judul kolom');
        }

        $siap = array_values(array_filter($hasil, fn ($r) => $r['status'] === 'siap'));
        $perUnit = [];
        foreach ($siap as $r) {
            $perUnit[$r['kelas']] = ($perUnit[$r['kelas']] ?? 0) + 1;
        }
        ksort($perUnit);

        return [
            'jenis' => $jenis,
            'lembar' => $lembar,
            'baris' => $hasil,
            'ringkas' => [
                'n_baris' => count($hasil),
                'n_siap' => count($siap),
                'n_gagal' => count($hasil) - count($siap),
                'n_catatan' => count(array_filter($siap, fn ($r) => $r['pesan'] !== '')),
                'per_unit' => $perUnit,
            ],
        ];
    }

    // ---------------------------------------------------------- pembaca sel

    public static function teks($v): string
    {
        return $v === null ? '' : trim((string) $v);
    }

    private static function tanggal($v): string
    {
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
            return checkdate((int) $m[2], (int) $m[3], (int) $m[1])
                ? sprintf('%s-%02d-%02d', $m[1], (int) $m[2], (int) $m[3]) : '';
        }
        if (! preg_match('#^(\d{1,2})[/.-](\d{1,2})[/.-](\d{2,4})$#', $s, $m)) {
            return '';
        }
        [$hari, $bulan, $th] = [(int) $m[1], (int) $m[2], $m[3]];
        $tahun = strlen($th) === 2 ? '20' . $th : $th;
        if (! checkdate($bulan, $hari, (int) $tahun)) {
            return '';
        }

        return sprintf('%s-%02d-%02d', $tahun, $bulan, $hari);
    }
}
