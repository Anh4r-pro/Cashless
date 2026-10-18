<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\ApiError;
use App\Models\AlasanPotong;
use App\Models\Setting;
use App\Models\Siswa;
use App\Services\Neraca;
use App\Services\Pengaturan;
use App\Services\Rupiah;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Pengaturan sekolah, neraca kas, kategori pemotongan, dan daftar kelas.
 */
class PengaturanController extends Controller
{
    private const MAX_ALASAN = 30;

    /** Batas pokok yang berlaku untuk seluruh sekolah. */
    public const LABEL_POKOK = [
        'max_saldo' => ['label' => 'Max saldo santri', 'unit' => 'rp'],
        'max_topup_harian' => ['label' => 'Max top up santri / hari', 'unit' => 'rp'],
        'max_beli_harian' => ['label' => 'Max jumlah transaksi santri / hari', 'unit' => 'kali'],
        'max_belanja_harian' => ['label' => 'Max akumulasi transaksi santri/hari', 'unit' => 'rp'],
        'max_per_transaksi' => ['label' => 'Max per transaksi', 'unit' => 'rp'],
    ];

    /**
     * Jenjang kelas santri.
     *
     * Dipakai aturan kenaikan kelas: tingkat tertinggi berujung pada Alumni,
     * dan tingkat di luar rentang ini ditandai sebagai kelas menyimpang, bukan
     * dinaikkan. Disimpan sebagai pengaturan supaya membuka jenjang baru —
     * MI/Ibtidaiyah dari kelas 1, atau tingkat 13 — cukup mengubah angka di
     * sini, bukan menyunting kode.
     */
    public const LABEL_KELAS = [
        'kelas_tingkat_awal' => ['label' => 'Kelas terendah', 'unit' => 'tingkat'],
        'kelas_tingkat_lulus' => ['label' => 'Kelas tertinggi (berujung Alumni)', 'unit' => 'tingkat'],
    ];

    /** Saldo titipan & isi ulang otomatis. */
    public const LABEL_TITIPAN = [
        'titipan_aktif' => ['label' => 'Kelebihan top up disimpan jadi titipan', 'unit' => 'onoff'],
        'max_titipan' => ['label' => 'Max saldo titipan / santri', 'unit' => 'rp'],
        'smart_aktif' => ['label' => 'Smart top up otomatis', 'unit' => 'onoff'],
        'smart_ambang' => ['label' => 'Isi ulang saat saldo turun sampai', 'unit' => 'rp'],
        'smart_isi' => ['label' => 'Isi ulang sampai saldo menjadi', 'unit' => 'rp'],
    ];

    /**
     * Batas belanja NON-MAKANAN (barang koperasi). Angka-angka ini berdiri
     * sendiri, tidak memotong jatah belanja makanan — itu justru alasan
     * kategorinya dipisah: membeli handuk tidak boleh menghabiskan uang makan
     * siang hari itu, dan sebaliknya.
     */
    public const LABEL_NONMAKANAN = [
        'nonmakanan_aktif' => ['label' => 'Batas khusus non-makanan berlaku', 'unit' => 'onoff'],
        'max_nonmakanan_harian' => ['label' => 'Max belanja non-makanan / hari', 'unit' => 'rp'],
        'max_beli_nonmakanan_harian' => ['label' => 'Max transaksi non-makanan / hari', 'unit' => 'kali'],
        'max_nonmakanan_transaksi' => ['label' => 'Max non-makanan per transaksi', 'unit' => 'rp'],
    ];

    /**
     * Transfer saldo antar teman lewat kios. Batasnya sengaja jauh lebih ketat
     * daripada batas belanja: fitur ini memindahkan uang tanpa ada barang yang
     * berpindah, jadi ia juga jalur paling menggoda untuk memeras uang jajan
     * teman. Batas TERIMA menahan satu kartu dipakai menampung setoran banyak anak.
     */
    public const LABEL_TRANSFER = [
        'transfer_aktif' => ['label' => 'Transfer saldo antar teman', 'unit' => 'onoff'],
        // Mati sampai admin yakin gender SELURUH santri sudah ditetapkan massal
        // per kelas (layar Santri → Set Gender Massal) — lihat Services\Transfer.
        // Sambil mati, transfer berperilaku PERSIS seperti sebelum sakelar ini ada.
        'transfer_gender_aktif' => ['label' => 'Batasi transfer hanya sesama gender (kecuali keluarga)', 'unit' => 'onoff'],
        'transfer_min' => ['label' => 'Nominal terkecil sekali kirim', 'unit' => 'rp'],
        'transfer_maks' => ['label' => 'Nominal terbesar sekali kirim', 'unit' => 'rp'],
        'transfer_maks_harian' => ['label' => 'Max total kirim / orang / hari', 'unit' => 'rp'],
        'transfer_terima_harian' => ['label' => 'Max total terima / orang / hari', 'unit' => 'rp'],
        'transfer_maks_kali_harian' => ['label' => 'Max jumlah kiriman / orang / hari', 'unit' => 'kali'],
    ];

    public const LABEL_ORTU = [
        'ortu_minta_hp' => ['label' => 'Minta 4 digit HP wali saat masuk portal', 'unit' => 'onoff'],
        'ortu_lihat_titipan' => ['label' => 'Wali boleh melihat riwayat titipan', 'unit' => 'onoff'],
    ];

    /**
     * Iuran ke PJ (SDM/pegawai) lewat kios. Batasnya sengaja TERPISAH dari
     * transfer_* meski mesinnya sama — lihat catatan di migrasi
     * `2026_09_29_000200_iuran_pj`. Siapa yang jadi PJ ditandai per orang di
     * data SDM (kolom "Boleh terima iuran"), bukan di sini.
     */
    public const LABEL_IURAN = [
        'iuran_aktif' => ['label' => 'Iuran ke PJ lewat kios', 'unit' => 'onoff'],
        'iuran_min' => ['label' => 'Nominal terkecil sekali bayar', 'unit' => 'rp'],
        'iuran_maks' => ['label' => 'Nominal terbesar sekali bayar', 'unit' => 'rp'],
        'iuran_maks_harian' => ['label' => 'Max total bayar / santri / hari', 'unit' => 'rp'],
        'iuran_terima_harian' => ['label' => 'Max total diterima / PJ / hari', 'unit' => 'rp'],
        'iuran_maks_kali_harian' => ['label' => 'Max jumlah pembayaran / santri / hari', 'unit' => 'kali'],
    ];

    /**
     * Kunci yang tidak terdaftar di sini TIDAK bisa disentuh dari HTTP sama
     * sekali — termasuk penanda internal seperti `alasan_potong_terisi` dan
     * angka neraca yang hanya boleh ditulis oleh perhitungannya sendiri.
     */
    public static function labelSemua(): array
    {
        return array_merge(self::LABEL_POKOK, self::LABEL_TITIPAN, self::LABEL_NONMAKANAN,
            self::LABEL_TRANSFER, self::LABEL_IURAN, self::LABEL_ORTU, self::LABEL_KELAS);
    }

    public function index(Request $r)
    {
        $this->wajibPeran($r, 'superadmin');

        return response()->json([
            'settings' => Pengaturan::sekolah(),
            'labels' => self::LABEL_POKOK,
            'labels_titipan' => self::LABEL_TITIPAN,
            'labels_nonmakanan' => self::LABEL_NONMAKANAN,
            'labels_transfer' => self::LABEL_TRANSFER,
            'labels_iuran' => self::LABEL_IURAN,
            'labels_ortu' => self::LABEL_ORTU,
            'labels_kelas' => self::LABEL_KELAS,
        ]);
    }

    public function simpan(Request $r)
    {
        $this->wajibPeran($r, 'superadmin');
        $b = $r->all();

        DB::transaction(function () use ($b) {
            foreach (self::labelSemua() as $key => $def) {
                if (! array_key_exists($key, $b)) {
                    continue;
                }
                if (! is_numeric($b[$key])) {
                    throw new ApiError(400, "Nilai \"{$def['label']}\" tidak valid");
                }
                $v = (int) floor((float) $b[$key]);

                // Sakelar hidup/mati boleh bernilai 0; batas berupa angka tidak
                // boleh, karena batas 0 artinya "melarang segalanya" dan hampir
                // pasti salah ketik.
                if ($def['unit'] === 'onoff') {
                    if ($v !== 0 && $v !== 1) {
                        throw new ApiError(400, "\"{$def['label']}\" hanya bisa dinyalakan (1) atau dimatikan (0)");
                    }
                } elseif ($v <= 0) {
                    throw new ApiError(400, "Nilai \"{$def['label']}\" harus lebih dari nol");
                }
                Setting::simpan($key, $v);
            }
            Pengaturan::lupakanCache();

            $c = Pengaturan::sekolah();
            // Isi ulang sampai angka di bawah ambang berarti smart top up
            // memicu dirinya sendiri berulang kali tanpa pernah melewati
            // ambang — dicegah di sini.
            if ($c['smart_isi'] <= $c['smart_ambang']) {
                throw new ApiError(400, '"' . self::LABEL_TITIPAN['smart_isi']['label'] . '" (' . Rupiah::f($c['smart_isi']) .
                    ') harus lebih besar dari "' . self::LABEL_TITIPAN['smart_ambang']['label'] . '" (' . Rupiah::f($c['smart_ambang']) . ')');
            }
            if ($c['kelas_tingkat_lulus'] <= $c['kelas_tingkat_awal']) {
                throw new ApiError(400, '"' . self::LABEL_KELAS['kelas_tingkat_lulus']['label'] . '" (' .
                    $c['kelas_tingkat_lulus'] . ') harus lebih besar dari "' .
                    self::LABEL_KELAS['kelas_tingkat_awal']['label'] . '" (' . $c['kelas_tingkat_awal'] . ')');
            }
            // Rentang yang mustahil hampir selalu salah ketik, dan akibatnya
            // fatal: kelas di luar rentang tidak akan dinaikkan sama sekali.
            if ($c['kelas_tingkat_lulus'] > 15 || $c['kelas_tingkat_awal'] > 15) {
                throw new ApiError(400, 'Tingkat kelas maksimal 15 — angka di atas itu hampir pasti salah ketik');
            }
            if ($c['transfer_min'] > $c['transfer_maks']) {
                throw new ApiError(400, '"' . self::LABEL_TRANSFER['transfer_min']['label'] . '" (' . Rupiah::f($c['transfer_min']) .
                    ') tidak boleh lebih besar dari "' . self::LABEL_TRANSFER['transfer_maks']['label'] . '" (' . Rupiah::f($c['transfer_maks']) . ')');
            }
            // Ambang yang lebih tinggi dari batas saldo TIDAK ditolak:
            // menurunkan batas saldo kartu adalah tindakan yang sah, dan
            // SmartTopup::aturan() sudah memampatkan kedua angka itu ke dalam
            // batas yang berlaku. Menolaknya di sini hanya memaksa admin
            // menyetel dua hal dalam urutan tertentu.
        });

        Pengaturan::lupakanCache();

        return response()->json(['ok' => true, 'settings' => Pengaturan::sekolah()]);
    }

    // ------------------------------------------------------------- neraca

    public function neraca(Request $r)
    {
        $this->wajibPeran($r, 'superadmin');

        return response()->json(Neraca::hitung());
    }

    public function saldoAwal(Request $r)
    {
        $this->wajibPeran($r, 'superadmin');
        if ($r->input('konfirmasi') !== true) {
            throw new ApiError(400, 'Penetapan saldo awal harus dikonfirmasi');
        }
        $hasil = Neraca::tetapkanSaldoAwal();

        return response()->json(array_merge(['ok' => true], $hasil, ['neraca' => Neraca::hitung()]));
    }

    // ------------------------------------------- kategori alasan pemotongan

    public function alasan(Request $r)
    {
        $this->wajibPeran($r, 'superadmin');
        // Halaman pemotongan hanya perlu yang aktif; layar pengelolaan minta semua.
        $q = AlasanPotong::orderBy('urutan')->orderBy('id');
        if ($r->query('semua') !== '1') {
            $q->where('aktif', true);
        }

        return response()->json(['alasan' => $q->get()]);
    }

    /**
     * Simpan seluruh daftar sekaligus: urutan mengikuti urutan array, dan baris
     * yang hilang dari kiriman berarti dihapus. Satu transaksi basis data supaya
     * daftar tidak pernah tersimpan setengah jadi.
     */
    public function simpanAlasan(Request $r)
    {
        $this->wajibPeran($r, 'superadmin');
        $kiriman = $r->input('alasan');
        if (! is_array($kiriman)) {
            throw new ApiError(400, 'Format daftar kategori tidak valid');
        }
        if (count($kiriman) > self::MAX_ALASAN) {
            throw new ApiError(400, 'Maksimal ' . self::MAX_ALASAN . ' kategori');
        }

        $bersih = [];
        $terlihat = [];
        foreach ($kiriman as $a) {
            $teks = mb_substr(preg_replace('/\s+/', ' ', trim((string) ($a['teks'] ?? ''))), 0, 100);
            if (mb_strlen($teks) < 3) {
                throw new ApiError(400, 'Setiap kategori minimal 3 huruf');
            }
            $kunci = mb_strtolower($teks);
            if (isset($terlihat[$kunci])) {
                throw new ApiError(409, "Kategori \"{$teks}\" tertulis dua kali");
            }
            $terlihat[$kunci] = true;
            $bersih[] = [
                'id' => isset($a['id']) && is_int($a['id']) ? $a['id'] : null,
                'teks' => $teks,
                'aktif' => ($a['aktif'] ?? true) !== false,
            ];
        }

        $hasil = DB::transaction(function () use ($bersih) {
            $adaId = AlasanPotong::pluck('id')->all();
            $simpan = [];
            foreach ($bersih as $i => $a) {
                if ($a['id'] !== null && in_array($a['id'], $adaId, true)) {
                    AlasanPotong::where('id', $a['id'])
                        ->update(['teks' => $a['teks'], 'urutan' => $i, 'aktif' => $a['aktif']]);
                    $simpan[] = $a['id'];
                } else {
                    $simpan[] = AlasanPotong::create([
                        'teks' => $a['teks'], 'urutan' => $i, 'aktif' => $a['aktif'],
                    ])->id;
                }
            }
            AlasanPotong::whereNotIn('id', $simpan ?: [0])->delete();

            return AlasanPotong::orderBy('urutan')->orderBy('id')->get();
        });

        return response()->json(['ok' => true, 'alasan' => $hasil]);
    }

    /** Daftar kelas yang benar-benar terpakai — untuk saringan di layar riwayat. */
    public function kelas(Request $r)
    {
        $this->wajibPeran($r, 'tenant', 'superadmin');

        return response()->json([
            // Diurutkan sadar-angka: `orderBy('kelas')` mengurutkan sebagai teks,
            // sehingga 10A melompat ke depan 7A begitu jenjang SMA masuk.
            'kelas' => Siswa::where('kelas', '!=', '')->distinct()
                ->orderByRaw(\App\Services\Kelas::urutSql())->pluck('kelas'),
            // Daftar Unit untuk SDM & personil datang dari tabel unit_sdm (bisa
            // disunting admin), bukan dari data siswa: pilihannya harus tetap
            // lengkap walau belum ada satu pun personil di unit tersebut.
            'unit_sdm' => \App\Models\UnitSdm::aktifNama(),
        ]);
    }
}
