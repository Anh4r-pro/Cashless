#!/bin/bash
# Menerapkan pembaruan ke produksi — dan MEMBANGUN ULANG cache Laravel.
#
# Berkas ini ada karena satu kebiasaan yang sempat berjalan berminggu-minggu:
# setiap kali menerapkan pembaruan, cache dibersihkan (`config:clear`,
# `route:clear`) supaya kode baru benar-benar terpakai — lalu tidak pernah
# dibangun lagi. Akibatnya produksi berjalan TANPA config cache dan TANPA
# route cache: tiap permintaan membaca ulang seluruh berkas config, membaca
# .env, dan menyusun ulang 195 rute dari nol.
#
# Di mesin yang lapang, itu hanya puluhan milidetik dan tidak terasa. Di mesin
# yang CPU-nya sedang diperebutkan, ia menjadi bagian yang terasa. Dan karena
# tidak ada yang MERUSAK — semuanya tetap berjalan benar, hanya lebih lambat —
# keadaan itu bisa bertahan lama tanpa ada yang menyadarinya.
#
# Urutannya tidak boleh ditukar: bersihkan dulu (supaya cache lama tidak
# dipakai saat migrasi berjalan), migrasikan, baru bangun cache dari kode baru.
#
#   ./terapkan.sh            — terapkan cabang yang sudah di-merge ke main
#   ./terapkan.sh --cek      — hanya periksa keadaan cache, tanpa mengubah apa pun
set -uo pipefail

APP=/var/www/kantin-laravel
PHP=php8.3

if [ "${1:-}" = "--cek" ]; then
  echo "Keadaan cache di $APP:"
  for f in config.php routes-v7.php events.php; do
    if [ -f "$APP/bootstrap/cache/$f" ]; then
      echo "  ✔ $f  ($(stat -c%y "$APP/bootstrap/cache/$f" | cut -d. -f1))"
    else
      echo "  ✘ $f TIDAK ADA — produksi berjalan tanpa cache ini"
    fi
  done
  exit 0
fi

cd "$APP" || exit 1

echo "→ membersihkan cache lama"
$PHP artisan config:clear >/dev/null
$PHP artisan route:clear  >/dev/null
$PHP artisan view:clear   >/dev/null

echo "→ migrasi basis data"
$PHP artisan migrate --force || exit 1

echo "→ membangun cache baru"
$PHP artisan config:cache >/dev/null || exit 1
$PHP artisan route:cache  >/dev/null || exit 1
$PHP artisan view:cache   >/dev/null || exit 1
$PHP artisan event:cache  >/dev/null || exit 1

# Cache dibaca php-fpm yang berjalan sebagai www-data. Ditulis root lewat skrip
# ini, jadi kepemilikannya dikembalikan — kalau tidak, pembersihan berikutnya
# oleh www-data akan gagal diam-diam.
chown www-data:www-data bootstrap/cache/*.php 2>/dev/null

echo "→ memuat ulang php-fpm (opcache ikut disegarkan)"
systemctl reload php8.3-fpm 2>/dev/null || systemctl restart php8.3-fpm

echo
"$(dirname "$(readlink -f "$0")")/terapkan.sh" --cek 2>/dev/null || true
echo "Selesai."
