<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\ApiError;
use App\Models\Siswa;
use App\Models\Transaksi;
use App\Services\Arsip;
use App\Services\Kelas;
use App\Services\Rupiah;
use Illuminate\Http\Request;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

/**
 * Mengeluarkan pemegang kartu: Alumni, Lanjut Study, atau Pindah.
 *
 * Melayani dua layar dengan satu mesin, dan hak aksesnya terpisah
 * (`santri.arsip` vs `sdm.arsip`) — lingkupnya ditentukan golongan kartu yang
 * bersangkutan, sama seperti pada SiswaController.
 *
 * Alurnya tiga langkah, dan langkah tengahnya bukan hiasan:
 *   1. pratinjau — siapa yang siap, siapa yang tertahan, dan mengapa
 *   2. unduh     — salinan .xlsx untuk arsip pesantren di luar sistem
 *   3. terapkan  — statusnya disetel, kartunya dilepas
 *
 * Nomor 2 diminta pemilik sistem: data alumni disimpan offline supaya tidak
 * bergantung pada sistem ini tetap hidup. Perlu dicatat bahwa barisnya TIDAK
 * dihapus setelah diunduh — delapan kunci asing dari buku besar menunjuk ke
 * sana, dan menghapusnya akan merusak neraca. Unduhan ini salinan, bukan
 * pemindahan.
 */
class ArsipController extends Controller
{
    private function lingkup(?string $kodeGolongan): string
    {
        $k = trim((string) $kodeGolongan);

        return ($k === '' || $k === 'siswa') ? 'santri' : 'sdm';
    }

    private function izinLingkup(Request $r, ?string $kodeGolongan): \App\Models\User
    {
        $u = $this->wajibPeran($r, 'superadmin');
        \App\Services\Izin::wajib($u, $this->lingkup($kodeGolongan) . '.arsip');

        return $u;
    }

    /**
     * Saringan golongan untuk layar yang sedang dilayani.
     *
     * Layar SDM & Personil memuat SELURUH golongan non-santri — sdm, tamu,
     * vip, dan golongan apa pun yang kelak ditambahkan admin. Menyaringnya
     * dengan `golongan = 'sdm'` akan melewatkan kartu tamu dan VIP tanpa ada
     * yang menyadarinya: pratinjaunya kosong, dan admin menyimpulkan tidak ada
     * yang perlu diarsipkan.
     */
    private function saring($q, string $gol)
    {
        return $this->lingkup($gol) === 'santri'
            ? $q->where('golongan', 'siswa')
            : $q->where('golongan', '!=', 'siswa');
    }

    /** Kandidat untuk satu kelas, lengkap dengan sebab tertahannya. */
    public function pratinjau(Request $r)
    {
        $kelas = trim((string) $r->query('kelas', ''));
        $gol = trim((string) $r->query('golongan', 'siswa'));
        $this->izinLingkup($r, $gol);

        if ($kelas === '') {
            throw new ApiError(400, 'Kelas wajib dipilih');
        }

        $rows = $this->saring(Siswa::query(), $gol)
            ->where('kelas', strtoupper($kelas))
            ->whereIn('status', ['aktif', 'blokir'])
            ->orderBy('nama')->get();

        $daftar = $rows->map(fn ($s) => [
            'id' => $s->id, 'nis' => $s->nis, 'nama' => $s->nama, 'kelas' => $s->kelas,
            'status' => $s->status, 'saldo' => (int) $s->saldo,
            'saldo_titipan' => (int) $s->saldo_titipan,
            'card_uid' => $s->card_uid,
            'penahan' => Arsip::penahan($s),
        ])->values();

        $siap = $daftar->filter(fn ($x) => $x['penahan'] === []);

        return response()->json([
            'kelas' => strtoupper($kelas),
            'golongan' => $gol,
            'daftar' => $daftar,
            'ringkas' => [
                'n' => $daftar->count(),
                'n_siap' => $siap->count(),
                'n_tertahan' => $daftar->count() - $siap->count(),
                // Total uang yang masih harus diselesaikan sebelum seangkatan
                // bisa diarsipkan — angka inilah yang dicari bendahara.
                'sisa_saldo' => $daftar->sum('saldo'),
                'sisa_titipan' => $daftar->sum('saldo_titipan'),
            ],
        ]);
    }

    /** Salinan .xlsx untuk arsip pesantren, sebelum status diubah. */
    public function unduh(Request $r)
    {
        $kelas = trim((string) $r->query('kelas', ''));
        $gol = trim((string) $r->query('golongan', 'siswa'));
        $this->izinLingkup($r, $gol);

        $q = $this->saring(Siswa::query(), $gol);
        if ($kelas !== '') {
            $q->where('kelas', strtoupper($kelas));
        }
        // Tanpa kelas: yang diunduh adalah seluruh yang SUDAH terarsip — dipakai
        // untuk mengambil ulang salinan angkatan lama.
        $q->whereIn('status', $kelas !== '' ? ['aktif', 'blokir'] : Kelas::ARSIP);
        $rows = $q->orderByRaw(Kelas::urutSql())->orderBy('nama')->get();

        if ($rows->isEmpty()) {
            throw new ApiError(404, 'Tidak ada data untuk diunduh');
        }

        // Jumlah transaksi & nilai belanja seumur kartu: ringkasan inilah yang
        // membuat berkas ini berguna sebagai arsip, bukan sekadar daftar nama.
        $rekap = Transaksi::whereIn('siswa_id', $rows->pluck('id'))
            ->selectRaw('siswa_id, COUNT(*) n, COALESCE(SUM(CASE WHEN jumlah < 0 THEN -jumlah ELSE 0 END),0) belanja')
            ->groupBy('siswa_id')->get()->keyBy('siswa_id');

        $judul = ['No', 'NIS/NIY', 'Nama', 'Kelas/Unit', 'Golongan', 'Status',
            'Tanggal Keluar', 'NISN', 'Tanggal Lahir', 'HP Wali', 'Jabatan',
            'Saldo Akhir', 'Titipan Akhir', 'Jumlah Transaksi', 'Total Belanja', 'Catatan Arsip'];

        $book = new Spreadsheet;
        $sheet = $book->getActiveSheet();
        $sheet->setTitle('Arsip');
        foreach ($judul as $i => $h) {
            $sheet->setCellValue([$i + 1, 1], $h);
            $sheet->getColumnDimensionByColumn($i + 1)->setWidth(max(10, min(34, strlen($h) + 6)));
        }
        $sheet->getStyle('A1:P1')->getFont()->setBold(true);
        $sheet->freezePane('A2');

        foreach ($rows as $i => $s) {
            $rk = $rekap[$s->id] ?? null;
            $isi = [
                $i + 1, $s->nis, $s->nama, $s->kelas, $s->golongan, $s->status,
                substr((string) $s->arsip_pada, 0, 10), $s->nisn, $s->tgl_lahir, $s->hp_ortu,
                $s->jabatan, (int) $s->saldo, (int) $s->saldo_titipan,
                (int) ($rk->n ?? 0), (int) ($rk->belanja ?? 0), $s->arsip_catatan,
            ];
            foreach ($isi as $k => $v) {
                // Nomor induk ditulis sebagai teks: nol di depan hilang kalau
                // Excel memperlakukannya sebagai angka.
                if ($k === 1) {
                    $sheet->setCellValueExplicit([$k + 1, $i + 2], (string) $v,
                        \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
                } else {
                    $sheet->setCellValue([$k + 1, $i + 2], $v);
                }
            }
        }

        $this->lembarKeterangan($book, $rows->count(), $kelas);

        $tmp = tempnam(sys_get_temp_dir(), 'arsip');
        (new Xlsx($book))->save($tmp);
        $isiBerkas = file_get_contents($tmp);
        @unlink($tmp);

        $nama = 'arsip-' . ($gol === 'siswa' ? 'santri' : $gol)
            . ($kelas !== '' ? '-' . preg_replace('/[^A-Za-z0-9]/', '', $kelas) : '-terarsip')
            . '-' . date('Y-m-d') . '.xlsx';

        return response($isiBerkas, 200, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Content-Disposition' => "attachment; filename=\"{$nama}\"",
        ]);
    }

    /** Arsipkan satu kartu. */
    public function satu(Request $r, int $id)
    {
        $s = Siswa::find($id);
        if (! $s) {
            throw new ApiError(404, 'Data pemegang kartu tidak ditemukan');
        }
        $u = $this->izinLingkup($r, $s->golongan);

        $hasil = Arsip::arsipkan($s, (string) $r->input('status'),
            (string) $r->input('catatan', ''), $u->nama);

        return response()->json(['ok' => true, 'siswa' => $hasil]);
    }

    /** Kembalikan kartu terarsip ke peredaran. */
    public function aktifkan(Request $r, int $id)
    {
        $s = Siswa::find($id);
        if (! $s) {
            throw new ApiError(404, 'Data pemegang kartu tidak ditemukan');
        }
        $u = $this->izinLingkup($r, $s->golongan);

        return response()->json(['ok' => true, 'siswa' => Arsip::aktifkan($s, $u->nama)]);
    }

    /**
     * Arsipkan sekelas sekaligus.
     *
     * Yang tertahan DILEWATI, bukan menggagalkan seluruh operasi: satu santri
     * yang saldonya belum diselesaikan tidak boleh menahan 29 lainnya, dan
     * bendahara tetap melihat daftar sisanya untuk dikerjakan.
     */
    public function massal(Request $r)
    {
        $gol = trim((string) $r->input('golongan', 'siswa'));
        $u = $this->izinLingkup($r, $gol);

        $kelas = strtoupper(trim((string) $r->input('kelas', '')));
        if ($kelas === '') {
            throw new ApiError(400, 'Kelas wajib dipilih');
        }
        $status = Kelas::wajibStatusArsip($r->input('status'));
        $catatan = (string) $r->input('catatan', '');

        $ids = $r->input('siswa_ids');
        $q = $this->saring(Siswa::query(), $gol)->where('kelas', $kelas)
            ->whereIn('status', ['aktif', 'blokir']);
        // Daftar id yang dikirim klien menyempitkan sasaran, tidak melebarkannya:
        // kelas dan golongan tetap disaring di server.
        if (is_array($ids) && $ids) {
            $q->whereIn('id', array_slice(array_map('intval', $ids), 0, 1000));
        }

        $berhasil = [];
        $gagal = [];
        foreach ($q->orderBy('nama')->get() as $s) {
            try {
                Arsip::arsipkan($s, $status, $catatan, $u->nama);
                $berhasil[] = ['id' => $s->id, 'nis' => $s->nis, 'nama' => $s->nama];
            } catch (ApiError $e) {
                $gagal[] = ['id' => $s->id, 'nis' => $s->nis, 'nama' => $s->nama,
                    'pesan' => $e->getMessage()];
            }
        }

        return response()->json([
            'ok' => true, 'kelas' => $kelas, 'status' => $status,
            'n_berhasil' => count($berhasil), 'n_gagal' => count($gagal),
            'berhasil' => $berhasil, 'gagal' => $gagal,
        ]);
    }

    private function lembarKeterangan(Spreadsheet $book, int $n, string $kelas): void
    {
        $sheet = $book->createSheet();
        $sheet->setTitle('Keterangan');
        $sheet->getColumnDimension('A')->setWidth(110);

        $teks = [
            'Arsip pemegang kartu — IMBOS Cashless',
            '',
            'Berkas ini dibuat ' . date('d/m/Y H:i') . ' WIB dan memuat ' . $n . ' baris'
                . ($kelas !== '' ? ' dari kelas ' . strtoupper($kelas) : ' yang sudah terarsip') . '.',
            '',
            'Simpan berkas ini di luar sistem (Drive, komputer administrasi) sebagai arsip pesantren',
            'yang berdiri sendiri. Kalau sistem ini suatu saat diganti, data di sini tetap terbaca.',
            '',
            'Istilah status:',
            '  alumni        — tamat SMA, keluar dari pesantren',
            '  lanjut_study  — tamat SMP lalu melanjutkan di luar pesantren ini',
            '  pindah        — keluar di tengah jalan',
            '  aktif/blokir  — belum diarsipkan (berkas ini diunduh sebelum pengarsipan)',
            '',
            'Catatan penting: baris santri TIDAK dihapus dari sistem setelah diarsipkan.',
            'Seluruh riwayat transaksinya menunjuk ke baris itu, dan neraca kas menghitung',
            'kewajiban sekolah darinya — menghapusnya akan membuat jejak uang hilang.',
            'Berkas ini salinan untuk disimpan, bukan pemindahan data keluar sistem.',
        ];
        foreach ($teks as $i => $t) {
            $sheet->setCellValue([1, $i + 1], $t);
        }
        $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(14);
    }
}
