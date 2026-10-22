'use strict';
/**
 * Kompresi gambar di PERAMBAN sebelum diunggah — bukti transfer, foto santri,
 * bukti setoran/penarikan dana admin.
 *
 * Kenapa perlu: unggahan ini datang langsung dari kamera HP (biasanya 2-5MB,
 * kadang lebih) atau tangkapan layar aplikasi bank. Tanpa ini, berkas sebesar
 * itu dikirim mentah-mentah lewat JSON (base64 menambah ~33% lagi di atas
 * ukuran aslinya), memberatkan jaringan wali yang mengunggah DAN penyimpanan
 * server yang terus bertambah setiap hari — dan batas 3MB di server menolak
 * foto kamera yang kebetulan sedikit lebih besar, memaksa wali yang awam
 * teknologi mencari cara mengecilkan gambar sendiri.
 *
 * Caranya: gambar digambar ulang ke kanvas pada sisi terpanjang yang
 * dibatasi, lalu diekspor sebagai JPEG. Hasilnya tetap data URL
 * (`data:image/jpeg;base64,...`) — bentuk yang sama yang sudah diterima
 * server (lihat app/Services/Berkas.php), jadi tidak ada yang berubah di
 * sisi server maupun di alur unggahan yang sudah ada.
 *
 * Yang SENGAJA TIDAK dikompres di sini: gambar QRIS. Itu kode yang harus
 * tetap bisa dipindai — downscale atau artefak JPEG berisiko merusak
 * keterbacaannya, dan berkas QRIS yang sudah ada rata-rata cuma puluhan KB,
 * jadi tidak ada masalah nyata yang perlu diselesaikan di sana.
 *
 * Falsafahnya: KOMPRESI TIDAK PERNAH BOLEH MENGHALANGI UNGGAHAN. Kalau ada
 * yang gagal di mana pun dalam prosesnya (format aneh, kanvas bermasalah,
 * peramban lawas), hasilnya jatuh ke berkas ASLI apa adanya — bukan
 * menampilkan galat ke wali yang sedang terburu-buru mengirim bukti
 * transfer. Pemeriksaan yang sungguhan tetap di server
 * (Berkas::wajibUtuh membaca seluruh piksel), jadi gagal "fail open" di sini
 * aman: yang terburuk terjadi adalah berkas besar lolos seperti sebelum ada
 * fitur ini, bukan unggahan yang macet.
 */

/** Berkas sekecil ini tidak perlu disentuh — recompress hanya buang waktu. */
const GAMBAR_LEWATI_DI_BAWAH = 400 * 1024;

/** Baca berkas apa adanya sebagai data URL, tanpa kompresi. */
function _gambarKeDataUrlAsli(file) {
  return new Promise((resolve, reject) => {
    const r = new FileReader();
    r.onload = () => resolve(r.result);
    r.onerror = () => reject(new Error('Berkas gagal dibaca'));
    r.readAsDataURL(file);
  });
}

/**
 * Muat berkas sebagai elemen <img>, BUKAN createImageBitmap().
 *
 * Sengaja dipilih <img>: peramban modern otomatis memutar gambar sesuai
 * metadata EXIF saat menggambarnya lewat <img>, sedangkan createImageBitmap()
 * TIDAK melakukan itu kecuali diminta eksplisit. Foto tegak dari kamera HP
 * (yang pikselnya sering tersimpan miring dengan penanda rotasi di EXIF) bisa
 * keluar TERBALIK SAMPING kalau jalur ini salah pilih — bukti transfer yang
 * sudah susah dibaca jadi makin tidak terbaca sama sekali.
 */
function _muatGambar(file) {
  return new Promise((resolve, reject) => {
    const url = URL.createObjectURL(file);
    const img = new Image();
    img.onload = () => resolve(img);
    img.onerror = () => { URL.revokeObjectURL(url); reject(new Error('Gambar tidak bisa dibaca')); };
    img.src = url;
  });
}

/** canvas → Blob, dengan jalan mundur untuk peramban sangat lawas tanpa toBlob. */
function _kanvasKeBlob(kanvas, tipe, kualitas) {
  return new Promise((resolve) => {
    if (kanvas.toBlob) { kanvas.toBlob(resolve, tipe, kualitas); return; }
    try {
      const url = kanvas.toDataURL(tipe, kualitas);
      const bin = atob(url.split(',')[1]);
      const arr = new Uint8Array(bin.length);
      for (let i = 0; i < bin.length; i++) arr[i] = bin.charCodeAt(i);
      resolve(new Blob([arr], { type: tipe }));
    } catch { resolve(null); }
  });
}

function _blobKeDataUrl(blob) {
  return new Promise((resolve, reject) => {
    const r = new FileReader();
    r.onload = () => resolve(r.result);
    r.onerror = () => reject(new Error('Hasil kompresi gagal dibaca'));
    r.readAsDataURL(blob);
  });
}

/**
 * Kompres sebuah berkas gambar untuk diunggah.
 *
 * @param {File} file
 * @param {{maxSisi?: number, kualitas?: number}} [opsi]
 *   maxSisi: batas sisi terpanjang dalam piksel (bawaan 1600 — cukup lega
 *     untuk angka & teks di tangkapan layar aplikasi bank tetap terbaca).
 *   kualitas: kualitas JPEG 0–1 (bawaan 0.85).
 * @returns {Promise<{dataUrl: string, byteAsli: number, byteAkhir: number, dikompres: boolean}>}
 */
async function kompresGambar(file, opsi = {}) {
  const maxSisi = opsi.maxSisi ?? 1600;
  const kualitas = opsi.kualitas ?? 0.85;

  if (!file || !file.type || !file.type.startsWith('image/') || file.size <= GAMBAR_LEWATI_DI_BAWAH) {
    const dataUrl = await _gambarKeDataUrlAsli(file);
    return { dataUrl, byteAsli: file.size, byteAkhir: file.size, dikompres: false };
  }

  try {
    const img = await _muatGambar(file);
    const sisi = Math.max(img.naturalWidth, img.naturalHeight);
    const skala = Math.min(1, maxSisi / sisi);   // tidak pernah MEMPERBESAR gambar kecil
    const w = Math.max(1, Math.round(img.naturalWidth * skala));
    const h = Math.max(1, Math.round(img.naturalHeight * skala));

    const kanvas = document.createElement('canvas');
    kanvas.width = w;
    kanvas.height = h;
    const ctx = kanvas.getContext('2d');
    // Dasar putih DULU: kanvas kosong bawaan peramban transparan-HITAM, jadi
    // gambar PNG beralfa yang diekspor sebagai JPEG (tanpa kanal alfa) akan
    // menampilkan bagian transparannya sebagai hitam kalau ini dilewatkan.
    ctx.fillStyle = '#fff';
    ctx.fillRect(0, 0, w, h);
    ctx.drawImage(img, 0, 0, w, h);
    URL.revokeObjectURL(img.src);

    const blob = await _kanvasKeBlob(kanvas, 'image/jpeg', kualitas);
    // Kompresi yang hasilnya malah lebih besar (jarang, tapi bisa terjadi
    // pada gambar yang sudah dikompres berat sebelumnya) dibuang — dipakai
    // yang lebih kecil di antara keduanya, bukan dipaksakan.
    if (!blob || blob.size >= file.size) {
      const dataUrl = await _gambarKeDataUrlAsli(file);
      return { dataUrl, byteAsli: file.size, byteAkhir: file.size, dikompres: false };
    }

    const dataUrl = await _blobKeDataUrl(blob);
    return { dataUrl, byteAsli: file.size, byteAkhir: blob.size, dikompres: true };
  } catch (e) {
    console.warn('kompresGambar: dibiarkan apa adanya —', e);
    const dataUrl = await _gambarKeDataUrlAsli(file);
    return { dataUrl, byteAsli: file.size, byteAkhir: file.size, dikompres: false };
  }
}
