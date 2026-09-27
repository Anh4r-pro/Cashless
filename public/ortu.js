'use strict';
/**
 * Portal Orang Tua / Wali Murid — halaman baca-saja di /ortu.
 * Masuk dengan NIS + tanggal lahir (ddmmyy), plus 4 digit terakhir nomor HP wali
 * bila sekolah masih memintanya (pengaturan `ortu_minta_hp` di panel admin).
 * Tidak memakai sesi staf: cookie sendiri (osid) dan hanya route /api/ortu/*.
 */

const $ = (s) => document.querySelector(s);
const rp = (n) => 'Rp' + Number(n || 0).toLocaleString('id-ID');
const esc = (s) => String(s ?? '').replace(/[&<>"']/g, (c) => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
const digit = (s) => String(s || '').replace(/\D/g, '');

const state = { hari: 30, tipe: '', page: 1 };

// Palet grafik disetel ke warna portal (navy & emas redam). VIZ milik
// charts.js dipakai bersama layar admin, jadi hanya DIUBAH DI HALAMAN INI —
// berkas ini tidak pernah dimuat di layar admin.
Object.assign(VIZ, { s1: '#1A2856', s2: '#C9A227', s3: '#0F7A5F',
  grid: '#F0EDE5', axis: '#D9D3C3', ink: '#141B2D', muted: '#6E7489' });
VIZ.kategori = ['#1A2856', '#C9A227', '#0F7A5F', '#5B45B8', '#B4233F', '#26366B'];

/** Ikon garis kecil untuk daftar transaksi — satu jenis, satu bentuk. */
const IKON = {
  beli: '<path d="M4 9.5 5.5 4h13L20 9.5"/><path d="M4 9.5a2.7 2.7 0 0 0 5.3 0 2.7 2.7 0 0 0 5.4 0 2.7 2.7 0 0 0 5.3 0"/><path d="M5 12v8h14v-8"/>',
  masuk: '<path d="M12 5v14M5 12h14"/>',
  keluar: '<path d="M5 12h14"/>',
  donasi: '<path d="M12 20s-7.5-4.5-7.5-10A4.3 4.3 0 0 1 12 7.3 4.3 4.3 0 0 1 19.5 10c0 5.5-7.5 10-7.5 10z"/>',
  kirim: '<path d="M21 3 10 14M21 3l-7 18-4-7-7-4z"/>',
  admin: '<path d="M6 3h12v18l-3-2-3 2-3-2-3 2z"/><path d="M9 8h6M9 12h6"/>',
};
const ikon = (k) => `<svg class="i" viewBox="0 0 24 24">${IKON[k] || IKON.masuk}</svg>`;

// Apakah kunci ketiga (4 digit HP) sedang diminta sekolah. Dijawab server lewat
// /api/ortu/me, bukan ditanam di halaman: sekolah bisa mengubahnya kapan saja
// dan wali tidak perlu menunggu halaman ini dipasang ulang.
let MINTA_HP = false;

function pasangKunci(mintaHp) {
  MINTA_HP = !!mintaHp;
  $('#o-hp4-field').classList.toggle('hidden', !MINTA_HP);
  if (!MINTA_HP) $('#o-hp4').value = '';
}

function toast(msg, kind) {
  const t = $('#toast');
  t.textContent = msg;
  t.className = 'show ' + (kind || '');
  clearTimeout(toast._t);
  toast._t = setTimeout(() => { t.className = ''; }, 3000);
}

async function api(path, opts = {}) {
  const res = await fetch(path, {
    method: opts.method || 'GET',
    headers: opts.body ? { 'Content-Type': 'application/json' } : {},
    body: opts.body ? JSON.stringify(opts.body) : undefined,
  });
  const data = await res.json().catch(() => ({}));
  if (!res.ok) {
    const e = new Error(data.error || 'Gagal memuat data');
    e.status = res.status;
    throw e;
  }
  return data;
}

/**
 * Gangguan jaringan sesaat tidak boleh melempar orang tua keluar dari halaman —
 * hanya 401 (sesi habis) yang mengembalikan ke form masuk.
 */
function tanganiGagal(e) {
  if (e.status === 401) tampilLogin('Sesi Anda berakhir. Silakan masuk lagi.');
  else toast(e.message, 'err');
}

// tanggal WIB yang enak dibaca orang tua: "Sen, 4 Agu · 09.41"
const HARI = ['Min', 'Sen', 'Sel', 'Rab', 'Kam', 'Jum', 'Sab'];
const BULAN = ['Jan','Feb','Mar','Apr','Mei','Jun','Jul','Agu','Sep','Okt','Nov','Des'];
function waktuWib(iso) {
  const d = new Date(new Date(iso).getTime() + 7 * 3600 * 1000);
  return `${HARI[d.getUTCDay()]}, ${d.getUTCDate()} ${BULAN[d.getUTCMonth()]} · ` +
         `${String(d.getUTCHours()).padStart(2, '0')}.${String(d.getUTCMinutes()).padStart(2, '0')}`;
}
function tanggalPendek(ymd) {
  const [y, m, d] = ymd.split('-').map(Number);
  return `${d} ${BULAN[m - 1]}`;
}
function sejakKapan(iso) {
  if (!iso) return 'belum ada transaksi';
  const menit = Math.round((Date.now() - new Date(iso).getTime()) / 60000);
  if (menit < 1) return 'baru saja';
  if (menit < 60) return `${menit} menit lalu`;
  if (menit < 1440) return `${Math.round(menit / 60)} jam lalu`;
  return `${Math.round(menit / 1440)} hari lalu`;
}

// ------------------------------------------------------------------ masuk
// NIS BUKAN kunci rahasia — teman sekelas pun tahu (kunci sebenarnya adalah
// tanggal lahir, kadang + 4 digit HP). Jadi kotak NIS aman diingat otomatis
// di HP itu; yang TIDAK pernah diingat adalah tanggal lahir maupun sesi login.
const NIS_INGAT_KUNCI = 'ortu-nis-terakhir';

function tampilLogin(pesan) {
  $('#boot').classList.add('hidden');
  $('#ortu-app').classList.add('hidden');
  $('#ortu-login').classList.remove('hidden');
  if (pesan) tampilError(pesan);
  // Tidak menimpa isian yang sedang diketik — hanya mengisi kalau kotaknya
  // masih kosong (mis. dipanggil ulang setelah percobaan login gagal).
  if (!$('#o-nis').value) {
    let terakhir = null;
    try { terakhir = localStorage.getItem(NIS_INGAT_KUNCI); } catch { /* abaikan */ }
    if (terakhir) $('#o-nis').value = terakhir;
  }
  $('#o-nis').focus();
}

/** Keterangan jujur di footer: mengikuti sesi yang SEDANG berjalan, bukan
 *  pilihan kotak centang di layar masuk (yang sudah hilang begitu masuk). */
function gambarFooterSesi(ingat) {
  const el = $('#o-footer-sesi');
  if (!el) return;
  el.textContent = ingat
    ? 'Tetap masuk di HP ini — otomatis keluar bila tidak dibuka 30 hari, atau setelah 6 bulan.'
    : 'Sesi portal berakhir otomatis setelah 2 jam.';
}

function tampilError(pesan) {
  const e = $('#o-err');
  e.textContent = pesan;
  e.classList.remove('hidden');
}

async function masuk() {
  const btn = $('#o-masuk');
  const ingat = $('#o-ingat').checked;
  const body = {
    nis: digit($('#o-nis').value),
    lahir: digit($('#o-lahir').value),
    hp4: MINTA_HP ? digit($('#o-hp4').value) : '',
    ingat,
  };
  if (!body.nis || !body.lahir || (MINTA_HP && !body.hp4))
    return tampilError('Semua kolom wajib diisi.');
  $('#o-err').classList.add('hidden');
  btn.disabled = true;
  btn.textContent = 'Memeriksa…';
  try {
    await api('/api/ortu/login', { method: 'POST', body });
    // Kegagalan menyimpan (mis. mode penyamaran) tidak boleh dianggap login-nya
    // gagal — wali sudah berhasil masuk, ini cuma kemudahan tambahan.
    try { localStorage.setItem(NIS_INGAT_KUNCI, body.nis); } catch { /* abaikan */ }
    $('#o-lahir').value = ''; $('#o-hp4').value = ''; $('#o-ingat').checked = false;
    gambarFooterSesi(ingat);
    await mulaiApp();
  } catch (e) {
    tampilError(e.message);
  } finally {
    btn.disabled = false;
    btn.textContent = 'Lihat Data Anak';
  }
}

async function keluar() {
  await api('/api/ortu/logout', { method: 'POST' }).catch(() => {});
  location.reload();
}

// ------------------------------------------------------------------ isi
async function mulaiApp() {
  $('#boot').classList.add('hidden');
  $('#ortu-login').classList.add('hidden');
  $('#ortu-app').classList.remove('hidden');
  /*
   * Empat permintaan ini tidak saling bergantung, jadi dikirim BERSAMAAN.
   * Dulu berurutan: di jaringan seluler tiap permintaan menunggu yang
   * sebelumnya selesai, dan log server mencatat jeda 5, 4, lalu 16 detik
   * sebelum halaman lengkap. Sekarang lamanya = permintaan terlama saja.
   * Masing-masing menangani gagalnya sendiri, jadi satu yang gagal tidak
   * menahan tiga lainnya tampil. Selama menunggu, kerangka (skeleton) di
   * ortu.html yang terlihat — bukan layar kosong.
   */
  await Promise.all([muatRingkasan(), muatTransaksi(), muatPengajuan(), siapkanTombolTopup()]);
}

/**
 * Inisial dulu, foto menyusul kalau benar-benar berhasil dimuat — supaya foto
 * yang hilang/gagal tidak menyisakan kotak abu-abu kosong di layar orang tua.
 */
function pasangAvatar(wadah, s) {
  const ini = String(s.nama || '?').split(/\s+/).slice(0, 2).map((w) => w[0] || '').join('').toUpperCase();
  wadah.innerHTML = `<div class="avatar">${esc(ini)}</div>`;
  if (!s.foto) return;
  const img = new Image();
  img.onload = () => { img.className = 'avatar'; img.alt = ''; wadah.replaceChildren(img); };
  img.src = '/uploads/' + encodeURIComponent(s.foto);
}

async function muatRingkasan() {
  let d;
  try {
    d = await api('/api/ortu/ringkasan?hari=' + state.hari);
  } catch (e) {
    return tanganiGagal(e);
  }
  const s = d.siswa;
  $('#o-hd-nama').textContent = s.nama;
  pasangAvatar($('#o-avatar'), s);
  $('#o-nama').textContent = s.nama;
  $('#o-meta').innerHTML = `Kelas ${esc(s.kelas || '—')} · NIS ${esc(s.nis || '—')}` +
    (s.status === 'blokir' ? ' · <span class="badge blokir">kartu diblokir</span>' : '') +
    (s.punya_kartu ? '' : ' · <span class="muted">kartu belum dihubungkan</span>');
  $('#o-hp').textContent = 'HP wali terdaftar: ' + s.hp_ortu_samar;
  $('#o-hp').classList.toggle('hidden', !MINTA_HP && s.hp_ortu_samar === '—');
  $('#o-saldo').textContent = rp(s.saldo);
  // Saldo titipan: uang yang sudah diterima sekolah tetapi sengaja belum ditaruh
  // di kartu. Ditampilkan agar wali yang menyetor sekaligus banyak bisa melihat
  // sisanya masih tercatat — bukan hilang, dan bukan pula bisa dibelanjakan.
  const titipan = $('#o-titipan');
  titipan.classList.toggle('hidden', !(s.saldo_titipan > 0));
  if (s.saldo_titipan > 0)
    titipan.textContent = `+ ${rp(s.saldo_titipan)} saldo titipan — otomatis mengisi kartu saat saldo menipis`;
  $('#o-terakhir').textContent = 'Transaksi terakhir: ' + sejakKapan(d.terakhir);

  const kartu = [['Jajan hari ini', rp(d.belanja_hari_ini), `${d.n_hari_ini}× transaksi`]];
  // Saat rentang yang dipilih memang 7 hari, kartu "7 hari terakhir" akan sama
  // persis dengan kartu rentang — tampilkan satu saja.
  if (d.hari !== 7) kartu.push(['7 hari terakhir', rp(d.belanja_7hari), '']);
  kartu.push(
    [`${d.hari} hari terakhir`, rp(d.belanja_periode), `${d.n_periode}× transaksi`],
    ['Rata-rata per hari jajan', rp(d.rata2_per_hari_aktif), `${d.hari_jajan} hari ada jajan`],
    [`Saldo masuk (${d.hari} hari)`, rp(d.topup_periode), ''],
  );
  $('#o-stat').innerHTML = kartu.map(([judul, nilai, sub]) => `<div class="statcard">
      <div class="muted">${esc(judul)}</div><b>${esc(nilai)}</b>
      ${sub ? `<div class="muted">${esc(sub)}</div>` : ''}
    </div>`).join('');

  vizBar($('#o-grafik'), {
    data: d.harian.map((h) => ({
      label: tanggalPendek(h.tgl),
      nilai: h.belanja,
      tooltipLabel: tanggalPendek(h.tgl),
      tooltipExtra: h.n ? `${h.n}× transaksi` : 'tidak jajan',
    })),
    namaDeret: 'Jajan',
    fmtY: (n) => 'Rp' + (n >= 1000 ? Math.round(n / 1000) + 'rb' : n),
    fmtTip: rp,
    tinggi: 220,
  });

  const totalTenant = d.per_tenant.reduce((a, t) => a + t.total, 0) || 1;
  vizHBar($('#o-tenant'), {
    data: d.per_tenant.map((t) => ({
      label: esc(t.nama), nilai: t.total,
      sub: `${t.n}× · ${Math.round((t.total / totalTenant) * 100)}% dari total jajan`,
    })),
    warnaPer: true,
  });

  $('#o-menu').innerHTML = d.menu_favorit.length
    ? `<div class="o-chips">${d.menu_favorit.map((m) =>
        `<span class="o-chip">${esc(m.nama)}<b>${m.n}×</b></span>`).join('')}</div>`
    : '<p class="muted">Belum ada pembelian pada rentang ini.</p>';
}

async function muatTransaksi() {
  const p = new URLSearchParams({ page: state.page, limit: 20 });
  if (state.tipe) p.set('tipe', state.tipe);
  let d;
  try {
    d = await api('/api/ortu/transaksi?' + p);
  } catch (e) {
    return tanganiGagal(e);
  }
  $('#o-tx-ringkas').textContent =
    `${d.total.toLocaleString('id-ID')} transaksi · masuk ${rp(d.total_masuk)} · keluar ${rp(d.total_keluar)}`;

  const LABEL = { beli: 'Jajan', topup: 'Isi saldo', koreksi: 'Penyesuaian', donasi: 'Donasi',
    transfer_keluar: 'Kirim ke teman', transfer_masuk: 'Terima dari teman', biaya_admin: 'Biaya admin' };
  // [kelas warna, ikon] per jenis. Warna sendiri tidak pernah jadi satu-satunya
  // penanda: tanda +/− dan labelnya tetap tertulis di sebelahnya.
  const RUPA = { beli: ['beli', 'beli'], donasi: ['donasi', 'donasi'], transfer_keluar: ['keluar', 'kirim'],
    transfer_masuk: ['masuk', 'kirim'], biaya_admin: ['keluar', 'admin'] };
  $('#o-tx').innerHTML = d.transaksi.map((t) => {
    const masuk = t.jumlah > 0;
    const [kelas, bentuk] = RUPA[t.tipe] || (masuk ? ['masuk', 'masuk'] : ['keluar', 'keluar']);
    return `<div class="o-tx">
      <div class="o-tx-ikon ${kelas}">${ikon(bentuk)}</div>
      <div class="o-tx-isi">
        <b>${esc(LABEL[t.tipe] || t.tipe)}${t.tenant_nama ? ' · ' + esc(t.tenant_nama) : ''}</b>
        <span class="muted">${esc(waktuWib(t.created_at))}</span>
        ${t.keterangan ? `<span class="o-tx-ket">${esc(t.keterangan)}</span>` : ''}
      </div>
      <div class="o-tx-nilai">
        <b class="${masuk ? 'plus' : 'minus'}">${masuk ? '+' : '−'}${rp(Math.abs(t.jumlah))}</b>
        <span class="muted">sisa ${rp(t.saldo_sesudah)}</span>
      </div>
    </div>`;
  }).join('') || '<p class="muted">Belum ada transaksi.</p>';

  const maxPage = Math.max(1, Math.ceil(d.total / d.limit));
  $('#o-tx-info').textContent = `hal ${d.page} / ${maxPage}`;
  $('#o-tx-prev').disabled = d.page <= 1;
  $('#o-tx-next').disabled = d.page >= maxPage;
}

// ------------------------------------------------------------ isi saldo
/**
 * Pengajuan isi saldo — tiga langkah dalam satu modal:
 *   1. pilih metode (QRIS statis / transfer bank)
 *   2. baca instruksi, bayar sendiri di luar sistem
 *   3. isi konfirmasi (nominal, tanggal, opsional pengirim & bukti)
 *
 * Halaman ini tidak pernah menambah saldo. Yang terkirim hanyalah klaim yang
 * menunggu diperiksa admin — karena itu seluruh teks di sini sengaja memakai
 * kata "menunggu/diperiksa", bukan "berhasil ditambahkan".
 */
let topupInfo = null;       // hasil /api/ortu/topup/info
let topupPilihan = null;    // metode yang sedang dipilih
let topupBukti = null;      // data URL bukti bayar (opsional)

function bukaModal(isi, tombol) {
  const root = $('#o-modal-root');
  root.innerHTML = `<div class="modal-bg"><div class="modal">${isi}
    <div class="actions">${tombol}</div></div></div>`;
  root.querySelector('.modal-bg').addEventListener('click', (e) => {
    if (e.target.classList.contains('modal-bg')) tutupModal();
  });
  const batal = $('#om-batal');
  if (batal) batal.onclick = tutupModal;
}
function tutupModal() { $('#o-modal-root').innerHTML = ''; }

function fileKeDataUrl(file) {
  return new Promise((resolve, reject) => {
    const r = new FileReader();
    r.onload = () => resolve(r.result);
    r.onerror = reject;
    r.readAsDataURL(file);
  });
}

/** Tanggal WIB n hari ke belakang, format YYYY-MM-DD untuk atribut min/max. */
function tglMundur(n) {
  return new Date(Date.now() + 7 * 3600 * 1000 - n * 86400000).toISOString().slice(0, 10);
}

/**
 * Tombol "Isi Saldo" hanya muncul kalau layanannya memang bisa dipakai —
 * lebih jujur daripada tombol yang selalu ada tapi selalu menolak.
 */
async function siapkanTombolTopup() {
  try {
    topupInfo = await api('/api/ortu/topup/info');
  } catch {
    return;                                   // gagal memuat → tombol tetap tersembunyi
  }
  $('#o-topup').classList.toggle('hidden', !topupInfo.aktif);
  $('#o-tab-topup').classList.toggle('hidden', !topupInfo.aktif);
}

async function mulaiTopup() {
  try {
    topupInfo = await api('/api/ortu/topup/info');   // muat ulang: saldo & antrean bisa berubah
  } catch (e) {
    return tanganiGagal(e);
  }
  if (!topupInfo.aktif) {
    $('#o-topup').classList.add('hidden');
    $('#o-tab-topup').classList.add('hidden');
    return toast(topupInfo.ditutup
      ? 'Pengajuan isi saldo sedang ditutup sekolah.'
      : 'Sekolah belum mengisi data pembayaran. Hubungi admin kantin.', 'err');
  }
  if (topupInfo.antre_n >= topupInfo.antre_maks)
    return toast(`Masih ada ${topupInfo.antre_n} pengajuan menunggu diperiksa admin.`, 'err');
  langkahMetode();
}

function langkahMetode() {
  topupBukti = null;
  const pilihan = topupInfo.metode.map((m) => `
    <button class="o-metode" data-kode="${esc(m.kode)}">
      <span class="o-metode-lg">${m.kode === 'qris' ? 'QRIS' : 'BANK'}</span>
      <span><b>${esc(m.nama)}</b>
      <span class="muted">${m.kode === 'qris'
        ? 'Scan kode QRIS sekolah dari aplikasi bank atau e-wallet'
        : `Transfer ke rekening ${esc(m.bank)} milik sekolah`}</span></span>
    </button>`).join('');

  bukaModal(`
    <h2>Isi Saldo</h2>
    <p class="muted">Pembayaran dilakukan langsung ke rekening sekolah, lalu Anda mengisi
      formulir konfirmasi di langkah berikutnya. Saldo bertambah setelah admin
      memeriksa dan menyetujui.</p>
    <div class="o-metode-grid">${pilihan}</div>`,
    '<button id="om-batal">Tutup</button>');

  document.querySelectorAll('.o-metode').forEach((b) => {
    b.onclick = () => langkahInstruksi(b.dataset.kode);
  });
}

function langkahInstruksi(kode) {
  const m = topupInfo.metode.find((x) => x.kode === kode);
  if (!m) return langkahMetode();
  topupPilihan = m;

  /*
   * QRIS bisa diunduh, dan itu bukan kemudahan tambahan melainkan keharusan:
   * wali membuka portal ini DI PONSEL yang sama dengan aplikasi banknya, dan
   * satu ponsel tidak bisa memindai layarnya sendiri. Tanpa tombol ini,
   * satu-satunya jalan adalah meminjam ponsel kedua.
   */
  const rincian = kode === 'qris'
    ? `<img class="o-qris" src="/uploads/${encodeURIComponent(m.gambar)}" alt="Kode QRIS sekolah">
       <p class="o-qris-unduh">
         <a class="linkbtn" id="o-qris-unduh"
            href="/uploads/${encodeURIComponent(m.gambar)}?unduh=1"
            download>⬇ Simpan gambar QRIS ke ponsel</a>
         <span class="muted hint">Simpan dulu, lalu buka aplikasi bank/e-wallet Anda dan
           pilih <b>scan dari galeri</b>.</span></p>
       ${m.penerima ? `<p class="o-bayar-baris"><span class="muted">Penerima</span><b>${esc(m.penerima)}</b></p>` : ''}`
    : `<p class="o-bayar-baris"><span class="muted">Bank</span><b>${esc(m.bank)}</b></p>
       <p class="o-bayar-baris"><span class="muted">Nomor rekening</span>
         <b>${esc(m.rekening)} <button class="linkbtn" id="o-salin">salin</button></b></p>
       ${m.atasnama ? `<p class="o-bayar-baris"><span class="muted">Atas nama</span><b>${esc(m.atasnama)}</b></p>` : ''}`;

  bukaModal(`
    <h2>${esc(m.nama)}</h2>
    ${rincian}
    ${m.instruksi ? `<p class="muted o-instruksi">${esc(m.instruksi)}</p>` : ''}
    <p class="o-catat">Nominal bebas antara <b>${rp(topupInfo.min)}</b> dan <b>${rp(topupInfo.maks)}</b>.
      Setelah benar-benar membayar, tekan tombol di bawah untuk mengisi konfirmasi.</p>`,
    `<button id="om-batal">Batal</button>
     <button id="om-kembali">‹ Metode lain</button>
     <button id="om-lanjut" class="primary">Saya sudah bayar ›</button>`);

  $('#om-kembali').onclick = langkahMetode;
  $('#om-lanjut').onclick = langkahKonfirmasi;
  const salin = $('#o-salin');
  if (salin) salin.onclick = async () => {
    try {
      await navigator.clipboard.writeText(m.rekening);
      toast('Nomor rekening disalin', 'ok');
    } catch {
      toast('Salin manual: ' + m.rekening);
    }
  };
}

function langkahKonfirmasi() {
  const m = topupPilihan;
  bukaModal(`
    <h2>Konfirmasi Pembayaran</h2>
    <p class="muted">Isi sesuai yang benar-benar Anda bayarkan. Admin mencocokkannya dengan
      mutasi rekening sekolah sebelum menyetujui.</p>
    <div class="field"><label>Nominal yang dibayar</label>
      ${inputUang({ id: 'o-tp-jumlah', placeholder: 'contoh: 50.000' })}
      <span class="muted hint">Antara ${rp(topupInfo.min)} dan ${rp(topupInfo.maks)}</span></div>
    ${topupInfo.biaya_admin > 0 ? '<div class="o-rincian" id="o-tp-rincian"></div>' : ''}
    <div class="field"><label>Metode pembayaran</label>
      <input value="${esc(m.nama)}" disabled></div>
    <div class="field"><label>Tanggal pembayaran</label>
      <input id="o-tp-tgl" type="date" value="${esc(topupInfo.hari_ini)}"
        min="${esc(tglMundur(topupInfo.mundur_maks_hari))}" max="${esc(topupInfo.hari_ini)}"></div>
    <div class="field"><label>Nama pengirim <span class="o-wajib">wajib</span></label>
      <input id="o-tp-pengirim" maxlength="80" placeholder="nama pemilik rekening pengirim">
      <span class="muted hint">Sesuai nama di rekening/aplikasi yang Anda pakai membayar —
        ini yang dicari admin di mutasi rekening sekolah.</span></div>
    <div class="field"><label>Bukti pembayaran <span class="o-wajib">wajib</span></label>
      <input id="o-tp-bukti" type="file" accept="image/*">
      <span class="muted hint" id="o-tp-bukti-hint">Foto atau tangkapan layar bukti bayar,
        maksimal 3MB. Pastikan gambarnya sudah selesai terunduh di ponsel Anda.</span></div>
    <p id="o-tp-err" class="o-err hidden"></p>`,
    `<button id="om-batal">Batal</button>
     <button id="om-kembali">‹ Instruksi</button>
     <button id="om-kirim" class="primary">Kirim konfirmasi</button>`);

  $('#om-kembali').onclick = () => langkahInstruksi(m.kode);
  const hintBukti = $('#o-tp-bukti-hint');
  const bakuHint = hintBukti.textContent;
  $('#o-tp-bukti').onchange = async (e) => {
    const f = e.target.files[0];
    topupBukti = null;
    segarkanTombolKirim();
    if (!f) { hintBukti.textContent = bakuHint; return; }
    if (f.size > 3 * 1024 * 1024) {
      e.target.value = ''; hintBukti.textContent = bakuHint;
      return toast('Bukti maksimal 3MB', 'err');
    }
    /*
     * Berkas yang terlalu kecil hampir pasti belum selesai terunduh — kejadian
     * biasa saat gambar dipilih dari galeri di sinyal yang seadanya. Ditahan di
     * sini selagi walinya masih di depan layar, bukan dibiarkan lolos lalu
     * ketahuan berhari-hari kemudian ketika admin tidak bisa membuka gambarnya.
     */
    if (f.size < 512) {
      e.target.value = ''; hintBukti.textContent = bakuHint;
      return toast('Gambar terbaca tidak lengkap. Tunggu unduhannya selesai, lalu pilih lagi.', 'err');
    }
    try {
      topupBukti = await fileKeDataUrl(f);
      hintBukti.textContent = `✔ ${f.name} (${Math.round(f.size / 1024)} KB) siap dikirim`;
    } catch {
      e.target.value = ''; hintBukti.textContent = bakuHint;
      toast('Bukti gagal dibaca', 'err');
    }
    segarkanTombolKirim();
  };

  /*
   * Tombol kirim baru hidup kalau keduanya sudah lengkap. Menolak SESUDAH
   * ditekan juga benar dan tetap dilakukan server, tapi tombol yang mati lebih
   * jujur: wali tahu apa yang kurang sebelum mengira pengajuannya sudah masuk.
   */
  function segarkanTombolKirim() {
    const btn = $('#om-kirim');
    if (!btn) return;
    const adaPengirim = ($('#o-tp-pengirim')?.value || '').trim().length >= 3;
    btn.disabled = !(adaPengirim && topupBukti);
    btn.title = btn.disabled ? 'Lengkapi nama pengirim dan bukti pembayaran dulu' : '';
  }
  $('#o-tp-pengirim').addEventListener('input', segarkanTombolKirim);
  segarkanTombolKirim();

  $('#om-kirim').onclick = kirimTopup;

  /*
   * Rincian biaya dihitung ulang setiap kali nominalnya diketik, bukan ditulis
   * sekali sebagai catatan kaki. Wali perlu melihat angka untuk nominal YANG
   * IA PILIH — "biaya Rp2.500" mudah terlewat, "masuk ke kartu Rp47.500"
   * tidak. Menemukan potongan itu baru setelah saldo anaknya ternyata kurang
   * adalah cara tercepat membuat orang berhenti percaya pada sistem pembayaran
   * sekolahnya.
   */
  if (topupInfo.biaya_admin > 0) {
    const gambarRincian = () => {
      const el = $('#o-tp-rincian');
      if (!el) return;
      const bayar = nilaiUang('#o-tp-jumlah') ?? 0;
      const masuk = Math.max(0, bayar - topupInfo.biaya_admin);
      el.innerHTML = `
        <div><span>Nominal dibayar</span><b>${bayar ? rp(bayar) : '—'}</b></div>
        <div><span>Biaya admin</span><span>${rp(topupInfo.biaya_admin)}</span></div>
        <div><span>Masuk ke kartu</span><b class="plus">${bayar ? rp(masuk) : '—'}</b></div>`;
    };
    $('#o-tp-jumlah').addEventListener('input', gambarRincian);
    gambarRincian();
  }

  $('#o-tp-jumlah').focus();
}

async function kirimTopup() {
  const btn = $('#om-kirim');
  const kotak = $('#o-tp-err');
  const tunjukkan = (pesan) => { kotak.textContent = pesan; kotak.classList.remove('hidden'); };

  const jumlah = nilaiUang('#o-tp-jumlah') ?? 0;
  const tgl = $('#o-tp-tgl').value;
  if (!jumlah) return tunjukkan('Nominal belum diisi.');
  if (jumlah < topupInfo.min) return tunjukkan(`Nominal minimal ${rp(topupInfo.min)}.`);
  if (jumlah > topupInfo.maks) return tunjukkan(`Nominal maksimal ${rp(topupInfo.maks)}.`);
  if (!tgl) return tunjukkan('Tanggal pembayaran belum diisi.');

  kotak.classList.add('hidden');
  btn.disabled = true;
  btn.textContent = 'Mengirim…';
  try {
    await api('/api/ortu/topup', { method: 'POST', body: {
      jumlah, metode: topupPilihan.kode, tgl_bayar: tgl,
      pengirim: $('#o-tp-pengirim').value,
      bukti: topupBukti || undefined,
    } });
    tutupModal();
    toast('Konfirmasi terkirim — menunggu diperiksa admin', 'ok');
    await muatPengajuan();
  } catch (e) {
    if (e.status === 401) { tutupModal(); return tanganiGagal(e); }
    tunjukkan(e.message);
    btn.disabled = false;
    btn.textContent = 'Kirim konfirmasi';
  }
}

const TP_STATUS = { pending: 'Menunggu diperiksa', disetujui: 'Disetujui', ditolak: 'Ditolak' };
const TP_METODE = { qris: 'QRIS', transfer: 'Transfer bank' };

async function muatPengajuan() {
  let d;
  try {
    d = await api('/api/ortu/topup');
  } catch (e) {
    return tanganiGagal(e);
  }
  const panel = $('#o-tp-panel');
  panel.classList.toggle('hidden', d.pengajuan.length === 0);
  if (!d.pengajuan.length) return;

  $('#o-tp-ringkas').textContent = d.menunggu
    ? `${d.menunggu} menunggu diperiksa admin`
    : 'tidak ada yang menunggu';

  $('#o-tp-list').innerHTML = d.pengajuan.map((p) => `
    <div class="o-tp">
      <div class="o-tp-isi">
        <b>${rp(p.jumlah)} · ${esc(TP_METODE[p.metode] || p.metode)}</b>
        <span class="muted">diajukan ${esc(waktuWib(p.created_at))} ·
          dibayar ${esc(tanggalPendek(p.tgl_bayar))}</span>
        ${p.status === 'ditolak' && p.alasan
          ? `<span class="o-tp-alasan">Alasan ditolak: ${esc(p.alasan)}</span>` : ''}
        ${p.bukti ? `<a class="o-tp-bukti" href="/uploads/${encodeURIComponent(p.bukti)}"
            target="_blank" rel="noopener">lihat bukti yang dikirim</a>` : ''}
      </div>
      <span class="badge tp-${esc(p.status)}">${esc(TP_STATUS[p.status] || p.status)}</span>
    </div>`).join('');
}

// ------------------------------------------------------------------ ikat
function pilihGrup(wadah, tombol, jalankan) {
  wadah.querySelectorAll('button').forEach((b) => b.classList.toggle('sel', b === tombol));
  jalankan();
}

/*
 * Aksi cepat dan menu bawah hanya MENGGULIR ke bagian yang sudah ada di
 * halaman — tidak ada layar kedua yang harus dimuat. Sorotan menu bawah
 * mengikuti bagian yang sedang terlihat, supaya wali tahu posisinya.
 */
document.querySelectorAll('[data-ke]').forEach((b) => {
  b.onclick = () => {
    const tujuan = document.getElementById(b.dataset.ke);
    if (tujuan) tujuan.scrollIntoView({ block: 'start' });
  };
});
$('#o-tab-topup').onclick = () => mulaiTopup();
if ('IntersectionObserver' in window) {
  const tab = [...document.querySelectorAll('.o-tabbar [data-ke]')];
  const tampak = new Map();
  const io = new IntersectionObserver((isi) => {
    isi.forEach((x) => tampak.set(x.target.id, x.isIntersecting));
    const aktif = tab.map((b) => b.dataset.ke).reverse().find((id) => tampak.get(id)) || 'o-atas';
    tab.forEach((b) => b.classList.toggle('on', b.dataset.ke === aktif));
  }, { rootMargin: '-35% 0px -55% 0px' });
  tab.forEach((b) => { const t = document.getElementById(b.dataset.ke); if (t) io.observe(t); });
}

$('#o-masuk').onclick = masuk;
$('#o-keluar').onclick = keluar;
$('#o-topup').onclick = mulaiTopup;
addEventListener('keydown', (e) => { if (e.key === 'Escape') tutupModal(); });
// :not([type=checkbox]) — "tetap masuk" bukan kolom angka, dan nilai bawaan
// checkbox ("on") tidak boleh ikut dilucuti seolah-olah isian NIS/tanggal.
document.querySelectorAll('#ortu-login input:not([type=checkbox])').forEach((i) => {
  i.addEventListener('keydown', (e) => { if (e.key === 'Enter') masuk(); });
  // kolom angka: buang karakter non-digit sambil diketik
  i.addEventListener('input', () => { i.value = digit(i.value); });
});

$('#o-rentang').onclick = (e) => {
  const b = e.target.closest('button');
  if (!b) return;
  state.hari = Number(b.dataset.hari);
  pilihGrup($('#o-rentang'), b, muatRingkasan);
};

$('#o-tipe').onclick = (e) => {
  const b = e.target.closest('button');
  if (!b) return;
  state.tipe = b.dataset.tipe;
  state.page = 1;
  pilihGrup($('#o-tipe'), b, muatTransaksi);
};

$('#o-tx-prev').onclick = () => { if (state.page > 1) { state.page--; muatTransaksi(); } };
$('#o-tx-next').onclick = () => { state.page++; muatTransaksi(); };

// sesi masih hidup? langsung tampilkan data, jangan kedipkan form masuk
api('/api/ortu/me')
  .then((d) => {
    pasangKunci(d.minta_hp);
    if (!d.authed) return tampilLogin();
    // Sesi lama (bukan login barusan) — footer perlu tahu dari server apakah
    // "tetap masuk"-nya sedang aktif, karena kotak centangnya sendiri sudah
    // lama tidak ada di layar.
    gambarFooterSesi(d.ingat);
    return mulaiApp();
  })
  // Server tak terjangkau: tampilkan form dengan kunci terlengkap yang mungkin,
  // supaya wali tidak tertahan oleh kolom yang hilang saat sambungan pulih.
  .catch(() => { pasangKunci(true); tampilLogin(); });
