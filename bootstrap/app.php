<?php

use App\Exceptions\ApiError;
use App\Http\Middleware\SesiOrtuMiddleware;
use App\Http\Middleware\SesiStafMiddleware;
use App\Http\Middleware\WajibMasuk;
use App\Http\Middleware\WajibOrtu;
use App\Http\Middleware\WajibIzin;
use App\Http\Middleware\WajibPeran;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        // Seluruh permukaan aplikasi adalah JSON di bawah /api. Berkas rute
        // terpisah ini juga yang membuatnya lepas dari middleware sesi web —
        // sesi di sini berbasis token cookie sendiri, bukan sesi Laravel.
        api: __DIR__.'/../routes/api.php',
        apiPrefix: 'api',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->api(prepend: [
            SesiStafMiddleware::class,
            SesiOrtuMiddleware::class,
        ]);

        /*
         * Cookie sesi aplikasi ini BUKAN cookie Laravel: nilainya token mentah
         * yang dibaca frontend dan penyaji berkas apa adanya. Kalau ikut
         * dienkripsi middleware web, rute /uploads tidak akan pernah bisa
         * mengenali sesi wali maupun staf — dan gejalanya muncul sebagai foto
         * yang selalu 401, bukan sebagai galat.
         */
        $middleware->encryptCookies(except: ['sid', 'osid']);

        /*
         * ConvertEmptyStringsToNull DIMATIKAN dengan sengaja.
         *
         * API ini MEMBEDAKAN "" dari tidak-dikirim: string kosong berarti
         * "kosongkan nilainya" (mis. mencopot gambar QRIS, menghapus masa
         * berlaku kartu), sedangkan bidang yang tidak dikirim berarti "biarkan
         * apa adanya". Mengubah "" menjadi null menghapus perbedaan itu, dan
         * gejalanya berupa tombol hapus yang diam-diam tidak berfungsi.
         */
        $middleware->remove(\Illuminate\Foundation\Http\Middleware\ConvertEmptyStringsToNull::class);

        /*
         * Sandi dan PIN tidak ikut dipangkas spasinya: spasi di awal atau akhir
         * adalah bagian sah dari sandi yang dipilih orang, dan memangkasnya
         * membuat sandi yang benar ditolak.
         */
        $middleware->trimStrings(except: ['pin', 'pin_lama', 'pin_baru', 'sandi', 'sandi_baru', 'password', 'password_baru']);

        $middleware->alias([
            'masuk' => WajibMasuk::class,
            'peran' => WajibPeran::class,
            'izin' => WajibIzin::class,
            'ortu' => WajibOrtu::class,
        ]);

        /**
         * Header keamanan dipasang di sini supaya berlaku untuk SETIAP tanggapan,
         * termasuk halaman galat — celah yang paling mudah terlewat kalau
         * header ditulis satu per satu di controller.
         */
        $middleware->web(append: [
            \App\Http\Middleware\HeaderKeamanan::class,
        ]);
        $middleware->api(append: [
            \App\Http\Middleware\HeaderKeamanan::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        /**
         * ApiError adalah galat yang memang layak dibaca pengguna — pesannya
         * ditulis untuk kasir dan admin sekolah. Selebihnya tidak pernah
         * membocorkan jejak tumpukan ke klien.
         */
        $exceptions->render(function (ApiError $e, Request $r) {
            return response()->json(['error' => $e->getMessage()], $e->status);
        });

        $exceptions->render(function (\Illuminate\Validation\ValidationException $e, Request $r) {
            if ($r->is('api/*')) {
                return response()->json(['error' => $e->validator->errors()->first()], 400);
            }
        });

        $exceptions->render(function (\Symfony\Component\HttpKernel\Exception\NotFoundHttpException $e, Request $r) {
            if ($r->is('api/*')) {
                return response()->json(['error' => 'Endpoint tidak dikenal'], 404);
            }
        });
    })->create();
