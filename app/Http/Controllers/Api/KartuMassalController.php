<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\ApiError;
use App\Models\KartuImpor;
use App\Models\KartuImporBaris;
use App\Models\Siswa;
use App\Services\ImporKartuUid;
use App\Support\WaktuIso;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use PhpOffice\PhpSpreadsheet\Cell\DataValidation;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

/**
 * Pendaftaran UID kartu RFID secara kolektif — dua opsi:
 *
 *   - `template`/`pratinjau`/`terapkan`: berkas Excel, untuk kartu yang
 *     UID-nya sudah diketahui/tercetak sebelum ditempel ke reader.
 *   - `terapkanTap`: sesi tap berturut-turut di reader, dikirim sekali jadi
 *     setelah admin melihat & mengoreksinya di layar — tidak ada langkah
 *     pratinjau terpisah karena daftarnya sudah dikurasi di klien.
 *
 * Hak akses memakai kode yang SUDAH ADA (`santri.kartu` / `sdm.kartu`) —
 * ini tetap "mendaftarkan kartu", cuma banyak sekaligus.
 */
class KartuMassalController extends Controller
{
    public function template(Request $r)
    {
        $jenis = ImporKartuUid::wajibJenis($r->query('jenis'));
        $this->wajibIzin($r, "{$jenis}.kartu");

        $kelas = trim((string) $r->query('kelas', ''));
        $semua = in_array($r->query('semua'), ['1', 'ya'], true);
        $siswa = ImporKartuUid::siswaUntukTemplate($jenis, $kelas, $semua);

        $book = new Spreadsheet;
        $sheet = $book->getActiveSheet();
        $sheet->setTitle($jenis === 'sdm' ? 'SDM' : 'Santri');

        foreach (ImporKartuUid::KOLOM as $k => $kol) {
            $sheet->setCellValue([$k + 1, 1], $kol['judul']);
            $sheet->getColumnDimensionByColumn($k + 1)->setWidth($kol['lebar']);
        }
        $sheet->getStyle('A1:D1')->getFont()->setBold(true);
        $sheet->freezePane('A2');

        $baris = 2;
        foreach ($siswa as $i => $s) {
            $sheet->setCellValueExplicit([1, $baris], $i + 1, \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_NUMERIC);
            $sheet->setCellValueExplicit([2, $baris], $s->nis, \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
            $sheet->setCellValue([3, $baris], $s->nama);
            $baris++;
        }
        if ($siswa->isEmpty()) {
            for ($i = 1; $i <= 30; $i++) {
                $sheet->setCellValue([1, $i + 1], $i);
            }
            $baris = 32;
        }

        $this->validasi($sheet, max($baris, ImporKartuUid::MAKS_BARIS + 1), $jenis);
        $this->lembarPetunjuk($book, $jenis);

        $tmp = tempnam(sys_get_temp_dir(), 'tplkartuuid');
        (new Xlsx($book))->save($tmp);
        $isi = file_get_contents($tmp);
        @unlink($tmp);

        $stamp = date('Y-m-d');
        $namaFile = 'template-kartu-' . ($jenis === 'sdm' ? 'sdm' : 'santri') .
            ($kelas !== '' ? '-' . preg_replace('/[^A-Za-z0-9]/', '', $kelas) : '') . "-{$stamp}.xlsx";

        return response($isi, 200, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Content-Disposition' => "attachment; filename=\"{$namaFile}\"",
        ]);
    }

    /** Langkah 1 jalur Excel: pratinjau — tidak ada kartu yang berubah. */
    public function pratinjau(Request $r)
    {
        $jenis = ImporKartuUid::wajibJenis($r->input('jenis'));
        $this->wajibIzin($r, "{$jenis}.kartu");

        ['isi' => $isi, 'nama' => $nama] = ImporKartuUid::berkasDariBody($r->all());
        $abaikanNama = $r->input('abaikan_nama') === true;
        $izinkanGanti = $r->input('izinkan_ganti') === true;
        $hasil = ImporKartuUid::urai($isi, $nama, $jenis, $abaikanNama, $izinkanGanti);

        return response()->json(array_merge([
            'ok' => true, 'nama_berkas' => $nama, 'sidik' => ImporKartuUid::sidik($isi),
            'abaikan_nama' => $abaikanNama, 'izinkan_ganti' => $izinkanGanti,
        ], $hasil));
    }

    /** Langkah 2 jalur Excel: terapkan. */
    public function terapkan(Request $r)
    {
        $jenis = ImporKartuUid::wajibJenis($r->input('jenis'));
        $user = $this->wajibIzin($r, "{$jenis}.kartu");

        ['isi' => $isi, 'nama' => $nama] = ImporKartuUid::berkasDariBody($r->all());
        $batchId = trim((string) $r->input('batch_id', ''));
        if (! preg_match('/^[A-Za-z0-9-]{4,40}$/', $batchId)) {
            throw new ApiError(400, 'batch_id tidak valid (huruf/angka/strip, 4–40 karakter)');
        }

        $sidik = ImporKartuUid::sidik($isi);
        if ($r->input('sidik') && (string) $r->input('sidik') !== $sidik) {
            throw new ApiError(409, 'Berkas berubah sejak dipratinjau — ulangi pratinjau dulu');
        }
        if ($lama = KartuImpor::where('batch_id', $batchId)->first()) {
            return response()->json([
                'ok' => true, 'duplikat' => true, 'impor' => $lama,
                'baris' => KartuImporBaris::where('impor_id', $lama->id)->orderBy('baris')->get(),
            ]);
        }

        $abaikanNama = $r->input('abaikan_nama') === true;
        $izinkanGanti = $r->input('izinkan_ganti') === true;
        $urai = ImporKartuUid::urai($isi, $nama, $jenis, $abaikanNama, $izinkanGanti);

        return response()->json($this->terapkanBaris(
            $urai, $jenis, $batchId, 'excel', $nama, $sidik, $user->nama,
        ));
    }

    /** Jalur tap: satu langkah, karena daftarnya sudah dikurasi di layar admin. */
    public function terapkanTap(Request $r)
    {
        $jenis = ImporKartuUid::wajibJenis($r->input('jenis'));
        $user = $this->wajibIzin($r, "{$jenis}.kartu");

        $batchId = trim((string) $r->input('batch_id', ''));
        if (! preg_match('/^[A-Za-z0-9-]{4,40}$/', $batchId)) {
            throw new ApiError(400, 'batch_id tidak valid (huruf/angka/strip, 4–40 karakter)');
        }
        if ($lama = KartuImpor::where('batch_id', $batchId)->first()) {
            return response()->json([
                'ok' => true, 'duplikat' => true, 'impor' => $lama,
                'baris' => KartuImporBaris::where('impor_id', $lama->id)->orderBy('baris')->get(),
            ]);
        }

        $items = is_array($r->input('baris')) ? $r->input('baris') : [];
        $izinkanGanti = $r->input('izinkan_ganti') === true;
        $urai = ImporKartuUid::uraiTap($items, $jenis, $izinkanGanti);

        return response()->json($this->terapkanBaris(
            $urai, $jenis, $batchId, 'tap', '', '', $user->nama,
        ));
    }

    /**
     * Inti penerapan, dipakai jalur Excel maupun jalur tap: baris yang lolos
     * pemeriksaan `urai`/`uraiTap` diperiksa SEKALI LAGI di dalam transaksi
     * (state DB bisa saja berubah sejak pratinjau/sejak sesi tap dimulai),
     * baru kartu-uid-nya ditulis.
     */
    private function terapkanBaris(array $urai, string $jenis, string $batchId, string $sumber,
        string $berkas, string $sidik, string $operator): array
    {
        $siap = array_values(array_filter($urai['baris'], fn ($b) => $b['status'] === 'siap'));
        if (! $siap) {
            throw new ApiError(400, 'Tidak ada baris yang siap diterapkan');
        }
        $nGagalAwal = count($urai['baris']) - count($siap);

        $hasil = DB::transaction(function () use ($siap, $nGagalAwal, $jenis, $batchId, $sumber, $berkas, $sidik, $operator) {
            $impor = KartuImpor::create([
                'batch_id' => $batchId, 'jenis' => $jenis, 'sumber' => $sumber,
                'berkas' => $berkas, 'sidik' => $sidik, 'n_baris' => count($siap),
                'operator' => $operator, 'created_at' => WaktuIso::sekarang(),
            ]);

            $nBerhasil = 0;
            $nGagal = 0;
            foreach ($siap as $b) {
                $s = Siswa::find($b['siswa_id']);
                $bentrok = $s ? Siswa::where('card_uid', $b['uid'])->where('id', '!=', $s->id)->first() : null;

                if (! $s) {
                    $status = 'gagal';
                    $pesan = 'Data pemegang kartu tidak ditemukan lagi';
                } elseif ($bentrok) {
                    $status = 'gagal';
                    $pesan = "UID {$b['uid']} sudah dipakai kartu {$bentrok->nama} saat diterapkan";
                } else {
                    $s->update(['card_uid' => $b['uid']]);
                    $status = 'berhasil';
                    $pesan = $b['pesan'];
                }

                KartuImporBaris::create([
                    'impor_id' => $impor->id, 'baris' => $b['baris'], 'siswa_id' => $b['siswa_id'],
                    'nis' => $b['nis'], 'nama' => $s->nama ?? ($b['nama_db'] ?: $b['nama_berkas']),
                    'uid' => $b['uid'], 'uid_lama' => $b['uid_lama'], 'status' => $status, 'pesan' => $pesan,
                ]);
                $status === 'berhasil' ? $nBerhasil++ : $nGagal++;
            }

            $impor->update(['n_berhasil' => $nBerhasil, 'n_gagal' => $nGagal + $nGagalAwal]);

            return $impor;
        });

        return [
            'ok' => true, 'impor' => $hasil->fresh(),
            'baris' => KartuImporBaris::where('impor_id', $hasil->id)->orderBy('baris')->get(),
        ];
    }

    public function riwayat(Request $r)
    {
        $jenis = $r->query('jenis');
        if ($jenis) {
            $this->wajibIzin($r, ImporKartuUid::wajibJenis($jenis) . '.kartu');
        } else {
            $this->wajibPeran($r, 'superadmin');
        }

        if ($id = $r->query('id')) {
            $impor = KartuImpor::find((int) $id);
            if (! $impor) {
                throw new ApiError(404, 'Riwayat tidak ditemukan');
            }

            return response()->json([
                'impor' => $impor, 'baris' => KartuImporBaris::where('impor_id', $impor->id)->orderBy('baris')->get(),
            ]);
        }

        $q = KartuImpor::orderByDesc('id')->limit(50);
        if ($jenis) {
            $q->where('jenis', $jenis);
        }

        return response()->json(['impor' => $q->get()]);
    }

    // ------------------------------------------------------------ template

    private function validasi($sheet, int $barisAkhir, string $jenis): void
    {
        $b = max(2, $barisAkhir);
        $ist = ImporKartuUid::istilah($jenis);

        $aturan = [
            ['A', DataValidation::TYPE_WHOLE, DataValidation::OPERATOR_BETWEEN, '1', (string) $b,
                'Nomor urut', 'Hanya penomoran, boleh dibiarkan apa adanya.',
                'Nomor tidak valid', 'Kolom No hanya boleh berisi angka urut.'],

            ['B', DataValidation::TYPE_TEXTLENGTH, DataValidation::OPERATOR_BETWEEN, '3', '20',
                $ist['nomor'],
                "Harus sama dengan {$ist['nomor']} pada data " . $ist['orang'] . ' yang sudah terdaftar.',
                "{$ist['nomor']} tidak valid", "{$ist['nomor']} harus 3–20 karakter."],

            ['D', DataValidation::TYPE_TEXTLENGTH, DataValidation::OPERATOR_BETWEEN, '0', '40',
                'UID Kartu',
                'Kosongkan baris yang kartunya belum ada di tangan Anda sekarang — baris kosong ' .
                    'dilewati, bukan dianggap gagal. Isi PERSIS seperti yang tercetak/terbaca di kartu.',
                'UID tidak valid', 'UID maksimal 40 karakter.'],
        ];

        foreach ($aturan as [$kolom, $tipe, $operator, $f1, $f2, $judulPesan, $pesan, $judulGalat, $galat]) {
            $dv = $sheet->getCell("{$kolom}2")->getDataValidation();
            $dv->setType($tipe);
            $dv->setErrorStyle(DataValidation::STYLE_STOP);
            $dv->setAllowBlank(true);
            $dv->setShowInputMessage(true);
            $dv->setShowErrorMessage(true);
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
            $sheet->setDataValidation("{$kolom}2:{$kolom}{$b}", $dv);
        }
    }

    private function lembarPetunjuk(Spreadsheet $book, string $jenis): void
    {
        $ist = ImporKartuUid::istilah($jenis);
        $sheet = $book->createSheet();
        $sheet->setTitle('Petunjuk');
        $sheet->getColumnDimension('A')->setWidth(110);

        $teks = [
            'Cara mengisi berkas pendaftaran kartu RFID kolektif',
            '',
            "1. Kolom No, {$ist['nomor']}, dan Nama sudah terisi otomatis untuk seluruh " . $ist['orang'] .
                ' yang belum punya kartu. Jangan mengubah judul kolom atau menghapus baris judul.',
            '2. Isi kolom "UID Kartu" HANYA untuk baris yang kartu fisiknya sudah di tangan Anda sekarang. ' .
                'Baris yang UID-nya masih kosong akan dilewati — bukan dianggap gagal — sehingga berkas yang ' .
                'sama bisa diisi bertahap kalau kartu datang beberapa kloter.',
            '3. UID hanya bisa diisi kalau kartu fisiknya punya nomor yang TERCETAK atau bisa dibaca tanpa ' .
                'reader. Kalau tidak, gunakan menu "Tap Berturut-turut" di aplikasi — kartu ditempelkan ' .
                'langsung ke reader satu per satu, jauh lebih cepat dan tidak berisiko salah ketik.',
            '4. Setiap UID harus khas. Kalau seorang ' . $ist['orang'] . ' sudah punya kartu terdaftar dan ' .
                'Anda isi UID yang berbeda, barisnya akan ditolak kecuali Anda mencentang ' .
                '"izinkan ganti kartu" di aplikasi — pengaman supaya kartu lama tidak tertimpa tanpa sadar.',
            '5. Simpan sebagai .xlsx (atau CSV), lalu unggah di menu Daftar Kartu Massal. Berkas akan ' .
                'dipratinjau lebih dulu — kartu baru berubah setelah Anda menekan Terapkan.',
            '',
            'Langkah pratinjau di aplikasi adalah pemeriksa yang sebenarnya — baris yang salah dilaporkan ' .
                'satu per satu di sana, lengkap dengan nomor barisnya.',
        ];
        foreach ($teks as $i => $t) {
            $sheet->setCellValue([1, $i + 1], $t);
        }
        $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(14);
    }
}
