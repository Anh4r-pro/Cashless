<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\ApiError;
use App\Models\Siswa;
use App\Models\TopupImpor;
use App\Models\TopupImporBaris;
use App\Services\ImporTopup;
use App\Services\Pengaturan;
use App\Services\Rupiah;
use App\Services\Setoran;
use App\Services\SmartTopup;
use App\Support\WaktuIso;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use PhpOffice\PhpSpreadsheet\Cell\DataValidation;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

/**
 * Top up massal dari berkas Excel/CSV.
 *
 * Bendahara mengumpulkan setoran orang tua di buku/spreadsheet, lalu mengunggah
 * satu berkas ketimbang mengetik ratusan top up satu per satu.
 *
 * Alurnya SELALU dua langkah, dan server tidak pernah mempercayai hasil bacaan
 * klien — berkasnya dikirim dua kali dan diurai ulang di server:
 *   1. pratinjau → tiap baris divonis siap/gagal, admin melihat pembagian
 *      saldo/titipan yang akan terjadi. Tidak ada saldo yang berubah.
 *   2. terapkan  → berkas yang SAMA (dicocokkan sidik SHA-256) diurai ulang,
 *      lalu seluruh baris dieksekusi dalam satu transaksi basis data.
 */
class TopupMassalController extends Controller
{
    public function template(Request $r)
    {
        $this->wajibPeran($r, 'superadmin');
        $cfg = Pengaturan::sekolah();
        $isi = in_array($r->query('isi'), ['1', 'ya'], true);
        $kelas = trim((string) $r->query('kelas', ''));

        $siswa = $isi
            ? Siswa::where('status', 'aktif')
                ->when($kelas !== '', fn ($q) => $q->where('kelas', $kelas))
                ->orderBy($kelas !== '' ? 'nama' : 'kelas')->orderBy('nama')
                ->get(['nis', 'nama', 'kelas'])
            : collect();

        $book = new Spreadsheet;
        $sheet = $book->getActiveSheet();
        $sheet->setTitle('Topup');

        foreach (ImporTopup::KOLOM as $k => $kol) {
            $sheet->setCellValue([$k + 1, 1], $kol['judul']);
            $sheet->getColumnDimensionByColumn($k + 1)->setWidth($kol['lebar']);
        }
        $sheet->getStyle('A1:F1')->getFont()->setBold(true);
        $sheet->freezePane('A2');

        $baris = 2;
        foreach ($siswa as $i => $s) {
            $sheet->setCellValueExplicit([1, $baris], $i + 1, \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_NUMERIC);
            // NIS ditulis sebagai TEKS: nol di depan hilang kalau Excel
            // memperlakukannya sebagai angka, dan NIS itulah kunci pencocokannya.
            $sheet->setCellValueExplicit([2, $baris], $s->nis, \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
            $sheet->setCellValue([3, $baris], $s->nama);
            $baris++;
        }
        // Berkas kosong tetap diberi 30 baris bernomor supaya terlihat sebagai
        // formulir yang siap diisi, bukan satu baris judul yang menggantung.
        if ($siswa->isEmpty()) {
            for ($i = 1; $i <= 30; $i++) {
                $sheet->setCellValue([1, $i + 1], $i);
            }
        }

        $this->validasi($sheet, max($baris, ImporTopup::MAKS_BARIS + 1), $cfg);
        $this->lembarPetunjuk($book, $cfg);

        $tmp = tempnam(sys_get_temp_dir(), 'tpl');
        (new Xlsx($book))->save($tmp);
        $isiBerkas = file_get_contents($tmp);
        @unlink($tmp);

        $stamp = date('Y-m-d');
        $namaFile = $isi
            ? 'template-topup-' . ($kelas !== '' ? preg_replace('/[^A-Za-z0-9]/', '', $kelas) . '-' : 'semua-') . "{$stamp}.xlsx"
            : "template-topup-{$stamp}.xlsx";

        return response($isiBerkas, 200, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Content-Disposition' => "attachment; filename=\"{$namaFile}\"",
        ]);
    }

    /** Langkah 1: pratinjau — tidak mengubah apa pun. */
    public function pratinjau(Request $r)
    {
        $this->wajibPeran($r, 'superadmin');
        ['isi' => $isi, 'nama' => $nama] = ImporTopup::berkasDariBody($r->all());

        $abaikanNama = $r->input('abaikan_nama') === true;
        $keTitipan = $r->input('ke_titipan') !== false;
        $hasil = ImporTopup::urai($isi, $nama, $abaikanNama, $keTitipan);
        $sidik = ImporTopup::sidik($isi);

        return response()->json(array_merge([
            'ok' => true, 'nama_berkas' => $nama, 'sidik' => $sidik,
            'ke_titipan' => $keTitipan, 'abaikan_nama' => $abaikanNama,
            // Peringatan dini: admin melihat "berkas ini sudah pernah
            // diterapkan" SEBELUM menekan Terapkan, bukan sesudah saldo
            // bertambah dua kali.
            'pernah_diterapkan' => ImporTopup::seisiSama($sidik),
        ], $hasil));
    }

    /** Langkah 2: terapkan. */
    public function terapkan(Request $r)
    {
        $user = $this->wajibPeran($r, 'superadmin');
        ['isi' => $isi, 'nama' => $nama] = ImporTopup::berkasDariBody($r->all());

        $batchId = trim((string) $r->input('batch_id', ''));
        if (! preg_match('/^[A-Za-z0-9-]{4,40}$/', $batchId)) {
            throw new ApiError(400, 'batch_id tidak valid (huruf/angka/strip, 4–40 karakter)');
        }

        // Berkas yang diterapkan harus benar-benar berkas yang tadi dipratinjau.
        $sidik = ImporTopup::sidik($isi);
        if ($r->input('sidik') && (string) $r->input('sidik') !== $sidik) {
            throw new ApiError(409, 'Berkas berubah sejak dipratinjau — ulangi pratinjau dulu');
        }

        // Batch yang sudah pernah diterapkan: kembalikan hasil yang tersimpan,
        // jangan menambah saldo lagi.
        if ($lama = TopupImpor::where('batch_id', $batchId)->first()) {
            return response()->json([
                'ok' => true, 'duplikat' => true, 'impor' => $lama,
                'baris' => TopupImporBaris::where('impor_id', $lama->id)->orderBy('baris')->get(),
            ]);
        }

        // Berkas dengan isi sama persis: hampir selalu berkas yang sama diunggah
        // dua kali, bukan setoran baru yang kebetulan identik sampai ke rupiahnya.
        $kembar = ImporTopup::seisiSama($sidik);
        if ($kembar && $r->input('izinkan_ulang') !== true) {
            throw new ApiError(409,
                'Berkas dengan isi sama persis sudah diterapkan ' . substr((string) $kembar->created_at, 0, 10) .
                " oleh {$kembar->operator} (batch {$kembar->batch_id}, {$kembar->n_berhasil} baris, " .
                Rupiah::f($kembar->total_saldo + $kembar->total_titipan) . '). Kalau ini memang setoran lain ' .
                'yang isinya kebetulan sama, centang "terapkan lagi walau isinya sama".');
        }

        $abaikanNama = $r->input('abaikan_nama') === true;
        $keTitipan = $r->input('ke_titipan') !== false;
        $urai = ImporTopup::urai($isi, $nama, $abaikanNama, $keTitipan);

        if (! array_filter($urai['baris'], fn ($x) => $x['status'] === 'siap')) {
            throw new ApiError(400, 'Tidak ada baris yang siap diterapkan — perbaiki berkasnya dulu');
        }

        $hasil = DB::transaction(function () use ($urai, $batchId, $nama, $sidik, $user, $keTitipan) {
            $impor = TopupImpor::create([
                'batch_id' => $batchId, 'berkas' => $nama, 'sidik' => $sidik,
                'n_baris' => count($urai['baris']), 'operator' => $user->nama,
                'created_at' => WaktuIso::sekarang(),
            ]);

            $nBerhasil = 0;
            $nGagal = 0;
            $totalSaldo = 0;
            $totalTitipan = 0;
            $perluSmart = [];

            foreach ($urai['baris'] as $b) {
                if ($b['status'] !== 'siap') {
                    TopupImporBaris::create([
                        'impor_id' => $impor->id, 'baris' => $b['baris'], 'siswa_id' => $b['siswa_id'],
                        'nis' => $b['nis'], 'nama' => $b['nama_berkas'], 'jumlah' => $b['jumlah'],
                        'ke_saldo' => 0, 'ke_titipan' => 0, 'metode' => $b['metode'],
                        'tgl_terima' => $b['tgl_terima'], 'status' => 'gagal', 'pesan' => $b['pesan'],
                    ]);
                    $nGagal++;

                    continue;
                }

                $ket = 'Top up massal ' . ImporTopup::METODE[$b['metode']] . " (uang diterima {$b['tgl_terima']})";
                $setor = Setoran::terima($b['siswa_id'], $b['jumlah'], [
                    'keTitipan' => $keTitipan,
                    'operator' => $user->nama,
                    'keterangan' => $ket,
                    // Idempotensi per BARIS, bukan per siswa: satu berkas boleh
                    // memuat dua setoran untuk siswa yang sama tanpa yang kedua
                    // dianggap duplikat.
                    'txId' => "impor-{$batchId}-b{$b['baris']}",
                    'refTitipan' => "impor-{$batchId}-b{$b['baris']}",
                ]);

                TopupImporBaris::create([
                    'impor_id' => $impor->id, 'baris' => $b['baris'], 'siswa_id' => $b['siswa_id'],
                    'nis' => $b['nis'], 'nama' => $b['nama_berkas'], 'jumlah' => $b['jumlah'],
                    'ke_saldo' => $setor['ke_saldo'], 'ke_titipan' => $setor['ke_titipan'],
                    'metode' => $b['metode'], 'tgl_terima' => $b['tgl_terima'],
                    'status' => 'berhasil', 'pesan' => $b['pesan'],
                ]);

                $nBerhasil++;
                $totalSaldo += $setor['ke_saldo'];
                $totalTitipan += $setor['ke_titipan'];
                if ($setor['ke_titipan'] > 0) {
                    $perluSmart[$b['siswa_id']] = true;
                }
            }

            $impor->update([
                'n_berhasil' => $nBerhasil, 'n_gagal' => $nGagal,
                'total_saldo' => $totalSaldo, 'total_titipan' => $totalTitipan,
            ]);

            return ['impor_id' => $impor->id, 'perluSmart' => array_keys($perluSmart)];
        });

        // Isi ulang otomatis dijalankan SETELAH batch tersimpan: siswa bersaldo
        // tipis langsung mendapat tetesan dari titipan yang baru masuk.
        $nSmart = 0;
        $rpSmart = 0;
        foreach ($hasil['perluSmart'] as $id) {
            if ($s = SmartTopup::diam($id, 'top up massal')) {
                $nSmart++;
                $rpSmart += $s['isi'];
            }
        }

        return response()->json([
            'ok' => true,
            'impor' => TopupImpor::find($hasil['impor_id']),
            'baris' => TopupImporBaris::where('impor_id', $hasil['impor_id'])->orderBy('baris')->get(),
            'smart' => ['n' => $nSmart, 'total' => $rpSmart],
        ]);
    }

    public function riwayat(Request $r)
    {
        $this->wajibPeran($r, 'superadmin');

        if ($id = $r->query('id')) {
            $impor = TopupImpor::find((int) $id);
            if (! $impor) {
                throw new ApiError(404, 'Impor tidak ditemukan');
            }

            return response()->json([
                'impor' => $impor,
                'baris' => TopupImporBaris::where('impor_id', $impor->id)->orderBy('baris')->get(),
            ]);
        }

        return response()->json(['impor' => TopupImpor::orderByDesc('id')->limit(50)->get()]);
    }

    /**
     * Pemeriksa isi sel pada template.
     *
     * Aturannya sengaja SEPAKAT dengan penilaian server: template yang lebih
     * ketat akan menolak setoran yang sebenarnya sah, sedangkan yang lebih
     * longgar meloloskan baris yang nanti gagal — dan bendahara baru tahu
     * setelah mengisi ratusan baris.
     *
     * allowBlank menyala di semua aturan karena baris sisa di bawah tabel
     * memang kosong dan tidak boleh dilaporkan sebagai kesalahan.
     */
    private function validasi($sheet, int $barisAkhir, array $cfg): void
    {
        $b = max(2, $barisAkhir);

        // Batas atas = paling banyak yang mungkin diterima satu baris: jatah
        // top up harian ditambah titipan yang masih boleh menampung. Sengaja
        // longgar (bukan sepersis aturan server, yang bergantung saldo siswa
        // saat itu) supaya Excel tidak pernah menolak nominal yang sah.
        $maksNominal = $cfg['max_topup_harian'] + $cfg['max_titipan'];
        $nomMin = 1000;

        // Tanggal disimpan sebagai TEKS bergaya DD/MM/YYYY, bukan tanggal Excel:
        // sel bertipe tanggal ditafsirkan menurut locale komputer pengisinya
        // (05/08 bisa jadi 5 Agustus atau 8 Mei), sedangkan teks berbentuk tetap
        // dibaca server dengan satu tafsiran. Rumus di bawah memeriksa BENTUKNYA;
        // kebenaran kalendernya (mis. 31/02) diperiksa server.
        $bentukTanggal = implode(',', [
            'LEN(E2)=10', 'MID(E2,3,1)="/"', 'MID(E2,6,1)="/"',
            'ISNUMBER(VALUE(LEFT(E2,2)))', 'ISNUMBER(VALUE(MID(E2,4,2)))', 'ISNUMBER(VALUE(RIGHT(E2,4)))',
            'VALUE(LEFT(E2,2))>=1', 'VALUE(LEFT(E2,2))<=31',
            'VALUE(MID(E2,4,2))>=1', 'VALUE(MID(E2,4,2))<=12',
            'VALUE(RIGHT(E2,4))>=2020',
        ]);

        $aturan = [
            ['A', DataValidation::TYPE_WHOLE, DataValidation::OPERATOR_BETWEEN, '1', (string) $b,
                'Nomor urut', 'Hanya penomoran, boleh dibiarkan kosong.',
                'Nomor tidak valid', 'Kolom No hanya boleh berisi angka urut.'],

            ['B', DataValidation::TYPE_TEXTLENGTH, DataValidation::OPERATOR_BETWEEN, '3', '20',
                'NIS siswa',
                'Harus sama dengan NIS pada data siswa (3–20 karakter). Nama siswa akan dicocokkan dengan NIS ini.',
                'NIS tidak valid', 'NIS harus 3–20 karakter. Jangan menulis nama di kolom ini.'],

            ['D', DataValidation::TYPE_CUSTOM, null,
                "AND(ISNUMBER(D2),D2>={$nomMin},D2<={$maksNominal},MOD(D2,100)=0)", null,
                'Nominal top up',
                'Tulis ANGKA saja tanpa "Rp" atau titik, mis. 50000. Kelipatan 100, antara ' .
                    Rupiah::f($nomMin) . ' dan ' . Rupiah::f($maksNominal) . '.',
                'Nominal tidak valid',
                'Nominal harus angka (bukan teks), kelipatan Rp100, antara ' . Rupiah::f($nomMin) .
                    ' dan ' . Rupiah::f($maksNominal) . '. Contoh: 50000. Jangan pakai "Rp" atau titik ribuan.'],

            ['E', DataValidation::TYPE_CUSTOM, null, "AND({$bentukTanggal})", null,
                'Tanggal uang diterima',
                'Format DD/MM/YYYY dengan angka nol di depan, mis. 05/08/2026.',
                'Format tanggal salah',
                'Tulis tanggal persis dalam bentuk DD/MM/YYYY, mis. 05/08/2026. ' .
                    'Tanggal di masa depan atau lebih dari 365 hari yang lalu akan ditolak saat diperiksa.'],

            ['F', DataValidation::TYPE_LIST, null,
                '"' . implode(',', array_values(ImporTopup::METODE)) . '"', null,
                'Metode pembayaran', 'Pilih dari daftar: Tunai, Transfer Bank, atau QRIS.',
                'Metode tidak dikenal', 'Pilih salah satu dari daftar: Tunai, Transfer Bank, atau QRIS.'],
        ];

        foreach ($aturan as [$kolom, $tipe, $operator, $f1, $f2, $judulPesan, $pesan, $judulGalat, $galat]) {
            $dv = $sheet->getCell("{$kolom}2")->getDataValidation();
            $dv->setType($tipe);
            $dv->setErrorStyle(DataValidation::STYLE_STOP);
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
            $sheet->setDataValidation("{$kolom}2:{$kolom}{$b}", $dv);
        }
    }

    private function lembarPetunjuk(Spreadsheet $book, array $cfg): void
    {
        $sheet = $book->createSheet();
        $sheet->setTitle('Petunjuk');
        $sheet->getColumnDimension('A')->setWidth(110);

        $teks = [
            'Cara mengisi berkas top up massal',
            '',
            '1. Isi satu baris untuk satu setoran. Kolom No hanya penomoran, boleh diabaikan.',
            '2. NIS harus sama dengan NIS pada data siswa. Jangan mengubah judul kolom dan jangan menghapus baris judul — kolom dikenali dari judulnya.',
            '3. Nama Santri wajib diisi dan dicocokkan dengan NIS. Kalau namanya berbeda, barisnya tidak akan diterapkan sebelum admin menyatakan sadar — pengaman agar uang tidak masuk ke kartu anak lain.',
            '4. Nominal Topup: ANGKA saja, tanpa "Rp" dan tanpa titik ribuan. Contoh: 50000 (bukan Rp50.000). Harus kelipatan Rp100.',
            '5. Tanggal Uang Diterima: persis DD/MM/YYYY dengan angka nol di depan, contoh 05/08/2026. Tanggal di masa depan atau lebih dari 365 hari yang lalu ditolak.',
            '6. Metode Pembayaran: tulis Tunai, Transfer Bank, atau QRIS.',
            '7. Batas saldo kartu ' . Rupiah::f($cfg['max_saldo']) . '. Nominal yang melebihi kapasitas kartu tidak ditolak: kelebihannya disimpan sebagai saldo titipan dan otomatis mengisi kartu saat saldo turun sampai ' . Rupiah::f($cfg['smart_ambang']) . ' (smart top up).',
            '8. Simpan sebagai .xlsx (atau CSV), lalu unggah di menu Topup Massal. Berkas akan dipratinjau lebih dulu — saldo baru berubah setelah Anda menekan Terapkan.',
            '',
            'Langkah pratinjau di aplikasi adalah pemeriksa yang sebenarnya — baris yang salah dilaporkan satu per satu di sana, lengkap dengan nomor barisnya.',
        ];
        foreach ($teks as $i => $t) {
            $sheet->setCellValue([1, $i + 1], $t);
        }
        $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(14);
    }
}
