let token = localStorage.getItem('yemekhane_token') || '';
let aktifKullanici = null;
let sabitVeriler = { yemekhaneler: [], ogunler: [], yemekler: [], alerjenler: [] };
let benimAlerjenlerim = [];

const bugunStr = () => new Date().toISOString().slice(0, 10);

function mesajGoster(metin, hataMi = false) {
  const kutu = document.getElementById('genel-mesaj');
  kutu.textContent = metin;
  kutu.className = 'bildirim ' + (hataMi ? 'bildirim-hata' : 'bildirim-basari');
  kutu.classList.remove('gizli');
  setTimeout(() => kutu.classList.add('gizli'), 5000);
}

function bakiyeGuncelle(yeniBakiye) {
  const b = Number(yeniBakiye || 0).toFixed(2) + ' TL';
  document.getElementById('ust-bakiye').textContent = b;
  document.getElementById('bakiye-buyuk').textContent = b;
}

async function apiIstek(yol, secenekler = {}) {
  const headers = { 'Content-Type': 'application/json', ...(secenekler.headers || {}) };
  if (token) headers['Authorization'] = 'Bearer ' + token;
  const yanit = await fetch(yol, { ...secenekler, headers });
  const veri = await yanit.json().catch(() => ({}));
  if (!yanit.ok) {
    if (yanit.status === 401) {
      oturumuKapatArayuz();
    }
    throw new Error(veri.hata || 'Islem basarisiz oldu.');
  }
  return veri;
}

function hizliDoldur(eposta) {
  document.getElementById('giris-eposta').value = eposta;
  document.getElementById('giris-sifre').value = 'Yemek2026!';
}

function oturumuKapatArayuz() {
  token = '';
  aktifKullanici = null;
  benimAlerjenlerim = [];
  localStorage.removeItem('yemekhane_token');
  document.getElementById('ekran-giris').classList.remove('gizli');
  document.getElementById('uygulama-alani').classList.add('gizli');
  document.getElementById('ust-kullanici').classList.add('gizli');
}

async function oturumuBaslat(kullanici) {
  aktifKullanici = kullanici;
  document.getElementById('ekran-giris').classList.add('gizli');
  document.getElementById('uygulama-alani').classList.remove('gizli');
  document.getElementById('ust-kullanici').classList.remove('gizli');
  document.getElementById('ust-ad-rol').textContent = `${kullanici.ad} ${kullanici.soyad} (${kullanici.rol})`;
  bakiyeGuncelle(kullanici.bakiye);

  const ynBtn = document.getElementById('sekme-yonetici-btn');
  if (kullanici.rol === 'yonetici') {
    ynBtn.classList.remove('gizli');
  } else {
    ynBtn.classList.add('gizli');
  }

  await sabitleriYukle();
  await alerjenlerimiYukle();
  await gununMenusunuYukle();
}

async function sabitleriYukle() {
  sabitVeriler = await apiIstek('/api/sabitler');
  const yhSecimler = ['gunun-yemekhane', 'haftalik-yemekhane', 'yn-yemekhane'];
  yhSecimler.forEach(id => {
    const el = document.getElementById(id);
    el.innerHTML = sabitVeriler.yemekhaneler.map(y => `<option value="${y.id}">${y.ad} (Kap: ${y.kapasite})</option>`).join('');
  });

  const ogunSecimler = ['gunun-ogun', 'yn-ogun'];
  ogunSecimler.forEach(id => {
    const el = document.getElementById(id);
    el.innerHTML = sabitVeriler.ogunler.map(o => `<option value="${o.id}" ${o.id == 2 ? 'selected' : ''}>${o.ad} (Son: ${o.son_saat.slice(0,5)})</option>`).join('');
  });

  document.getElementById('gunun-tarih').value = bugunStr();
  document.getElementById('yn-tarih').value = bugunStr();

  const ynListe = document.getElementById('yn-yemek-listesi');
  ynListe.innerHTML = sabitVeriler.yemekler.map(y => `
    <label class="yemek-secim-satir">
      <input type="checkbox" name="yn_yemek" value="${y.id}">
      <span><strong>[${y.kategori}]</strong> ${y.ad} (${y.kalori} kcal)</span>
    </label>
  `).join('');
}

async function alerjenlerimiYukle() {
  const veri = await apiIstek('/api/alerjenlerim');
  benimAlerjenlerim = (veri.alerjen_idler || []).map(Number);

  const liste = document.getElementById('profil-alerjen-listesi');
  if (liste && sabitVeriler.alerjenler) {
    liste.innerHTML = sabitVeriler.alerjenler.map(a => `
      <label class="yemek-secim-satir">
        <input type="checkbox" name="profil_alerjen" value="${a.id}" ${benimAlerjenlerim.includes(Number(a.id)) ? 'checked' : ''}>
        <span>${a.ad}</span>
      </label>
    `).join('');
  }
}

function yemekAlerjenUyarisiVarMi(yemek) {
  if (!yemek.alerjen_idler || !benimAlerjenlerim.length) return false;
  return yemek.alerjen_idler.some(id => benimAlerjenlerim.includes(Number(id)));
}

async function gununMenusunuYukle() {
  const yh = document.getElementById('gunun-yemekhane').value || 1;
  const tarih = document.getElementById('gunun-tarih').value || bugunStr();
  const ogun = document.getElementById('gunun-ogun').value || 2;
  const icerik = document.getElementById('gunun-icerik');
  const rozet = document.getElementById('gunun-kaynak-rozet');

  try {
    const veri = await apiIstek(`/api/gunun-menusu?yemekhane_id=${yh}&tarih=${tarih}&ogun_id=${ogun}`);
    rozet.textContent = veri.kaynak === 'redis' ? 'Kaynak: Redis Onbellek' : 'Kaynak: Veritabani (Replica)';
    rozet.className = 'rozet ' + (veri.kaynak === 'redis' ? 'rozet-redis' : 'rozet-db');

    if (!veri.menu) {
      icerik.innerHTML = '<p style="color:var(--metin-soluk);">Secilen tarih ve ogun icin tanimli menu bulunamadi.</p>';
      return;
    }

    const m = veri.menu;
    const fiyat = aktifKullanici?.rol === 'ogrenci' ? m.ogrenci_fiyati : m.personel_fiyati;
    const yarimBilgi = Number(m.ogun_id) !== 1 ? ` / Yarim: ${(Number(fiyat) * 0.6).toFixed(2)} TL` : '';
    const uyariliYemekler = m.yemekler.filter(yemekAlerjenUyarisiVarMi);

    icerik.innerHTML = `
      ${uyariliYemekler.length ? `
        <div class="bildirim bildirim-hata" style="margin-bottom:0.8rem;">
          <strong>Alerjen Uyarisi!</strong> Sectiginiz alerjenleri iceren yemekler var:
          <strong>${uyariliYemekler.map(y => y.ad).join(', ')}</strong>
        </div>
      ` : ''}
      <p><strong>Toplam Kalori:</strong> ${m.toplam_kalori} kcal | <strong>Sizin Ucretiniz:</strong> Tam ${Number(fiyat).toFixed(2)} TL${yarimBilgi} | <strong>Rezervasyon Son:</strong> ${m.rezervasyon_son}</p>
      <div class="tablo-kaydir">
        <table>
          <thead><tr><th>Kategori</th><th>Yemek</th><th>Kalori</th><th>Ozellik / Alerjen</th></tr></thead>
          <tbody>
            ${m.yemekler.map(y => {
              const uyari = yemekAlerjenUyarisiVarMi(y);
              return `
                <tr style="${uyari ? 'background:#fdecea;' : ''}">
                  <td>${y.kategori}</td>
                  <td>
                    <strong>${y.ad}</strong>
                    ${uyari ? '<span class="rozet rozet-iptal" style="margin-left:0.35rem;">ALERJEN UYARISI</span>' : ''}
                  </td>
                  <td>${y.kalori} kcal</td>
                  <td>
                    ${y.vejetaryen ? '<span class="rozet rozet-vej">Vejetaryen</span> ' : ''}
                    ${y.alerjenler ? `<span class="rozet ${uyari ? 'rozet-iptal' : 'rozet-alerjen'}">Alerjen: ${y.alerjenler}</span>` : (!y.vejetaryen ? '-' : '')}
                  </td>
                </tr>
              `;
            }).join('')}
          </tbody>
        </table>
      </div>
    `;
  } catch (e) {
    icerik.innerHTML = `<p class="bildirim bildirim-hata">${e.message}</p>`;
  }
}

async function haftalikMenuyuYukle() {
  const yh = document.getElementById('haftalik-yemekhane').value || 1;
  const liste = document.getElementById('haftalik-liste');
  try {
    const veri = await apiIstek(`/api/haftalik-menu?yemekhane_id=${yh}`);
    if (!veri.menuler.length) {
      liste.innerHTML = '<p>Bu hafta icin planlanmis menu bulunmuyor.</p>';
      return;
    }
    liste.innerHTML = veri.menuler.map(m => {
      const tamFiyat = Number(veri.rol === 'ogrenci' ? m.ogrenci_fiyati : m.personel_fiyati);
      const yarimFiyat = Number((tamFiyat * 0.60).toFixed(2));
      const kahvaltiMi = Number(m.ogun_id) === 1;
      const aktifRez = m.benim_rez_durum === 'aktif' || m.benim_rez_durum === 'kullanildi';
      const pasif = Number(m.sure_doldu) === 1 || Number(m.bos_yer) <= 0 || aktifRez;

      let butonMetni = 'Rezervasyon Yap';
      if (aktifRez) butonMetni = `Rezerve Edildi (${m.benim_rez_durum} - ${m.benim_porsiyon === 'yarim' ? 'Yarim' : 'Tam'})`;
      else if (Number(m.sure_doldu) === 1) butonMetni = 'Sure Doldu';
      else if (Number(m.bos_yer) <= 0) butonMetni = 'Kapasite Dolu';

      return `
        <article class="kart" style="margin:0;display:flex;flex-direction:column;justify-content:space-between;">
          <div>
            <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:0.4rem;">
              <strong>${m.tarih} - ${m.ogun}</strong>
              <span class="rozet rozet-aktif">Bos Yer: ${m.bos_yer}/${m.kapasite}</span>
            </div>
            <p style="font-size:0.88rem;color:var(--metin-soluk);margin-bottom:0.5rem;">
              Tam: <strong>${tamFiyat.toFixed(2)} TL</strong>${!kahvaltiMi ? ` | Yarim (%60): <strong>${yarimFiyat.toFixed(2)} TL</strong>` : ''} | Son Saat: ${m.rezervasyon_son.slice(11,16)}
            </p>
            <ul style="padding-left:1.1rem;margin:0 0 0.75rem;font-size:0.9rem;">
              ${m.yemekler.map(y => {
                const uyari = yemekAlerjenUyarisiVarMi(y);
                return `<li style="${uyari ? 'color:var(--hata);font-weight:700;' : ''}">
                  ${y.ad} (${y.kalori} kcal) ${uyari ? '[ALERJEN!]' : ''}
                </li>`;
              }).join('')}
            </ul>
          </div>
          <div>
            ${!pasif ? `
              <select id="porsiyon-${m.menu_id}" style="margin-bottom:0.5rem;">
                <option value="tam">Tam Porsiyon (${tamFiyat.toFixed(2)} TL)</option>
                ${!kahvaltiMi ? `<option value="yarim">Yarim Porsiyon (%60 - ${yarimFiyat.toFixed(2)} TL)</option>` : ''}
              </select>
            ` : ''}
            <button class="dugme" style="width:100%;" ${pasif ? 'disabled' : ''} onclick="rezervasyonYap(${m.menu_id})">${butonMetni}</button>
          </div>
        </article>
      `;
    }).join('');
  } catch (e) {
    mesajGoster(e.message, true);
  }
}

async function rezervasyonYap(menuId) {
  const porsiyonEl = document.getElementById(`porsiyon-${menuId}`);
  const porsiyon = porsiyonEl ? porsiyonEl.value : 'tam';
  try {
    const sonuc = await apiIstek('/api/rezervasyon', {
      method: 'POST',
      body: JSON.stringify({ menu_id: menuId, porsiyon })
    });
    bakiyeGuncelle(sonuc.bakiye);
    mesajGoster(sonuc.mesaj);
    await haftalikMenuyuYukle();
  } catch (e) {
    mesajGoster(e.message, true);
  }
}

async function rezervasyonlariYukle() {
  try {
    const veri = await apiIstek('/api/rezervasyonlarim');
    const tablo = document.getElementById('rez-tablo');
    tablo.innerHTML = veri.rezervasyonlar.map(r => `
      <tr>
        <td>${r.tarih} - ${r.ogun} (${r.porsiyon === 'yarim' ? 'Yarim' : 'Tam'})</td>
        <td>${r.yemekhane}</td>
        <td>${Number(r.ucret).toFixed(2)} TL</td>
        <td><span class="rozet rozet-${r.durum}">${r.durum.toUpperCase()}</span></td>
        <td>
          ${r.durum === 'aktif' && Number(r.iptal_edilebilir) === 1
            ? `<button class="dugme dugme-hata" style="min-height:34px;padding:0.25rem 0.6rem;" onclick="rezervasyonIptal(${r.rezervasyon_id})">Iptal Et</button>`
            : '-'}
        </td>
      </tr>
    `).join('') || '<tr><td colspan="5">Henuz rezervasyonunuz yok.</td></tr>';

    const kullanilanlar = veri.rezervasyonlar.filter(r => r.durum === 'kullanildi');
    const degListe = document.getElementById('degerlendirme-liste');
    degListe.innerHTML = kullanilanlar.map(r => `
      <div class="kart" style="margin:0;">
        <strong>${r.tarih} - ${r.ogun} (${r.yemekhane})</strong>
        <form onsubmit="degerlendirmeGonder(event, ${r.menu_id})" style="margin-top:0.5rem;">
          <label>Puan (1-5)</label>
          <select id="puan-${r.menu_id}">
            ${[5,4,3,2,1].map(p => `<option value="${p}" ${Number(r.verdigim_puan) === p ? 'selected' : ''}>${p} Yildiz</option>`).join('')}
          </select>
          <label style="margin-top:0.5rem;">Yorumunuz</label>
          <textarea id="yorum-${r.menu_id}" rows="2" placeholder="Yemekler nasildi?">${r.verdigim_yorum || ''}</textarea>
          <button type="submit" class="dugme" style="margin-top:0.5rem;width:100%;">Degerlendirmeyi Kaydet</button>
        </form>
      </div>
    `).join('') || '<p>Henuz turnikeden gecip kullandiginiz bir menu bulunmuyor.</p>';
  } catch (e) {
    mesajGoster(e.message, true);
  }
}

async function rezervasyonIptal(rezId) {
  try {
    const sonuc = await apiIstek('/api/rezervasyon-iptal', {
      method: 'POST',
      body: JSON.stringify({ rezervasyon_id: rezId })
    });
    bakiyeGuncelle(sonuc.bakiye);
    mesajGoster(sonuc.mesaj);
    await rezervasyonlariYukle();
  } catch (e) {
    mesajGoster(e.message, true);
  }
}

async function degerlendirmeGonder(ev, menuId) {
  ev.preventDefault();
  const puan = document.getElementById(`puan-${menuId}`).value;
  const yorum = document.getElementById(`yorum-${menuId}`).value;
  try {
    const sonuc = await apiIstek('/api/degerlendir', {
      method: 'POST',
      body: JSON.stringify({ menu_id: menuId, puan: Number(puan), yorum })
    });
    mesajGoster(sonuc.mesaj);
  } catch (e) {
    mesajGoster(e.message, true);
  }
}

async function bakiyeEkraniniYukle() {
  try {
    const veri = await apiIstek('/api/bakiye');
    bakiyeGuncelle(veri.bakiye);
    document.getElementById('hareket-tablo').innerHTML = veri.hareketler.map(h => `
      <tr>
        <td>${h.olusturma}</td>
        <td>${h.tur.toUpperCase()}</td>
        <td>${h.aciklama || '-'}</td>
        <td style="font-weight:700;color:${Number(h.tutar) >= 0 ? 'var(--basari)' : 'var(--hata)'};">
          ${Number(h.tutar) >= 0 ? '+' : ''}${Number(h.tutar).toFixed(2)} TL
        </td>
      </tr>
    `).join('');
  } catch (e) {
    mesajGoster(e.message, true);
  }
}

async function yoneticiEkraniniYukle() {
  try {
    const veri = await apiIstek('/api/yonetici/menuler');
    document.getElementById('yn-rapor-tablo').innerHTML = veri.menuler.map(m => `
      <tr>
        <td>${m.tarih} - ${m.ogun}</td>
        <td>${m.yemekhane}</td>
        <td>${m.rezerve}/${m.kapasite} (%${m.doluluk_yuzde})</td>
        <td>${m.ogrenci_fiyati} / ${m.personel_fiyati} TL</td>
        <td>${m.ortalama_puan ? `${m.ortalama_puan} (${m.oy_sayisi} oy)` : 'Oy yok'}</td>
      </tr>
    `).join('');
  } catch (e) {
    mesajGoster(e.message, true);
  }
}

document.getElementById('giris-form').addEventListener('submit', async e => {
  e.preventDefault();
  try {
    const sonuc = await apiIstek('/api/giris', {
      method: 'POST',
      body: JSON.stringify({
        eposta: document.getElementById('giris-eposta').value,
        sifre: document.getElementById('giris-sifre').value
      })
    });
    token = sonuc.token;
    localStorage.setItem('yemekhane_token', token);
    await oturumuBaslat(sonuc.kullanici);
  } catch (err) {
    mesajGoster(err.message, true);
  }
});

document.getElementById('cikis-btn').addEventListener('click', async () => {
  await apiIstek('/api/cikis', { method: 'POST' }).catch(() => {});
  oturumuKapatArayuz();
});

document.querySelectorAll('.sekme-menu .sekme-btn').forEach(btn => {
  btn.addEventListener('click', async () => {
    document.querySelectorAll('.sekme-menu .sekme-btn').forEach(b => b.classList.remove('aktif'));
    btn.classList.add('aktif');
    const hedef = btn.dataset.sekme;
    ['gunun', 'haftalik', 'rezervasyonlar', 'bakiye', 'degerlendirme', 'profil', 'yonetici'].forEach(p => {
      document.getElementById('panel-' + p).classList.toggle('gizli', p !== hedef);
    });
    if (hedef === 'gunun') await gununMenusunuYukle();
    if (hedef === 'haftalik') await haftalikMenuyuYukle();
    if (hedef === 'rezervasyonlar' || hedef === 'degerlendirme') await rezervasyonlariYukle();
    if (hedef === 'bakiye') await bakiyeEkraniniYukle();
    if (hedef === 'profil') await alerjenlerimiYukle();
    if (hedef === 'yonetici') await yoneticiEkraniniYukle();
  });
});

['gunun-yemekhane', 'gunun-tarih', 'gunun-ogun'].forEach(id => {
  document.getElementById(id).addEventListener('change', gununMenusunuYukle);
});
document.getElementById('haftalik-yemekhane').addEventListener('change', haftalikMenuyuYukle);

document.getElementById('bakiye-form').addEventListener('submit', async e => {
  e.preventDefault();
  try {
    const sonuc = await apiIstek('/api/bakiye-yukle', {
      method: 'POST',
      body: JSON.stringify({ tutar: Number(document.getElementById('yukle-tutar').value) })
    });
    mesajGoster(sonuc.mesaj);
    await bakiyeEkraniniYukle();
  } catch (err) {
    mesajGoster(err.message, true);
  }
});

document.getElementById('alerjen-form').addEventListener('submit', async e => {
  e.preventDefault();
  const secili = Array.from(document.querySelectorAll('input[name="profil_alerjen"]:checked')).map(cb => Number(cb.value));
  try {
    const sonuc = await apiIstek('/api/alerjenlerim', {
      method: 'POST',
      body: JSON.stringify({ alerjen_idler: secili })
    });
    benimAlerjenlerim = sonuc.alerjen_idler.map(Number);
    mesajGoster(sonuc.mesaj);
  } catch (err) {
    mesajGoster(err.message, true);
  }
});

document.getElementById('yonetici-menu-form').addEventListener('submit', async e => {
  e.preventDefault();
  const seciliYemekler = Array.from(document.querySelectorAll('input[name="yn_yemek"]:checked')).map(cb => Number(cb.value));
  try {
    const sonuc = await apiIstek('/api/yonetici/menu-kaydet', {
      method: 'POST',
      body: JSON.stringify({
        yemekhane_id: Number(document.getElementById('yn-yemekhane').value),
        tarih: document.getElementById('yn-tarih').value,
        ogun_id: Number(document.getElementById('yn-ogun').value),
        ogrenci_fiyati: Number(document.getElementById('yn-ogr-fiyat').value),
        personel_fiyati: Number(document.getElementById('yn-per-fiyat').value),
        yemek_idler: seciliYemekler
      })
    });
    mesajGoster(sonuc.mesaj);
    await yoneticiEkraniniYukle();
  } catch (err) {
    mesajGoster(err.message, true);
  }
});

(async () => {
  if (token) {
    try {
      const veri = await apiIstek('/api/ben');
      await oturumuBaslat(veri.kullanici);
    } catch {
      oturumuKapatArayuz();
    }
  }
})();