'use strict';
/**
 * Isian nominal rupiah yang memisahkan ribuan sambil diketik: 5000 → 5.000.
 *
 * Kenapa perlu: seluruh nominal di sistem ini bilangan bulat rupiah, dan
 * kesalahan yang paling sering terjadi bukan salah angka melainkan salah
 * BANYAKNYA nol. "50000" dan "500000" terlihat nyaris sama di kotak isian
 * sempit pada layar ponsel, sedangkan akibatnya sepuluh kali lipat. Titik ribuan
 * membuat perbedaan itu terbaca sekilas.
 *
 * Kenapa bukan <input type="number">: peramban menolak titik di dalamnya, dan
 * nilainya jadi kosong. Karena itu kotaknya bertipe teks dengan inputmode
 * numeric — papan ketik ponsel tetap muncul sebagai papan angka.
 *
 * Cara memakai:
 *   inputUang({ id: 'm-jumlah', nilai: 5000, placeholder: 'mis. 50.000' })
 *   nilaiUang('#m-jumlah')   →  5000   (angka bulat; null kalau kosong)
 *
 * Berkas ini dimuat oleh halaman admin, portal wali, dan kios — ketiganya punya
 * isian nominal, dan aturannya harus sama persis di ketiganya.
 */

const UANG_MAKS_DIGIT = 12;   // Rp999 miliar sudah jauh di atas apa pun yang masuk akal

/** Semua angka di dalam teks, tanpa nol di depan. '' kalau tidak ada angka. */
function digitUang(teks) {
  const d = String(teks ?? '').replace(/\D/g, '').slice(0, UANG_MAKS_DIGIT);
  const bersih = d.replace(/^0+(?=\d)/, '');
  return bersih === '0' ? '0' : bersih;
}

/** 5000 → '5.000'. Kosong tetap kosong (bukan '0'), supaya placeholder terlihat. */
function teksUang(nilai) {
  const d = digitUang(nilai);
  return d === '' ? '' : Number(d).toLocaleString('id-ID');
}

/**
 * Baca nilai sebuah isian uang sebagai angka bulat.
 * Mengembalikan null kalau kosong — sengaja BUKAN 0, karena "belum diisi" dan
 * "sengaja nol" adalah dua hal berbeda bagi pemanggilnya (mis. penimpa batas
 * golongan, yang kosongnya berarti "ikut pengaturan sekolah").
 */
function nilaiUang(sel) {
  const el = typeof sel === 'string' ? document.querySelector(sel) : sel;
  if (!el) return null;
  const d = digitUang(el.value);
  return d === '' ? null : Number(d);
}

/** HTML satu isian uang. Atribut lain diteruskan apa adanya. */
function inputUang(o = {}) {
  const attr = [
    'type="text"', 'inputmode="numeric"', 'autocomplete="off"',
    `class="uang${o.kelas ? ' ' + o.kelas : ''}"`,
    o.id ? `id="${o.id}"` : '',
    `value="${teksUang(o.nilai)}"`,
    o.placeholder ? `placeholder="${String(o.placeholder).replace(/"/g, '&quot;')}"` : '',
    o.extra || '',
  ].filter(Boolean);
  return `<input ${attr.join(' ')}>`;
}

/**
 * Rapikan isian sambil menjaga posisi kursor.
 *
 * Menulis ulang el.value memindahkan kursor ke ujung kanan — menyebalkan saat
 * seseorang membetulkan satu angka di tengah. Jadi yang dihitung bukan posisi
 * karakter, melainkan BERAPA ANGKA yang ada di sebelah kiri kursor: jumlah itu
 * tidak berubah oleh titik yang bergeser, sehingga kursor bisa ditaruh kembali
 * di tempat yang sama secara logis.
 */
function rapikanUang(el) {
  const sebelum = el.value;
  const kursor = el.selectionStart ?? sebelum.length;
  const angkaKiri = sebelum.slice(0, kursor).replace(/\D/g, '').length;
  const teks = teksUang(sebelum);
  if (teks === sebelum) return;
  el.value = teks;

  if (angkaKiri === 0) { el.setSelectionRange(0, 0); return; }
  let n = 0, pos = teks.length;
  for (let i = 0; i < teks.length; i++) {
    if (teks[i] >= '0' && teks[i] <= '9') n++;
    if (n === angkaKiri) { pos = i + 1; break; }
  }
  el.setSelectionRange(pos, pos);
}

// Dipasang sekali sebagai penangkap di tingkat dokumen, bukan per elemen: hampir
// semua isian di aplikasi ini lahir dari innerHTML modal yang dibuat ulang terus
// menerus, dan memasang pendengar satu per satu berarti selalu ada yang terlewat.
document.addEventListener('input', (e) => {
  const t = e.target;
  if (t && t.tagName === 'INPUT' && t.classList.contains('uang')) rapikanUang(t);
});

// Nilai yang dipasang program (mis. tombol nominal cepat) juga harus tampil rapi.
function isiUang(sel, nilai) {
  const el = typeof sel === 'string' ? document.querySelector(sel) : sel;
  if (el) el.value = teksUang(nilai);
}
