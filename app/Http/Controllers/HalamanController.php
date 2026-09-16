<?php

namespace App\Http\Controllers;

use Illuminate\Http\Response;

/** Penyaji halaman statis. Satu halaman per peran, tanpa build step. */
class HalamanController
{
    public function index(): Response
    {
        return $this->sajikan('index.html');
    }

    public function ortu(): Response
    {
        return $this->sajikan('ortu.html');
    }

    public function kios(): Response
    {
        return $this->sajikan('kios.html');
    }

    private function sajikan(string $berkas): Response
    {
        return response(file_get_contents(public_path($berkas)), 200, [
            'Content-Type' => 'text/html; charset=utf-8',
            // Halaman ini berubah setiap kali aplikasinya diperbarui, dan
            // peramban kios jarang di-refresh manual.
            'Cache-Control' => 'no-cache',
        ]);
    }
}
