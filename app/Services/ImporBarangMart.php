<?php

namespace App\Services;

use App\Exceptions\ApiError;
use App\Models\MartKategori;
use App\Models\MartStokMutasi;
use App\Models\Produk;
use App\Models\Tenant;
use App\Support\WaktuIso;
use Illuminate\Support\Facades\DB;
use PhpOffice\PhpSpreadsheet\Cell\DataValidation;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

/**
 * Tambah barang IMBOS Mart berombongan lewat berkas Excel.
 *
 * Bedanya dengan ImporMenu (pengajuan menu kantin): DI SINI TIDAK ADA AJUAN.
 * Mart unit usaha yayasan sendiri, jadi barangnya langsung jadi — sama seperti
 * menambah satu per satu lewat formulir. Yang ditambahkan pun lebih banyak:
 * SKU, harga modal, stok awal, dan ambang menipis, karena Mart melacak stok
 * sedangkan kantin tidak.
 *
 * Alurnya tetap dua langkah dan server TIDAK PERNAH mempercayai bacaan klien:
 * berkas dikirim ulang saat terapkan lalu diurai lagi dari nol. Yang dipakai
 * hanya hasil uraian kedua; pratinjau semata supaya orangnya bisa membatalkan
 * sebelum apa pun tersimpan.
 *
 * Impor ini hanya MENAMBAH. Barang yang namanya sudah ada dilewati, bukan
 * ditimpa — menimpa lewat berkas berarti harga seluruh rak bisa berubah tanpa
 * seorang pun melihat angka lamanya. Akibat sampingannya menyenangkan:
 * mengunggah berkas yang sama dua kali tidak pernah membuat barang kembar.
 */
class ImporBarangMart
{
    public const MAKS_BARIS = 500;

    public const KOLOM = [
        ['judul' => 'No', 'lebar' => 6],
        ['judul' => 'SKU / Barcode', 'lebar' => 20],
        ['judul' => 'Nama Barang', 'lebar' => 36],
        ['judul' => 'Kategori', 'lebar' => 22],
        ['judul' => 'Harga Beli (HPP)', 'lebar' => 18],
        ['judul' => 'Harga Jual', 'lebar' => 16],
        ['judul' => 'Stok Awal', 'lebar' => 12],
        ['judul' => 'Ambang Menipis', 'lebar' => 16],
        ['judul' => 'Jatah Santri (Makanan / Non-makanan)', 'lebar' => 34],
    ];

    /**
     * Kolom dikenali dari JUDULNYA, bukan posisinya — orang memindah kolom,
     * menyisipkan kolom catatan sendiri, atau menyalin dari berkas lamanya.
     *
     * Urutannya disengaja: yang paling khas diperiksa lebih dulu. "Harga Beli"
     * dan "Harga Jual" sama-sama memuat kata "harga", jadi kalau 'harga'
     * diperiksa duluan ia akan menyambar kolom harga beli.
     */
    private const PETUNJUK = [
        'barcode' => '/sku|barcode|bar code|kode/i',
        'kategori' => '/kategori|rak|etalase/i',
        'hpp' => '/beli|modal|hpp|pokok/i',
        'min' => '/ambang|menipis|minimal|reorder/i',
        'stok' => '/stok|persediaan|qty|jumlah/i',
        'jatah' => '/jatah|santri|non.?makanan/i',
        'harga' => '/jual|harga|rupiah/i',
        'nama' => '/nama|barang|produk|item/i',
    ];

    // ------------------------------------------------------------ template

    public static function template(Tenant $t): string
    {
        $kategori = MartKategori::where('tenant_id', $t->id)->where('aktif', 1)
            ->orderBy('urutan')->orderBy('id')->pluck('nama')->all();

        $book = new Spreadsheet;
        $sheet = $book->getActiveSheet();
        $sheet->setTitle('Barang');
        foreach (self::KOLOM as $k => $kol) {
            $sheet->setCellValue([$k + 1, 1], $kol['judul']);
            $sheet->getColumnDimensionByColumn($k + 1)->setWidth($kol['lebar']);
        }
        $sheet->getStyle('A1:I1')->getFont()->setBold(true);
        $sheet->freezePane('A2');
        for ($i = 1; $i <= 50; $i++) {
            $sheet->setCellValue([1, $i + 1], $i);
        }
        // SKU disimpan sebagai TEKS, bukan angka: barcode 13 digit yang dibaca
        // Excel sebagai bilangan berubah menjadi 8,99E+12 dan nol di depannya
        // hilang — dan yang hilang itu tidak bisa dipulihkan dari berkasnya.
        $sheet->getStyle('B2:B' . (self::MAKS_BARIS + 1))->getNumberFormat()->setFormatCode('@');

        $b = self::MAKS_BARIS + 1;
        $pasang = function (string $kol, string $tipe, ?string $op, string $f1, ?string $f2,
            string $pesan, string $galat) use ($sheet, $b) {
            $dv = $sheet->getCell("{$kol}2")->getDataValidation();
            $dv->setType($tipe);
            $dv->setErrorStyle(DataValidation::STYLE_STOP);
            $dv->setAllowBlank(true);
            $dv->setShowInputMessage(true);
            $dv->setShowErrorMessage(true);
            $dv->setShowDropDown($tipe === DataValidation::TYPE_LIST);
            if ($op) {
                $dv->setOperator($op);
            }
            $dv->setFormula1($f1);
            if ($f2 !== null) {
                $dv->setFormula2($f2);
            }
            $dv->setPrompt($pesan);
            $dv->setError($galat);
            $sheet->setDataValidation("{$kol}2:{$kol}{$b}", $dv);
        };

        $pasang('B', DataValidation::TYPE_TEXTLENGTH, DataValidation::OPERATOR_BETWEEN, '4', '32',
            'Angka/huruf barcode. Boleh dikosongkan — barang tetap bisa dicari lewat namanya.',
            'SKU/barcode 4–32 karakter angka atau huruf.');
        $pasang('C', DataValidation::TYPE_TEXTLENGTH, DataValidation::OPERATOR_BETWEEN, '2', '120',
            'Nama barang seperti yang akan terlihat di layar kasir.',
            'Nama barang 2–120 huruf.');
        // Daftar kategori hanya dipasang kalau sudah ada isinya: daftar kosong
        // membuat Excel menolak SETIAP isian di kolom itu.
        if ($kategori) {
            $pasang('D', DataValidation::TYPE_LIST, null,
                '"' . str_replace('"', "'", implode(',', $kategori)) . '"', null,
                'Pilih kategori etalase. Kategori baru boleh diketik sendiri — nanti dibuatkan.',
                'Pilih dari daftar, atau ketik nama kategori baru.');
        }
        $pasang('E', DataValidation::TYPE_WHOLE, DataValidation::OPERATOR_BETWEEN, '0', '100000000',
            'Harga beli per satuan, ANGKA saja. Contoh: 3500. Boleh dikosongkan kalau belum tahu.',
            'Harga beli harus angka bulat dan tidak negatif.');
        $pasang('F', DataValidation::TYPE_WHOLE, DataValidation::OPERATOR_BETWEEN, '1', '100000000',
            'Harga jual per satuan, ANGKA saja. Contoh: 5000.',
            'Harga jual harus angka bulat lebih dari nol.');
        $pasang('G', DataValidation::TYPE_WHOLE, DataValidation::OPERATOR_BETWEEN, '0', '1000000',
            'Jumlah barang yang ada di rak sekarang. Kosong dianggap 0.',
            'Stok awal harus angka bulat dan tidak negatif.');
        $pasang('H', DataValidation::TYPE_WHOLE, DataValidation::OPERATOR_BETWEEN, '0', '100000',
            'Stok di bawah atau sama dengan angka ini ditandai kuning "menipis". Kosong = 5.',
            'Ambang menipis harus angka bulat dan tidak negatif.');
        $pasang('I', DataValidation::TYPE_LIST, null, '"Makanan,Non-makanan"', null,
            'Menentukan jatah belanja harian santri mana yang terpakai saat dibayar dengan kartu.',
            'Pilih Makanan atau Non-makanan.');

        $p = $book->createSheet();
        $p->setTitle('Petunjuk');
        $p->getColumnDimension('A')->setWidth(118);
        $teks = [
            'Cara mengisi berkas tambah barang IMBOS Mart',
            '',
            '1. Satu baris untuk satu barang. Kolom No hanya penomoran, boleh diabaikan. Baris kosong dilewati.',
            '2. Jangan mengubah judul kolom — kolom dikenali dari judulnya, bukan dari urutannya.',
            '3. SKU / Barcode: boleh dikosongkan. Kalau diisi, 4–32 angka/huruf dan tidak boleh sama dengan barang lain.',
            '4. Nama Barang: 2–120 huruf. Nama yang SUDAH ADA di daftar barang Anda akan dilewati, tidak ditimpa.',
            '5. Kategori: kategori etalase untuk menyaring barang di kasir. Pilih dari daftar, atau ketik nama baru —',
            '   kategori yang belum ada akan dibuatkan otomatis, dan akan disebutkan di layar pratinjau.',
            '6. Harga Beli (HPP): modal per satuan. Boleh dikosongkan, tetapi tanpa ini laba & nilai stok tidak bisa dihitung.',
            '7. Harga Jual: wajib, ANGKA saja (5000, bukan Rp5.000).',
            '8. Stok Awal: jumlah yang ada di rak sekarang. Dicatat sebagai barang masuk, jadi ada jejaknya.',
            '9. Ambang Menipis: kosong berarti 5.',
            '10. Jatah Santri: Makanan atau Non-makanan. Ini BUKAN kategori etalase — ia menentukan jatah belanja',
            '    harian santri mana yang terpakai saat barang dibayar dengan kartu. Kosong dianggap Makanan.',
            '',
            'Maksimal ' . self::MAKS_BARIS . ' barang per berkas. Berkas dipratinjau dulu sebelum disimpan.',
            'Barang Mart TIDAK perlu persetujuan admin — begitu disimpan, ia langsung tampil di kasir.',
        ];
        foreach ($teks as $i => $x) {
            $p->setCellValue([1, $i + 1], $x);
        }
        $p->getStyle('A1')->getFont()->setBold(true)->setSize(14);
        $book->setActiveSheetIndex(0);

        $tmp = tempnam(sys_get_temp_dir(), 'martbrg');
        (new Xlsx($book))->save($tmp);
        $isi = file_get_contents($tmp);
        @unlink($tmp);

        return $isi;
    }

    // ---------------------------------------------------------------- urai

    public static function urai(string $isi, string $nama, Tenant $t): array
    {
        $baris = self::barisDariBerkas($isi, $nama);
        ['peta' => $peta, 'kepala' => $kepala] = self::petakan($baris);

        $sudahAda = Produk::where('tenant_id', $t->id)->pluck('nama')
            ->mapWithKeys(fn ($n) => [mb_strtolower($n) => true])->all();
        $skuAda = Produk::where('tenant_id', $t->id)->whereNotNull('barcode')
            ->pluck('nama', 'barcode')->all();
        $katAda = MartKategori::where('tenant_id', $t->id)->get()
            ->mapWithKeys(fn ($k) => [mb_strtolower($k->nama) => $k->nama])->all();

        $diBerkas = [];
        $skuBerkas = [];
        $katBaru = [];

        $hasil = [];
        for ($i = $kepala + 1; $i < count($baris); $i++) {
            $row = $baris[$i] ?? [];
            $sel = fn (string $k) => isset($peta[$k]) ? ($row[$peta[$k]] ?? null) : null;

            $namaBarang = trim(preg_replace('/\s+/u', ' ', ImporTopup::teks($sel('nama'))));
            $hargaTeks = ImporTopup::teks($sel('harga'));
            if ($namaBarang === '' && $hargaTeks === '') {
                continue;
            }
            if (count($hasil) >= self::MAKS_BARIS) {
                throw new ApiError(400, 'Maksimal ' . self::MAKS_BARIS . ' barang per berkas — pecah berkasnya');
            }

            $barcode = preg_replace('/[^0-9A-Za-z]/', '', ImporTopup::teks($sel('barcode')));
            $harga = ImporTopup::nominal($sel('harga'));
            $hppTeks = ImporTopup::teks($sel('hpp'));
            $hpp = $hppTeks === '' ? null : ImporTopup::nominal($sel('hpp'));
            $stokTeks = ImporTopup::teks($sel('stok'));
            $stok = $stokTeks === '' ? 0 : ImporTopup::nominal($sel('stok'));
            $minTeks = ImporTopup::teks($sel('min'));
            $min = $minTeks === '' ? 5 : ImporTopup::nominal($sel('min'));
            $katTeks = trim(preg_replace('/\s+/u', ' ', ImporTopup::teks($sel('kategori'))));
            $katKunci = mb_strtolower($katTeks);

            $b = [
                'baris' => $i + 1, 'nama' => $namaBarang, 'barcode' => $barcode ?: null,
                'kategori' => $katTeks === '' ? null : ($katAda[$katKunci] ?? $katTeks),
                'kategori_baru' => false,
                'hpp' => $hpp, 'harga' => (int) ($harga ?? 0),
                'stok' => (int) ($stok ?? 0), 'stok_min' => (int) ($min ?? 5),
                'jatah' => preg_match('/non/i', ImporTopup::teks($sel('jatah'))) ? 'non_makanan' : 'makanan',
                'status' => 'gagal', 'pesan' => '',
            ];
            $kunci = mb_strtolower($namaBarang);

            if ($namaBarang === '') {
                $b['pesan'] = 'Nama barang kosong';
            } elseif (mb_strlen($namaBarang) < 2) {
                $b['pesan'] = 'Nama barang minimal 2 huruf';
            } elseif (mb_strlen($namaBarang) > 120) {
                $b['pesan'] = 'Nama barang lebih dari 120 huruf';
            } elseif ($hargaTeks === '') {
                $b['pesan'] = 'Harga jual kosong';
            } elseif ($harga === null || $harga <= 0) {
                $b['pesan'] = "Harga jual \"{$hargaTeks}\" tidak valid";
            } elseif ($hppTeks !== '' && ($hpp === null || $hpp < 0)) {
                $b['pesan'] = "Harga beli \"{$hppTeks}\" tidak valid";
            } elseif ($stokTeks !== '' && ($stok === null || $stok < 0)) {
                $b['pesan'] = "Stok awal \"{$stokTeks}\" tidak valid";
            } elseif ($minTeks !== '' && ($min === null || $min < 0)) {
                $b['pesan'] = "Ambang menipis \"{$minTeks}\" tidak valid";
            } elseif ($barcode !== '' && (strlen($barcode) < 4 || strlen($barcode) > 32)) {
                $b['pesan'] = 'SKU/barcode harus 4–32 angka/huruf';
            } elseif (isset($sudahAda[$kunci])) {
                $b['pesan'] = 'Sudah ada — dilewati';
            } elseif (isset($diBerkas[$kunci])) {
                $b['pesan'] = "Nama kembar dengan baris {$diBerkas[$kunci]}";
            } elseif ($barcode !== '' && isset($skuAda[$barcode])) {
                $b['pesan'] = "SKU sudah dipakai \"{$skuAda[$barcode]}\"";
            } elseif ($barcode !== '' && isset($skuBerkas[$barcode])) {
                $b['pesan'] = "SKU kembar dengan baris {$skuBerkas[$barcode]}";
            } else {
                $b['status'] = 'siap';
                $diBerkas[$kunci] = $b['baris'];
                if ($barcode !== '') {
                    $skuBerkas[$barcode] = $b['baris'];
                }
                if ($katTeks !== '' && ! isset($katAda[$katKunci])) {
                    $b['kategori_baru'] = true;
                    $katBaru[$katKunci] = $katTeks;
                }
            }
            $hasil[] = $b;
        }

        if (! $hasil) {
            throw new ApiError(400, 'Berkas tidak memuat satu pun barang di bawah judul kolom');
        }
        $siap = array_filter($hasil, fn ($x) => $x['status'] === 'siap');

        return [
            'baris' => $hasil,
            'kategori_baru' => array_values($katBaru),
            'ringkas' => [
                'n_baris' => count($hasil),
                'n_siap' => count($siap),
                'n_gagal' => count($hasil) - count($siap),
                'n_berstok' => count(array_filter($siap, fn ($x) => $x['stok'] > 0)),
                'nilai_stok' => array_sum(array_map(fn ($x) => $x['stok'] * (int) ($x['hpp'] ?? 0), $siap)),
            ],
        ];
    }

    // -------------------------------------------------------------- simpan

    /** Baris yang siap disimpan menjadi barang jadi. Yang gagal tidak disentuh. */
    public static function terapkan(array $urai, Tenant $t, string $operator): int
    {
        $siap = array_values(array_filter($urai['baris'], fn ($x) => $x['status'] === 'siap'));
        if (! $siap) {
            throw new ApiError(400, 'Tidak ada baris yang siap disimpan');
        }

        return DB::transaction(function () use ($siap, $t, $operator) {
            $kat = MartKategori::where('tenant_id', $t->id)->get()
                ->mapWithKeys(fn ($k) => [mb_strtolower($k->nama) => $k->id])->all();
            $urutan = (int) MartKategori::where('tenant_id', $t->id)->max('urutan');

            foreach ($siap as $b) {
                $katId = null;
                if ($b['kategori']) {
                    $kunci = mb_strtolower($b['kategori']);
                    if (! isset($kat[$kunci])) {
                        $kat[$kunci] = MartKategori::create([
                            'tenant_id' => $t->id, 'nama' => mb_substr($b['kategori'], 0, 40),
                            'urutan' => ++$urutan, 'aktif' => true,
                            'created_at' => WaktuIso::sekarang(),
                        ])->id;
                    }
                    $katId = $kat[$kunci];
                }

                $p = Produk::create([
                    'tenant_id' => $t->id, 'nama' => $b['nama'], 'barcode' => $b['barcode'] ?: null,
                    'kategori_id' => $katId, 'kategori' => $b['jatah'],
                    'hpp' => $b['hpp'], 'harga' => $b['harga'],
                    'stok' => $b['stok'], 'stok_min' => $b['stok_min'], 'aktif' => true,
                ]);
                // Stok awal dicatat sebagai barang masuk, bukan diam-diam
                // ditulis ke kolom stok: nanti saat opname menemukan selisih,
                // orang harus bisa menelusuri dari mana angka awalnya datang.
                if ($p->stok > 0) {
                    MartStokMutasi::create([
                        'tenant_id' => $t->id, 'produk_id' => $p->id, 'jenis' => 'masuk',
                        'qty' => $p->stok, 'stok_sesudah' => $p->stok,
                        'nilai' => $p->stok * (int) ($b['hpp'] ?? 0), 'ref' => '',
                        'keterangan' => 'stok awal dari impor Excel',
                        'operator' => $operator, 'created_at' => WaktuIso::sekarang(),
                    ]);
                }
            }

            return count($siap);
        });
    }

    // --------------------------------------------------------------- baca

    private static function barisDariBerkas(string $isi, string $nama): array
    {
        $tmp = tempnam(sys_get_temp_dir(), 'martbrg');
        file_put_contents($tmp, $isi);
        try {
            $xlsx = strlen($isi) > 2 && $isi[0] === 'P' && $isi[1] === 'K';
            if (! $xlsx && preg_match('/\.xls$/i', $nama)) {
                throw new ApiError(400, 'Format .xls lama belum didukung — simpan ulang sebagai .xlsx atau CSV');
            }
            $book = IOFactory::createReader($xlsx ? 'Xlsx' : 'Csv')->load($tmp);
            // Lembar "Petunjuk" dilewati: yang dipakai lembar pertama yang
            // punya judul kolom nama & harga.
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
            if (isset($peta['nama'], $peta['harga'])) {
                return ['peta' => $peta, 'kepala' => $i];
            }
        }

        throw new ApiError(400, 'Judul kolom tidak ditemukan — pastikan ada kolom Nama Barang dan Harga Jual');
    }
}
