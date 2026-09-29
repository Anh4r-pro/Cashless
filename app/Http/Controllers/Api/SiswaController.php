<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\ApiError;
use App\Models\Golongan;
use App\Models\SesiOrtu;
use App\Models\Siswa;
use App\Models\SiswaPin;
use App\Models\Transaksi;
use App\Services\BidangSiswa;
use App\Services\Izin;
use App\Services\Kelas;
use App\Services\Berkas;
use App\Services\Pengaturan;
use App\Services\Pin;
use App\Services\Rem;
use App\Services\SesiKios;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Data pemegang kartu — santri maupun SDM, tamu, dan VIP.
 *
 * Layar Santri dan layar SDM memakai endpoint yang SAMA, hanya berbeda saringan
 * golongan. Tanpa parameter apa pun yang tampil tetap hanya santri, supaya menu
 * Santri tidak tiba-tiba kemasukan guru dan tamu setelah server diperbarui.
 *
 * Karena satu endpoint melayani dua layar, hak aksesnya TIDAK bisa dipasang di
 * rute — ia baru ketahuan setelah golongan yang diminta dibaca. Karena itu tiap
 * aksi memanggil lingkupIzin() sendiri, dan `santri.*` terpisah penuh dari
 * `sdm.*`: admin data santri bisa diberi hak mengubah santri tanpa ikut
 * memegang data pegawai.
 */
class SiswaController extends Controller
{
    /**
     * Layar mana yang sedang dilayani, dilihat dari kode golongan.
     *
     * Golongan berkode 'siswa' (atau tanpa golongan sama sekali) berarti layar Santri;
     * apa pun selainnya — termasuk daftar berkoma seperti "sdm,tamu,vip" —
     * berarti layar SDM & Personil.
     */
    private function lingkup(?string $kode): string
    {
        $k = trim((string) $kode);

        return ($k === '' || $k === 'siswa') ? 'santri' : 'sdm';
    }

    /** Pagar per aksi, dengan lingkup ditentukan golongan yang bersangkutan. */
    private function lingkupIzin(Request $r, ?string $kodeGolongan, string $aksi): \App\Models\User
    {
        $u = $this->wajibPeran($r, 'superadmin');
        Izin::wajib($u, $this->lingkup($kodeGolongan) . '.' . $aksi);

        return $u;
    }

    public function index(Request $r)
    {
        $u = $this->wajibPeran($r, 'superadmin');
        $golMinta = $r->query('golongan');

        // "semua" melintasi kedua layar, jadi cukup salah satu haknya dipegang —
        // penyaringan barisnya sendiri tidak berubah.
        if ($golMinta === 'semua') {
            if (! Izin::boleh($u, 'santri.lihat') && ! Izin::boleh($u, 'sdm.lihat')) {
                throw new ApiError(403, 'Akun Anda tidak punya hak melihat data pemegang kartu');
            }
        } else {
            Izin::wajib($u, $this->lingkup($golMinta) . '.lihat');
        }
        $page = max(1, (int) $r->query('page', 1));
        // Batas 500 supaya satu kelas (bahkan satu tingkat) muat sekali ambil
        // untuk daftar pemotongan kolektif.
        $limit = min(500, (int) $r->query('limit', 20) ?: 20);

        $q = Siswa::query();

        if ($cari = $r->query('cari')) {
            // Kelas ikut dicari supaya admin bisa mengetik "7A" untuk menyaring sekelas.
            $like = '%' . $cari . '%';
            $q->where(fn ($w) => $w->where('nama', 'like', $like)
                ->orWhere('nis', 'like', $like)->orWhere('nisn', 'like', $like)
                ->orWhere('card_uid', 'like', $like)->orWhere('kelas', 'like', $like));
        }

        // Saring pemegang kartu yang datanya belum siap untuk portal wali.
        // Yang dihitung "belum siap" mengikuti kunci yang benar-benar berlaku:
        // kalau sekolah mematikan kunci 4 digit HP, nomor HP kosong bukan lagi
        // penghalang dan tidak perlu ikut dikejar admin.
        if ($r->query('ortu') === 'belum') {
            $mintaHp = (Pengaturan::sekolah()['ortu_minta_hp'] ?? 0) === 1;
            $q->where(function ($w) use ($mintaHp) {
                $w->where('nis', '')->orWhere('tgl_lahir', '');
                if ($mintaHp) {
                    $w->orWhere('hp_ortu', '');
                }
            });
        }
        if ($kelas = $r->query('kelas')) {
            $q->where('kelas', strtoupper((string) $kelas));
        }
        /*
         * Yang TERARSIP tidak ikut tampil kalau tidak diminta.
         *
         * Ini bukan sekadar kerapian: "jumlah santri" di dashboard dan di kepala
         * tabel dihitung dari kueri yang sama, dan angkatan yang sudah lulus
         * tidak boleh ikut terhitung sebagai santri sekolah. Untuk melihatnya,
         * status arsip harus disebut dengan sadar — atau 'arsip' untuk semuanya
         * sekaligus.
         */
        $status = (string) $r->query('status', '');
        if ($status === 'arsip') {
            $q->whereIn('status', Kelas::ARSIP);
        } elseif ($status !== '') {
            $q->where('status', $status);
        } else {
            $q->whereNotIn('status', Kelas::ARSIP);
        }

        /*
         * Saringan pembebasan biaya: supaya bendahara bisa melihat sekaligus
         * siapa saja yang dibebaskan, tanpa membuka data satu per satu.
         */
        if ($bi = $r->query('biaya')) {
            if ($bi === 'bebas') {
                $q->where('biaya_admin', 0);
            } elseif ($bi === 'khusus') {
                $q->whereNotNull('biaya_admin')->where('biaya_admin', '>', 0);
            } elseif ($bi === 'ikut') {
                $q->whereNull('biaya_admin');
            }
        }

        $gol = $r->query('golongan');
        if ($gol === 'semua') {
            // tanpa saringan golongan
        } elseif ($gol) {
            $kode = array_slice(array_filter(array_map('trim', explode(',', (string) $gol))), 0, 10);
            if (! $kode) {
                throw new ApiError(400, 'Golongan tidak dikenali');
            }
            $q->whereIn('golongan', $kode);
        } else {
            $q->where('golongan', 'siswa');
        }

        $total = (clone $q)->count();
        $jumlah = (clone $q)->selectRaw('COALESCE(SUM(saldo),0) s, COALESCE(SUM(saldo_titipan),0) t')->first();

        /*
         * Kolom urut dibatasi daftar putih: parameternya datang langsung dari
         * query string, dan `orderBy` tidak memparameterkan nama kolom — daftar
         * ini yang menutup jalan injeksi lewat `urut`.
         */
        $kolomUrut = ['nama', 'kelas', 'nis', 'saldo', 'status', 'golongan', 'berlaku_sampai', 'card_uid'];
        $urut = in_array($r->query('urut'), $kolomUrut, true) ? $r->query('urut') : 'nama';
        $arah = $r->query('arah') === 'desc' ? 'desc' : 'asc';
        $q->orderBy($urut, $arah);
        // Nama sebagai penentu kedua: baris dengan kelas/saldo/status yang sama
        // tidak melompat-lompat urutannya di antara satu halaman dan halaman berikutnya.
        if ($urut !== 'nama') {
            $q->orderBy('nama');
        }

        // pin_kustom: santri sudah mengganti PIN kios sendiri. Yang dikirim hanya
        // penanda ya/tidak — hash PIN tidak pernah keluar dari server.
        $rows = $q->select('siswa.*')
            ->selectRaw('EXISTS(SELECT 1 FROM siswa_pin p WHERE p.siswa_id = siswa.id) AS pin_kustom')
            ->limit($limit)->offset(($page - 1) * $limit)->get();

        return response()->json([
            'siswa' => $rows, 'total' => $total, 'page' => $page, 'limit' => $limit,
            'total_saldo' => (int) $jumlah->s, 'total_titipan' => (int) $jumlah->t,
            'golongan' => Golongan::orderBy('urutan')->orderBy('id')->get(),
        ]);
    }

    public function store(Request $r)
    {
        $b = $r->all();
        $this->lingkupIzin($r, $b['golongan'] ?? 'siswa', 'tambah');
        $nama = trim((string) ($b['nama'] ?? ''));
        if ($nama === '') {
            throw new ApiError(400, 'Nama wajib diisi');
        }
        $uid = BidangSiswa::uid($b['card_uid'] ?? null);
        if ($uid && Siswa::where('card_uid', $uid)->exists()) {
            throw new ApiError(409, 'Kartu sudah terdaftar untuk pemegang lain');
        }

        $s = Siswa::create(array_merge([
            'nis' => BidangSiswa::nisKhas($b['nis'] ?? '', null),
            'nama' => $nama,
            'kelas' => strtoupper(trim((string) ($b['kelas'] ?? ''))),
            'card_uid' => $uid,
        ], BidangSiswa::ortu($b, null, null), BidangSiswa::golongan($b, null)))->fresh();

        return response()->json(['ok' => true, 'siswa' => $s]);
    }

    public function update(Request $r, int $id)
    {
        $u = $this->wajibPeran($r, 'superadmin');
        $b = $r->all();
        $s = Siswa::find($id);
        if (! $s) {
            throw new ApiError(404, 'Data pemegang kartu tidak ditemukan');
        }
        // Hak diperiksa untuk lingkup ASAL maupun TUJUAN: memindahkan santri
        // menjadi pegawai (atau sebaliknya) menyentuh kedua layar, jadi tidak
        // boleh bisa dilakukan oleh yang hanya memegang salah satunya.
        Izin::wajib($u, $this->lingkup($s->golongan) . '.ubah');
        if (array_key_exists('golongan', $b)) {
            Izin::wajib($u, $this->lingkup((string) $b['golongan']) . '.ubah');
        }

        $uid = array_key_exists('card_uid', $b) ? BidangSiswa::uid($b['card_uid']) : $s->card_uid;
        if ($uid && $bentrok = Siswa::where('card_uid', $uid)->where('id', '!=', $s->id)->first()) {
            throw new ApiError(409, "Kartu sudah dipakai {$bentrok->nama}");
        }

        $o = BidangSiswa::ortu($b, $s, $s->id);
        $g = BidangSiswa::golongan($b, $s);
        $nis = BidangSiswa::nisKhas($b['nis'] ?? $s->nis, $s->id);
        // Nilai lama ditangkap SEBELUM update(): sesudahnya Eloquent sudah
        // menyinkronkan atribut asli, sehingga perbandingannya selalu sama.
        $lama = [
            'nis' => $s->nis, 'status' => $s->status, 'nisn' => $s->nisn,
            'tgl_lahir' => $s->tgl_lahir, 'hp_ortu' => $s->hp_ortu,
        ];

        $s->update(array_merge([
            'nis' => $nis,
            'nama' => trim((string) ($b['nama'] ?? $s->nama)),
            'kelas' => strtoupper(trim((string) ($b['kelas'] ?? $s->kelas))),
            'card_uid' => $uid,
            // Hanya aktif/blokir yang boleh disetel dari sini. Status arsip
            // (alumni / lanjut_study / pindah) sengaja TIDAK diterima: jalurnya
            // ada di ArsipController, yang menolak selama saldo belum nol —
            // kalau lewat sini, kewajiban bersaldo bisa lenyap dari neraca.
            'status' => in_array($b['status'] ?? null, ['aktif', 'blokir'], true) ? $b['status'] : $s->status,
        ], $o, $g));

        // Ganti kredensial (NIS / NISN / tgl lahir / HP) → sesi portal wali yang
        // lama ikut gugur, supaya pemegang data lama tidak terus melihat data anak.
        if ($nis !== $lama['nis'] || $o['nisn'] !== $lama['nisn']
            || $o['tgl_lahir'] !== $lama['tgl_lahir'] || $o['hp_ortu'] !== $lama['hp_ortu']) {
            SesiOrtu::where('siswa_id', $s->id)->delete();
        }
        // NIS adalah PIN bawaan kios, dan status menentukan boleh-tidaknya
        // berdonasi: begitu salah satunya berubah, sesi kios ikut ditutup.
        if ($nis !== $lama['nis'] || $s->status !== $lama['status']) {
            SesiKios::tutupSemua($s->id);
        }

        return response()->json(['ok' => true, 'siswa' => $s->fresh()]);
    }

    public function destroy(Request $r, int $id)
    {
        $s = Siswa::find($id);
        if (! $s) {
            throw new ApiError(404, 'Data pemegang kartu tidak ditemukan');
        }
        $this->lingkupIzin($r, $s->golongan, 'hapus');
        if (Transaksi::where('siswa_id', $id)->exists()) {
            throw new ApiError(409, 'Pemegang kartu ini punya riwayat transaksi — blokir saja, jangan dihapus');
        }
        try {
            DB::transaction(function () use ($id) {
                SesiOrtu::where('siswa_id', $id)->delete();   // lepas kunci asing
                SiswaPin::where('siswa_id', $id)->delete();
                SesiKios::tutupSemua($id);

                /*
                 * Baris impor top up DILEPAS, bukan dihapus: kolomnya memang
                 * nullable untuk baris yang tidak menemukan pemiliknya, jadi
                 * riwayat impornya tetap utuh sebagai catatan bendahara
                 * sementara santrinya boleh pergi. Baris yang berhasil selalu
                 * punya transaksi, dan itu sudah ditolak di atas.
                 */
                \App\Models\TopupImporBaris::where('siswa_id', $id)->update(['siswa_id' => null]);

                Siswa::where('id', $id)->delete();
            });
        } catch (\Illuminate\Database\QueryException $e) {
            /*
             * Kunci asing yang belum terpikirkan tidak boleh sampai ke admin
             * sebagai halaman galat 500. Rincian teknisnya tetap masuk log
             * Laravel — yang diganti hanya apa yang terbaca di layar.
             */
            throw new ApiError(409, 'Pemegang kartu ini masih dirujuk data lain dan belum bisa '
                . 'dihapus — blokir atau arsipkan saja');
        }

        return response()->json(['ok' => true]);
    }

    public function foto(Request $r, int $id)
    {
        $s = Siswa::find($id);
        if (! $s) {
            throw new ApiError(404, 'Data pemegang kartu tidak ditemukan');
        }
        $this->lingkupIzin($r, $s->golongan, 'ubah');
        $nama = Berkas::simpanGambarBase64((string) $r->input('data'), 'siswa-' . $s->id);
        Berkas::hapus($s->foto);
        $s->update(['foto' => $nama]);

        return response()->json(['ok' => true, 'foto' => $nama]);
    }

    public function assignCard(Request $r)
    {
        $s = Siswa::find((int) $r->input('siswa_id'));
        if (! $s) {
            throw new ApiError(404, 'Data pemegang kartu tidak ditemukan');
        }
        $this->lingkupIzin($r, $s->golongan, 'kartu');
        $uid = BidangSiswa::uid($r->input('uid'));
        if (! $uid) {
            throw new ApiError(400, 'UID kosong');
        }
        if ($bentrok = Siswa::where('card_uid', $uid)->where('id', '!=', $s->id)->first()) {
            throw new ApiError(409, "Kartu sudah dipakai {$bentrok->nama}");
        }
        $s->update(['card_uid' => $uid]);

        return response()->json(['ok' => true, 'siswa' => $s->fresh()]);
    }

    /**
     * Reset PIN kios — jalan keluar untuk pemegang kartu yang lupa PIN.
     *
     * Dikerjakan dengan MENGHAPUS barisnya, bukan menulis nilai bawaan: tidak
     * adanya baris itulah yang berarti "PIN masih NIS". Kartu yang terlanjur
     * terkunci karena salah PIN berkali-kali ikut dibuka di sini — kalau tidak,
     * yang bersangkutan tetap tidak bisa memakai kios selama 15 menit setelah
     * ditolong admin.
     */
    public function resetPin(Request $r, int $id)
    {
        $s = Siswa::find($id);
        if (! $s) {
            throw new ApiError(404, 'Data pemegang kartu tidak ditemukan');
        }
        $this->lingkupIzin($r, $s->golongan, 'reset_pin');
        SiswaPin::where('siswa_id', $s->id)->delete();
        SesiKios::tutupSemua($s->id);
        Rem::lupakan('pin:' . $s->id);

        $bawaan = Pin::bawaan($s);
        $siap = strlen($bawaan) >= Pin::MIN;

        return response()->json([
            'ok' => true,
            'pin_bawaan' => $bawaan,
            'siap' => $siap,
            'pesan' => $siap
                ? "PIN {$s->nama} kembali ke NIS: {$bawaan}"
                : "PIN direset, tapi NIS {$s->nama} belum diisi (atau kurang dari " . Pin::MIN .
                  ' angka) — isi dulu NIS-nya supaya kios bisa dipakai',
        ]);
    }
}
