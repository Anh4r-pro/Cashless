<?php

namespace App\Services;

use Illuminate\Support\Collection;

/**
 * Penyusun berkas ekspor riwayat.
 *
 * Format .xls dan .doc di sini adalah TABEL HTML, bukan berkas biner — Excel,
 * LibreOffice, dan Google Sheets sama-sama membukanya, dan sekolah tidak perlu
 * memasang apa pun. Ini pilihan yang sama seperti sistem lama; menggantinya
 * dengan berkas XLSX sejati akan mengubah hasil yang sudah biasa dipakai
 * bendahara tanpa ada yang meminta.
 */
class Ekspor
{
    public const JUDUL = ['Waktu (WIB)', 'Tipe', 'Santri', 'Kelas', 'Tenant',
        'Jumlah (Rp)', 'Saldo Sesudah', 'Operator', 'Keterangan'];

    /** Satu baris transaksi, waktunya sudah digeser ke WIB. */
    public static function baris(object $t): array
    {
        $wib = (new \DateTime($t->created_at, new \DateTimeZone('UTC')))
            ->setTimezone(new \DateTimeZone('Asia/Jakarta'))
            ->format('Y-m-d H:i');

        return [$wib, $t->tipe, $t->siswa_nama, $t->kelas, $t->tenant_nama ?: '-',
            $t->jumlah, $t->saldo_sesudah, $t->operator, $t->keterangan];
    }

    public static function csv(Collection $rows): string
    {
        $baris = [implode(',', array_map([self::class, 'kutip'], self::JUDUL))];
        foreach ($rows as $t) {
            $baris[] = implode(',', array_map([self::class, 'kutip'], self::baris($t)));
        }

        // BOM di depan supaya Excel membaca berkasnya sebagai UTF-8.
        return "\xEF\xBB\xBF" . implode("\r\n", $baris);
    }

    public static function tabelHtml(Collection $rows, array $meta): string
    {
        $head = implode('', array_map(fn ($h) => '<th>' . e($h) . '</th>', self::JUDUL));
        $body = '';
        foreach ($rows as $t) {
            $sel = '';
            foreach (self::baris($t) as $i => $c) {
                // Kolom nominal dirata-kanan supaya angka rupiah mudah dibandingkan.
                $sel .= in_array($i, [5, 6], true)
                    ? '<td style="text-align:right">' . e($c) . '</td>'
                    : '<td>' . e($c) . '</td>';
            }
            $body .= "<tr>{$sel}</tr>\n";
        }

        return '
<meta charset="utf-8">
<h3>IMBOS Cashless — Riwayat Transaksi (' . e($meta['judul']) . ')</h3>
<p>Periode: ' . e($meta['periode']) . ' · ' . $rows->count() . ' transaksi ·
   Total belanja: ' . Rupiah::f($meta['total_belanja']) . ' ·
   Total top up: ' . Rupiah::f($meta['total_masuk']) . '</p>
<table border="1" cellspacing="0" cellpadding="4">
  <thead><tr style="background:#eee">' . $head . '</tr></thead>
  <tbody>' . $body . '</tbody>
</table>';
    }

    private static function kutip($v): string
    {
        $s = (string) $v;

        return preg_match('/[",\r\n]/', $s) ? '"' . str_replace('"', '""', $s) . '"' : $s;
    }
}
