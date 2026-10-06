<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\ApiError;
use App\Models\PotongImpor;
use App\Models\Siswa;
use App\Services\BukuBesar;
use App\Services\ImporPotong;
use App\Services\ImporTopup;
use App\Services\Kelas;
use App\Services\Rupiah;
use App\Support\WaktuIso;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

/**
 * Potong saldo kolektif lewat berkas Excel, nominal berbeda per santri.
 *
 * Alurnya dua langkah dan langkah pertama TIDAK memindahkan satu rupiah pun:
 * unggah → pratinjau per baris → baru diterapkan. Berkasnya dikirim ulang saat
 * diterapkan dan diurai lagi dari nol; yang tersimpan adalah hasil bacaan
 * server, bukan tabel yang kebetulan tampil di layar.
 *
 * Uangnya BENAR-BENAR KELUAR dari kas sekolah — saldo kartu turun, kas ikut
 * turun, neraca tetap seimbang. Itu sebabnya ia dicatat sebagai `koreksi`,
 * sama seperti potong massal yang sudah ada. Kalau yang dimaksud adalah
 * memindahkan saldo ke penanggung jawab kegiatan, yang dipakai bukan ini
 * melainkan iuran di kios — di sana uangnya berpindah tangan tanpa keluar kas.
 */
class PotongMassalController extends Controller
{
    /**
     * Template Excel. Bisa langsung terisi daftar santri (opsional per kelas),
     * supaya admin tinggal mengetik nominalnya saja — dan NIS serta namanya
     * sudah pasti benar sejak awal, bukan hasil ketik ulang.
     */
    public function template(Request $r)
    {
        $this->wajibPeran($r, 'superadmin');
        $isi = in_array($r->query('isi'), ['1', 'ya'], true);
        $kelas = trim((string) $r->query('kelas', ''));

        $siswa = $isi
            ? Siswa::where('status', 'aktif')
                ->when($kelas !== '', fn ($q) => $q->where('kelas', $kelas))
                ->when($kelas === '', fn ($q) => $q->orderByRaw(Kelas::urutSql()))
                ->orderBy('nama')->get(['nis', 'nama', 'kelas', 'saldo'])
            : collect();

        $book = new Spreadsheet;
        $sheet = $book->getActiveSheet();
        $sheet->setTitle('Potong');
        foreach (ImporPotong::KOLOM as $k => $kol) {
            $sheet->setCellValue([$k + 1, 1], $kol['judul']);
            $sheet->getColumnDimensionByColumn($k + 1)->setWidth($kol['lebar']);
        }
        $sheet->getStyle('A1:' . ImporPotong::hurufAkhir() . '1')->getFont()->setBold(true);
        $sheet->freezePane('A2');

        $baris = 2;
        foreach ($siswa as $i => $s) {
            $sheet->setCellValueExplicit([1, $baris], $i + 1, DataType::TYPE_NUMERIC);
            // NIS ditulis sebagai TEKS: nol di depan hilang kalau Excel
            // memperlakukannya sebagai angka, dan NIS itulah kunci pencocokannya.
            $sheet->setCellValueExplicit([2, $baris], $s->nis, DataType::TYPE_STRING);
            $sheet->setCellValue([3, $baris], $s->nama);
            $sheet->setCellValue([4, $baris], $s->kelas);
            $baris++;
        }
        if ($siswa->isEmpty()) {
            for ($i = 1; $i <= 30; $i++) {
                $sheet->setCellValue([1, $i + 1], $i);
            }
        }
        // NIS diformat TEKS: nol di depan hilang kalau Excel menganggapnya
        // bilangan, dan NIS itulah kunci pencocokannya.
        $kNis = ImporPotong::huruf('nis');
        $sheet->getStyle("{$kNis}2:{$kNis}" . max($baris, ImporPotong::MAKS_BARIS + 1))
            ->getNumberFormat()->setFormatCode('@');

        $p = $book->createSheet();
        $p->setTitle('Petunjuk');
        $p->getColumnDimension('A')->setWidth(116);
        $teks = [
            'Cara mengisi berkas potong saldo kolektif',
            '',
            'Berkas ini MENGURANGI saldo kartu santri. Isinya diperiksa dulu di layar sebelum',
            'ada satu rupiah pun yang berpindah — tetapi sesudah diterapkan, pembetulannya hanya',
            'bisa dilakukan dengan top up balik. Periksa sekali lagi sebelum menekan terapkan.',
            '',
            '1. Satu baris untuk satu santri. Kolom No hanya penomoran. Baris kosong dilewati.',
            '2. Jangan mengubah judul kolom — kolom dikenali dari judulnya, bukan urutannya.',
            '3. NIS: angka saja, dan inilah yang menentukan santrinya.',
            '   Kolom Kelas hanya penolong baca — ia tidak diperiksa, sebab santri bisa naik kelas',
            '   antara template ini diunduh dan berkasnya diunggah.',
            '4. Nama Santri: WAJIB diisi dan harus cocok dengan nama di sistem. Nama di sini bukan',
            '   hiasan — ia yang menangkap NIS salah ketik yang kebetulan menunjuk santri lain.',
            '   Baris yang namanya tidak cocok akan ditolak di layar pratinjau, bukan dipotong.',
            '5. Nominal Potong: ANGKA saja, contoh 50000 (bukan Rp50.000). Boleh berbeda tiap baris.',
            '6. Keterangan: alasan khusus baris itu. Boleh dikosongkan — baris yang kosong memakai',
            '   alasan umum yang diketik admin saat menerapkan.',
            '',
            'Baris yang DILEWATI (tidak dipotong), dan akan disebutkan sebabnya di pratinjau:',
            '  · NIS tidak terdaftar',
            '  · Nama tidak cocok dengan yang ada di sistem',
            '  · Saldo santri kurang dari nominal yang diminta — dilewati utuh, bukan dipotong sebagian',
            '  · NIS yang sama muncul lebih dari sekali di berkas ini',
            '',
            'Maksimal ' . ImporPotong::MAKS_BARIS . ' baris per berkas.',
            'Berkas yang sama tidak bisa diterapkan dua kali, walau diunggah ulang.',
        ];
        foreach ($teks as $i => $x) {
            $p->setCellValue([1, $i + 1], $x);
        }
        $p->getStyle('A1')->getFont()->setBold(true)->setSize(14);
        $book->setActiveSheetIndex(0);

        $tmp = tempnam(sys_get_temp_dir(), 'tplpotong');
        (new Xlsx($book))->save($tmp);
        $isiBerkas = file_get_contents($tmp);
        @unlink($tmp);

        $stamp = date('Y-m-d');
        $namaFile = $isi && $kelas !== ''
            ? 'template-potong-' . preg_replace('/[^A-Za-z0-9]/', '', $kelas) . "-{$stamp}.xlsx"
            : "template-potong-{$stamp}.xlsx";

        return response($isiBerkas, 200, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Content-Disposition' => "attachment; filename=\"{$namaFile}\"",
        ]);
    }

    /** Langkah 1: pratinjau. Belum ada saldo yang tersentuh. */
    public function pratinjau(Request $r)
    {
        $this->wajibPeran($r, 'superadmin');
        ['isi' => $isi, 'nama' => $nama] = ImporTopup::berkasDariBody($r->all());
        $sidik = ImporTopup::sidik($isi);

        $lama = PotongImpor::where('sidik', $sidik)->orderByDesc('id')->first();

        return response()->json(array_merge(
            ['ok' => true, 'nama_berkas' => $nama, 'sidik' => $sidik,
                // Berkas yang isinya sama persis pernah diterapkan: diberitahu
                // SEKARANG, bukan nanti setelah tombol terapkan ditekan.
                'sudah_pernah' => $lama ? [
                    'waktu' => $lama->created_at, 'operator' => $lama->operator,
                    'n_berhasil' => $lama->n_berhasil, 'total' => $lama->total_potong,
                ] : null],
            ImporPotong::urai($isi, $nama),
        ));
    }

    /**
     * Langkah 2: terapkan.
     *
     * Saldo diperiksa SEKALI LAGI di sini, bukan dipercaya dari pratinjau:
     * di antara keduanya santri bisa jajan, dan memotong lebih dari saldo yang
     * ada akan membuat kartu minus — angka yang tidak punya arti apa pun di
     * sistem ini.
     */
    public function terapkan(Request $r)
    {
        $user = $this->wajibPeran($r, 'superadmin');
        ['isi' => $isi, 'nama' => $nama] = ImporTopup::berkasDariBody($r->all());
        $sidik = ImporTopup::sidik($isi);

        if ($r->input('sidik') && (string) $r->input('sidik') !== $sidik) {
            throw new ApiError(409, 'Berkas berubah sejak dipratinjau — ulangi pratinjau dulu');
        }
        $batchId = trim((string) $r->input('batch_id', ''));
        if (! preg_match('/^[A-Za-z0-9-]{4,40}$/', $batchId)) {
            throw new ApiError(400, 'batch_id tidak valid (huruf/angka/strip, 4–40 karakter)');
        }
        $alasan = mb_substr(trim((string) $r->input('alasan', '')), 0, 200);
        if (mb_strlen($alasan) < 3) {
            throw new ApiError(400, 'Alasan umum wajib diisi (minimal 3 huruf) — ' .
                'ia yang dipakai baris yang kolom keterangannya kosong');
        }

        // Dua pagar berlapis. Kode batch menangkap klik ganda; sidik isi berkas
        // menangkap berkas yang sama diunggah ulang dengan kode batch baru —
        // dan yang kedua inilah yang benar-benar melindungi, sebab layar
        // membuat kode batch baru setiap kali berkas dipilih.
        $kembar = PotongImpor::where('sidik', $sidik)->orWhere('batch_id', $batchId)
            ->orderByDesc('id')->first();
        if ($kembar) {
            return response()->json(['ok' => true, 'duplikat' => true,
                'pesan' => 'Berkas ini sudah pernah diterapkan pada ' .
                    substr((string) $kembar->created_at, 0, 10) . ' oleh ' . $kembar->operator .
                    ' — ' . $kembar->n_berhasil . ' santri, total ' . Rupiah::f($kembar->total_potong),
                'impor' => $kembar]);
        }

        $urai = ImporPotong::urai($isi, $nama);
        $siap = array_values(array_filter($urai['baris'], fn ($x) => $x['status'] === 'siap'));
        if (! $siap) {
            // Berkasnya diurai ULANG di sini, jadi baris yang tadi siap bisa
            // saja sudah tidak siap lagi — saldonya berubah di antara dua
            // langkah. Pesannya menyebut kemungkinan itu, bukan sekadar
            // mengatakan tidak ada yang bisa dipotong.
            throw new ApiError(400, 'Tidak ada baris yang siap dipotong — ' .
                'kalau tadi ada yang siap, saldonya mungkin sudah berubah sejak dipratinjau. ' .
                'Ulangi pratinjau untuk melihat keadaan terbarunya.');
        }

        $hasil = DB::transaction(function () use ($siap, $batchId, $alasan, $user, $urai, $sidik, $nama) {
            $berhasil = [];
            $dilewati = [];
            foreach ($siap as $b) {
                $potong = (int) $b['nominal'];
                // Pengurangan BERSYARAT di basis data: kalau saldonya sudah
                // berubah sejak pratinjau dan jadi kurang, baris ini tidak
                // tersentuh sama sekali — bukan terpotong sebagian.
                $ubah = Siswa::where('id', $b['siswa_id'])->where('saldo', '>=', $potong)
                    ->update(['saldo' => DB::raw("saldo - {$potong}")]);
                if ($ubah === 0) {
                    $s = Siswa::find($b['siswa_id']);
                    $dilewati[] = array_merge($b, ['status' => 'gagal',
                        'pesan' => 'Saldo berubah sejak pratinjau — sekarang ' .
                            Rupiah::f((int) ($s->saldo ?? 0))]);

                    continue;
                }
                $s = Siswa::find($b['siswa_id']);
                $ket = $b['keterangan'] !== '' ? $b['keterangan'] : $alasan;
                BukuBesar::catatTransaksi("{$batchId}-{$b['siswa_id']}", 'koreksi', $s,
                    -$potong, $user->nama, mb_substr('[Potong massal] ' . $ket, 0, 300));
                $berhasil[] = array_merge($b, ['saldo_sesudah' => (int) $s->saldo]);
            }

            $impor = PotongImpor::create([
                'batch_id' => $batchId, 'berkas' => mb_substr($nama, 0, 160), 'sidik' => $sidik,
                'alasan' => $alasan, 'n_baris' => $urai['ringkas']['n_baris'],
                'n_berhasil' => count($berhasil),
                'n_gagal' => $urai['ringkas']['n_gagal'] + count($dilewati),
                'total_potong' => array_sum(array_map(fn ($x) => $x['nominal'], $berhasil)),
                'operator' => $user->nama, 'created_at' => WaktuIso::sekarang(),
            ]);

            return ['impor' => $impor, 'berhasil' => $berhasil, 'dilewati' => $dilewati];
        });

        return response()->json(array_merge(['ok' => true], $hasil, [
            'gagal_pratinjau' => array_values(array_filter($urai['baris'],
                fn ($x) => $x['status'] === 'gagal')),
        ]));
    }

    public function riwayat(Request $r)
    {
        $this->wajibPeran($r, 'superadmin');

        return response()->json(['impor' => PotongImpor::orderByDesc('id')->limit(50)->get()]);
    }
}
