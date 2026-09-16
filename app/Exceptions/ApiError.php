<?php

namespace App\Exceptions;

/**
 * Galat yang memang layak dilihat pengguna, lengkap dengan kode HTTP-nya.
 *
 * Pesannya ditulis untuk kasir dan admin sekolah, bukan untuk programmer:
 * "Saldo tidak cukup (saldo Rp5.000)" menyelesaikan antrean, "constraint
 * violation" hanya memindahkan kebingungan.
 */
class ApiError extends \RuntimeException
{
    public function __construct(public int $status, string $pesan)
    {
        parent::__construct($pesan);
    }

    public function render(): \Illuminate\Http\JsonResponse
    {
        return response()->json(['error' => $this->getMessage()], $this->status);
    }
}
