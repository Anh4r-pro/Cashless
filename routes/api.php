<?php

use App\Http\Controllers\Api\AksesController;
use App\Http\Controllers\Api\AkunController;
use App\Http\Controllers\Api\ArsipController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\ImporKartuController;
use App\Http\Controllers\Api\KartuMassalController;
use App\Http\Controllers\Api\KenaikanKelasController;
use App\Http\Controllers\Api\KasirController;
use App\Http\Controllers\Api\AnalitikController;
use App\Http\Controllers\Api\DanaAdminController;
use App\Http\Controllers\Api\DashboardController;
use App\Http\Controllers\Api\GolonganController;
use App\Http\Controllers\Api\SettlementController;
use App\Http\Controllers\Api\TopupMassalController;
use App\Http\Controllers\Api\TopupOrtuController;
use App\Http\Controllers\Api\TransaksiController;
use App\Http\Controllers\Api\DonasiController;
use App\Http\Controllers\Api\KiosController;
use App\Http\Controllers\Api\OrtuController;
use App\Http\Controllers\Api\PengaturanController;
use App\Http\Controllers\Api\NotifController;
use App\Http\Controllers\Api\ProdukController;
use App\Http\Controllers\Api\SaldoController;
use App\Http\Controllers\Api\SiswaController;
use App\Http\Controllers\Api\TenantController;
use App\Http\Controllers\Api\UnitSdmController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Rute API kantin sekolah
|--------------------------------------------------------------------------
|
| Seluruh permukaan aplikasi ada di sini. Sesi memakai token cookie sendiri
| (`sid` untuk staf, `osid` untuk wali), bukan sesi Laravel — lihat App\Services\Sesi.
|
*/

// --- staf: masuk & keluar -------------------------------------------------
Route::post('login', [AuthController::class, 'login']);
Route::get('me', [AuthController::class, 'me']);
Route::post('logout', [AuthController::class, 'logout']);

// --- kasir: tap kartu & pembelian -----------------------------------------
Route::post('card-lookup', [KasirController::class, 'cardLookup'])->middleware('peran:tenant,superadmin');
Route::post('beli', [KasirController::class, 'beli'])->middleware('peran:tenant');

// --- uang masuk & keluar di luar kasir (eksklusif superadmin) --------------
Route::middleware('peran:superadmin')->group(function () {
    Route::post('topup', [SaldoController::class, 'topup'])->middleware('izin:saldo.topup');
    Route::get('topup/tarif', [SaldoController::class, 'tarifTopup'])->middleware('izin:saldo.topup');
    Route::post('koreksi', [SaldoController::class, 'koreksi'])->middleware('izin:saldo.koreksi');
    Route::post('koreksi-massal', [SaldoController::class, 'koreksiMassal'])->middleware('izin:saldo.koreksi_massal');

    Route::post('titipan/setor', [SaldoController::class, 'titipanSetor'])->middleware('izin:titipan.setor');
    Route::post('titipan/tarik', [SaldoController::class, 'titipanTarik'])->middleware('izin:titipan.tarik');
    Route::post('titipan/cairkan', [SaldoController::class, 'titipanCairkan'])->middleware('izin:titipan.cairkan');
    Route::get('siswa/{id}/titipan', [SaldoController::class, 'titipanSiswa'])->middleware('izin:saldo.lihat');
    Route::put('siswa/{id}/smart', [SaldoController::class, 'smart'])->middleware('izin:titipan.smart');
});

// --- pemegang kartu, golongan, tenant -------------------------------------
Route::get('golongan', [GolonganController::class, 'index'])->middleware('peran:superadmin,tenant');

Route::middleware('peran:superadmin')->group(function () {
    // Hak akses rute pemegang kartu diperiksa DI DALAM controller: satu endpoint
    // melayani layar Santri dan layar SDM sekaligus, dan lingkupnya baru
    // ketahuan setelah golongan yang diminta dibaca.
    Route::get('siswa', [SiswaController::class, 'index']);
    Route::post('siswa', [SiswaController::class, 'store']);
    Route::put('siswa/{id}', [SiswaController::class, 'update']);
    Route::delete('siswa/{id}', [SiswaController::class, 'destroy']);
    Route::post('siswa/{id}/foto', [SiswaController::class, 'foto']);
    Route::post('siswa/{id}/reset-pin', [SiswaController::class, 'resetPin']);
    Route::post('assign-card', [SiswaController::class, 'assignCard']);

    Route::post('golongan', [GolonganController::class, 'store'])->middleware('izin:golongan.kelola');
    Route::put('golongan/{id}', [GolonganController::class, 'update'])->middleware('izin:golongan.kelola');
    Route::delete('golongan/{id}', [GolonganController::class, 'destroy'])->middleware('izin:golongan.kelola');

    Route::get('unit-sdm', [UnitSdmController::class, 'index'])->middleware('izin:golongan.kelola');
    Route::post('unit-sdm', [UnitSdmController::class, 'store'])->middleware('izin:golongan.kelola');
    Route::put('unit-sdm/{id}', [UnitSdmController::class, 'update'])->middleware('izin:golongan.kelola');
    Route::delete('unit-sdm/{id}', [UnitSdmController::class, 'destroy'])->middleware('izin:golongan.kelola');

    Route::get('tenants', [TenantController::class, 'index'])->middleware('izin:tenant.lihat');
    Route::post('tenants', [TenantController::class, 'store'])->middleware('izin:tenant.tambah');
    Route::put('tenants/{id}', [TenantController::class, 'update'])->middleware('izin:tenant.ubah');
    Route::delete('tenants/{id}', [TenantController::class, 'destroy'])->middleware('izin:tenant.hapus');
});

// --- menu: tenant mengusulkan, superadmin memutuskan ----------------------
Route::get('produk', [ProdukController::class, 'index'])->middleware('peran:tenant,superadmin');
Route::get('produk/ajuan', [ProdukController::class, 'daftarAjuan'])->middleware('peran:tenant,superadmin');
// Form untuk dibawa ke luar sistem: .xls/.csv untuk diolah, /cetak untuk PDF
// lewat dialog cetak peramban. Hak aksesnya sama dengan melihat daftarnya.
Route::get('produk/ajuan/ekspor', [ProdukController::class, 'eksporAjuan'])->middleware('peran:tenant,superadmin');
Route::get('produk/ajuan/cetak', [ProdukController::class, 'cetakAjuan'])->middleware('peran:tenant,superadmin');

Route::middleware('peran:tenant')->group(function () {
    Route::post('produk', [ProdukController::class, 'store']);
    Route::put('produk/{id}', [ProdukController::class, 'update']);
    Route::put('produk/{id}/ketersediaan', [ProdukController::class, 'ketersediaan']);
    Route::delete('produk/{id}', [ProdukController::class, 'destroy']);
    Route::delete('produk/ajuan/{id}', [ProdukController::class, 'batalAjuan']);
    // Pengajuan barang lewat Excel: template → pratinjau → kirim sebagai satu paket.
    Route::get('produk/impor/template', [ProdukController::class, 'templateImpor']);
    Route::post('produk/impor/pratinjau', [ProdukController::class, 'pratinjauImpor']);
    Route::post('produk/impor/terapkan', [ProdukController::class, 'terapkanImpor']);
});

Route::middleware('peran:superadmin')->group(function () {
    Route::put('produk/{id}/kategori', [ProdukController::class, 'kategori'])->middleware('izin:produk.kategori');
    Route::post('produk/ajuan/{id}/setujui', [ProdukController::class, 'setujui'])->middleware('izin:ajuan.putuskan');
    Route::post('produk/ajuan/{id}/tolak', [ProdukController::class, 'tolak'])->middleware('izin:ajuan.putuskan');
    Route::get('produk/ajuan/paket', [ProdukController::class, 'daftarPaket'])->middleware('izin:ajuan.lihat');
    Route::get('produk/ajuan/paket/{paket}', [ProdukController::class, 'isiPaket'])->middleware('izin:ajuan.lihat');
    Route::post('produk/ajuan/paket/{paket}/setujui', [ProdukController::class, 'setujuiPaket'])->middleware('izin:ajuan.putuskan');
    Route::post('produk/ajuan/paket/{paket}/tolak', [ProdukController::class, 'tolakPaket'])->middleware('izin:ajuan.putuskan');
});

// --- riwayat, ekspor, setoran tenant, dashboard ----------------------------
Route::middleware('peran:tenant,superadmin')->group(function () {
    Route::get('transaksi', [TransaksiController::class, 'index']);
    Route::get('export', [TransaksiController::class, 'ekspor']);
    Route::get('export-print', [TransaksiController::class, 'cetak']);
    Route::get('settlements', [SettlementController::class, 'index']);
});

Route::middleware('peran:superadmin')->group(function () {
    Route::post('settlements', [SettlementController::class, 'store'])->middleware('izin:dashboard.setor');
    Route::post('settlements/{id}/bukti', [SettlementController::class, 'bukti'])->middleware('izin:dashboard.setor');
    Route::get('dashboard', [DashboardController::class, 'admin'])->middleware('izin:dashboard.lihat');
});
Route::get('dashboard-tenant', [DashboardController::class, 'tenant'])->middleware('peran:tenant');

// --- pengaturan sekolah, neraca kas, kategori potongan --------------------
Route::get('kelas', [PengaturanController::class, 'kelas'])->middleware('peran:tenant,superadmin');

Route::middleware('peran:superadmin')->group(function () {
    Route::get('settings', [PengaturanController::class, 'index'])->middleware('izin:settings.lihat');
    Route::put('settings', [PengaturanController::class, 'simpan'])->middleware('izin:settings.ubah');
    Route::get('neraca', [PengaturanController::class, 'neraca'])->middleware('izin:neraca.lihat');
    Route::post('neraca/saldo-awal', [PengaturanController::class, 'saldoAwal'])->middleware('izin:neraca.saldo_awal');
    Route::get('alasan-potong', [PengaturanController::class, 'alasan'])->middleware('izin:saldo.lihat,saldo.alasan');
    Route::put('alasan-potong', [PengaturanController::class, 'simpanAlasan'])->middleware('izin:saldo.alasan');
});

// --- analitik penjualan ---------------------------------------------------
Route::middleware('peran:tenant,superadmin')->group(function () {
    Route::get('analitik', [AnalitikController::class, 'umum']);
    Route::get('analitik/menu', [AnalitikController::class, 'menu']);
    Route::get('analitik/menu-tren', [AnalitikController::class, 'menuTren']);
});

// --- portal wali (/ortu): hanya BACA, selalu dipagari siswa_id dari sesi ---
Route::post('ortu/login', [OrtuController::class, 'login']);
Route::post('ortu/logout', [OrtuController::class, 'logout']);
Route::get('ortu/me', [OrtuController::class, 'me']);
Route::middleware('ortu')->group(function () {
    Route::get('ortu/ringkasan', [OrtuController::class, 'ringkasan']);
    Route::get('ortu/transaksi', [OrtuController::class, 'transaksi']);
});

// --- donasi: admin menentukan jenis & besarannya ---------------------------
Route::middleware('peran:superadmin')->group(function () {
    Route::get('donasi/program', [DonasiController::class, 'program'])->middleware('izin:donasi.lihat');
    Route::put('donasi/program', [DonasiController::class, 'simpanProgram'])->middleware('izin:donasi.program');
    Route::get('donasi/pengaturan', [DonasiController::class, 'pengaturan'])->middleware('izin:donasi.lihat');
    Route::put('donasi/pengaturan', [DonasiController::class, 'simpanPengaturan'])->middleware('izin:donasi.pengaturan');
    Route::get('donasi/rekap', [DonasiController::class, 'rekap'])->middleware('izin:donasi.lihat');
    Route::put('kios/sandi-layar', [KiosController::class, 'sandiLayar'])->middleware('izin:kios.pengaturan');
});

// --- layar kios santri: tanpa login, dipagari tap kartu + PIN ---------------
Route::get('kios/info', [KiosController::class, 'info']);
Route::post('kios/tap', [KiosController::class, 'tap']);
Route::post('kios/donasi', [KiosController::class, 'donasi']);
Route::post('kios/transfer/cari', [KiosController::class, 'cariPenerima']);
Route::post('kios/transfer', [KiosController::class, 'transfer']);
Route::post('kios/iuran', [KiosController::class, 'iuran']);
Route::post('kios/ganti-pin', [KiosController::class, 'gantiPin']);
Route::post('kios/riwayat', [KiosController::class, 'riwayat']);
Route::post('kios/buka-layar', [KiosController::class, 'bukaLayar']);
Route::post('kios/selesai', [KiosController::class, 'selesai']);

// --- top up massal dari berkas Excel/CSV (dua langkah: pratinjau, terapkan) -
Route::middleware('peran:superadmin')->group(function () {
    Route::get('topup-massal/template', [TopupMassalController::class, 'template'])->middleware('izin:massal.template');
    Route::post('topup-massal/pratinjau', [TopupMassalController::class, 'pratinjau'])->middleware('izin:massal.lihat');
    Route::post('topup-massal/terapkan', [TopupMassalController::class, 'terapkan'])->middleware('izin:massal.terapkan');
    Route::get('topup-massal/riwayat', [TopupMassalController::class, 'riwayat'])->middleware('izin:massal.lihat');
});

// --- top up mandiri orang tua ---------------------------------------------
Route::middleware('ortu')->group(function () {
    Route::get('ortu/topup/info', [TopupOrtuController::class, 'info']);
    Route::get('ortu/topup', [TopupOrtuController::class, 'daftarOrtu']);
    Route::post('ortu/topup', [TopupOrtuController::class, 'ajukan']);
});

// Lencana "ada yang menunggu" — dipanggil berkala oleh aplikasi admin, jadi
// jawabannya sengaja cuma dua angka. Izinnya diperiksa di controller, karena
// yang dihitung bergantung pada fitur mana yang boleh dibuka akun itu.
Route::middleware('peran:superadmin')->get('notif', [NotifController::class, 'hitung']);

Route::middleware('peran:superadmin')->group(function () {
    Route::get('topup-ortu', [TopupOrtuController::class, 'antrean'])->middleware('izin:topup_ortu.lihat');
    Route::post('topup-ortu/{id}/setujui', [TopupOrtuController::class, 'setujui'])->middleware('izin:topup_ortu.putuskan');
    Route::post('topup-ortu/{id}/tolak', [TopupOrtuController::class, 'tolak'])->middleware('izin:topup_ortu.putuskan');
    Route::get('topup-ortu/pengaturan', [TopupOrtuController::class, 'pengaturan'])->middleware('izin:topup_ortu.lihat,topup_ortu.pengaturan');
    Route::put('topup-ortu/pengaturan', [TopupOrtuController::class, 'simpanPengaturan'])->middleware('izin:topup_ortu.pengaturan');
});

// --- pendaftaran kolektif pemegang kartu dari Excel (dua langkah) ----------
// Izinnya (santri.impor / sdm.impor) diperiksa di controller, karena jenisnya
// baru diketahui setelah parameter `jenis` dibaca.
Route::middleware('peran:superadmin')->group(function () {
    Route::get('impor-kartu/template', [ImporKartuController::class, 'template']);
    Route::post('impor-kartu/pratinjau', [ImporKartuController::class, 'pratinjau']);
    Route::post('impor-kartu/terapkan', [ImporKartuController::class, 'terapkan']);
});

// --- pendaftaran UID kartu RFID kolektif: template Excel & tap berturut-turut
// (izinnya santri.kartu / sdm.kartu, diperiksa di controller seperti di atas)
Route::middleware('peran:superadmin')->group(function () {
    Route::get('kartu-massal/template', [KartuMassalController::class, 'template']);
    Route::post('kartu-massal/pratinjau', [KartuMassalController::class, 'pratinjau']);
    Route::post('kartu-massal/terapkan', [KartuMassalController::class, 'terapkan']);
    Route::post('kartu-massal/terapkan-tap', [KartuMassalController::class, 'terapkanTap']);
    Route::get('kartu-massal/riwayat', [KartuMassalController::class, 'riwayat']);
});

// --- kategori akses & akun pengguna ---------------------------------------
Route::middleware('peran:superadmin')->group(function () {
    Route::get('akses/katalog', [AksesController::class, 'katalog']);
    Route::get('akses/kategori', [AksesController::class, 'index']);
    Route::post('akses/kategori', [AksesController::class, 'store']);
    Route::put('akses/kategori/{id}', [AksesController::class, 'update']);
    Route::delete('akses/kategori/{id}', [AksesController::class, 'destroy']);

    Route::get('akun', [AkunController::class, 'index']);
    Route::post('akun', [AkunController::class, 'store']);
    Route::put('akun/{id}', [AkunController::class, 'update']);
    Route::delete('akun/{id}', [AkunController::class, 'destroy']);
});

// Ganti sandi sendiri: terbuka untuk SETIAP staf yang sudah masuk, termasuk
// akun kasir. Tanpa ini, seorang kasir yang sandinya bocor harus menunggu admin.
Route::put('akun/saya/sandi', [AkunController::class, 'gantiSandi'])->middleware('masuk');

// --- daur hidup pemegang kartu: Alumni / Lanjut Study / Pindah -------------
// Izinnya (santri.arsip / sdm.arsip) diperiksa di controller, karena lingkupnya
// baru diketahui setelah golongan kartu yang bersangkutan dibaca.
Route::middleware('peran:superadmin')->group(function () {
    Route::get('arsip/pratinjau', [ArsipController::class, 'pratinjau']);
    Route::get('arsip/unduh', [ArsipController::class, 'unduh']);
    Route::post('arsip/massal', [ArsipController::class, 'massal']);
    Route::post('siswa/{id}/arsip', [ArsipController::class, 'satu']);
    Route::post('siswa/{id}/aktifkan', [ArsipController::class, 'aktifkan']);
});

// --- kenaikan kelas massal (sekali per tahun ajaran) -----------------------
Route::middleware('peran:superadmin')->group(function () {
    Route::get('kenaikan-kelas/pratinjau', [KenaikanKelasController::class, 'pratinjau']);
    Route::get('kenaikan-kelas', [KenaikanKelasController::class, 'riwayat']);
    Route::post('kenaikan-kelas/terapkan', [KenaikanKelasController::class, 'terapkan']);
    Route::post('kenaikan-kelas/{id}/batalkan', [KenaikanKelasController::class, 'batalkan']);
});

// --- Dana Admin: pos dana milik sekolah dari biaya admin ------------------
Route::middleware('peran:superadmin')->group(function () {
    Route::get('dana-admin', [DanaAdminController::class, 'index'])->middleware('izin:dana.lihat');
    Route::post('dana-admin/tarik', [DanaAdminController::class, 'tarik'])->middleware('izin:dana.tarik');
});
