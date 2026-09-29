<?php

namespace App\Services;

use App\Exceptions\ApiError;
use App\Models\Siswa;
use PhpOffice\PhpSpreadsheet\IOFactory;

/**
 * Pendaftaran UID kartu RFID secara kolektif, dari dua sumber:
 *
 *   - Berkas Excel/CSV (`urai`), untuk sekolah yang UID kartunya sudah
 *     tercetak/diketahui SEBELUM ditempel ke reader — polanya disamakan
 *     dengan top up massal & impor pemegang kartu: dua langkah, berkas
 *     diurai ULANG di server pada langkah kedua, sidik SHA-256 mengikat
 *     keduanya berkas yang sama.
 *   - Sesi tap berturut-turut (`uraiTap`), untuk kartu yang UID-nya baru
 *     diketahui saat ditempel ke reader. Klien mengumpulkan daftar
 *     (santri, UID) dari tap sungguhan lalu mengirim semuanya sekali jadi —
 *     tidak ada langkah pratinjau terpisah karena admin sudah melihat &
 *     mengoreksi daftarnya di layar sebelum menekan "Terapkan Semua".
 *
 * Keduanya bermuara ke pemeriksa baris yang SAMA (`nilaiBaris`), supaya
 * aturan "UID sudah dipakai orang lain", "kartu lama tidak boleh tertimpa
 * diam-diam", dan seterusnya tidak bisa berbeda antara dua jalur ini.
 */
class ImporKartuUid
{
    public const MAKS_BARIS = 500;

    public const JENIS = ['santri', 'sdm'];

    public const KOLOM = [
        ['kunci' => 'no', 'judul' => 'No', 'lebar' => 6],
        ['kunci' => 'nis', 'judul' => 'NIS/NIY', 'lebar' => 18],
        ['kunci' => 'nama', 'judul' => 'Nama', 'lebar' => 32],
        ['kunci' => 'uid', 'judul' => 'UID Kartu', 'lebar' => 22],
    ];

    private const PETUNJUK = [
        'uid' => '/uid|rfid|kartu/i',
        'nis' => '/\bniy\b|pegawai|\bnip\b|\bnis\b|induk/i',
        'nama' => '/nama/i',
        'no' => '/^no\.?$|nomor urut|urut/i',
    ];

    public static function wajibJenis(?string $j): string
    {
        $j = strtolower(trim((string) $j));
        if (! in_array($j, self::JENIS, true)) {
            throw new ApiError(400, 'Jenis harus "santri" atau "sdm"');
        }

        return $j;
    }

    public static function istilah(string $jenis): array
    {
        return $jenis === 'sdm'
            ? ['orang' => 'personil', 'nomor' => 'NIY/Nomor Pegawai']
            : ['orang' => 'santri', 'nomor' => 'NIS'];
    }

    /** Daftar pemegang kartu untuk lembar template — mengikuti lingkup jenis, seperti layar Santri/SDM. */
    public static function siswaUntukTemplate(string $jenis, string $kelas = '', bool $semua = false)
    {
        $q = $jenis === 'sdm' ? Siswa::where('golongan', '!=', 'siswa') : Siswa::where('golongan', 'siswa');
        $q->where('status', 'aktif');
        if ($kelas !== '') {
            $q->where('kelas', $kelas);
        }
        if (! $semua) {
            $q->where(fn ($w) => $w->whereNull('card_uid')->orWhere('card_uid', ''));
        }
        $q->when($jenis !== 'sdm', fn ($w) => $w->orderByRaw(Kelas::urutSql()));

        return $q->orderBy('nama')->get(['id', 'nis', 'nama', 'kelas', 'card_uid']);
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

    private static function barisDariBerkas(string $isi, string $nama): array
    {
        $tmp = tempnam(sys_get_temp_dir(), 'kartuuid');
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

            // Lembar "Petunjuk" pada template tidak berisi data; yang dipakai
            // adalah lembar pertama yang punya kolom NIS/NIY.
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

    private static function petakanKolom(array $baris): array
    {
        $batas = min(count($baris), 25);
        for ($i = 0; $i < $batas; $i++) {
            $teks = array_map([self::class, 'teks'], $baris[$i] ?? []);

            $adaNis = false;
            $adaUid = false;
            foreach ($teks as $t) {
                $adaNis = $adaNis || preg_match(self::PETUNJUK['nis'], $t);
                $adaUid = $adaUid || preg_match(self::PETUNJUK['uid'], $t);
            }
            if (! $adaNis || ! $adaUid) {
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

        throw new ApiError(400, 'Judul kolom tidak ditemukan — pastikan ada kolom NIS/NIY dan UID Kartu');
    }

    // ------------------------------------------------------------- urai

    /** Langkah pemeriksa yang dipakai bersama oleh jalur Excel maupun jalur tap. */
    private static function nilaiBaris(
        int $barisKe, string $nisTeks, string $namaBerkas, string $uidMentah, string $jenis,
        bool $abaikanNama, bool $izinkanGanti, array &$uidTerlihat, array &$nisTerlihat,
    ): array {
        $b = [
            'baris' => $barisKe, 'nis' => $nisTeks, 'nama_berkas' => $namaBerkas,
            'uid' => '', 'uid_lama' => '', 'siswa_id' => null, 'nama_db' => '', 'kelas' => '',
            'status' => 'gagal', 'pesan' => '',
        ];
        // Closure BUKAN arrow-fn dan menangkap $b lewat REFERENSI dengan sengaja:
        // $b terus dimutasi (siswa_id, uid, dst.) seiring pemeriksaan berjalan,
        // dan pesan gagal di baris-baris akhir harus tetap membawa data itu —
        // arrow-fn akan membekukan $b pada keadaan di titik ini saja.
        $gagal = function (string $pesan) use (&$b) {
            $b['status'] = 'gagal';
            $b['pesan'] = $pesan;

            return $b;
        };

        if ($nisTeks === '') {
            return $gagal(self::istilah($jenis)['nomor'] . ' kosong');
        }
        $s = self::cariSiswa($nisTeks, $jenis);
        if (! $s) {
            return $gagal(self::istilah($jenis)['nomor'] . " {$nisTeks} tidak terdaftar di data " .
                self::istilah($jenis)['orang']);
        }
        $b['siswa_id'] = $s->id;
        $b['nama_db'] = $s->nama;
        $b['kelas'] = $s->kelas;
        $b['nis'] = $s->nis;

        $uid = BidangSiswa::uid($uidMentah);
        if (! $uid) {
            return $gagal('UID Kartu kosong');
        }
        if (strlen($uid) < 6) {
            return $gagal("UID \"{$uid}\" terlalu pendek (minimal 6 karakter) — pastikan tersalin lengkap dari kartu");
        }
        $b['uid'] = $uid;

        if ($namaBerkas !== '' && self::seragam($namaBerkas) !== self::seragam($s->nama)) {
            if (! $abaikanNama) {
                return $gagal("Nama pada berkas \"{$namaBerkas}\" berbeda dengan data {$b['nis']} (\"{$s->nama}\")");
            }
            $b['pesan'] = "Nama berbeda (\"{$namaBerkas}\" vs \"{$s->nama}\") — diterapkan atas persetujuan admin";
        }

        if (isset($uidTerlihat[$uid]) && $uidTerlihat[$uid] !== $b['siswa_id']) {
            return $gagal("UID {$uid} sudah dipakai baris/tap lain pada sesi yang sama");
        }
        if (isset($nisTerlihat[$b['siswa_id']])) {
            return $gagal("{$s->nama} sudah muncul di baris/tap lain pada sesi yang sama");
        }

        if ($s->card_uid && $s->card_uid !== $uid) {
            $b['uid_lama'] = $s->card_uid;
            if (! $izinkanGanti) {
                return $gagal("{$s->nama} sudah punya kartu terdaftar (UID lain) — centang " .
                    '"izinkan ganti kartu" untuk menimpanya');
            }
        } elseif ($s->card_uid === $uid) {
            $b['pesan'] = $b['pesan'] ?: 'UID sama seperti kartu yang sudah terdaftar — tidak berubah';
        }

        if ($bentrok = Siswa::where('card_uid', $uid)->where('id', '!=', $s->id)->first()) {
            return $gagal("UID {$uid} sudah dipakai kartu {$bentrok->nama}");
        }

        $uidTerlihat[$uid] = $b['siswa_id'];
        $nisTerlihat[$b['siswa_id']] = true;
        $b['status'] = 'siap';

        return $b;
    }

    public static function urai(
        string $isi, string $nama, string $jenis, bool $abaikanNama = false, bool $izinkanGanti = false,
    ): array {
        ['baris' => $baris, 'lembar' => $lembar] = self::barisDariBerkas($isi, $nama);
        ['peta' => $peta, 'barisKepala' => $barisKepala] = self::petakanKolom($baris);

        $hasil = [];
        $uidTerlihat = [];
        $nisTerlihat = [];

        for ($i = $barisKepala + 1; $i < count($baris); $i++) {
            $row = $baris[$i] ?? [];
            $sel = fn (string $k) => isset($peta[$k]) ? ($row[$peta[$k]] ?? null) : null;

            $nisTeks = preg_replace('/\s+/', '', self::teks($sel('nis')));
            $namaBerkas = self::teks($sel('nama'));
            $uidTeks = self::teks($sel('uid'));

            // Baris kosong, dan baris yang rostenya terisi tapi kolom UID belum
            // diisi (roster ditampilkan penuh, admin baru mengisi sebagian saat
            // ini) — DUA-duanya dilewati diam-diam, bukan dilaporkan gagal.
            if ($nisTeks === '' && $namaBerkas === '' && $uidTeks === '') {
                continue;
            }
            if ($uidTeks === '') {
                continue;
            }
            if (count($hasil) >= self::MAKS_BARIS) {
                throw new ApiError(400, 'Maksimal ' . self::MAKS_BARIS . ' baris berisi UID per berkas — pecah berkasnya');
            }

            $hasil[] = self::nilaiBaris(
                $i + 1, $nisTeks, $namaBerkas, $uidTeks, $jenis, $abaikanNama, $izinkanGanti, $uidTerlihat, $nisTerlihat,
            );
        }

        if (! $hasil) {
            throw new ApiError(400, 'Tidak ada baris dengan UID Kartu terisi — isi kolom UID untuk kartu yang mau didaftarkan');
        }

        return ['lembar' => $lembar, 'baris' => $hasil, 'ringkas' => self::ringkas($hasil)];
    }

    /**
     * @param array $items daftar ['siswa_id' => int, 'uid' => string, 'nama' => string opsional]
     *   hasil tap sungguhan di klien. `nama` dikirim sekadar untuk dicocokkan
     *   ulang (jaga-jaga data di layar admin sempat berubah selama sesi tap).
     */
    public static function uraiTap(array $items, string $jenis, bool $izinkanGanti = false): array
    {
        if (! $items) {
            throw new ApiError(400, 'Belum ada kartu yang ditap');
        }
        if (count($items) > self::MAKS_BARIS) {
            throw new ApiError(400, 'Maksimal ' . self::MAKS_BARIS . ' kartu per sesi — terapkan dulu yang ini');
        }

        $hasil = [];
        $uidTerlihat = [];
        $nisTerlihat = [];
        foreach ($items as $i => $it) {
            $siswaId = (int) ($it['siswa_id'] ?? 0);
            $s = $siswaId ? Siswa::find($siswaId) : null;
            $nis = $s?->nis ?? '';
            $hasil[] = self::nilaiBaris(
                $i + 1, $nis, (string) ($it['nama'] ?? ''), (string) ($it['uid'] ?? ''),
                $jenis, true, $izinkanGanti, $uidTerlihat, $nisTerlihat,
            );
        }

        return ['lembar' => 'tap', 'baris' => $hasil, 'ringkas' => self::ringkas($hasil)];
    }

    private static function ringkas(array $hasil): array
    {
        $siap = array_values(array_filter($hasil, fn ($r) => $r['status'] === 'siap'));

        return [
            'n_baris' => count($hasil),
            'n_siap' => count($siap),
            'n_gagal' => count($hasil) - count($siap),
            'n_ganti' => count(array_filter($siap, fn ($r) => $r['uid_lama'] !== '')),
        ];
    }

    // ---------------------------------------------------------- pembaca sel

    public static function teks($v): string
    {
        return $v === null ? '' : trim((string) $v);
    }

    private static function seragam(?string $s): string
    {
        return trim(preg_replace('/[^a-z0-9]+/', ' ', mb_strtolower((string) $s)));
    }

    /** Pencarian NIS/NIY dipersempit sesuai jenis — santri tidak bisa menimpa kartu SDM, dan sebaliknya. */
    private static function cariSiswa(string $nis, string $jenis): ?Siswa
    {
        $q = fn () => $jenis === 'sdm' ? Siswa::where('golongan', '!=', 'siswa') : Siswa::where('golongan', 'siswa');

        $tepat = (clone $q())->where('nis', $nis)->first();
        if ($tepat) {
            return $tepat;
        }
        if (! preg_match('/^\d+$/', $nis)) {
            return null;
        }
        // Nol di depan sering hilang saat Excel menyimpan NIS sebagai angka.
        return (clone $q())->whereRaw('CAST(nis AS UNSIGNED) = ?', [(int) $nis])->first();
    }
}
