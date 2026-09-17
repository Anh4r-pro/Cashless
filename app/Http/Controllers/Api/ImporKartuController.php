<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\ApiError;
use App\Models\Golongan;
use App\Models\Siswa;
use App\Services\BidangSiswa;
use App\Services\ImporKartu;
use App\Support\WaktuIso;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Cell\DataValidation;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

/**
 * Pendaftaran kolektif pemegang kartu dari berkas Excel/CSV.
 *
 * Melayani dua layar dengan satu mesin: menu Santri (`jenis=santri`) dan menu
 * SDM & Personil (`jenis=sdm`). Yang berbeda hanya susunan kolom dan istilahnya
 * — aturan pemeriksaannya satu, sehingga tidak ada dua tempat yang bisa
 * bergeser sendiri-sendiri.
 *
 * Hak aksesnya pun terpisah: `santri.impor` dan `sdm.impor` adalah dua sakelar
 * berbeda, jadi admin data santri bisa diberi satu tanpa yang lain.
 */
class ImporKartuController extends Controller
{
    public function template(Request $r)
    {
        $jenis = ImporKartu::wajibJenis($r->query('jenis'));
        $this->wajibIzin($r, "{$jenis}.impor");

        $kolom = ImporKartu::KOLOM[$jenis];
        $ist = ImporKartu::istilah($jenis);

        $book = new Spreadsheet;
        $sheet = $book->getActiveSheet();
        $sheet->setTitle($jenis === 'sdm' ? 'SDM' : 'Santri');

        foreach ($kolom as $k => $kol) {
            $sheet->setCellValue([$k + 1, 1], $kol['judul']);
            $sheet->getColumnDimensionByColumn($k + 1)->setWidth($kol['lebar']);
        }
        $akhirKolom = chr(ord('A') + count($kolom) - 1);
        $sheet->getStyle("A1:{$akhirKolom}1")->getFont()->setBold(true);
        $sheet->freezePane('A2');

        // Formulir kosong tetap diberi 50 baris bernomor supaya terbaca sebagai
        // borang siap isi, bukan satu baris judul yang menggantung.
        $nBaris = 50;
        for ($i = 1; $i <= $nBaris; $i++) {
            $sheet->setCellValue([1, $i + 1], $i);
        }

        $this->validasi($sheet, $jenis, $nBaris + 1);
        $this->lembarPetunjuk($book, $jenis, $ist);

        $tmp = tempnam(sys_get_temp_dir(), 'tplkartu');
        (new Xlsx($book))->save($tmp);
        $isi = file_get_contents($tmp);
        @unlink($tmp);

        $namaFile = 'template-' . ($jenis === 'sdm' ? 'sdm-personil' : 'santri') . '-' . date('Y-m-d') . '.xlsx';

        return response($isi, 200, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Content-Disposition' => "attachment; filename=\"{$namaFile}\"",
        ]);
    }

    /** Langkah 1: periksa seluruh baris. Tidak ada satu pun data yang dibuat. */
    public function pratinjau(Request $r)
    {
        $jenis = ImporKartu::wajibJenis($r->input('jenis'));
        $this->wajibIzin($r, "{$jenis}.impor");

        ['isi' => $isi, 'nama' => $nama] = ImporKartu::berkasDariBody($r->all());
        $hasil = ImporKartu::urai($isi, $nama, $jenis);

        return response()->json(array_merge([
            'ok' => true, 'nama_berkas' => $nama, 'sidik' => ImporKartu::sidik($isi),
        ], $hasil));
    }

    /** Langkah 2: buat barisnya. */
    public function terapkan(Request $r)
    {
        $jenis = ImporKartu::wajibJenis($r->input('jenis'));
        $this->wajibIzin($r, "{$jenis}.impor");

        ['isi' => $isi, 'nama' => $nama] = ImporKartu::berkasDariBody($r->all());

        // Berkas yang diterapkan harus benar-benar berkas yang tadi dipratinjau.
        $sidik = ImporKartu::sidik($isi);
        if ($r->input('sidik') && (string) $r->input('sidik') !== $sidik) {
            throw new ApiError(409, 'Berkas berubah sejak dipratinjau — ulangi pratinjau dulu');
        }

        $urai = ImporKartu::urai($isi, $nama, $jenis);
        $siap = array_values(array_filter($urai['baris'], fn ($b) => $b['status'] === 'siap'));
        if (! $siap) {
            throw new ApiError(400, 'Tidak ada baris yang siap diterapkan — perbaiki berkasnya dulu');
        }

        $hasil = DB::transaction(function () use ($siap, $jenis) {
            $dibuat = [];
            $gagal = [];

            foreach ($siap as $b) {
                // Diperiksa ULANG di dalam transaksi: antara pratinjau dan
                // penerapan, nomor yang sama bisa saja sudah dipakai admin lain
                // atau oleh baris yang baru dibuat beberapa iterasi tadi.
                if ($bentrok = Siswa::where('nis', $b['nis'])->first()) {
                    $gagal[] = array_merge($b, [
                        'status' => 'gagal',
                        'pesan' => "Sudah terdaftar atas nama {$bentrok->nama} saat berkas diterapkan",
                    ]);

                    continue;
                }
                if ($b['nisn'] !== '' && Siswa::where('nisn', $b['nisn'])->exists()) {
                    $gagal[] = array_merge($b, [
                        'status' => 'gagal',
                        'pesan' => "NISN {$b['nisn']} sudah dipakai saat berkas diterapkan",
                    ]);

                    continue;
                }

                $s = Siswa::create([
                    'nis' => $b['nis'],
                    'nama' => $b['nama'],
                    'kelas' => $b['kelas'],
                    'nisn' => $b['nisn'],
                    'tgl_lahir' => $b['tgl_lahir'],
                    'hp_ortu' => $b['hp_ortu'],
                    'golongan' => $b['golongan'],
                    'jabatan' => $b['jabatan'],
                    'berlaku_sampai' => $b['berlaku_sampai'],
                    'created_at' => WaktuIso::sekarang(),
                ])->fresh();

                $dibuat[] = array_merge($b, ['siswa_id' => $s->id]);
            }

            return ['dibuat' => $dibuat, 'gagal' => $gagal];
        });

        return response()->json([
            'ok' => true,
            'jenis' => $jenis,
            'nama_berkas' => $nama,
            'n_dibuat' => count($hasil['dibuat']),
            'n_gagal' => count($hasil['gagal']) + $urai['ringkas']['n_gagal'],
            'dibuat' => $hasil['dibuat'],
            // Baris yang gagal saat diterapkan digabung dengan yang sudah gagal
            // sejak pratinjau, supaya satu daftar memuat semua yang perlu
            // diperbaiki — bukan dua daftar yang harus disatukan sendiri.
            'gagal' => array_merge(
                $hasil['gagal'],
                array_values(array_filter($urai['baris'], fn ($b) => $b['status'] === 'gagal')),
            ),
        ]);
    }

    // ------------------------------------------------------------ template

    /**
     * Pemeriksa isi sel pada template.
     *
     * Aturannya sengaja SEPAKAT dengan penilaian server: template yang lebih
     * ketat menolak data yang sebenarnya sah, sedangkan yang lebih longgar
     * meloloskan baris yang nanti gagal — dan operator baru tahu setelah
     * mengisi ratusan baris.
     */
    private function validasi($sheet, string $jenis, int $barisAkhir): void
    {
        $b = max(2, $barisAkhir);
        $kolom = ImporKartu::KOLOM[$jenis];
        $huruf = [];
        foreach ($kolom as $i => $k) {
            $huruf[$k['kunci']] = chr(ord('A') + $i);
        }

        $bentukTanggal = fn (string $c) => implode(',', [
            "LEN({$c}2)=10", "MID({$c}2,3,1)=\"/\"", "MID({$c}2,6,1)=\"/\"",
            "ISNUMBER(VALUE(LEFT({$c}2,2)))", "ISNUMBER(VALUE(MID({$c}2,4,2)))", "ISNUMBER(VALUE(RIGHT({$c}2,4)))",
            "VALUE(LEFT({$c}2,2))>=1", "VALUE(LEFT({$c}2,2))<=31",
            "VALUE(MID({$c}2,4,2))>=1", "VALUE(MID({$c}2,4,2))<=12",
        ]);

        $ist = ImporKartu::istilah($jenis);
        $aturan = [
            ['no', DataValidation::TYPE_WHOLE, DataValidation::OPERATOR_BETWEEN, '1', (string) $b,
                'Nomor urut', 'Hanya penomoran, boleh dibiarkan apa adanya.',
                'Nomor tidak valid', 'Kolom No hanya boleh berisi angka urut.'],

            ['nis', DataValidation::TYPE_TEXTLENGTH, DataValidation::OPERATOR_BETWEEN, '3', '20',
                $ist['nomor'],
                "{$ist['nomor']} WAJIB diisi dan harus khas (3–20 karakter). Nomor yang sudah terdaftar akan ditolak.",
                "{$ist['nomor']} tidak valid",
                "{$ist['nomor']} harus 3–20 karakter dan tidak boleh kosong."],

            ['nama', DataValidation::TYPE_TEXTLENGTH, DataValidation::OPERATOR_BETWEEN, '2', '120',
                'Nama lengkap', 'Nama wajib diisi, 2–120 karakter.',
                'Nama tidak valid', 'Nama wajib diisi, 2–120 karakter.'],
        ];

        if ($jenis === 'sdm') {
            $aturan[] = ['kelas', DataValidation::TYPE_LIST, null,
                '"' . implode(',', BidangSiswa::UNIT_SDM) . '"', null,
                'Unit', 'Pilih dari daftar: ' . implode(', ', BidangSiswa::UNIT_SDM) . '.',
                'Unit tidak dikenal', 'Pilih salah satu dari daftar yang tersedia.'];

            $gol = Golongan::where('kode', '!=', 'siswa')->where('aktif', 1)
                ->orderBy('urutan')->orderBy('id')->pluck('kode')->all();
            if ($gol) {
                $aturan[] = ['golongan', DataValidation::TYPE_LIST, null,
                    '"' . implode(',', $gol) . '"', null,
                    'Golongan', 'Boleh dikosongkan — akan memakai golongan "' . $gol[0] .
                        '". Golongan menentukan batas belanja kartu.',
                    'Golongan tidak dikenal', 'Pilih salah satu golongan yang terdaftar, atau kosongkan.'];
            }
            $aturan[] = ['berlaku', DataValidation::TYPE_CUSTOM, null,
                'OR(' . $huruf['berlaku'] . '2="",AND(' . $bentukTanggal($huruf['berlaku']) . '))', null,
                'Berlaku sampai', 'Boleh dikosongkan (= tanpa batas). Kalau diisi: DD/MM/YYYY, mis. 31/12/2027.',
                'Format tanggal salah', 'Tulis DD/MM/YYYY, mis. 31/12/2027, atau kosongkan.'];
        } else {
            $aturan[] = ['kelas', DataValidation::TYPE_TEXTLENGTH, DataValidation::OPERATOR_BETWEEN, '1', '20',
                'Kelas', 'Wajib diisi, mis. 7A. Ditulis huruf besar secara otomatis.',
                'Kelas tidak valid', 'Kelas wajib diisi, maksimal 20 karakter.'];

            $aturan[] = ['nisn', DataValidation::TYPE_CUSTOM, null,
                'OR(' . $huruf['nisn'] . '2="",AND(ISNUMBER(VALUE(' . $huruf['nisn'] . '2)),LEN(' . $huruf['nisn'] . '2)>=4,LEN(' . $huruf['nisn'] . '2)<=20))', null,
                'NISN (opsional)', 'Boleh dikosongkan. Kalau diisi: 4–20 angka, dan harus khas.',
                'NISN tidak valid', 'NISN harus 4–20 angka, atau dikosongkan.'];

            $aturan[] = ['lahir', DataValidation::TYPE_CUSTOM, null,
                'OR(' . $huruf['lahir'] . '2="",AND(' . $bentukTanggal($huruf['lahir']) . '))', null,
                'Tanggal lahir', 'Format DD/MM/YYYY dengan nol di depan, mis. 05/08/2012. ' .
                    'Dipakai wali untuk masuk portal orang tua — kosong berarti wali belum bisa masuk.',
                'Format tanggal salah', 'Tulis DD/MM/YYYY, mis. 05/08/2012, atau kosongkan.'];

            $aturan[] = ['hp', DataValidation::TYPE_CUSTOM, null,
                'OR(' . $huruf['hp'] . '2="",AND(LEN(' . $huruf['hp'] . '2)>=8,LEN(' . $huruf['hp'] . '2)<=15))', null,
                'No HP wali (opsional)', 'Boleh dikosongkan. Angka saja, 8–15 digit, mis. 081234567890.',
                'Nomor HP tidak valid', 'Nomor HP 8–15 angka, atau dikosongkan.'];
        }

        foreach ($aturan as [$kunci, $tipe, $operator, $f1, $f2, $judulPesan, $pesan, $judulGalat, $galat]) {
            if (! isset($huruf[$kunci])) {
                continue;
            }
            $c = $huruf[$kunci];
            $dv = $sheet->getCell("{$c}2")->getDataValidation();
            $dv->setType($tipe);
            $dv->setErrorStyle(DataValidation::STYLE_STOP);
            // Baris sisa di bawah tabel memang kosong dan tidak boleh dilaporkan
            // sebagai kesalahan.
            $dv->setAllowBlank(true);
            $dv->setShowInputMessage(true);
            $dv->setShowErrorMessage(true);
            $dv->setShowDropDown($tipe === DataValidation::TYPE_LIST);
            if ($operator) {
                $dv->setOperator($operator);
            }
            $dv->setFormula1($f1);
            if ($f2 !== null) {
                $dv->setFormula2($f2);
            }
            $dv->setPromptTitle($judulPesan);
            $dv->setPrompt($pesan);
            $dv->setErrorTitle($judulGalat);
            $dv->setError($galat);
            $sheet->setDataValidation("{$c}2:{$c}{$b}", $dv);
        }

        // Nomor induk ditulis sebagai TEKS: nol di depan hilang kalau Excel
        // memperlakukannya sebagai angka, dan nomor itulah kunci pencocokannya.
        $kolNis = $huruf['nis'];
        for ($i = 2; $i <= $b; $i++) {
            $sheet->setCellValueExplicit("{$kolNis}{$i}", '', DataType::TYPE_STRING);
        }
        $sheet->getStyle("{$kolNis}2:{$kolNis}{$b}")
            ->getNumberFormat()->setFormatCode('@');
    }

    private function lembarPetunjuk(Spreadsheet $book, string $jenis, array $ist): void
    {
        $sheet = $book->createSheet();
        $sheet->setTitle('Petunjuk');
        $sheet->getColumnDimension('A')->setWidth(115);

        $umum = [
            'Cara mengisi berkas pendaftaran ' . $ist['orang'] . ' kolektif',
            '',
            '1. Satu baris untuk satu ' . $ist['orang'] . '. Kolom No hanya penomoran, boleh diabaikan.',
            '2. Jangan mengubah atau menghapus baris judul — kolom dikenali dari judulnya, bukan dari urutannya.',
            '3. ' . $ist['nomor'] . ' WAJIB diisi dan harus khas. Nomor yang sudah terdaftar akan ditolak, ' .
                'sehingga berkas yang tidak sengaja diunggah dua kali tidak membuat kartu kembar.',
            '4. Nama wajib diisi.',
            '5. ' . $ist['unit'] . ' wajib diisi.',
        ];

        $khusus = $jenis === 'sdm'
            ? [
                '6. Unit harus salah satu dari: ' . implode(', ', BidangSiswa::UNIT_SDM) . '.',
                '7. Golongan boleh dikosongkan — kartu akan memakai golongan SDM bawaan. Golongan menentukan ' .
                    'batas saldo dan belanja kartu, jadi isilah kalau personil ini memang bukan pegawai biasa.',
                '8. Berlaku Sampai boleh dikosongkan (= tanpa batas waktu). Kalau diisi, pakai DD/MM/YYYY.',
                '9. Kartu NFC TIDAK didaftarkan lewat berkas ini. Setelah data masuk, tempelkan kartu satu per ' .
                    'satu lewat tombol "kartu" di tabel SDM & Personil.',
            ]
            : [
                '6. NISN boleh dikosongkan. Kalau diisi harus 4–20 angka dan khas.',
                '7. Tanggal Lahir memakai DD/MM/YYYY dengan nol di depan, mis. 05/08/2012. Tanggal lahir inilah ' .
                    'yang dipakai wali untuk masuk Portal Orang Tua — baris tanpa tanggal lahir tetap dibuat, ' .
                    'tapi walinya belum bisa masuk sampai tanggalnya diisi.',
                '8. No HP Wali boleh dikosongkan. Angka saja, 8–15 digit.',
                '9. PIN kios bawaan setiap santri adalah NIS-nya. Mintalah santri menggantinya lewat kios.',
                '10. Kartu NFC TIDAK didaftarkan lewat berkas ini. Setelah data masuk, tempelkan kartu satu per ' .
                    'satu lewat tombol "kartu" di tabel Santri.',
            ];

        $penutup = [
            '',
            'Simpan sebagai .xlsx (atau CSV), lalu unggah di menu yang sama. Berkas akan DIPERIKSA lebih dulu — ' .
                'data baru dibuat setelah Anda menekan tombol Terapkan.',
            'Langkah pratinjau di aplikasi adalah pemeriksa yang sebenarnya: baris yang salah dilaporkan satu ' .
                'per satu di sana, lengkap dengan nomor barisnya seperti yang terlihat di Excel.',
            'Maksimal ' . ImporKartu::MAKS_BARIS . ' baris per berkas. Kalau lebih, pecah menjadi beberapa berkas.',
        ];

        foreach (array_merge($umum, $khusus, $penutup) as $i => $t) {
            $sheet->setCellValue([1, $i + 1], $t);
        }
        $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(14);
    }
}
