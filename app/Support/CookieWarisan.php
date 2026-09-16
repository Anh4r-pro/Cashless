<?php

namespace App\Support;

use Symfony\Component\HttpFoundation\Cookie;

/**
 * Cookie sesi dengan bentuk tulisan yang PERSIS seperti sistem lama.
 *
 * RFC 6265 menyatakan nama atribut cookie tidak peka huruf besar-kecil, dan
 * Symfony menuliskannya sebagai `httponly`. Secara perilaku itu identik — tetapi
 * frontend dan suite uji yang sudah ada memeriksa string `HttpOnly` apa adanya,
 * dan mengubah pemeriksa demi menyesuaikan implementasi baru berarti melemahkan
 * alat ukurnya sendiri. Lebih jujur menyamakan keluarannya di sini.
 */
class CookieWarisan extends Cookie
{
    public function __toString(): string
    {
        $bagian = [$this->getName() . '=' . $this->getValue()];

        if ($this->isHttpOnly()) {
            $bagian[] = 'HttpOnly';
        }
        if ($this->isSecure()) {
            $bagian[] = 'Secure';
        }
        $bagian[] = 'Path=' . ($this->getPath() ?: '/');

        $maxAge = $this->getMaxAge();
        $bagian[] = 'Max-Age=' . $maxAge;

        if ($sameSite = $this->getSameSite()) {
            $bagian[] = 'SameSite=' . ucfirst($sameSite);
        }

        return implode('; ', $bagian);
    }
}
