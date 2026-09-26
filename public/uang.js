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

/**
 * Isian berisi DAFTAR nominal (mis. pilihan donasi): "2.000 / 5.000 / 10.000".
 *
 * Pemisahnya garis miring, bukan koma: begitu titik dipakai sebagai pemisah
 * ribuan, koma dalam kebiasaan Indonesia terbaca sebagai desimal — "2.000, 5.000"
 * mudah disangka dua koma lima. Koma, titik koma, dan spasi tetap DITERIMA saat
 * dibaca, supaya kebiasaan lama ("2000, 5000") tidak tiba-tiba ditolak.
 *
 * Sambil diketik hanya titik ribuan yang disentuh; pemisah dibiarkan persis
 * seperti yang diketik. Kalau pemisahnya ikut dirapikan saat mengetik, spasi di
 * sekitar "/" yang dihapus dengan backspace akan langsung muncul lagi dan
 * kursornya tidak pernah bisa maju. Pemisah baru diseragamkan saat kotak
 * ditinggalkan.
 */
const UANG_PEMISAH_DAFTAR = /[\/,;\s]+/;

/** "2000, 5.000 /10000" → [2000, 5000, 10000]. Potongan tanpa angka dilewati. */
function daftarUang(teks) {
  return String(teks ?? '').split(UANG_PEMISAH_DAFTAR)
    .map(digitUang).filter((d) => d !== '').map(Number);
}

/** [2000, 5000] → '2.000 / 5.000'. Teks juga diterima. */
function teksDaftarUang(nilai) {
  const arr = Array.isArray(nilai) ? nilai : daftarUang(nilai);
  return arr.map(teksUang).filter(Boolean).join(' / ');
}

function rapikanDaftarUang(el) {
  const sebelum = el.value;
  const kursor = el.selectionStart ?? sebelum.length;
  // Hanya angka, titik, dan pemisah yang boleh tinggal.
  const saring = (s) => s.replace(/[^\d.\/,;\s]/g, '');
  // Titik ribuan satu-satunya yang berubah, jadi posisi kursor dihitung dari
  // banyaknya karakter BUKAN-titik di kirinya — sejalan dengan rapikanUang.
  const kiri = saring(sebelum.slice(0, kursor)).replace(/\./g, '').length;
  const teks = saring(sebelum).replace(/[\d.]+/g, (m) => teksUang(m));
  if (teks === sebelum) return;
  el.value = teks;

  let n = 0, pos = 0;
  if (kiri > 0) {
    pos = teks.length;
    for (let i = 0; i < teks.length; i++) {
      if (teks[i] !== '.') n++;
      if (n === kiri) { pos = i + 1; break; }
    }
  }
  el.setSelectionRange(pos, pos);
}

// Dipasang sekali sebagai penangkap di tingkat dokumen, bukan per elemen: hampir
// semua isian di aplikasi ini lahir dari innerHTML modal yang dibuat ulang terus
// menerus, dan memasang pendengar satu per satu berarti selalu ada yang terlewat.
document.addEventListener('input', (e) => {
  const t = e.target;
  if (!t || t.tagName !== 'INPUT') return;
  if (t.classList.contains('uang')) rapikanUang(t);
  else if (t.classList.contains('uang-daftar')) rapikanDaftarUang(t);
});
// Pemisah diseragamkan saat kotak ditinggalkan. 'blur' tidak menggelembung,
// jadi yang didengar 'focusout'.
document.addEventListener('focusout', (e) => {
  const t = e.target;
  if (!t || t.tagName !== 'INPUT' || !t.classList.contains('uang-daftar')) return;
  const rapi = teksDaftarUang(t.value);
  if (rapi === t.value) return;
  t.value = rapi;
  // Pemanggil yang menyimpan nilainya lewat oninput perlu tahu isinya berubah.
  t.dispatchEvent(new Event('input', { bubbles: true }));
});

// Nilai yang dipasang program (mis. tombol nominal cepat) juga harus tampil rapi.
function isiUang(sel, nilai) {
  const el = typeof sel === 'string' ? document.querySelector(sel) : sel;
  if (el) el.value = teksUang(nilai);
}
