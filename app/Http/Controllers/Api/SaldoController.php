<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\ApiError;
use App\Models\Siswa;
use App\Models\TitipanMutasi;
use App\Models\Transaksi;
use App\Services\BiayaAdmin;
use App\Services\BukuBesar;
use App\Services\DanaAdmin;
use App\Services\Pengaturan;
use App\Services\PotongSaldo;
use App\Services\Rupiah;
use App\Services\Setoran;
use App\Services\SmartTopup;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Uang masuk dan keluar di luar kasir — top up, pemotongan, dan saldo titipan.
 *
 * Seluruhnya EKSKLUSIF superadmin. Tenant tidak pernah boleh menambah saldo:
 * kalau bisa, kantin dapat "menjual" saldo tanpa uang tunai pernah masuk ke
 * sekolah, dan neraca kas berhenti berarti.
 */
class SaldoController extends Controller
{
    private const MAX_BATCH = 500;

    public function topup(Request $r)
    {
        $user = $this->wajibPeran($r, 'superadmin');
        $siswaId = (int) $r->input('siswa_id');
        $jumlah = (int) floor((float) $r->input('jumlah'));
        if ($jumlah <= 0) {
            throw new ApiError(400, 'Nominal tidak valid');
        }
        $txId = $r->input('tx_id') ? (string) $r->input('tx_id') : null;
        if ($dupe = $this->duplikat($txId, $siswaId)) {
            return response()->json($dupe);
        }
        $keterangan = mb_substr(trim((string) $r->input('keterangan', 'Top up')), 0, 300) ?: 'Top up';
        // Bawaannya TANPA biaya: memungut orang harus pilihan sadar admin,
        // bukan akibat lupa mengganti pilihan.
        $pungut = $r->input('biaya_admin') === true;
        // Baris biaya harus bisa ditunjuk ke top up-nya (lihat Neraca: biaya_yatim),
        // dan pasangannya dikenali lewat tx_id — jadi top up berbiaya selalu punya satu.
        if ($pungut && ! $txId) {
            $txId = 'adm-srv-' . bin2hex(random_bytes(8));
        }

        $hasil = DB::transaction(function () use ($r, $siswaId, $jumlah, $txId, $user, $keterangan, $pungut) {
            $s = Siswa::find($siswaId);
            if (! $s) {
                throw new ApiError(404, 'Pemegang kartu tidak ditemukan');
            }
            $biaya = $pungut ? BiayaAdmin::untukManual($s, $jumlah) : 0;
            if ($biaya > 0 && $biaya >= $jumlah) {
                throw new ApiError(400, 'Nominal harus lebih besar dari biaya admin (' . Rupiah::f($biaya) . ')');
            }
            $ket = $biaya > 0
                ? mb_substr($keterangan . ' — dari ' . Rupiah::f($jumlah) . ', biaya admin ' . Rupiah::f($biaya), 0, 300)
                : $keterangan;

            $setor = Setoran::terima($siswaId, $jumlah - $biaya, [
                'keTitipan' => $r->input('ke_titipan') === true,
                'operator' => $user->nama,
                'keterangan' => $ket,
                'txId' => $txId,
                'refTitipan' => $txId ? "topup-{$txId}" : null,
            ]);

            // Pola yang sama dengan portal wali: baris biaya_admin di buku besar
            // (tidak mengubah saldo kartu) + mutasi masuk Dana Admin. Neraca
            // mengenali keduanya tanpa perubahan apa pun.
            if ($biaya > 0) {
                $txBiaya = BiayaAdmin::catat($setor['siswa'], $biaya, $user->nama,
                    'Biaya admin top up manual', "{$txId}-biaya");
                DanaAdmin::terima($biaya, $setor['siswa'], $txBiaya, $user->nama,
                    "topup-biaya-{$txId}");
            }

            return $setor + ['biaya' => $biaya];
        });

        // Kalau saldo kartu memang sedang tipis, uang yang baru mengendap di
        // titipan langsung diteteskan — kalau tidak, admin akan bingung melihat
        // setoran masuk tapi kartu tetap kosong.
        $smart = $hasil['ke_titipan'] > 0 ? SmartTopup::diam($siswaId, 'setoran baru') : null;

        return response()->json([
            'ok' => true,
            'siswa' => $smart['siswa'] ?? $hasil['siswa'],
            'transaksi' => $hasil['transaksi'],
            'ke_saldo' => $hasil['ke_saldo'],
            'ke_titipan' => $hasil['ke_titipan'],
            'biaya' => $hasil['biaya'],
            'smart' => $smart,
        ]);
    }

    /** Tarif yang akan dipakai bila admin memilih "biaya admin" — untuk pratinjau layar. */
    public function tarifTopup(Request $r)
    {
        $this->wajibPeran($r, 'superadmin');

        return response()->json(['tarif' => BiayaAdmin::tarifSekolah()]);
    }

    public function koreksi(Request $r)
    {
        $user = $this->wajibPeran($r, 'superadmin');
        $siswaId = (int) $r->input('siswa_id');
        $jumlah = (int) floor((float) $r->input('jumlah'));
        $keterangan = mb_substr(trim((string) $r->input('keterangan', '')), 0, 300);
        if ($jumlah <= 0) {
            throw new ApiError(400, 'Nominal tidak valid');
        }
        if (mb_strlen($keterangan) < 3) {
            throw new ApiError(400, 'Alasan pemotongan wajib diisi (minimal 3 huruf)');
        }
        $txId = $r->input('tx_id') ? (string) $r->input('tx_id') : null;
        if ($dupe = $this->duplikat($txId, $siswaId)) {
            return response()->json($dupe);
        }

        $sumber = PotongSaldo::sumberSah($r->input('sumber'));

        return response()->json(DB::transaction(function () use ($siswaId, $jumlah, $user,
            $keterangan, $txId, $sumber) {
            $s = Siswa::find($siswaId);
            if (! $s) {
                throw new ApiError(404, 'Santri tidak ditemukan');
            }
            // Kartu blokir tetap boleh dipotong — justru saldo santri keluar
            // yang perlu dikembalikan. Saldo tidak boleh minus (dijaga di WHERE).
            $bagi = PotongSaldo::bagi($s, $jumlah, $sumber, false);
            if (! $bagi['cukup']) {
                throw new ApiError(402, $sumber === 'kartu'
                    ? 'Saldo tidak cukup untuk dipotong (saldo ' . Rupiah::f((int) $s->saldo) . ')'
                    : 'Kartu + titipan tidak cukup (kartu ' . Rupiah::f((int) $s->saldo) .
                      ', titipan ' . Rupiah::f((int) $s->saldo_titipan) . ')');
            }
            $h = PotongSaldo::ambil($s, $bagi, $user->nama, $keterangan, $txId);
            if (! empty($h['gagal'])) {
                throw new ApiError(409, 'Saldo berubah saat pemotongan berjalan — ulangi');
            }

            return ['ok' => true, 'siswa' => $h['siswa'], 'transaksi' => $h['transaksi'],
                'mutasi' => $h['mutasi'], 'dari_kartu' => $h['kartu'], 'dari_titipan' => $h['titipan']];
        }));
    }

    /**
     * Potong saldo kolektif (mis. satu kelas). Seluruh batch berjalan dalam SATU
     * transaksi: kalau gagal di tengah, tidak ada saldo yang terlanjur berubah.
     * Santri bersaldo kurang tidak menggagalkan batch — dilaporkan balik supaya
     * admin bisa menindaklanjuti.
     */
    public function koreksiMassal(Request $r)
    {
        $user = $this->wajibPeran($r, 'superadmin');
        $jumlah = (int) floor((float) $r->input('jumlah'));
        $keterangan = mb_substr(trim((string) $r->input('keterangan', '')), 0, 300);
        // 'lewati' = santri bersaldo kurang dilewati utuh (bawaan);
        // 'sebisanya' = dipotong sampai saldo habis.
        $modeKurang = $r->input('saldo_kurang') === 'sebisanya' ? 'sebisanya' : 'lewati';
        $ids = array_values(array_unique(array_filter(array_map(
            'intval', (array) $r->input('siswa_ids', [])))));

        if ($jumlah <= 0) {
            throw new ApiError(400, 'Nominal tidak valid');
        }
        if (mb_strlen($keterangan) < 3) {
            throw new ApiError(400, 'Alasan pemotongan wajib diisi (minimal 3 huruf)');
        }
        if (! $ids) {
            throw new ApiError(400, 'Belum ada yang dipilih');
        }
        if (count($ids) > self::MAX_BATCH) {
            throw new ApiError(400, 'Maksimal ' . self::MAX_BATCH . ' orang sekali potong');
        }

        // batch_id menjaga idempotensi: tiap baris memakai tx_id
        // "<batch_id>-<siswa_id>", jadi kiriman ulang batch tidak menumpuk.
        $batchId = trim((string) $r->input('batch_id', ''));
        if (! preg_match('/^[A-Za-z0-9-]{4,40}$/', $batchId)) {
            throw new ApiError(400, 'batch_id tidak valid (huruf/angka/strip, 4–40 karakter)');
        }
        // Pola LIKE aman: batch_id sudah dibatasi huruf/angka/strip, tanpa wildcard.
        $sudah = Transaksi::where('tx_id', 'like', $batchId . '-%')->get();
        if ($sudah->isNotEmpty()) {
            return response()->json([
                'ok' => true, 'duplikat' => true, 'batch_id' => $batchId,
                'total_dipotong' => (int) $sudah->sum(fn ($t) => -$t->jumlah),
                'berhasil' => $sudah->map(fn ($t) => [
                    'id' => $t->siswa_id, 'nama' => $t->siswa_nama, 'kelas' => $t->kelas,
                    'dipotong' => -$t->jumlah, 'saldo_sesudah' => $t->saldo_sesudah,
                ])->values(),
                'dilewati' => [],
            ]);
        }

        $sumber = PotongSaldo::sumberSah($r->input('sumber'));

        return response()->json(DB::transaction(function () use ($ids, $jumlah, $modeKurang,
            $batchId, $user, $keterangan, $sumber) {
            $berhasil = [];
            $dilewati = [];
            foreach ($ids as $id) {
                $s = Siswa::find($id);
                if (! $s) {
                    $dilewati[] = ['id' => $id, 'nama' => '—', 'kelas' => '', 'saldo' => 0,
                        'alasan' => 'Tidak ditemukan'];

                    continue;
                }
                $bagi = PotongSaldo::bagi($s, $jumlah, $sumber, $modeKurang === 'sebisanya');
                if ($bagi['total'] <= 0) {
                    $punya = (int) $s->saldo + ($sumber === 'kartu' ? 0 : (int) $s->saldo_titipan);
                    $dilewati[] = ['id' => $id, 'nama' => $s->nama, 'kelas' => $s->kelas,
                        'saldo' => (int) $s->saldo, 'titipan' => (int) $s->saldo_titipan,
                        'alasan' => $punya === 0 ? 'Saldo kosong'
                            : ($sumber === 'kartu' ? 'Saldo kurang (' . Rupiah::f((int) $s->saldo) . ')'
                                : 'Kartu + titipan kurang (' . Rupiah::f($punya) . ')')];

                    continue;
                }
                $h = PotongSaldo::ambil($s, $bagi, $user->nama, $keterangan, "{$batchId}-{$id}");
                if (! empty($h['gagal'])) {
                    $dilewati[] = ['id' => $id, 'nama' => $s->nama, 'kelas' => $s->kelas,
                        'saldo' => (int) $s->saldo, 'titipan' => (int) $s->saldo_titipan,
                        'alasan' => 'Saldo berubah saat pemotongan berjalan'];

                    continue;
                }
                $baru = $h['siswa'];
                $berhasil[] = ['id' => $id, 'nama' => $baru->nama, 'kelas' => $baru->kelas,
                    'dipotong' => $h['total'], 'dari_kartu' => $h['kartu'],
                    'dari_titipan' => $h['titipan'], 'saldo_sesudah' => (int) $baru->saldo,
                    'titipan_sesudah' => (int) $baru->saldo_titipan];
            }

            return ['ok' => true, 'batch_id' => $batchId, 'berhasil' => $berhasil, 'dilewati' => $dilewati,
                'total_dipotong' => array_sum(array_column($berhasil, 'dipotong'))];
        }));
    }

    // ------------------------------------------------------------ titipan

    /** Setor langsung ke titipan tanpa menyentuh kartu (mis. pelunasan sebulan di muka). */
    public function titipanSetor(Request $r)
    {
        $user = $this->wajibPeran($r, 'superadmin');
        $siswaId = (int) $r->input('siswa_id');
        $jumlah = (int) floor((float) $r->input('jumlah'));
        if ($jumlah <= 0) {
            throw new ApiError(400, 'Nominal tidak valid');
        }
        $keterangan = mb_substr(trim((string) $r->input('keterangan', 'Setoran titipan')), 0, 300) ?: 'Setoran titipan';
        $ref = $r->input('ref') ? 'titipan-' . mb_substr((string) $r->input('ref'), 0, 60) : null;

        if ($ref && TitipanMutasi::where('ref', $ref)->exists()) {
            return response()->json(['ok' => true, 'duplikat' => true, 'siswa' => Siswa::find($siswaId)]);
        }

        $hasil = DB::transaction(function () use ($siswaId, $jumlah, $user, $keterangan, $ref) {
            $s0 = Siswa::find($siswaId);
            if (! $s0) {
                throw new ApiError(404, 'Pemegang kartu tidak ditemukan');
            }
            $cfg = Pengaturan::untuk($s0);
            if ($s0->saldo_titipan + $jumlah > $cfg['max_titipan']) {
                throw new ApiError(400, 'Titipan akan menjadi ' . Rupiah::f($s0->saldo_titipan + $jumlah) .
                    ', melebihi batas ' . Rupiah::f($cfg['max_titipan']));
            }
            Siswa::where('id', $siswaId)->update(['saldo_titipan' => DB::raw("saldo_titipan + {$jumlah}")]);
            $s = Siswa::find($siswaId);

            return ['siswa' => $s,
                'mutasi' => BukuBesar::catatTitipan($s, 'setor', $jumlah, $user->nama, $keterangan, $ref)];
        });

        $smart = SmartTopup::diam($siswaId, 'setoran titipan');

        return response()->json(['ok' => true, 'siswa' => $smart['siswa'] ?? $hasil['siswa'],
            'mutasi' => $hasil['mutasi'], 'smart' => $smart]);
    }

    /** Tarik titipan (uang dikembalikan tunai ke orang tua / santri keluar). */
    public function titipanTarik(Request $r)
    {
        $user = $this->wajibPeran($r, 'superadmin');
        $siswaId = (int) $r->input('siswa_id');
        $jumlah = (int) floor((float) $r->input('jumlah'));
        $keterangan = mb_substr(trim((string) $r->input('keterangan', '')), 0, 300);
        if ($jumlah <= 0) {
            throw new ApiError(400, 'Nominal tidak valid');
        }
        // Uang keluar dari sekolah — alasannya wajib, sama seperti pemotongan saldo.
        if (mb_strlen($keterangan) < 3) {
            throw new ApiError(400, 'Alasan penarikan wajib diisi (minimal 3 huruf)');
        }

        return response()->json(['ok' => true, ...DB::transaction(function () use ($siswaId, $jumlah, $user, $keterangan) {
            $ubah = Siswa::where('id', $siswaId)->where('saldo_titipan', '>=', $jumlah)
                ->update(['saldo_titipan' => DB::raw("saldo_titipan - {$jumlah}")]);
            if ($ubah === 0) {
                $s = Siswa::find($siswaId);
                if (! $s) {
                    throw new ApiError(404, 'Santri tidak ditemukan');
                }
                throw new ApiError(402, 'Titipan tidak cukup (titipan ' . Rupiah::f($s->saldo_titipan) . ')');
            }
            $s = Siswa::find($siswaId);

            return ['siswa' => $s,
                'mutasi' => BukuBesar::catatTitipan($s, 'tarik', -$jumlah, $user->nama, $keterangan)];
        })]);
    }

    /** Cairkan titipan ke kartu sekarang, tanpa menunggu ambang smart top up. */
    public function titipanCairkan(Request $r)
    {
        $user = $this->wajibPeran($r, 'superadmin');
        $siswaId = (int) $r->input('siswa_id');
        $minta = $r->input('jumlah');

        return response()->json(['ok' => true, ...DB::transaction(function () use ($siswaId, $user, $minta) {
            $s0 = Siswa::find($siswaId);
            if (! $s0) {
                throw new ApiError(404, 'Santri tidak ditemukan');
            }
            // Batas DITIMPA golongan santrinya — sama seperti Setoran::terima
            // dan SmartTopup::jalankan(). Memakai angka sekolah di sini pernah
            // membuat pencairan ke kartu untuk golongan berplafon lebih besar
            // (mis. SDM) terhenti di angka sekolah yang jauh lebih kecil,
            // padahal golongannya mengizinkan jauh lebih banyak.
            $cfg = Pengaturan::untuk($s0);
            if ($s0->status !== 'aktif') {
                throw new ApiError(403, 'Kartu diblokir — pulihkan dulu atau tarik titipannya');
            }
            if ($s0->saldo_titipan <= 0) {
                throw new ApiError(400, 'Santri ini tidak punya saldo titipan');
            }
            $ruang = $cfg['max_saldo'] - $s0->saldo;
            if ($ruang <= 0) {
                throw new ApiError(400, 'Saldo kartu sudah di batas maksimal (' . Rupiah::f($cfg['max_saldo']) . ')');
            }
            // Nominal opsional: tanpa itu, dicairkan sebanyak yang tertampung kartu.
            $meminta = $minta === null ? $s0->saldo_titipan : (int) floor((float) $minta);
            if ($meminta <= 0) {
                throw new ApiError(400, 'Nominal tidak valid');
            }
            $isi = min($meminta, $s0->saldo_titipan, $ruang);

            $ubah = Siswa::where('id', $siswaId)
                ->where('saldo_titipan', '>=', $isi)
                ->whereRaw('saldo + ? <= ?', [$isi, $cfg['max_saldo']])
                ->update([
                    'saldo' => DB::raw("saldo + {$isi}"),
                    'saldo_titipan' => DB::raw("saldo_titipan - {$isi}"),
                ]);
            if ($ubah === 0) {
                throw new ApiError(409, 'Saldo santri berubah barusan — coba lagi');
            }
            $s = Siswa::find($siswaId);
            $ket = 'Pencairan titipan ke kartu oleh admin';
            $trx = BukuBesar::catatTransaksi('cair-' . $siswaId . '-' . (int) (microtime(true) * 1000),
                'topup', $s, $isi, $user->nama, $ket);

            return ['siswa' => $s, 'transaksi' => $trx, 'isi' => $isi,
                'mutasi' => BukuBesar::catatTitipan($s, 'isi_saldo', -$isi, $user->nama, $ket, null, $trx->id)];
        })]);
    }

    /** Riwayat titipan seorang santri + setelan smart top up yang berlaku baginya. */
    public function titipanSiswa(Request $r, int $id)
    {
        $this->wajibPeran($r, 'superadmin');
        $s = Siswa::find($id);
        if (! $s) {
            throw new ApiError(404, 'Santri tidak ditemukan');
        }

        return response()->json([
            'siswa' => $s->only(['id', 'nis', 'nama', 'kelas', 'saldo', 'saldo_titipan',
                'status', 'smart_mode', 'smart_ambang', 'smart_isi']),
            'berlaku' => SmartTopup::aturan($s, Pengaturan::sekolah()),
            'mutasi' => TitipanMutasi::where('siswa_id', $s->id)->orderByDesc('id')->limit(100)->get(),
        ]);
    }

    /** Setelan smart top up khusus seorang santri (mengalahkan pengaturan sekolah). */
    public function smart(Request $r, int $id)
    {
        $this->wajibPeran($r, 'superadmin');
        $s = Siswa::find($id);
        if (! $s) {
            throw new ApiError(404, 'Santri tidak ditemukan');
        }
        $cfg = Pengaturan::sekolah();

        $mode = in_array($r->input('mode'), ['ikut', 'aktif', 'mati'], true) ? $r->input('mode') : $s->smart_mode;

        // null / '' = kembali mengikuti angka sekolah. Nol BUKAN pilihan yang
        // sah: ambang nol berarti kartu baru diisi setelah benar-benar kosong.
        $angka = function (string $kunci, $lama, string $nama) use ($cfg, $r) {
            if (! $r->has($kunci)) {
                return $lama;
            }
            $nilai = $r->input($kunci);
            if ($nilai === null || $nilai === '') {
                return null;
            }
            $v = (int) floor((float) $nilai);
            if ($v <= 0) {
                throw new ApiError(400, "{$nama} tidak valid");
            }
            if ($v > $cfg['max_saldo']) {
                throw new ApiError(400, "{$nama} tidak boleh melebihi batas saldo (" . Rupiah::f($cfg['max_saldo']) . ')');
            }

            return $v;
        };
        $ambang = $angka('ambang', $s->smart_ambang, 'Ambang isi ulang');
        $isi = $angka('isi', $s->smart_isi, 'Sasaran isi ulang');

        $ambangEfektif = $ambang ?? $cfg['smart_ambang'];
        $isiEfektif = $isi ?? $cfg['smart_isi'];
        if ($isiEfektif <= $ambangEfektif) {
            throw new ApiError(400, 'Sasaran isi ulang (' . Rupiah::f($isiEfektif) .
                ') harus lebih besar dari ambangnya (' . Rupiah::f($ambangEfektif) . ')');
        }

        $s->update(['smart_mode' => $mode, 'smart_ambang' => $ambang, 'smart_isi' => $isi]);
        $baru = Siswa::find($s->id);
        $smart = SmartTopup::diam($s->id, 'setelan smart diubah');

        return response()->json([
            'ok' => true,
            'siswa' => $smart['siswa'] ?? $baru,
            'berlaku' => SmartTopup::aturan($baru, $cfg),
            'smart' => $smart,
        ]);
    }

    private function duplikat(?string $txId, int $siswaId): ?array
    {
        if (! $txId) {
            return null;
        }
        $dupe = Transaksi::where('tx_id', $txId)->first();

        return $dupe ? ['ok' => true, 'siswa' => Siswa::find($siswaId), 'transaksi' => $dupe, 'duplikat' => true] : null;
    }
}
