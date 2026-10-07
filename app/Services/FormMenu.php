<?php

namespace App\Services;

use App\Support\WaktuIso;
use Illuminate\Support\Collection;

/**
 * Daftar menu (bukan pengajuannya) untuk dibawa keluar sistem — dicetak jadi
 * PDF lewat dialog cetak peramban, atau diunduh sebagai .xls/.csv.
 *
 * Sengaja terpisah dari FormAjuan: formulir itu berisi usulan "dari → menjadi"
 * lengkap dengan blok tanda tangan untuk rapat keputusan, sedangkan ini
 * sekadar potret menu yang BERLAKU saat ini di kasir.
 */
class FormMenu
{
    public const JUDUL = ['No', 'Tenant', 'Menu', 'Harga', 'Kategori', 'Ketersediaan'];

    public static function baris(object $p, int $no): array
    {
        return [
            $no,
            $p->tenant_nama ?? '—',
            $p->nama,
            self::rp($p->harga),
            $p->kategori === 'non_makanan' ? 'Non-makanan' : 'Makanan',
            $p->aktif ? 'Tersedia' : 'Habis',
        ];
    }

    public static function csv(Collection $rows): string
    {
        $baris = [implode(',', array_map([self::class, 'kutip'], self::JUDUL))];
        foreach ($rows as $i => $p) {
            $baris[] = implode(',', array_map([self::class, 'kutip'], self::baris($p, $i + 1)));
        }

        return "\xEF\xBB\xBF" . implode("\r\n", $baris);
    }

    /** $meta: judul, sekolah, penyaring (nama tenant atau "Semua tenant"). */
    public static function html(Collection $rows, array $meta): string
    {
        $head = implode('', array_map(fn ($h) => '<th>' . e($h) . '</th>', self::JUDUL));
        $body = '';
        foreach ($rows as $i => $p) {
            $sel = '';
            foreach (self::baris($p, $i + 1) as $k => $c) {
                $sel .= $k === 3 ? '<td class="num">' . e($c) . '</td>' : '<td>' . e($c) . '</td>';
            }
            $body .= "<tr>{$sel}</tr>\n";
        }
        if ($rows->isEmpty()) {
            $body = '<tr><td colspan="' . count(self::JUDUL) .
                '" style="text-align:center;padding:18px">Belum ada menu.</td></tr>';
        }

        $sekolah = e($meta['sekolah'] ?? 'IMBOS Cashless');
        $judul = e($meta['judul'] ?? 'Daftar Menu Kantin');
        $saring = e($meta['penyaring'] ?? 'Semua tenant');
        $dicetak = self::wib(WaktuIso::sekarang());

        return <<<HTML
        <meta charset="utf-8">
        <div class="kop">
          <h2>{$sekolah}</h2>
          <h3>{$judul}</h3>
        </div>
        <table class="meta">
          <tr><td>Tenant</td><td>: {$saring}</td>
              <td>Jumlah menu</td><td>: {$rows->count()}</td></tr>
          <tr><td>Dicetak</td><td>: {$dicetak} WIB</td><td></td><td></td></tr>
        </table>
        <table class="isi">
          <thead><tr>{$head}</tr></thead>
          <tbody>
        {$body}
          </tbody>
        </table>
        HTML;
    }

    public static function gaya(): string
    {
        return FormAjuan::gaya();
    }

    private static function rp(?int $n): string
    {
        return $n === null ? '—' : 'Rp' . number_format($n, 0, ',', '.');
    }

    private static function wib(?string $iso): string
    {
        if (! $iso) {
            return '—';
        }
        try {
            return (new \DateTime($iso, new \DateTimeZone('UTC')))
                ->setTimezone(new \DateTimeZone('Asia/Jakarta'))->format('d/m/Y H:i');
        } catch (\Throwable) {
            return '—';
        }
    }

    private static function kutip($v): string
    {
        return '"' . str_replace('"', '""', (string) $v) . '"';
    }
}
