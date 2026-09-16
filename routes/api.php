<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\KasirController;
use App\Http\Controllers\Api\AnalitikController;
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
use App\Http\Controllers\Api\ProdukController;
use App\Http\Controllers\Api\SaldoController;
use App\Http\Controllers\Api\SiswaController;
use App\Http\Controllers\Api\TenantController;
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
    Route::post('topup', [SaldoController::class, 'topup']);
    Route::post('koreksi', [SaldoController::class, 'koreksi']);
    Route::post('koreksi-massal', [SaldoController::class, 'koreksiMassal']);

    Route::post('titipan/setor', [SaldoController::class, 'titipanSetor']);
    Route::post('titipan/tarik', [SaldoController::class, 'titipanTarik']);
    Route::post('titipan/cairkan', [SaldoController::class, 'titipanCairkan']);
    Route::get('siswa/{id}/titipan', [SaldoController::class, 'titipanSiswa']);
    Route::put('siswa/{id}/smart', [SaldoController::class, 'smart']);
});

// --- pemegang kartu, golongan, tenant -------------------------------------
Route::get('golongan', [GolonganController::class, 'index'])->middleware('peran:superadmin,tenant');

Route::middleware('peran:superadmin')->group(function () {
    Route::get('siswa', [SiswaController::class, 'index']);
    Route::post('siswa', [SiswaController::class, 'store']);
    Route::put('siswa/{id}', [SiswaController::class, 'update']);
    Route::delete('siswa/{id}', [SiswaController::class, 'destroy']);
    Route::post('siswa/{id}/foto', [SiswaController::class, 'foto']);
    Route::post('siswa/{id}/reset-pin', [SiswaController::class, 'resetPin']);
    Route::post('assign-card', [SiswaController::class, 'assignCard']);

    Route::post('golongan', [GolonganController::class, 'store']);
    Route::put('golongan/{id}', [GolonganController::class, 'update']);
    Route::delete('golongan/{id}', [GolonganController::class, 'destroy']);

    Route::get('tenants', [TenantController::class, 'index']);
    Route::post('tenants', [TenantController::class, 'store']);
    Route::put('tenants/{id}', [TenantController::class, 'update']);
    Route::delete('tenants/{id}', [TenantController::class, 'destroy']);
});

// --- menu: tenant mengusulkan, superadmin memutuskan ----------------------
Route::get('produk', [ProdukController::class, 'index'])->middleware('peran:tenant,superadmin');
Route::get('produk/ajuan', [ProdukController::class, 'daftarAjuan'])->middleware('peran:tenant,superadmin');

Route::middleware('peran:tenant')->group(function () {
    Route::post('produk', [ProdukController::class, 'store']);
    Route::put('produk/{id}', [ProdukController::class, 'update']);
    Route::put('produk/{id}/ketersediaan', [ProdukController::class, 'ketersediaan']);
    Route::delete('produk/{id}', [ProdukController::class, 'destroy']);
    Route::delete('produk/ajuan/{id}', [ProdukController::class, 'batalAjuan']);
});

Route::middleware('peran:superadmin')->group(function () {
    Route::put('produk/{id}/kategori', [ProdukController::class, 'kategori']);
    Route::post('produk/ajuan/{id}/setujui', [ProdukController::class, 'setujui']);
    Route::post('produk/ajuan/{id}/tolak', [ProdukController::class, 'tolak']);
});

// --- riwayat, ekspor, setoran tenant, dashboard ----------------------------
Route::middleware('peran:tenant,superadmin')->group(function () {
    Route::get('transaksi', [TransaksiController::class, 'index']);
    Route::get('export', [TransaksiController::class, 'ekspor']);
    Route::get('export-print', [TransaksiController::class, 'cetak']);
    Route::get('settlements', [SettlementController::class, 'index']);
});

Route::middleware('peran:superadmin')->group(function () {
    Route::post('settlements', [SettlementController::class, 'store']);
    Route::post('settlements/{id}/bukti', [SettlementController::class, 'bukti']);
    Route::get('dashboard', [DashboardController::class, 'admin']);
});
Route::get('dashboard-tenant', [DashboardController::class, 'tenant'])->middleware('peran:tenant');

// --- pengaturan sekolah, neraca kas, kategori potongan --------------------
Route::get('kelas', [PengaturanController::class, 'kelas'])->middleware('peran:tenant,superadmin');

Route::middleware('peran:superadmin')->group(function () {
    Route::get('settings', [PengaturanController::class, 'index']);
    Route::put('settings', [PengaturanController::class, 'simpan']);
    Route::get('neraca', [PengaturanController::class, 'neraca']);
    Route::post('neraca/saldo-awal', [PengaturanController::class, 'saldoAwal']);
    Route::get('alasan-potong', [PengaturanController::class, 'alasan']);
    Route::put('alasan-potong', [PengaturanController::class, 'simpanAlasan']);
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
    Route::get('donasi/program', [DonasiController::class, 'program']);
    Route::put('donasi/program', [DonasiController::class, 'simpanProgram']);
    Route::get('donasi/pengaturan', [DonasiController::class, 'pengaturan']);
    Route::put('donasi/pengaturan', [DonasiController::class, 'simpanPengaturan']);
    Route::get('donasi/rekap', [DonasiController::class, 'rekap']);
    Route::put('kios/sandi-layar', [KiosController::class, 'sandiLayar']);
});

// --- layar kios siswa: tanpa login, dipagari tap kartu + PIN ---------------
Route::get('kios/info', [KiosController::class, 'info']);
Route::post('kios/tap', [KiosController::class, 'tap']);
Route::post('kios/donasi', [KiosController::class, 'donasi']);
Route::post('kios/transfer/cari', [KiosController::class, 'cariPenerima']);
Route::post('kios/transfer', [KiosController::class, 'transfer']);
Route::post('kios/ganti-pin', [KiosController::class, 'gantiPin']);
Route::post('kios/riwayat', [KiosController::class, 'riwayat']);
Route::post('kios/buka-layar', [KiosController::class, 'bukaLayar']);
Route::post('kios/selesai', [KiosController::class, 'selesai']);

// --- top up massal dari berkas Excel/CSV (dua langkah: pratinjau, terapkan) -
Route::middleware('peran:superadmin')->group(function () {
    Route::get('topup-massal/template', [TopupMassalController::class, 'template']);
    Route::post('topup-massal/pratinjau', [TopupMassalController::class, 'pratinjau']);
    Route::post('topup-massal/terapkan', [TopupMassalController::class, 'terapkan']);
    Route::get('topup-massal/riwayat', [TopupMassalController::class, 'riwayat']);
});

// --- top up mandiri orang tua ---------------------------------------------
Route::middleware('ortu')->group(function () {
    Route::get('ortu/topup/info', [TopupOrtuController::class, 'info']);
    Route::get('ortu/topup', [TopupOrtuController::class, 'daftarOrtu']);
    Route::post('ortu/topup', [TopupOrtuController::class, 'ajukan']);
});

Route::middleware('peran:superadmin')->group(function () {
    Route::get('topup-ortu', [TopupOrtuController::class, 'antrean']);
    Route::post('topup-ortu/{id}/setujui', [TopupOrtuController::class, 'setujui']);
    Route::post('topup-ortu/{id}/tolak', [TopupOrtuController::class, 'tolak']);
    Route::get('topup-ortu/pengaturan', [TopupOrtuController::class, 'pengaturan']);
    Route::put('topup-ortu/pengaturan', [TopupOrtuController::class, 'simpanPengaturan']);
});
