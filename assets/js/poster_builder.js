/* Builder d'affiches — planning/admin/poster.php
 * Tout le rendu se fait dans le navigateur :
 *  - aperçu : le même HTML (794×1123 px, soit A4 à 96 dpi) mis à l'échelle
 *  - PDF A4/A3 : impression du même HTML agrandi, via « Enregistrer au format PDF »
 *  - image : html2canvas sur une copie non mise à l'échelle
 * Dépend de qrcode (vendor/qrcode-generator.js) et html2canvas (vendor/html2canvas.min.js).
 */
(function () {
  'use strict';

  var root = document.getElementById('pb');
  if (!root) return;

  var W = 794, H = 1123;
  var FD = "'Sora',system-ui,sans-serif", FB = "'DM Sans',system-ui,sans-serif";
  var INK = '#1a1510', SAND = '#fdf8f0';

  var COLORS = [
    { id: 'orange', name: 'Orange', hex: '#d4732a' },
    { id: 'bleu', name: 'Bleu', hex: '#2a5fa0' },
    { id: 'jaune', name: 'Jaune', hex: '#e8b923' },
    { id: 'turquoise', name: 'Turquoise', hex: '#2d8276' },
    { id: 'rouge', name: 'Rouge', hex: '#c0432a' }
  ];

  /* ───────────── helpers ───────────── */
  function esc(s) {
    return String(s).replace(/[&<>"']/g, function (ch) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[ch];
    });
  }
  function multi(s) { return esc(s).replace(/\r?\n/g, '<br>'); }
  function rgb(hex) { var n = parseInt(hex.slice(1), 16); return [n >> 16 & 255, n >> 8 & 255, n & 255]; }
  function mix(a, b, t) {
    var x = rgb(a), y = rgb(b);
    return '#' + [0, 1, 2].map(function (i) {
      return ('0' + Math.round(x[i] * (1 - t) + y[i] * t).toString(16)).slice(-2);
    }).join('');
  }
  function lum(hex) {
    var c = rgb(hex).map(function (v) { v /= 255; return v <= .03928 ? v / 12.92 : Math.pow((v + .055) / 1.055, 2.4); });
    return .2126 * c[0] + .7152 * c[1] + .0722 * c[2];
  }
  function palette(ac) {
    return {
      ac: ac,
      on: lum(ac) > .35 ? INK : '#ffffff',
      soft: mix(ac, '#ffffff', .86),
      mid: mix(ac, '#ffffff', .55),
      dark: mix(ac, '#000000', .35)
    };
  }
  function titleSize(text, base) {
    var n = text.length;
    return Math.round(base * (n <= 16 ? 1 : n <= 28 ? .84 : n <= 44 ? .68 : .55));
  }
  function cap(s) { return s ? s.charAt(0).toUpperCase() + s.slice(1) : s; }
  function slug(s) {
    return (s || 'affiche').normalize('NFD').replace(/[̀-ͯ]/g, '').toLowerCase()
      .replace(/[^a-z0-9]+/g, '-').replace(/^-+|-+$/g, '').slice(0, 50) || 'affiche';
  }
  function parseDt(s) { return s ? new Date(s.replace(' ', 'T')) : null; }
  function hm(d) {
    var m = d.getMinutes();
    return d.getHours() + 'h' + (m ? ('0' + m).slice(-2) : '');
  }

  /* ───────────── état ───────────── */
  var TPL = {
    collecte: {
      label: 'Collecte', accent: '#d4732a',
      msg: "Venez déposer vos dons pour l'Ukraine : denrées, produits d'hygiène, vêtements chauds.",
      title: 'Collecte de dons'
    },
    vente: {
      label: 'Vente solidaire', accent: '#d4732a',
      msg: "Chaque achat finance l'envoi de matériel et de produits de première nécessité en Ukraine.",
      title: 'Vente solidaire'
    },
    reunion: {
      label: 'Réunion', accent: '#2d8276',
      msg: "Bilan des derniers convois, organisation des prochaines actions. Nouveaux bénévoles bienvenus.",
      title: 'Réunion des bénévoles'
    },
    benevoles: {
      label: 'Bénévoles', accent: '#2d8276',
      msg: "Une heure ou une journée, chaque coup de main compte pour préparer les convois.",
      title: 'Chargement du convoi'
    }
  };
  var ORDER = ['collecte', 'vente', 'reunion', 'benevoles'];

  var S = {
    tpl: 'collecte', accent: TPL.collecte.accent,
    title: '', date: '', time: '', place: '', msg: TPL.collecte.msg,
    qr: true, photo: ''
  };
  var accentTouched = false, msgIsDefault = true, titleIsDefault = true;

  var planUrl = new URL(root.getAttribute('data-plan-url'), location.origin).href;
  var qrCache = {};
  function qrImg() {
    if (!window.qrcode) return '';
    if (!qrCache[planUrl]) {
      var q = window.qrcode(0, 'M');
      q.addData(planUrl);
      q.make();
      qrCache[planUrl] = q.createDataURL(8, 0);
    }
    return qrCache[planUrl];
  }

  /* ───────────── briques de modèles ───────────── */
  function photoSlot(h, radius) {
    if (S.photo) {
      return '<div style="height:' + h + 'px;border-radius:' + radius + 'px;background:#e8d5b7 url(' + S.photo +
        ') center/cover no-repeat;flex-shrink:0"></div>';
    }
    return '<div style="height:' + h + 'px;border-radius:' + radius + 'px;overflow:hidden;display:flex;flex-direction:column;flex-shrink:0">' +
      '<div style="flex:1;background:#2a5fa0"></div><div style="flex:1;background:#e8b923"></div></div>';
  }

  function qrBlock(size, textHtml, textColor) {
    var url = location.host;
    var img = S.qr ? qrImg() : '';
    return (img
      ? '<div style="width:' + size + 'px;height:' + size + 'px;background:#fff;border-radius:8px;padding:8px;box-sizing:border-box;flex-shrink:0">' +
        '<img src="' + img + '" alt="" style="width:100%;height:100%;display:block"></div>'
      : '') +
      '<div style="font-size:20px;line-height:1.4;color:' + textColor + '">' + textHtml.replace('{url}', esc(url)) + '</div>';
  }

  function dots(lines, c, size, color) {
    return lines.filter(Boolean).map(function (l) {
      return '<div style="display:flex;align-items:center;gap:16px;font-family:' + FD + ';font-weight:700;font-size:' + size +
        'px;line-height:1.2;color:' + color + '"><span style="width:14px;height:14px;border-radius:50%;background:' + c.ac +
        ';flex-shrink:0;display:block"></span><span>' + esc(l) + '</span></div>';
    }).join('');
  }

  var wrap = 'width:' + W + 'px;height:' + H + 'px;box-sizing:border-box;overflow:hidden;position:relative;font-family:' + FB + ';';

  var R = {};

  R.collecte = function (c) {
    var ts = titleSize(S.title, 76);
    return '<div style="' + wrap + 'background:' + SAND + ';color:' + INK + ';display:flex;flex-direction:column">' +
      '<div style="background:' + c.ac + ';color:' + c.on + ';padding:34px 56px;display:flex;justify-content:space-between;align-items:center">' +
        '<span style="font-family:' + FD + ';font-weight:800;font-size:22px;letter-spacing:.16em;text-transform:uppercase">Touraine-Ukraine</span>' +
        '<span style="font-size:18px;font-weight:700;letter-spacing:.14em;text-transform:uppercase">Collecte</span></div>' +
      '<div style="padding:52px 56px 0;display:flex;flex-direction:column;gap:30px;flex:1;min-height:0">' +
        '<div style="font-family:' + FD + ';font-weight:800;font-size:' + ts + 'px;line-height:1.04;letter-spacing:-.02em">' + multi(S.title) + '</div>' +
        photoSlot(300, 22) +
        '<div style="display:flex;flex-direction:column;gap:12px">' + dots([S.date, S.time], c, 34, INK) +
          (S.place ? '<div style="display:flex;align-items:center;gap:16px;font-size:30px;font-weight:600;color:#6b5c47"><span style="width:14px;height:14px;border-radius:50%;background:' + c.mid + ';flex-shrink:0;display:block"></span><span>' + esc(S.place) + '</span></div>' : '') +
        '</div>' +
        (S.msg ? '<div style="font-size:24px;line-height:1.45;color:#3d3428">' + multi(S.msg) + '</div>' : '') +
      '</div>' +
      '<div style="padding:26px 56px;border-top:3px solid ' + c.mid + ';display:flex;align-items:center;gap:22px;background:' + c.soft + '">' +
        qrBlock(110, '<b style="font-family:' + FD + ';font-size:22px">Venez nous aider !</b><br>Scannez pour vous inscrire au planning<br><span style="color:#6b5c47">{url}</span>', '#3d3428') +
      '</div></div>';
  };

  R.vente = function (c) {
    var ts = titleSize(S.title, 92);
    return '<div style="' + wrap + 'background:' + INK + ';color:' + SAND + ';display:flex;flex-direction:column;align-items:center;padding:56px">' +
      '<span style="font-family:' + FD + ';font-weight:800;font-size:20px;letter-spacing:.2em;text-transform:uppercase;color:' + c.mid + '">Touraine-Ukraine</span>' +
      '<div style="margin-top:48px;font-size:24px;font-weight:700;letter-spacing:.2em;text-transform:uppercase;color:' + c.ac + '">Vente solidaire</div>' +
      '<div style="margin-top:16px;font-family:' + FD + ';font-weight:800;font-size:' + ts + 'px;line-height:1.02;text-align:center;letter-spacing:-.02em">' + multi(S.title) + '</div>' +
      '<div style="margin-top:40px;width:100%">' + photoSlot(270, 28) + '</div>' +
      '<div style="margin-top:40px;text-align:center;display:flex;flex-direction:column;gap:10px">' +
        (S.date ? '<div style="font-family:' + FD + ';font-size:42px;font-weight:700">' + esc(S.date) + '</div>' : '') +
        (S.time ? '<div style="font-family:' + FD + ';font-size:34px;font-weight:600;color:' + c.ac + '">' + esc(S.time) + '</div>' : '') +
        (S.place ? '<div style="font-size:28px;color:#c8b8a0;font-weight:500">' + esc(S.place) + '</div>' : '') +
      '</div>' +
      (S.msg ? '<div style="margin-top:30px;font-size:24px;line-height:1.45;text-align:center;color:#e8ddd0;max-width:600px">' + multi(S.msg) + '</div>' : '') +
      '<div style="margin-top:auto;display:flex;align-items:center;gap:22px;border:2px solid #3d3428;border-radius:18px;padding:18px 26px">' +
        qrBlock(92, '<b style="font-family:' + FD + ';color:#fff;font-size:21px">Un coup de main ?</b><br>Scannez pour rejoindre l\'équipe<br><span style="color:' + c.mid + '">{url}</span>', '#e8ddd0') +
      '</div></div>';
  };

  R.reunion = function (c) {
    var ts = titleSize(S.title, 76);
    return '<div style="' + wrap + 'background:#fffef9;color:' + INK + ';display:flex">' +
      '<div style="width:96px;background:' + c.ac + ';flex-shrink:0;display:flex;align-items:flex-end;justify-content:center;padding-bottom:48px">' +
        '<span style="writing-mode:vertical-rl;transform:rotate(180deg);font-family:' + FD + ';font-weight:800;font-size:20px;letter-spacing:.2em;text-transform:uppercase;color:' + c.on + '">Touraine-Ukraine</span></div>' +
      '<div style="flex:1;min-width:0;padding:72px 64px 56px;display:flex;flex-direction:column;gap:28px">' +
        '<div style="font-size:22px;font-weight:700;letter-spacing:.2em;text-transform:uppercase;color:' + c.dark + '">Réunion</div>' +
        '<div style="font-family:' + FD + ';font-weight:800;font-size:' + ts + 'px;line-height:1.04;letter-spacing:-.02em">' + multi(S.title) + '</div>' +
        '<div style="height:6px;width:120px;background:' + c.ac + ';border-radius:3px;flex-shrink:0"></div>' +
        '<div style="display:flex;flex-direction:column;gap:18px">' +
          ((S.date || S.time) ? '<div><div style="font-size:15px;font-weight:700;letter-spacing:.14em;text-transform:uppercase;color:#a89880">Quand</div>' +
            (S.date ? '<div style="font-family:' + FD + ';font-size:38px;font-weight:700;margin-top:4px">' + esc(S.date) + '</div>' : '') +
            (S.time ? '<div style="font-family:' + FD + ';font-size:30px;font-weight:600;color:' + c.dark + '">' + esc(S.time) + '</div>' : '') + '</div>' : '') +
          (S.place ? '<div><div style="font-size:15px;font-weight:700;letter-spacing:.14em;text-transform:uppercase;color:#a89880">Où</div><div style="font-size:30px;font-weight:600;margin-top:4px;color:#3d3428">' + esc(S.place) + '</div></div>' : '') +
        '</div>' +
        (S.msg ? '<div style="font-size:24px;line-height:1.5;color:#3d3428">' + multi(S.msg) + '</div>' : '') +
        '<div style="margin-top:auto;display:flex;align-items:center;gap:22px;padding-top:22px;border-top:3px solid #e8ddd0">' +
          qrBlock(100, '<b style="font-family:' + FD + ';font-size:21px">Confirmez votre venue</b><br>Scannez pour vous inscrire<br><span style="color:#a89880">{url}</span>', '#3d3428') +
        '</div>' +
      '</div></div>';
  };

  R.benevoles = function (c) {
    var ts = titleSize(S.title, 96);
    var dark = c.dark;
    return '<div style="' + wrap + 'background:' + c.ac + ';color:' + c.on + ';display:flex;flex-direction:column;padding:60px 60px 0">' +
      '<span style="font-family:' + FD + ';font-weight:800;font-size:20px;letter-spacing:.2em;text-transform:uppercase;opacity:.85">Touraine-Ukraine</span>' +
      '<div style="margin-top:60px;font-size:26px;font-weight:700;letter-spacing:.16em;text-transform:uppercase;opacity:.9">Nous recherchons des bénévoles</div>' +
      '<div style="margin-top:16px;font-family:' + FD + ';font-weight:800;font-size:' + ts + 'px;line-height:1.02;letter-spacing:-.03em">' + multi(S.title) + '</div>' +
      (S.msg ? '<div style="margin-top:30px;font-size:27px;line-height:1.45;max-width:620px;opacity:.95">' + multi(S.msg) + '</div>' : '') +
      '<div style="margin-top:38px;background:#fff;color:' + INK + ';border-radius:22px;padding:30px 36px;display:flex;flex-direction:column;gap:8px">' +
        (S.date ? '<div style="font-family:' + FD + ';font-size:36px;font-weight:700">' + esc(S.date) + '</div>' : '') +
        (S.time ? '<div style="font-family:' + FD + ';font-size:30px;font-weight:600;color:' + (lum(c.ac) > .6 ? INK : c.dark) + '">' + esc(S.time) + '</div>' : '') +
        (S.place ? '<div style="font-size:26px;font-weight:600;color:#6b5c47">' + esc(S.place) + '</div>' : '') +
      '</div>' +
      '<div style="margin-top:auto;margin-left:-60px;margin-right:-60px;background:' + dark + ';color:#fff;padding:28px 60px;display:flex;align-items:center;gap:24px">' +
        qrBlock(112, '<b style="font-family:' + FD + ';font-size:26px">Je m\'inscris en 30 secondes</b><br>Scannez le code pour choisir votre créneau<br><span style="opacity:.8">{url}</span>', '#fff') +
      '</div></div>';
  };

  function posterHtml() {
    return R[S.tpl](palette(S.accent));
  }

  /* ───────────── UI : modèles, couleurs ───────────── */
  var elTpls = document.getElementById('pbTpls');
  var elColors = document.getElementById('pbColors');

  function thumb(id) {
    var a = TPL[id].accent;
    if (id === 'collecte') return '<div style="height:100%;background:#fdf8f0;display:flex;flex-direction:column"><div style="height:12px;background:' + a + '"></div><div style="padding:6px;display:flex;flex-direction:column;gap:4px;flex:1"><div style="height:7px;width:75%;background:#1a1510;border-radius:2px"></div><div style="flex:1;border-radius:4px;overflow:hidden;display:flex;flex-direction:column"><div style="flex:1;background:#2a5fa0"></div><div style="flex:1;background:#e8b923"></div></div></div></div>';
    if (id === 'vente') return '<div style="height:100%;background:#1a1510;display:flex;flex-direction:column;align-items:center;justify-content:center;gap:5px"><div style="height:5px;width:45%;background:' + a + ';border-radius:2px"></div><div style="width:28px;height:28px;border-radius:50%;overflow:hidden;display:flex;flex-direction:column"><div style="flex:1;background:#2a5fa0"></div><div style="flex:1;background:#e8b923"></div></div><div style="height:4px;width:55%;background:#6b5c47;border-radius:2px"></div></div>';
    if (id === 'reunion') return '<div style="height:100%;background:#fffef9;display:flex"><div style="width:14px;background:' + a + '"></div><div style="flex:1;padding:8px;display:flex;flex-direction:column;gap:4px"><div style="height:7px;width:85%;background:#1a1510;border-radius:2px"></div><div style="height:3px;width:30%;background:' + a + '"></div><div style="height:4px;width:60%;background:#c8b8a0;border-radius:2px"></div></div></div>';
    return '<div style="height:100%;background:' + a + ';display:flex;flex-direction:column;justify-content:center;padding:8px;gap:5px"><div style="height:8px;width:80%;background:#fff;border-radius:2px"></div><div style="height:4px;width:55%;background:rgba(255,255,255,.6);border-radius:2px"></div><div style="height:12px;width:90%;background:#fff;border-radius:3px;margin-top:3px"></div></div>';
  }

  ORDER.forEach(function (id) {
    var b = document.createElement('button');
    b.type = 'button'; b.className = 'pb-tpl'; b.setAttribute('role', 'radio'); b.dataset.tpl = id;
    b.innerHTML = '<div class="pb-tpl-thumb">' + thumb(id) + '</div><span class="pb-tpl-name">' + esc(TPL[id].label) + '</span>';
    b.addEventListener('click', function () { setTemplate(id); });
    elTpls.appendChild(b);
  });
  COLORS.forEach(function (col) {
    var b = document.createElement('button');
    b.type = 'button'; b.className = 'pb-color'; b.setAttribute('role', 'radio');
    b.setAttribute('aria-label', col.name); b.title = col.name; b.dataset.hex = col.hex;
    b.style.background = col.hex; b.style.setProperty('--c', col.hex);
    b.addEventListener('click', function () { accentTouched = true; S.accent = col.hex; refresh(); });
    elColors.appendChild(b);
  });

  function setTemplate(id) {
    S.tpl = id;
    if (!accentTouched) S.accent = TPL[id].accent;
    if (msgIsDefault) { S.msg = TPL[id].msg; }
    if (titleIsDefault) { S.title = TPL[id].title; }
    syncInputs();
    refresh();
  }

  /* ───────────── champs ───────────── */
  var f = {
    title: document.getElementById('pbTitle'),
    date: document.getElementById('pbDate'),
    time: document.getElementById('pbTime'),
    place: document.getElementById('pbPlace'),
    msg: document.getElementById('pbMsg'),
    qr: document.getElementById('pbQr')
  };
  function syncInputs() {
    f.title.value = S.title; f.date.value = S.date; f.time.value = S.time;
    f.place.value = S.place; f.msg.value = S.msg; f.qr.checked = S.qr;
  }
  ['title', 'date', 'time', 'place', 'msg'].forEach(function (k) {
    f[k].addEventListener('input', function () {
      S[k] = f[k].value;
      if (k === 'msg') msgIsDefault = false;
      if (k === 'title') titleIsDefault = false;
      refresh();
    });
  });
  f.qr.addEventListener('change', function () { S.qr = f.qr.checked; refresh(); });

  /* photo : lue dans le navigateur, jamais envoyée au serveur */
  var elPhoto = document.getElementById('pbPhoto');
  var elPhotoBtn = document.getElementById('pbPhotoBtn');
  var elPhotoClear = document.getElementById('pbPhotoClear');
  var elPhotoName = document.getElementById('pbPhotoName');
  var photoHint = elPhotoName.textContent;
  elPhotoBtn.addEventListener('click', function () { elPhoto.click(); });
  elPhotoClear.addEventListener('click', function () {
    S.photo = ''; elPhoto.value = ''; elPhotoClear.hidden = true; elPhotoName.textContent = photoHint; refresh();
  });
  elPhoto.addEventListener('change', function () {
    var file = elPhoto.files && elPhoto.files[0];
    if (!file) return;
    var reader = new FileReader();
    reader.onload = function () {
      // Réduit l'image (2000 px max) pour garder un export rapide.
      var img = new Image();
      img.onload = function () {
        var k = Math.min(1, 2000 / Math.max(img.width, img.height));
        var cv = document.createElement('canvas');
        cv.width = Math.round(img.width * k); cv.height = Math.round(img.height * k);
        cv.getContext('2d').drawImage(img, 0, 0, cv.width, cv.height);
        S.photo = cv.toDataURL('image/jpeg', .88);
        elPhotoClear.hidden = false; elPhotoName.textContent = file.name;
        refresh();
      };
      img.src = reader.result;
    };
    reader.readAsDataURL(file);
  });

  /* ───────────── évènements du planning ───────────── */
  var events = [];
  try { events = JSON.parse(document.getElementById('pbEvents').textContent) || []; } catch (e) { events = []; }
  var elEvent = document.getElementById('pbEvent');

  events.forEach(function (ev, i) {
    var d = parseDt(ev.start);
    var o = document.createElement('option');
    o.value = String(i);
    o.textContent = (d ? d.toLocaleDateString('fr-FR', { weekday: 'short', day: 'numeric', month: 'short' }) + ' · ' : '') + ev.title;
    elEvent.appendChild(o);
  });

  function suggestTemplate(title) {
    var t = title.toLowerCase();
    if (/collecte/.test(t)) return 'collecte';
    if (/vente|vide|brocante|marché|marche/.test(t)) return 'vente';
    if (/réunion|reunion|assemblée|assemblee|ag\b/.test(t)) return 'reunion';
    if (/bénévole|benevole|chargement|permanence|tri\b/.test(t)) return 'benevoles';
    return null;
  }

  function applyEvent(ev) {
    var s = parseDt(ev.start), e = parseDt(ev.end);
    S.title = ev.title; titleIsDefault = false;
    S.date = s ? cap(s.toLocaleDateString('fr-FR', { weekday: 'long', day: 'numeric', month: 'long' })) : '';
    S.time = '';
    if (s && (s.getHours() || s.getMinutes())) {
      S.time = e && e > s ? 'de ' + hm(s) + ' à ' + hm(e) : 'à partir de ' + hm(s);
    }
    var tpl = suggestTemplate(ev.title);
    if (tpl) { S.tpl = tpl; if (!accentTouched) S.accent = TPL[tpl].accent; }
    if (ev.desc) { S.msg = ev.desc; msgIsDefault = false; }
    else { S.msg = TPL[S.tpl].msg; msgIsDefault = true; }
    syncInputs();
    refresh();
  }

  elEvent.addEventListener('change', function () {
    if (elEvent.value === '') return;
    applyEvent(events[parseInt(elEvent.value, 10)]);
  });

  /* ───────────── rendu ───────────── */
  var elStage = document.getElementById('pbStage');
  var elScaler = document.getElementById('pbScaler');
  var elPreview = document.getElementById('pbPreview');

  function fitPreview() {
    var avail = Math.min(elPreview.clientWidth || 440, 460);
    var k = Math.min(1, avail / W);
    elScaler.style.width = Math.round(W * k) + 'px';
    elScaler.style.height = Math.round(H * k) + 'px';
    elStage.style.transform = 'scale(' + k + ')';
  }

  function refresh() {
    elStage.innerHTML = posterHtml();
    [].forEach.call(elTpls.children, function (b) { b.setAttribute('aria-checked', b.dataset.tpl === S.tpl ? 'true' : 'false'); });
    [].forEach.call(elColors.children, function (b) { b.setAttribute('aria-checked', b.dataset.hex === S.accent ? 'true' : 'false'); });
    fitPreview();
  }
  window.addEventListener('resize', fitPreview);

  /* ───────────── export ───────────── */
  var elOut = document.getElementById('pbMsgOut');
  function say(t) { elOut.textContent = t || ''; }

  function fontsReady() {
    return (document.fonts && document.fonts.ready) ? document.fonts.ready : Promise.resolve();
  }

  function exportPng() {
    if (!window.html2canvas) { say("L'export image n'est pas disponible."); return; }
    say('Création de l\'image…');
    var holder = document.createElement('div');
    holder.style.cssText = 'position:fixed;left:-10000px;top:0;width:' + W + 'px;height:' + H + 'px';
    holder.innerHTML = posterHtml();
    document.body.appendChild(holder);
    fontsReady().then(function () {
      return window.html2canvas(holder.firstElementChild, { scale: 2, backgroundColor: null, logging: false, useCORS: true });
    }).then(function (cv) {
      cv.toBlob(function (blob) {
        var a = document.createElement('a');
        a.href = URL.createObjectURL(blob);
        a.download = slug(S.title) + '.png';
        document.body.appendChild(a); a.click(); a.remove();
        setTimeout(function () { URL.revokeObjectURL(a.href); }, 4000);
        say('Image téléchargée.');
      }, 'image/png');
    }).catch(function () {
      say("L'image n'a pas pu être créée.");
    }).then(function () { holder.remove(); });
  }

  function exportPdf(format) {
    var mm = format === 'a3' ? [297, 420] : [210, 297];
    var pxW = mm[0] * 96 / 25.4;
    var k = pxW / W;

    var style = document.createElement('style');
    style.id = 'pbPrintStyle';
    style.textContent = '@page{size:' + format.toUpperCase() + ' portrait;margin:0}';
    var printRoot = document.createElement('div');
    printRoot.id = 'pbPrintRoot';
    printRoot.style.cssText = 'width:' + mm[0] + 'mm;height:' + mm[1] + 'mm;overflow:hidden;position:relative';
    printRoot.innerHTML = '<div style="width:' + W + 'px;height:' + H + 'px;transform:scale(' + k + ');transform-origin:top left">' + posterHtml() + '</div>';
    document.head.appendChild(style);
    document.body.appendChild(printRoot);

    function cleanup() {
      window.removeEventListener('afterprint', cleanup);
      style.remove(); printRoot.remove();
    }
    window.addEventListener('afterprint', cleanup);
    say('Dans la fenêtre d\'impression, choisissez « Enregistrer au format PDF ».');
    fontsReady().then(function () {
      requestAnimationFrame(function () { window.print(); });
    });
  }

  [].forEach.call(document.querySelectorAll('[data-export]'), function (b) {
    b.addEventListener('click', function () {
      var t = b.getAttribute('data-export');
      if (t === 'png') exportPng(); else exportPdf(t);
    });
  });

  /* ───────────── init ───────────── */
  S.title = TPL.collecte.title;
  if (events.length) {
    elEvent.value = '0';
    applyEvent(events[0]);
  } else {
    syncInputs();
    refresh();
  }
  if (document.fonts && document.fonts.ready) document.fonts.ready.then(refresh);
})();
