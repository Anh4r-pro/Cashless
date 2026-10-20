<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\ApiError;
use App\Models\Setting;
use App\Models\Siswa;
use App\Models\TopupInfo;
use App\Models\TopupRequest;
use App\Services\Berkas;
use App\Services\Kartu;
use App\Services\BiayaAdmin;
use App\Services\DanaAdmin;
use App\Services\Pengaturan;
use App\Services\Rupiah;
use App\Services\Setoran;
use App\Services\SmartTopup;
use App\Support\WaktuIso;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Top up mandiri orang tua — jalan pintas non-tunai TANPA payment gateway.
 *
 * Uangnya berpindah DI LUAR sistem (QRIS statis atau transfer bank), orang tua
 * mengonfirmasi lewat portal, dan admin yang memutuskan.
 *
 * Yang dijaga server, bukan sekadar disembunyikan di layar:
 *   - Pengajuan tidak pernah menyentuh saldo. Kolom transaksi_id baru terisi
 *     saat disetujui, dan tx_id transaksinya diturunkan dari id pengajuan
 *     ("ortu-topup-<id>", kolom UNIQUE) — jadi satu pengajuan mustahil cair dua
 *     kali sekalipun dua admin menekan Setujui pada detik yang sama.
 *   - Batas max_saldo dan max_topup_harian yang berlaku untuk top up manual
 *     berlaku sama di sini, dan diperiksa ulang PADA SAAT PERSETUJUAN — bukan
 *     saat pengajuan dibuat, karena saldo bisa berubah selama menunggu.
 *   - Metode yang instruksinya belum diisi admin tidak pernah ditawarkan.
 */
class TopupOrtuController extends Controller
{
    private const MUNDUR_MAKS_HARI = 30;

    private const ANTRE_BATAS_ATAS = 20;

    public const LABELS = [
        'topup_ortu_aktif' => ['label' => 'Orang tua boleh mengajukan top up', 'unit' => 'onoff'],
        'topup_ortu_min' => ['label' => 'Nominal terkecil satu pengajuan', 'unit' => 'rp'],
        'topup_ortu_maks' => ['label' => 'Nominal terbesar satu pengajuan', 'unit' => 'rp'],
        'topup_ortu_antre' => ['label' => 'Maks pengajuan menunggu / santri', 'unit' => 'kali'],
        // 0 = tidak memungut. Satu-satunya kunci di sini yang boleh bernilai
        // nol selain sakelar hidup/mati.
        'biaya_admin_topup_ortu' => ['label' => 'Biaya admin / pengajuan', 'unit' => 'rp'],
    ];

    private const INFO_KEYS = ['qris_gambar', 'qris_nama', 'qris_instruksi',
        'bank_nama', 'bank_rekening', 'bank_atasnama', 'bank_instruksi'];

    // ------------------------------------------------------- sisi orang tua

    public function info(Request $r)
    {
        $s = $this->ortu($r);
        // Per-orang, bukan sekolah: max_saldo bisa ditimpa per golongan (lihat
        // Pengaturan::BATAS_GOLONGAN), dan Setoran::terima() yang memutuskan
        // saat admin menyetujui sudah memakai angka per-golongan ini juga —
        // memakai Pengaturan::sekolah() di sini membuat portal wali menampilkan
        // dan menegakkan batas yang beda dari yang sungguhan berlaku.
        $cfg = Pengaturan::untuk($s);
        $info = self::info_();
        $siap = self::metodeSiap($info);

        $metode = [];
        if ($siap['qris']) {
            $metode[] = ['kode' => 'qris', 'nama' => 'QRIS', 'penerima' => $info['qris_nama'],
                'gambar' => $info['qris_gambar'], 'instruksi' => $info['qris_instruksi']];
        }
        if ($siap['transfer']) {
            $metode[] = ['kode' => 'transfer', 'nama' => 'Transfer Bank', 'bank' => $info['bank_nama'],
                'rekening' => $info['bank_rekening'], 'atasnama' => $info['bank_atasnama'],
                'instruksi' => $info['bank_instruksi']];
        }

        $antre = $this->antre($s->id);

        return response()->json([
            // Dua sebab berbeda kenapa tombol bisa mati — dipisah supaya pesan
            // di layar orang tua tepat: ditutup sekolah, atau sekolah belum
            // mengisi rekening.
            'aktif' => $cfg['topup_ortu_aktif'] === 1 && $metode !== [],
            'ditutup' => $cfg['topup_ortu_aktif'] !== 1,
            'belum_disiapkan' => $cfg['topup_ortu_aktif'] === 1 && $metode === [],
            'metode' => $metode,
            'min' => $cfg['topup_ortu_min'], 'maks' => $cfg['topup_ortu_maks'],
            // Wali harus tahu biayanya SEBELUM membayar. Menemukannya setelah
            // saldo anaknya tidak sesuai adalah cara tercepat membuat orang
            // tidak percaya pada sistem pembayaran sekolahnya sendiri.
            'biaya_admin' => BiayaAdmin::tarifUntuk($s),
            'max_saldo' => $cfg['max_saldo'], 'saldo' => $s->saldo,
            'antre_n' => $antre['n'], 'antre_total' => $antre['total'],
            'antre_maks' => $cfg['topup_ortu_antre'],
            'hari_ini' => Kartu::hariIni(),
            'mundur_maks_hari' => self::MUNDUR_MAKS_HARI,
        ]);
    }

    public function daftarOrtu(Request $r)
    {
        $s = $this->ortu($r);
        $rows = TopupRequest::where('siswa_id', $s->id)
            ->orderByDesc('created_at')->orderByDesc('id')->limit(20)->get();

        return response()->json([
            'pengajuan' => $rows->map(fn ($x) => $this->untukOrtu($x)),
            'menunggu' => TopupRequest::where('siswa_id', $s->id)->where('status', 'pending')->count(),
        ]);
    }

    public function ajukan(Request $r)
    {
        $s = $this->ortu($r);
        // Sama seperti info(): per-orang, supaya pagar max_saldo di sini cocok
        // persis dengan yang ditegakkan Setoran::terima() saat admin menyetujui.
        $cfg = Pengaturan::untuk($s);
        if ($cfg['topup_ortu_aktif'] !== 1) {
            throw new ApiError(403, 'Pengajuan isi saldo lewat portal sedang ditutup sekolah.');
        }

        $siap = self::metodeSiap(self::info_());
        $metode = (string) $r->input('metode');
        if (! in_array($metode, ['qris', 'transfer'], true)) {
            throw new ApiError(400, 'Metode pembayaran belum dipilih');
        }
        if (! $siap[$metode]) {
            throw new ApiError(409, 'Metode pembayaran itu belum disiapkan sekolah. Pilih metode lain.');
        }

        $jumlah = (int) floor((float) $r->input('jumlah'));
        if ($jumlah <= 0) {
            throw new ApiError(400, 'Nominal tidak valid');
        }
        if ($jumlah < $cfg['topup_ortu_min']) {
            throw new ApiError(400, 'Nominal minimal ' . Rupiah::f($cfg['topup_ortu_min']));
        }
        if ($jumlah > $cfg['topup_ortu_maks']) {
            throw new ApiError(400, 'Nominal maksimal ' . Rupiah::f($cfg['topup_ortu_maks']) . ' untuk satu pengajuan');
        }

        $tgl = trim((string) $r->input('tgl_bayar'));
        if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $tgl) || ! strtotime($tgl)) {
            throw new ApiError(400, 'Tanggal pembayaran tidak valid');
        }
        $hariIni = Kartu::hariIni();
        if ($tgl > $hariIni) {
            throw new ApiError(400, 'Tanggal pembayaran tidak boleh di masa depan');
        }
        if ((strtotime($hariIni) - strtotime($tgl)) / 86400 > self::MUNDUR_MAKS_HARI) {
            throw new ApiError(400, 'Tanggal pembayaran maksimal ' . self::MUNDUR_MAKS_HARI .
                ' hari ke belakang. Untuk pembayaran yang lebih lama, hubungi admin kantin.');
        }

        /*
         * Nama pengirim WAJIB, dan bukan sekadar formalitas.
         *
         * Yang memeriksa pengajuan ini adalah manusia yang membuka mutasi
         * rekening sekolah. Tanpa nama pengirim, satu-satunya cara mencocokkan
         * adalah menebak dari nominal — dan nominal kembar adalah hal biasa
         * pada hari yang sama. Dua huruf tidak cukup untuk dicari di mutasi,
         * jadi ambangnya tiga.
         */
        $pengirim = mb_substr(preg_replace('/\s+/', ' ', trim((string) $r->input('pengirim', ''))), 0, 80);
        if (mb_strlen($pengirim) < 3) {
            throw new ApiError(400, 'Nama pengirim wajib diisi, sesuai nama di rekening/aplikasi ' .
                'yang Anda pakai membayar. Ini yang dipakai admin mencocokkan dengan mutasi rekening sekolah.');
        }

        // Rem anti-banjir, sekaligus menjaga agar antrean yang menunggu tidak
        // sampai melampaui batas saldo begitu semuanya disetujui.
        $antre = $this->antre($s->id);
        if ($antre['n'] >= $cfg['topup_ortu_antre']) {
            throw new ApiError(429, "Masih ada {$antre['n']} pengajuan yang menunggu diperiksa admin. " .
                'Tunggu yang itu diproses dulu sebelum mengajukan lagi.');
        }
        /*
         * Melebihi batas saldo kartu TIDAK LAGI MENOLAK pengajuannya — hak wali
         * untuk menyetor tetap ada, persis filosofi Setoran::bagi(): uang yang
         * tidak muat di kartu bukan uang yang ditolak, melainkan uang yang
         * mengendap sebagai titipan dan menetes masuk otomatis saat saldo
         * menipis. Sakelar "Simpan kelebihan sebagai saldo titipan" di layar
         * admin SUDAH menyala bawaannya, jadi persetujuannya tetap mulus tanpa
         * admin perlu melakukan apa pun yang berbeda. Yang berubah hanya:
         * walinya diberi tahu LEBIH AWAL lewat `peringatan`, bukan dikejutkan
         * penolakan di tengah pengisian form.
         */
        $peringatan = null;
        if ($s->saldo + $antre['total'] + $jumlah > $cfg['max_saldo']) {
            $peringatan = 'Nominal ini membuat saldo kartu melebihi batas ' . Rupiah::f($cfg['max_saldo']) .
                ' (saldo sekarang ' . Rupiah::f($s->saldo) .
                ($antre['total'] ? ', ditambah ' . Rupiah::f($antre['total']) . ' yang masih menunggu disetujui' : '') .
                '). Kelebihannya akan disimpan sebagai saldo titipan oleh admin dan otomatis ' .
                'mengisi kartu saat saldo menipis — pengajuan tetap bisa dikirim.';
        }

        /*
         * Bukti bayar WAJIB. Sebelum ini pengajuan bisa dikirim tanpa bukti
         * apa pun, dan admin diminta menyetujui penambahan saldo hanya
         * berdasarkan pengakuan — pemeriksaannya jadi bergantung pada ingatan
         * dan itikad baik, bukan pada dokumen.
         */
        if (! $r->input('bukti')) {
            throw new ApiError(400, 'Bukti bayar wajib dilampirkan. Unggah foto atau tangkapan ' .
                'layar bukti transfer/QRIS Anda sebelum mengirim pengajuan.');
        }
        /*
         * Ambang 100 piksel menutup celah yang paling gampang: bukti WAJIB
         * yang dipenuhi dengan gambar satu piksel bukanlah bukti, dan aturan
         * wajib yang bisa dilewati begitu saja lebih buruk daripada tidak ada
         * — ia membuat admin mengira setiap pengajuan sudah berdokumen.
         * Angkanya sengaja jauh di bawah tangkapan layar ponsel mana pun,
         * supaya yang tersaring hanya gambar yang memang tidak mungkin dibaca.
         */
        $berkas = Berkas::simpanGambarLengkap((string) $r->input('bukti'), 'topup', 100);
        $bukti = $berkas['nama'];

        /*
         * Satu bukti, satu pengajuan.
         *
         * Wali yang mentransfer sekali lalu mengajukan dua kali lolos dari
         * semua pemeriksaan lain — nominal, tanggal, dan antreannya sama-sama
         * sah. Yang membongkarnya cuma kesamaan gambar buktinya. Pengajuan yang
         * sudah DITOLAK sengaja tidak ikut menghalangi: bukti yang ditolak
         * karena salah ketik nominal harus tetap bisa dipakai mengajukan ulang.
         */
        $kembar = TopupRequest::where('bukti_sidik', $berkas['sidik'])
            ->whereIn('status', ['pending', 'disetujui'])->first();
        if ($kembar) {
            Berkas::hapus($bukti);
            $kapan = substr((string) $kembar->created_at, 0, 10);
            throw new ApiError(409, 'Bukti bayar ini sudah pernah dipakai untuk pengajuan ' .
                Rupiah::f($kembar->jumlah) . ' tanggal ' . $kapan .
                ($kembar->status === 'pending' ? ' yang masih menunggu diperiksa admin.' : ' yang sudah disetujui.') .
                ' Satu bukti transfer hanya untuk satu pengajuan. Kalau Anda memang membayar ' .
                'dua kali, lampirkan bukti pembayaran yang kedua.');
        }

        $row = TopupRequest::create([
            'siswa_id' => $s->id, 'siswa_nama' => $s->nama, 'kelas' => $s->kelas,
            'jumlah' => $jumlah, 'metode' => $metode, 'tgl_bayar' => $tgl,
            // Tarif dikunci di sini, bukan dibaca ulang saat disetujui: wali
            // sudah benar-benar mentransfer uang berdasarkan angka yang tampil
            // di layarnya, dan pengajuan bisa menunggu berhari-hari di antrean.
            'biaya_admin' => BiayaAdmin::untuk($s, $jumlah, $metode),
            'pengirim' => $pengirim, 'bukti' => $bukti, 'bukti_sidik' => $berkas['sidik'],
            'created_at' => WaktuIso::sekarang(),
        ])->fresh();

        return response()->json(['ok' => true, 'pengajuan' => $this->untukOrtu($row),
            'peringatan' => $peringatan], 201);
    }

    // ------------------------------------------------------------ sisi admin

    public function antrean(Request $r)
    {
        $this->wajibPeran($r, 'superadmin');
        $status = in_array($r->query('status'), ['pending', 'disetujui', 'ditolak'], true) ? $r->query('status') : null;
        $page = max(1, (int) $r->query('page', 1));
        $limit = min(100, (int) $r->query('limit', 25) ?: 25);

        $q = TopupRequest::when($status, fn ($w) => $w->where('status', $status));
        $total = (clone $q)->count();

        // Yang menunggu selalu di atas: itu satu-satunya bagian yang butuh tindakan.
        $rows = (clone $q)
            ->orderByRaw("CASE status WHEN 'pending' THEN 0 ELSE 1 END")
            ->orderByDesc('created_at')->orderByDesc('id')
            ->limit($limit)->offset(($page - 1) * $limit)->get();

        /*
         * Tandai pengajuan yang BERPASANGAN MENCURIGAKAN.
         *
         * Penjagaan sidik jari bukti hanya menangkap gambar yang sama persis.
         * Wali yang memotret layar dua kali menghasilkan dua berkas berbeda
         * untuk satu transfer yang sama, dan itu lolos. Yang tersisa sebagai
         * petunjuk adalah pola yang gampang dilihat manusia tapi mudah
         * terlewat di tengah antrean panjang: santri yang sama, nominal yang
         * sama, tanggal bayar yang sama, metode yang sama.
         *
         * Sengaja hanya DITANDAI, tidak ditolak. Wali yang memang mengirim dua
         * kali dengan nominal sama pada hari yang sama itu wajar, dan menolak
         * otomatis akan menahan uang yang sah. Yang dibutuhkan admin cuma
         * diberi tahu ke mana harus melihat dua kali.
         */
        $kunci = fn ($x) => $x->siswa_id . '|' . $x->jumlah . '|' . $x->tgl_bayar . '|' . $x->metode;
        $hitung = TopupRequest::whereIn('status', ['pending', 'disetujui'])
            ->selectRaw('siswa_id, jumlah, tgl_bayar, metode, COUNT(*) AS n')
            ->groupBy('siswa_id', 'jumlah', 'tgl_bayar', 'metode')
            ->havingRaw('COUNT(*) > 1')->get()
            ->keyBy($kunci);
        foreach ($rows as $row) {
            $row->kembar = (int) ($hitung[$kunci($row)]->n ?? 1);
        }

        $pending = TopupRequest::where('status', 'pending')
            ->selectRaw('COUNT(*) AS n, COALESCE(SUM(jumlah),0) AS rp')->first();
        $hariIni = TopupRequest::where('status', 'disetujui')
            ->where('decided_at', '>=', WaktuIso::awalHariIni())
            ->selectRaw('COUNT(*) AS n, COALESCE(SUM(jumlah),0) AS rp')->first();

        return response()->json([
            'pengajuan' => $rows, 'total' => $total, 'page' => $page, 'limit' => $limit,
            'n_pending' => (int) $pending->n, 'rp_pending' => (int) $pending->rp,
            'n_hari_ini' => (int) $hariIni->n, 'rp_hari_ini' => (int) $hariIni->rp,
        ]);
    }

    public function setujui(Request $r, int $id)
    {
        $user = $this->wajibPeran($r, 'superadmin');
        // Konfirmasi kedua dari layar admin ikut dikirim ke server. Ini bukan
        // lapis keamanan — klien mana pun bisa menyertakannya — melainkan
        // pengaman agar klik nyasar atau permintaan yang terulang tidak
        // diam-diam menambah saldo.
        if ($r->input('konfirmasi') !== true) {
            throw new ApiError(400, 'Persetujuan harus melewati konfirmasi kedua');
        }

        $keTitipan = $r->input('ke_titipan') === true;

        $hasil = DB::transaction(function () use ($id, $user, $keTitipan) {
            $req = TopupRequest::find($id);
            if (! $req) {
                throw new ApiError(404, 'Pengajuan tidak ditemukan');
            }
            if ($req->status !== 'pending') {
                throw new ApiError(409, $req->status === 'disetujui'
                    ? 'Pengajuan ini sudah disetujui sebelumnya — saldo tidak ditambah dua kali'
                    : 'Pengajuan ini sudah ditolak');
            }

            /*
             * Biaya admin dipungut dari nominal yang DIBAYAR wali, sebelum
             * sisanya dibagi ke saldo/titipan. Tarifnya dibaca saat persetujuan,
             * bukan saat pengajuan dibuat — pengajuan bisa menunggu berhari-hari
             * di antrean, dan yang berlaku adalah aturan pada saat uangnya
             * benar-benar diakui masuk.
             */
            $santri = \App\Models\Siswa::find($req->siswa_id);
            $biaya = BiayaAdmin::untukPengajuan(
                $santri, (int) $req->jumlah, $req->metode, $req->biaya_admin);
            $bersih = (int) $req->jumlah - $biaya;

            $ket = mb_substr('Top up ' . ($req->metode === 'qris' ? 'QRIS' : 'transfer bank') .
                ' via portal orang tua' . ($req->pengirim ? " — {$req->pengirim}" : '') .
                " (bayar {$req->tgl_bayar})" .
                ($biaya > 0 ? ' — dari ' . Rupiah::f((int) $req->jumlah)
                    . ', biaya admin ' . Rupiah::f($biaya) : ''), 0, 300);

            $setor = Setoran::terima($req->siswa_id, $bersih, [
                'keTitipan' => $keTitipan,
                'operator' => $user->nama,
                'keterangan' => $ket,
                'txId' => "ortu-topup-{$req->id}",
                'refTitipan' => "ortu-topup-{$req->id}",
            ]);

            // Dicatat SESUDAH top up, dan memakai saldo sesudahnya, supaya
            // rantai saldo di buku besar tetap runut.
            $txBiaya = BiayaAdmin::catat($setor['siswa'], $biaya, $user->nama,
                "Biaya admin top up portal orang tua (pengajuan #{$req->id})",
                "ortu-biaya-{$req->id}");

            // Uangnya bermuara ke Dana Admin — pos milik sekolah. `ref` yang
            // sama dengan tx_id membuat persetujuan yang terulang tidak
            // menambah dana dua kali sementara wali hanya dipungut sekali.
            DanaAdmin::terima($biaya, $setor['siswa'], $txBiaya, $user->nama,
                "ortu-biaya-{$req->id}");

            $tandai = TopupRequest::where('id', $req->id)->where('status', 'pending')->update([
                'status' => 'disetujui', 'transaksi_id' => $setor['transaksi']?->id,
                'operator' => $user->nama, 'alasan' => '', 'decided_at' => WaktuIso::sekarang(),
            ]);
            if ($tandai === 0) {
                throw new ApiError(409, 'Pengajuan sudah diproses admin lain');
            }

            return [
                'ok' => true,
                'pengajuan' => TopupRequest::find($req->id),
                'siswa' => $setor['siswa'], 'transaksi' => $setor['transaksi'],
                'ke_saldo' => $setor['ke_saldo'], 'ke_titipan' => $setor['ke_titipan'],
            ];
        });

        // Pengajuan yang seluruhnya mengendap di titipan tetap layak diteteskan
        // sekarang kalau kartunya sedang tipis.
        if ($hasil['ke_titipan'] > 0 && $smart = SmartTopup::diam($hasil['siswa']->id, 'persetujuan top up ortu')) {
            $hasil['siswa'] = $smart['siswa'];
            $hasil['smart'] = ['isi' => $smart['isi'], 'sisa_titipan' => $smart['sisa_titipan']];
        }

        return response()->json($hasil);
    }

    public function tolak(Request $r, int $id)
    {
        $user = $this->wajibPeran($r, 'superadmin');
        $alasan = mb_substr(trim((string) $r->input('alasan', '')), 0, 300);
        // Alasannya DIBACA orang tua di portal, jadi tidak boleh kosong — kalau
        // tidak, wali hanya melihat pengajuannya hilang tanpa tahu harus
        // berbuat apa.
        if (mb_strlen($alasan) < 3) {
            throw new ApiError(400, 'Alasan penolakan wajib diisi (minimal 3 huruf) — orang tua akan membacanya');
        }

        $ubah = TopupRequest::where('id', $id)->where('status', 'pending')->update([
            'status' => 'ditolak', 'alasan' => $alasan,
            'operator' => $user->nama, 'decided_at' => WaktuIso::sekarang(),
        ]);
        if ($ubah === 0) {
            $req = TopupRequest::find($id);
            if (! $req) {
                throw new ApiError(404, 'Pengajuan tidak ditemukan');
            }
            throw new ApiError(409, "Pengajuan ini sudah {$req->status}");
        }

        return response()->json(['ok' => true, 'pengajuan' => TopupRequest::find($id)]);
    }

    public function pengaturan(Request $r)
    {
        $this->wajibPeran($r, 'superadmin');
        $cfg = Pengaturan::sekolah();
        $p = [];
        foreach (array_keys(self::LABELS) as $k) {
            $p[$k] = $cfg[$k] ?? 0;
        }
        $info = self::info_();

        return response()->json(['pengaturan' => $p, 'labels' => self::LABELS,
            'info' => $info, 'siap' => self::metodeSiap($info),
            'sasaran_biaya' => BiayaAdmin::sasaran()]);
    }

    public function simpanPengaturan(Request $r)
    {
        $this->wajibPeran($r, 'superadmin');
        $b = $r->all();
        $nilai = Pengaturan::sekolah();

        foreach (self::LABELS as $key => $def) {
            if (! array_key_exists($key, $b)) {
                continue;
            }
            if (! is_numeric($b[$key])) {
                throw new ApiError(400, "Nilai \"{$def['label']}\" tidak valid");
            }
            $v = (int) floor((float) $b[$key]);
            if ($key === 'topup_ortu_aktif') {
                if ($v !== 0 && $v !== 1) {
                    throw new ApiError(400, 'Pengajuan top up hanya bisa dibuka (1) atau ditutup (0)');
                }
            } elseif ($key === BiayaAdmin::KUNCI) {
                // Nol berarti tidak memungut — itu keadaan yang sah, dan harus
                // bisa dipilih tanpa harus mematikan fitur lain.
                if ($v < 0) {
                    throw new ApiError(400, 'Biaya admin tidak boleh negatif');
                }
            } elseif ($v <= 0) {
                throw new ApiError(400, "Nilai \"{$def['label']}\" harus lebih dari nol");
            }
            $nilai[$key] = $v;
        }

        if ($nilai['topup_ortu_maks'] < $nilai['topup_ortu_min']) {
            throw new ApiError(400, 'Nominal terbesar tidak boleh lebih kecil dari nominal terkecil');
        }
        // Pengajuan yang dari awal mustahil disetujui hanya akan menumpuk di antrean.
        if ($nilai['topup_ortu_maks'] > $nilai['max_saldo']) {
            throw new ApiError(400, 'Nominal terbesar tidak boleh melampaui batas saldo santri (' .
                Rupiah::f($nilai['max_saldo']) . ')');
        }
        /*
         * Biaya harus lebih kecil dari nominal terkecil yang boleh diajukan.
         * Kalau tidak, ada pengajuan sah yang habis dimakan biayanya sendiri —
         * wali membayar, saldo anaknya tidak bertambah sepeser pun, dan tidak
         * ada satu pun pesan yang menjelaskan mengapa.
         */
        // `?? 0` bukan kehati-hatian berlebihan: basis data yang dipasang
        // sebelum kunci ini ada akan kehilangannya, dan tanpa ini seluruh layar
        // pengaturan Topup Ortu gagal dengan galat 500 yang tidak menyebut
        // sebabnya. Terjadi di produksi.
        $tarifBiaya = (int) ($nilai[BiayaAdmin::KUNCI] ?? 0);
        if ($tarifBiaya > 0 && $tarifBiaya >= $nilai['topup_ortu_min']) {
            throw new ApiError(400, 'Biaya admin (' . Rupiah::f($tarifBiaya) .
                ') harus lebih kecil dari nominal terkecil pengajuan (' .
                Rupiah::f($nilai['topup_ortu_min']) . ') — kalau tidak, ada pengajuan yang ' .
                'seluruh nominalnya habis oleh biaya');
        }
        if ($nilai['topup_ortu_antre'] > self::ANTRE_BATAS_ATAS) {
            throw new ApiError(400, 'Maksimal ' . self::ANTRE_BATAS_ATAS . ' pengajuan menunggu per santri');
        }

        $info = self::info_();
        $satuBaris = fn ($v, int $maks) => mb_substr(preg_replace('/\s+/', ' ', trim((string) ($v ?? ''))), 0, $maks);

        foreach (['qris_nama' => 80, 'bank_nama' => 40, 'bank_rekening' => 40, 'bank_atasnama' => 80] as $k => $maks) {
            if (array_key_exists($k, $b)) {
                $info[$k] = $satuBaris($b[$k], $maks);
            }
        }
        foreach (['qris_instruksi', 'bank_instruksi'] as $k) {
            if (array_key_exists($k, $b)) {
                $info[$k] = mb_substr(trim((string) ($b[$k] ?? '')), 0, 500);
            }
        }
        // Gambar QRIS: kirim data URL untuk mengganti, string kosong untuk mencopot.
        if (! empty($b['qris_gambar_baru'])) {
            $info['qris_gambar'] = Berkas::simpanGambarBase64((string) $b['qris_gambar_baru'], 'qris');
        } elseif (($b['qris_gambar'] ?? null) === '') {
            $info['qris_gambar'] = '';
        }

        // Nomor rekening tanpa nama bank (atau sebaliknya) akan tampil setengah
        // jadi di layar orang tua — lebih baik ditolak sekarang.
        if ((bool) $info['bank_nama'] !== (bool) $info['bank_rekening']) {
            throw new ApiError(400, 'Nama bank dan nomor rekening harus diisi berpasangan');
        }

        DB::transaction(function () use ($nilai, $info) {
            foreach (array_keys(self::LABELS) as $key) {
                Setting::simpan($key, $nilai[$key] ?? 0);
            }
            foreach (self::INFO_KEYS as $k) {
                TopupInfo::simpan($k, $info[$k]);
            }
        });
        Pengaturan::lupakanCache();

        $sesudah = Pengaturan::sekolah();
        $p = [];
        foreach (array_keys(self::LABELS) as $k) {
            $p[$k] = $sesudah[$k];
        }
        $infoBaru = self::info_();

        return response()->json(['ok' => true, 'pengaturan' => $p,
            'info' => $infoBaru, 'siap' => self::metodeSiap($infoBaru)]);
    }

    // ------------------------------------------------------------ penolong

    private function ortu(Request $r): Siswa
    {
        $s = $r->attributes->get('ortu');
        if (! $s) {
            throw new ApiError(401, 'Sesi wali sudah berakhir — masuk lagi');
        }

        return $s;
    }

    private function antre(int $siswaId): array
    {
        $a = TopupRequest::where('siswa_id', $siswaId)->where('status', 'pending')
            ->selectRaw('COUNT(*) AS n, COALESCE(SUM(jumlah),0) AS total')->first();

        return ['n' => (int) $a->n, 'total' => (int) $a->total];
    }

    /** Metode yang instruksinya sudah lengkap — yang belum tidak pernah ditawarkan. */
    public static function metodeSiap(array $info): array
    {
        return [
            'qris' => (bool) $info['qris_gambar'],
            'transfer' => (bool) ($info['bank_nama'] && $info['bank_rekening']),
        ];
    }

    /**
     * Kunci asing yang mungkin tersisa di tabel diabaikan, bukan diteruskan ke
     * klien — daftar kunci di kode inilah yang menentukan bentuk jawabannya.
     */
    public static function info_(): array
    {
        $out = array_fill_keys(self::INFO_KEYS, '');
        foreach (TopupInfo::semua() as $k => $v) {
            if (in_array($k, self::INFO_KEYS, true)) {
                $out[$k] = (string) $v;
            }
        }

        return $out;
    }

    private function untukOrtu(TopupRequest $r): array
    {
        return $r->only(['id', 'jumlah', 'metode', 'tgl_bayar', 'pengirim', 'bukti',
            'status', 'alasan', 'created_at', 'decided_at']);
    }
}
