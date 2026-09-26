<?php

namespace App\Services;

use App\Exceptions\ApiError;
use App\Models\Produk;
use App\Models\ProdukAjuan;
use App\Models\Tenant;
use PhpOffice\PhpSpreadsheet\Cell\DataValidation;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

/**
 * Pengajuan menu/barang lewat berkas Excel — untuk koperasi yang barangnya
 * puluhan sampai ratusan, yang kalau diketik satu per satu makan waktu berjam-jam.
 *
 * Hasilnya tetap AJUAN, bukan barang jadi: aturan "tenant mengusulkan, admin
 * memutuskan" tidak berubah hanya karena usulannya datang berombongan. Semua
 * ajuan dari satu berkas diikat satu `paket`, supaya admin bisa memutusnya
 * sekaligus.
 *
 * Sama seperti top up massal, alurnya dua langkah dan server tidak pernah
 * mempercayai bacaan klien: berkas dikirim ulang saat terapkan dan diurai lagi.
 */
class ImporMenu
{
    public const MAKS_BARIS = 500;

    public const KOLOM = [
        ['judul' => 'No', 'lebar' => 6],
        ['judul' => 'Nama Barang', 'lebar' => 40],
        ['judul' => 'Harga', 'lebar' => 14],
        ['judul' => 'Kategori (Makanan / Non-makanan)', 'lebar' => 30],
    ];

    /** Kolom dikenali dari JUDULNYA, bukan posisinya. */
    private const PETUNJUK = [
        'nama' => '/nama|barang|menu|produk|item/i',
        'harga' => '/harga|rupiah|nominal/i',
        'kategori' => '/kategori|jenis|golongan/i',
    ];

    // ------------------------------------------------------------ template

    public static function template(Tenant $t): string
    {
        $koperasi = $t->jenis === 'koperasi';
        $maks = (int) (Pengaturan::sekolah()['max_per_transaksi'] ?? 0);

        $book = new Spreadsheet;
        $sheet = $book->getActiveSheet();
        $sheet->setTitle('Barang');
        $kolom = $koperasi ? self::KOLOM : array_slice(self::KOLOM, 0, 3);
        foreach ($kolom as $k => $kol) {
            $sheet->setCellValue([$k + 1, 1], $kol['judul']);
            $sheet->getColumnDimensionByColumn($k + 1)->setWidth($kol['lebar']);
        }
        $akhir = $koperasi ? 'D' : 'C';
        $sheet->getStyle("A1:{$akhir}1")->getFont()->setBold(true);
        $sheet->freezePane('A2');
        for ($i = 1; $i <= 50; $i++) {
            $sheet->setCellValue([1, $i + 1], $i);
        }

        $b = self::MAKS_BARIS + 1;
        $pasang = function (string $kol, string $tipe, ?string $op, string $f1, ?string $f2, string $pesan, string $galat) use ($sheet, $b) {
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
        $pasang('B', DataValidation::TYPE_TEXTLENGTH, DataValidation::OPERATOR_BETWEEN, '1', '60',
            'Nama barang seperti yang akan terlihat di kasir, maksimal 60 huruf.',
            'Nama barang maksimal 60 huruf.');
        $pasang('C', DataValidation::TYPE_WHOLE, DataValidation::OPERATOR_BETWEEN, '1', (string) $maks,
            'Harga dalam ANGKA saja, tanpa "Rp" dan titik. Contoh: 5000.',
            'Harga harus angka bulat antara 1 dan ' . $maks . ' (batas satu transaksi).');
        if ($koperasi) {
            $pasang('D', DataValidation::TYPE_LIST, null, '"Makanan,Non-makanan"', null,
                'Usulan kategori. Keputusan akhirnya tetap di admin.',
                'Pilih Makanan atau Non-makanan.');
        }

        $p = $book->createSheet();
        $p->setTitle('Petunjuk');
        $p->getColumnDimension('A')->setWidth(110);
        $teks = array_values(array_filter([
            'Cara mengisi berkas pengajuan barang',
            '',
            '1. Satu baris untuk satu barang. Kolom No hanya penomoran, boleh diabaikan. Baris kosong dilewati.',
            '2. Jangan mengubah judul kolom — kolom dikenali dari judulnya.',
            '3. Nama Barang: seperti yang akan terlihat di layar kasir, maksimal 60 huruf. Nama yang sudah ada di daftar menu Anda, atau yang masih menunggu putusan, akan dilewati.',
            '4. Harga: ANGKA saja, contoh 5000 (bukan Rp5.000). Paling tinggi ' . Rupiah::f($maks) . ' (batas satu transaksi).',
            $koperasi ? '5. Kategori: Makanan atau Non-makanan. Ini USULAN — admin sekolah yang menetapkan kategori akhirnya, karena barang non-makanan memakai jatah belanja harian tersendiri.' : null,
            'Maksimal ' . self::MAKS_BARIS . ' barang per berkas. Setelah diunggah, berkas dipratinjau dulu; barang baru masuk daftar kasir setelah admin menyetujui.',
        ], fn ($x) => $x !== null));
        foreach ($teks as $i => $t) {
            $p->setCellValue([1, $i + 1], $t);
        }
        $p->getStyle('A1')->getFont()->setBold(true)->setSize(14);
        $book->setActiveSheetIndex(0);

        $tmp = tempnam(sys_get_temp_dir(), 'menu');
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

        $koperasi = $t->jenis === 'koperasi';
        $maks = (int) (Pengaturan::sekolah()['max_per_transaksi'] ?? 0);

        // Nama yang sudah ada dan yang masih diajukan, dibandingkan tanpa
        // membedakan besar-kecil huruf — sama dengan pemeriksaan satu per satu.
        $sudahAda = Produk::where('tenant_id', $t->id)->pluck('nama')
            ->mapWithKeys(fn ($n) => [mb_strtolower($n) => true])->all();
        $diajukan = ProdukAjuan::where('tenant_id', $t->id)->where('status', 'menunggu')
            ->where('jenis', 'baru')->pluck('nama')
            ->mapWithKeys(fn ($n) => [mb_strtolower($n) => true])->all();
        $diBerkas = [];

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

            $katTeks = ImporTopup::teks($sel('kategori'));
            $b = [
                'baris' => $i + 1, 'nama' => $namaBarang, 'harga' => 0,
                'kategori' => ($koperasi && preg_match('/non/i', $katTeks)) ? 'non_makanan' : 'makanan',
                'status' => 'gagal', 'pesan' => '',
            ];
            $kunci = mb_strtolower($namaBarang);
            $harga = ImporTopup::nominal($sel('harga'));

            if ($namaBarang === '') {
                $b['pesan'] = 'Nama barang kosong';
            } elseif (mb_strlen($namaBarang) > 60) {
                $b['pesan'] = 'Nama barang lebih dari 60 huruf';
            } elseif ($harga === null || $harga <= 0) {
                $b['pesan'] = "Harga \"{$hargaTeks}\" tidak valid";
            } elseif ($harga > $maks) {
                $b['pesan'] = 'Harga melebihi batas satu transaksi (' . Rupiah::f($maks) . ')';
            } elseif (isset($sudahAda[$kunci])) {
                $b['pesan'] = 'Sudah ada di daftar menu Anda';
            } elseif (isset($diajukan[$kunci])) {
                $b['pesan'] = 'Sudah diajukan dan masih menunggu putusan';
            } elseif (isset($diBerkas[$kunci])) {
                $b['pesan'] = "Nama kembar dengan baris {$diBerkas[$kunci]}";
            } else {
                $b['status'] = 'siap';
                $diBerkas[$kunci] = $b['baris'];
            }
            $b['harga'] = (int) ($harga ?? 0);
            $hasil[] = $b;
        }

        if (! $hasil) {
            throw new ApiError(400, 'Berkas tidak memuat satu pun barang di bawah judul kolom');
        }
        $siap = array_filter($hasil, fn ($x) => $x['status'] === 'siap');

        return [
            'baris' => $hasil,
            'ringkas' => [
                'n_baris' => count($hasil),
                'n_siap' => count($siap),
                'n_gagal' => count($hasil) - count($siap),
                'n_non_makanan' => count(array_filter($siap, fn ($x) => $x['kategori'] === 'non_makanan')),
            ],
        ];
    }

    private static function barisDariBerkas(string $isi, string $nama): array
    {
        $tmp = tempnam(sys_get_temp_dir(), 'menu');
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

        throw new ApiError(400, 'Judul kolom tidak ditemukan — pastikan ada kolom Nama Barang dan Harga');
    }
}
