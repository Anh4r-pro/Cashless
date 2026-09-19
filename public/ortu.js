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
function tampilLogin(pesan) {
  $('#boot').classList.add('hidden');
  $('#ortu-app').classList.add('hidden');
  $('#ortu-login').classList.remove('hidden');
  if (pesan) tampilError(pesan);
  $('#o-nis').focus();
}

function tampilError(pesan) {
  const e = $('#o-err');
  e.textContent = pesan;
  e.classList.remove('hidden');
}

async function masuk() {
  const btn = $('#o-masuk');
  const body = {
    nis: digit($('#o-nis').value),
    lahir: digit($('#o-lahir').value),
    hp4: MINTA_HP ? digit($('#o-hp4').value) : '',
  };
  if (!body.nis || !body.lahir || (MINTA_HP && !body.hp4))
    return tampilError('Semua kolom wajib diisi.');
  $('#o-err').classList.add('hidden');
  btn.disabled = true;
  btn.textContent = 'Memeriksa…';
  try {
    await api('/api/ortu/login', { method: 'POST', body });
    $('#o-lahir').value = ''; $('#o-hp4').value = '';
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
  await muatRingkasan();
  await muatTransaksi();
  await muatPengajuan();
  await siapkanTombolTopup();
}

/**
 * Inisial dulu, foto menyusul kalau benar-benar berhasil dimuat — supaya foto
 * yang hilang/gagal tidak menyisakan kotak abu-abu kosong di layar orang tua.
 */
function pasangAvatar(wadah, s) {
  const ini = String(s.nama || '?').split(/\s+/).slice(0, 2).map((w) => w[0] || '').join('').toUpperCase();
  wadah.innerHTML = `<div class="avatar big">${esc(ini)}</div>`;
  if (!s.foto) return;
  const img = new Image();
  img.onload = () => { img.className = 'avatar big'; img.alt = ''; wadah.replaceChildren(img); };
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
    transfer_keluar: 'Kirim ke teman', transfer_masuk: 'Terima dari teman' };
  $('#o-tx').innerHTML = d.transaksi.map((t) => {
    const masuk = t.jumlah > 0;
    return `<div class="o-tx">
      <div class="o-tx-ikon ${masuk ? 'plus' : 'minus'}">${masuk ? '+' : '−'}</div>
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
}

async function mulaiTopup() {
  try {
    topupInfo = await api('/api/ortu/topup/info');   // muat ulang: saldo & antrean bisa berubah
  } catch (e) {
    return tanganiGagal(e);
  }
  if (!topupInfo.aktif) {
    $('#o-topup').classList.add('hidden');
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
      <b>${esc(m.nama)}</b>
      <span class="muted">${m.kode === 'qris'
        ? 'Scan kode QRIS sekolah dari aplikasi bank atau e-wallet'
        : `Transfer ke rekening ${esc(m.bank)} milik sekolah`}</span>
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

  const rincian = kode === 'qris'
    ? `<img class="o-qris" src="/uploads/${encodeURIComponent(m.gambar)}" alt="Kode QRIS sekolah">
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
    <div class="field"><label>Metode pembayaran</label>
      <input value="${esc(m.nama)}" disabled></div>
    <div class="field"><label>Tanggal pembayaran</label>
      <input id="o-tp-tgl" type="date" value="${esc(topupInfo.hari_ini)}"
        min="${esc(tglMundur(topupInfo.mundur_maks_hari))}" max="${esc(topupInfo.hari_ini)}"></div>
    <div class="field"><label>Nama pengirim <span class="muted">(opsional)</span></label>
      <input id="o-tp-pengirim" maxlength="80" placeholder="nama pemilik rekening pengirim"></div>
    <div class="field"><label>Bukti pembayaran <span class="muted">(opsional)</span></label>
      <input id="o-tp-bukti" type="file" accept="image/*">
      <span class="muted hint">Foto atau tangkapan layar bukti bayar, maksimal 3MB</span></div>
    <p id="o-tp-err" class="o-err hidden"></p>`,
    `<button id="om-batal">Batal</button>
     <button id="om-kembali">‹ Instruksi</button>
     <button id="om-kirim" class="primary">Kirim konfirmasi</button>`);

  $('#om-kembali').onclick = () => langkahInstruksi(m.kode);
  $('#o-tp-bukti').onchange = async (e) => {
    const f = e.target.files[0];
    topupBukti = null;
    if (!f) return;
    if (f.size > 3 * 1024 * 1024) { e.target.value = ''; return toast('Bukti maksimal 3MB', 'err'); }
    try { topupBukti = await fileKeDataUrl(f); }
    catch { e.target.value = ''; toast('Bukti gagal dibaca', 'err'); }
  };
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
      if (!bayar) {
        el.textContent = `Biaya admin ${rp(topupInfo.biaya_admin)} per pengajuan.`;
        return;
      }
      const masuk = Math.max(0, bayar - topupInfo.biaya_admin);
      el.innerHTML = `Bayar <b>${rp(bayar)}</b> − biaya admin
        <b>${rp(topupInfo.biaya_admin)}</b> → masuk ke kartu <b>${rp(masuk)}</b>`;
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

$('#o-masuk').onclick = masuk;
$('#o-keluar').onclick = keluar;
$('#o-topup').onclick = mulaiTopup;
addEventListener('keydown', (e) => { if (e.key === 'Escape') tutupModal(); });
document.querySelectorAll('#ortu-login input').forEach((i) => {
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
  .then((d) => { pasangKunci(d.minta_hp); return d.authed ? mulaiApp() : tampilLogin(); })
  // Server tak terjangkau: tampilkan form dengan kunci terlengkap yang mungkin,
  // supaya wali tidak tertahan oleh kolom yang hilang saat sambungan pulih.
  .catch(() => { pasangKunci(true); tampilLogin(); });
