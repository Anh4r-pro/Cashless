<?php

use App\Http\Controllers\HalamanController;
use App\Http\Controllers\UnggahanController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Halaman & berkas unggahan
|--------------------------------------------------------------------------
|
| Antarmukanya satu halaman per peran, vanilla JS tanpa build step: berkasnya
| dilayani apa adanya dari public/, TIDAK lewat Blade — templat Blade akan
| menafsirkan kurung kurawal ganda di dalam JavaScript sebagai kodenya sendiri.
|
| Yang perlu logika hanya berkas unggahan: tiap berkas diperiksa haknya dulu.
|
*/

Route::get('/', [HalamanController::class, 'index']);
// Alamat pendek: /ortu untuk wali murid, /kios untuk layar di depan reader.
Route::get('/ortu', [HalamanController::class, 'ortu']);
Route::get('/kios', [HalamanController::class, 'kios']);

Route::get('/uploads/{nama}', [UnggahanController::class, 'ambil'])
    ->where('nama', '[A-Za-z0-9._-]+');
