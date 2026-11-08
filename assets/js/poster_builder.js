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
  function dv(style, inner) { return '<div style="' + style + '">' + (inner || '') + '</div>'; }

  /* Taille d'un titre en capitales selon sa longueur (tient en 1 à 3 lignes). */
  function titleSize(text, base) {
    var n = text.length;
    return Math.round(base * (n <= 12 ? 1 : n <= 22 ? .8 : n <= 34 ? .62 : n <= 50 ? .5 : .42));
  }

  /* ───────────── thèmes de couleurs ───────────── */
  var BLUE = '#1d4f9f', YELLOW = '#f6d046';
  var THEMES = [
    { id: 'ukraine', name: 'Bleu et jaune', bg: BLUE, hl: YELLOW },
    { id: 'soleil', name: 'Jaune et bleu', bg: YELLOW, hl: BLUE },
    { id: 'orange', name: 'Orange', bg: '#d4732a', hl: '#fff1d6' },
    { id: 'turquoise', name: 'Turquoise et jaune', bg: '#23766b', hl: YELLOW },
    { id: 'nuit', name: 'Nuit et ambre', bg: '#1a1510', hl: '#f0b060' }
  ];
  /* Palette d'une affiche. Mode « plein » : grands aplats de couleur.
   * Mode « léger » : fond blanc, couleur réservée aux textes et filets (économise l'encre). */
  function palette(id, light) {
    var t = THEMES.filter(function (x) { return x.id === id; })[0] || THEMES[0];
    var main = lum(t.bg) <= lum(t.hl) ? t.bg : t.hl;     // la plus foncée : texte
    var accent = main === t.bg ? t.hl : t.bg;            // l'autre : touches de couleur
    return {
      bg: t.bg, hl: t.hl, main: main, accent: accent, light: !!light,
      deep: mix(main, '#000000', .35),
      topBg: light ? '#ffffff' : t.bg, topText: light ? main : t.hl,
      botBg: light ? mix(accent, '#ffffff', .8) : t.hl, botText: light ? main : t.bg
    };
  }

  /* ───────────── état ───────────── */
  var TPL = {
    collecte: {
      label: 'Collecte', theme: 'ukraine',
      msg: "Venez déposer vos dons pour l'Ukraine : denrées, produits d'hygiène, vêtements chauds.",
      title: 'Collecte de dons'
    },
    vente: {
      label: 'Vente solidaire', theme: 'ukraine',
      msg: "Chaque achat finance l'envoi de matériel et de produits de première nécessité en Ukraine.",
      title: 'Vente solidaire'
    },
    reunion: {
      label: 'Réunion', theme: 'ukraine',
      msg: "Bilan des derniers convois, organisation des prochaines actions. Nouveaux bénévoles bienvenus.",
      title: 'Réunion des bénévoles'
    },
    benevoles: {
      label: 'Bénévoles', theme: 'soleil',
      msg: "Une heure ou une journée, chaque coup de main compte pour préparer les convois.",
      title: 'Chargement du convoi'
    }
  };
  var ORDER = ['collecte', 'vente', 'reunion', 'benevoles'];

  var S = {
    tpl: 'collecte', theme: TPL.collecte.theme,
    title: '', date: '', time: '', place: '', msg: TPL.collecte.msg,
    qr: true, link: location.origin + '/', photo: '', light: false
  };
  var themeTouched = false, msgIsDefault = true, titleIsDefault = true;

  var LOGO = '';
  try { LOGO = JSON.parse(document.getElementById('pbLogo').textContent) || ''; } catch (e) { LOGO = ''; }
  var logoRatio = 1.4;
  if (LOGO) {
    var logoProbe = new Image();
    logoProbe.onload = function () {
      if (logoProbe.naturalHeight) logoRatio = logoProbe.naturalWidth / logoProbe.naturalHeight;
      refresh();
    };
    logoProbe.src = LOGO;
  }

  var qrCache = {};
  function validLink() {
    try { var u = new URL(S.link); return /^https?:$/.test(u.protocol) ? u.href : ''; } catch (e) { return ''; }
  }
  function linkLabel() { return validLink() ? S.link.trim().replace(/^https?:\/\//i, '').replace(/\/$/, '') : ''; }
  function qrImg() {
    var href = validLink();
    if (!window.qrcode || !href) return '';
    if (!qrCache[href]) {
      var q = window.qrcode(0, 'M');
      q.addData(href);
      q.make();
      qrCache[href] = q.createDataURL(8, 0);
    }
    return qrCache[href];
  }

  /* ───────────── briques graphiques ───────────── */
  function svgImg(svg, w, h, style) {
    return '<img alt="" width="' + w + '" height="' + h + '" src="data:image/svg+xml;charset=utf-8,' + encodeURIComponent(svg) +
      '" style="display:block;' + (style || '') + '">';
  }

  function sunflower(c1, c2, center, seed) {
    var p = '', i, a;
    for (i = 0; i < 18; i++) p += '<ellipse cx="0" cy="-70" rx="15" ry="38" fill="' + c2 + '" transform="rotate(' + (i * 20 + 10) + ')"/>';
    for (i = 0; i < 18; i++) p += '<ellipse cx="0" cy="-62" rx="16" ry="40" fill="' + c1 + '" transform="rotate(' + (i * 20) + ')"/>';
    p += '<circle r="40" fill="' + center + '"/>';
    for (i = 0; i < 80; i++) {
      var r = 4.2 * Math.sqrt(i + .5); a = i * 137.508 * Math.PI / 180;
      if (r < 36) p += '<circle cx="' + (r * Math.cos(a)).toFixed(1) + '" cy="' + (r * Math.sin(a)).toFixed(1) + '" r="1.9" fill="' + seed + '"/>';
    }
    return '<svg xmlns="http://www.w3.org/2000/svg" width="220" height="220" viewBox="-110 -110 220 220">' + p + '</svg>';
  }
  /* Tournesol jaune ; sur un fond jaune clair, version « ton sur ton ». */
  function sunflowerFor(c, size, style) {
    var svg = (!c.light && lum(c.topBg) > .45)
      ? sunflower(c.topText, mix(c.topText, '#ffffff', .18), mix(c.topText, '#000000', .45), mix(c.topText, '#ffffff', .3))
      : sunflower('#f4be26', '#e29d12', '#4f3216', '#8a5e2c');
    return svgImg(svg, size, size, style);
  }

  /* Tournesol très pâle (filigrane de la version économe en encre). */
  function sunflowerSoft(c, size) {
    return svgImg(sunflower(mix(c.accent, '#ffffff', .55), mix(c.accent, '#ffffff', .7), mix(c.accent, '#ffffff', .35), mix(c.accent, '#ffffff', .5)), size, size);
  }

  /* Vague entre deux aplats (top null = transparent) ; stripe = filet de couleur sur la crête. */
  function wave(top, bottom, h, stripe) {
    var path = 'C130,18 300,8 470,46 S700,98 794,50 L794,121 L0,121 Z';
    return svgImg('<svg xmlns="http://www.w3.org/2000/svg" width="' + W + '" height="' + h + '" viewBox="0 0 794 120" preserveAspectRatio="none">' +
      (top ? '<rect width="794" height="120" fill="' + top + '"/>' : '') +
      (stripe ? '<path d="M0,66 ' + path + '" fill="' + stripe + '"/>' : '') +
      '<path d="M0,78 ' + path + '" fill="' + bottom + '"/></svg>', W, h, 'flex-shrink:0;margin-top:-1px');
  }

  var ICON = {
    clock: '<circle cx="12" cy="12" r="9"/><path d="M12 7v5.2l3.4 2"/>',
    pin: '<path d="M12 21.5s7-6.1 7-11.8a7 7 0 1 0-14 0c0 5.7 7 11.8 7 11.8z"/><circle cx="12" cy="9.7" r="2.6"/>'
  };
  function icon(kind, color, size) {
    return svgImg('<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="' + color +
      '" stroke-width="2.3" stroke-linecap="round" stroke-linejoin="round">' + ICON[kind] + '</svg>', size, size, 'flex-shrink:0');
  }
  function infoRows(size, color, iconColor, center) {
    var rows = [];
    if (S.time) rows.push(['clock', S.time]);
    if (S.place) rows.push(['pin', S.place]);
    return rows.map(function (r) {
      return '<div style="display:flex;align-items:center;' + (center ? 'justify-content:center;' : '') + 'gap:' + Math.round(size * .45) + 'px">' + icon(r[0], iconColor, Math.round(size * 1.1)) +
        '<span style="font-family:' + FD + ';font-weight:700;font-size:' + size + 'px;line-height:1.15;color:' + color + '">' + esc(r[1]) + '</span></div>';
    }).join('');
  }

  function dateParts() {
    if (!/^\d{4}-\d{2}-\d{2}$/.test(S.date)) return null;
    var d = new Date(S.date + 'T12:00:00');
    if (isNaN(d)) return null;
    return {
      wd: cap(d.toLocaleDateString('fr-FR', { weekday: 'long' })),
      day: String(d.getDate()),
      mon: d.toLocaleDateString('fr-FR', { month: 'long' }),
      full: cap(d.toLocaleDateString('fr-FR', { weekday: 'long', day: 'numeric', month: 'long' }))
    };
  }
  function dateLineSize(txt) { return txt.length <= 16 ? 60 : txt.length <= 20 ? 52 : 46; }

  /* Logo : carte blanche sur les aplats de couleur, posé tel quel sur fond blanc (plain). */
  function logoBadge(h, style, plain) {
    if (!LOGO) return '';
    var wpx = Math.round(h * logoRatio);
    return '<div style="display:flex;width:max-content;' + (plain ? '' : 'background:#fff;border-radius:16px;padding:8px 10px;box-shadow:0 6px 18px rgba(0,0,0,.2);') + (style || '') +
      '"><img alt="" src="' + LOGO + '" width="' + wpx + '" height="' + h + '" style="width:' + wpx + 'px;height:' + h + 'px;display:block"></div>';
  }
  function photoBg(radius, style) {
    return dv('border-radius:' + radius + 'px;background:#e8d5b7 url(' + S.photo + ') center/cover no-repeat;' + (style || ''));
  }

  /* Pied de page : le texte suit la présence du QR code et du lien. */
  function cta(o) {
    var label = linkLabel(), img = S.qr ? qrImg() : '';
    return (img ? dv('width:' + o.size + 'px;height:' + o.size + 'px;background:#fff;border-radius:10px;padding:7px;box-sizing:border-box;flex-shrink:0;box-shadow:0 4px 12px rgba(0,0,0,.15)',
        '<img alt="" src="' + img + '" style="width:100%;height:100%;display:block">') : '') +
      '<div style="font-size:20px;line-height:1.4;color:' + o.color + ';' + (o.center && !img ? 'text-align:center;' : '') + '"><b style="font-family:' + FD + ';font-size:' + (o.hs || 24) + 'px;color:' + o.hc + '">' + esc(o.head) + '</b>' +
      (label ? '<br>' + (img ? o.withQr : o.noQr) + ' <b style="color:' + o.uc + '">' + esc(label) + '</b>' : '') + '</div>';
  }
  var CTA = { withQr: 'Scannez pour en savoir plus :', noQr: 'Plus d\'informations sur' };

  var wrap = 'width:' + W + 'px;height:' + H + 'px;box-sizing:border-box;overflow:hidden;position:relative;font-family:' + FB + ';';
  var UP = 'text-transform:uppercase;font-family:' + FD + ';font-weight:800;letter-spacing:-.01em;';
  var R = {};

  /* ───────────── Collecte : bandeau, logo, vague, infos ───────────── */
  R.collecte = function (c) {
    var d = dateParts(), ts = titleSize(S.title, 98), dl = d ? d.full.toUpperCase() : '';
    var visual = S.photo
      ? dv('width:100%;flex:1;min-height:200px;margin-top:22px;position:relative', photoBg(26, 'position:absolute;inset:0;') + (LOGO ? logoBadge(96, 'position:absolute;left:18px;bottom:18px') : ''))
      : dv('margin-top:22px;flex:1;min-height:200px;display:flex;align-items:center;justify-content:center', LOGO ? logoBadge(250, '', c.light) : sunflowerFor(c, 230));
    return dv(wrap + 'background:' + c.botBg + ';display:flex;flex-direction:column',
      dv('flex:1;min-height:0;background:' + c.topBg + ';padding:42px 56px 0;display:flex;flex-direction:column;align-items:center;text-align:center',
        dv('font-family:' + FD + ';font-weight:700;font-size:19px;letter-spacing:.26em;text-transform:uppercase;color:' + c.topText + ';opacity:.9', 'Association Touraine-Ukraine') +
        dv(UP + 'margin-top:16px;font-size:' + ts + 'px;line-height:1.04;color:' + c.topText, multi(S.title)) + visual) +
      wave(c.topBg, c.botBg, 110, c.light ? c.accent : '') +
      dv('margin-top:-1px;padding:0 56px 34px;background:' + c.botBg + ';color:' + c.botText + ';display:flex;flex-direction:column;align-items:center;text-align:center;gap:14px',
        (d ? dv(UP + 'font-size:' + dateLineSize(dl) + 'px;line-height:1.05', esc(dl)) : '') +
        dv('display:flex;flex-direction:column;gap:8px;align-items:center', infoRows(29, c.botText, c.botText, true)) +
        (S.msg ? dv('font-size:23px;line-height:1.4;max-width:620px', multi(S.msg)) : '') +
        dv('margin-top:6px;display:flex;align-items:center;justify-content:center;gap:20px',
          cta({ size: 92, color: c.botText, hc: c.botText, uc: c.botText, head: 'Venez nous aider !', withQr: CTA.withQr, noQr: CTA.noQr, center: true }))));
  };

  /* ───────────── Vente solidaire : grand visuel plein cadre ───────────── */
  R.vente = function (c) {
    var d = dateParts(), ts = titleSize(S.title, 92), H1 = 690;
    var bBg = c.light ? c.botBg : c.deep, bTx = c.light ? c.botText : '#ffffff', bNum = c.light ? c.main : c.accent;
    var hero = S.photo
      ? photoBg(0, 'position:absolute;left:0;top:0;width:' + W + 'px;height:' + H1 + 'px;') +
        dv('position:absolute;left:0;top:0;width:' + W + 'px;height:' + H1 + 'px;background:linear-gradient(to bottom,rgba(0,0,0,.4),rgba(0,0,0,.05) 38%,rgba(0,0,0,.8))')
      : dv('position:absolute;left:0;top:0;width:' + W + 'px;height:' + H1 + 'px;background:' + c.topBg) +
        dv('position:absolute;left:197px;top:120px', sunflowerFor(c, 400));
    var titleColor = S.photo ? '#ffffff' : c.topText;
    return dv(wrap + 'background:' + bBg,
      hero +
      (LOGO ? dv('position:absolute;left:44px;top:40px', logoBadge(104, '', c.light && !S.photo)) : '') +
      dv('position:absolute;right:48px;top:' + (LOGO ? 74 : 48) + 'px;font-family:' + FD + ';font-weight:700;font-size:17px;letter-spacing:.24em;text-transform:uppercase;color:' + titleColor + ';text-align:right;line-height:1.5;' + (S.photo ? 'text-shadow:0 1px 6px rgba(0,0,0,.35)' : ''), 'Vente<br>solidaire') +
      dv('position:absolute;left:56px;right:56px;bottom:' + (H - H1 + 46) + 'px;' + UP + 'font-size:' + ts + 'px;line-height:1.03;color:' + titleColor + (S.photo ? ';text-shadow:0 2px 14px rgba(0,0,0,.4)' : ''), multi(S.title)) +
      '<div style="position:absolute;left:0;top:' + (H1 - 60) + 'px">' + wave(null, bBg, 66, c.light ? c.accent : '') + '</div>' +
      dv('position:absolute;left:56px;right:56px;top:' + (H1 + 6) + 'px;bottom:0;color:' + bTx + ';display:flex;flex-direction:column;gap:14px',
        dv('display:flex;align-items:center;justify-content:space-between;gap:20px',
          (d ? dv('display:flex;align-items:center;gap:18px', dv('font-family:' + FD + ';font-weight:800;font-size:132px;line-height:.82;color:' + bNum + ';letter-spacing:-.04em', d.day) +
            dv('font-family:' + FD + ';font-weight:800;font-size:34px;line-height:1.1;text-transform:uppercase', esc(d.wd) + '<br><span style="color:' + bNum + '">' + esc(d.mon) + '</span>')) : '') +
          dv('display:flex;flex-direction:column;gap:10px;align-items:flex-start', infoRows(25, bTx, bNum))) +
        (S.msg ? dv('font-size:21px;line-height:1.4;opacity:.9', multi(S.msg)) : '') +
        dv('margin-top:auto;margin-bottom:34px;display:flex;align-items:center;gap:20px;border-top:2px solid ' + (c.light ? c.accent : mix(c.deep, '#ffffff', .25)) + ';padding-top:16px',
          cta({ size: 84, color: bTx, hc: bTx, uc: bNum, hs: 23, head: 'Venez nombreux !', withQr: CTA.withQr, noQr: CTA.noQr }))));
  };

  /* ───────────── Réunion : la date comme image ───────────── */
  R.reunion = function (c) {
    var d = dateParts(), hasPh = !!S.photo, ts = titleSize(S.title, 74);
    var ink = '#14213d', deco = lum(c.accent) > .5 ? c.accent : c.main;
    var bBg = c.light ? c.botBg : c.main, bTx = c.light ? c.botText : '#ffffff', bHi = c.light ? c.main : c.accent;
    return dv(wrap + 'background:#ffffff;color:' + ink + ';display:flex;flex-direction:column',
      dv('padding:42px 56px 0;display:flex;justify-content:space-between;align-items:center',
        (LOGO ? logoBadge(100, '', true) : dv('font-family:' + FD + ';font-weight:800;font-size:19px;letter-spacing:.2em;text-transform:uppercase', 'Touraine-Ukraine')) +
        dv('font-family:' + FD + ';font-weight:800;font-size:24px;letter-spacing:.26em;text-transform:uppercase;color:' + c.main + ';border:3px solid ' + c.main + ';border-radius:40px;padding:8px 22px', 'Réunion')) +
      (d ? dv('padding:' + (hasPh ? 10 : 22) + 'px 56px 0;display:flex;align-items:flex-end;gap:26px',
        dv('font-family:' + FD + ';font-weight:800;font-size:' + (hasPh ? 230 : 330) + 'px;line-height:.8;letter-spacing:-.05em;color:' + c.main, d.day) +
        dv('padding-bottom:6px;' + UP + 'font-size:46px;line-height:1.08', esc(d.wd) + '<br><span style="color:' + c.main + '">' + esc(d.mon) + '</span>')) : '') +
      dv('padding:' + (d ? 26 : 40) + 'px 56px 0;' + UP + 'font-size:' + ts + 'px;line-height:1.04;color:' + ink, multi(S.title)) +
      dv('margin:16px 56px 0;height:9px;width:120px;background:' + deco + ';border-radius:5px;flex-shrink:0') +
      dv('padding:22px 56px 0;display:flex;flex-direction:column;gap:10px', infoRows(30, ink, c.main)) +
      (hasPh ? photoBg(22, 'margin:22px 56px 0;height:170px;flex-shrink:0') : '') +
      (S.msg ? dv('margin:22px 56px 0;padding-left:20px;border-left:7px solid ' + deco + ';font-size:23px;line-height:1.4;color:#3d3a33', multi(S.msg)) : '') +
      dv('flex:1') +
      wave('#ffffff', bBg, 90, c.light ? c.accent : '') +
      dv('margin-top:-1px;background:' + bBg + ';padding:0 56px 32px;display:flex;align-items:center;gap:22px',
        cta({ size: 88, color: bTx, hc: bHi, uc: bHi, hs: 25, head: 'Une question ?', withQr: 'Scannez pour nous contacter :', noQr: 'Contactez-nous sur' })));
  };

  /* ───────────── Appel aux bénévoles : message géant ───────────── */
  R.benevoles = function (c) {
    var ts = titleSize(S.title, 62), d = dateParts(), dl = d ? d.full.toUpperCase() : '';
    var art = S.photo ? '' : dv('position:absolute;right:-170px;top:150px;' + (c.light ? '' : 'opacity:.3'), c.light ? sunflowerSoft(c, 560) : sunflowerFor(c, 560));
    return dv(wrap + 'background:' + c.botBg + ';display:flex;flex-direction:column',
      dv('flex:1;min-height:0;background:' + c.topBg + ';position:relative;overflow:hidden;padding:40px 56px 0',
        art +
        dv('position:relative', LOGO ? logoBadge(108, '', c.light) : dv('font-family:' + FD + ';font-weight:800;font-size:19px;letter-spacing:.2em;text-transform:uppercase;color:' + c.topText, 'Touraine-Ukraine')) +
        dv('position:relative;margin-top:44px;font-family:' + FD + ';font-weight:700;font-size:29px;line-height:1.25;letter-spacing:.16em;text-transform:uppercase;color:' + c.topText, 'Nous recherchons<br>des') +
        dv('position:relative;' + UP + 'font-size:112px;line-height:.95;letter-spacing:-.03em;color:' + c.topText + ';margin-top:6px', 'Bénévoles') +
        dv('position:relative;margin-top:26px;width:110px;height:9px;border-radius:5px;background:' + (c.light ? c.accent : c.topText)) +
        dv('position:relative;margin-top:22px;max-width:640px;' + UP + 'font-size:' + ts + 'px;line-height:1.08;color:' + c.topText, multi(S.title)) +
        (S.photo ? photoBg(22, 'position:relative;margin-top:22px;height:100px') : '')) +
      wave(c.topBg, c.botBg, 100, c.light ? c.accent : '') +
      dv('margin-top:-1px;padding:0 56px 36px;background:' + c.botBg + ';color:' + c.botText + ';display:flex;flex-direction:column;gap:14px',
        (d ? dv(UP + 'font-size:' + dateLineSize(dl) + 'px;line-height:1.05', esc(dl)) : '') +
        dv('display:flex;flex-direction:column;gap:8px', infoRows(31, c.botText, c.botText)) +
        (S.msg ? dv('font-size:22px;line-height:1.4;max-width:640px', multi(S.msg)) : '') +
        dv('margin-top:6px;display:flex;align-items:center;gap:20px',
          cta({ size: 92, color: c.botText, hc: c.botText, uc: c.botText, hs: 25, head: 'Envie de nous rejoindre ?', withQr: 'Scannez pour nous contacter :', noQr: 'Contactez-nous sur' }))));
  };

  function posterHtml() {
    return R[S.tpl](palette(S.theme, S.light));
  }

  /* ───────────── UI : modèles, couleurs ───────────── */
  var elTpls = document.getElementById('pbTpls');
  var elColors = document.getElementById('pbColors');

  function thumb(id) {
    if (id === 'collecte') return '<div style="height:100%;display:flex;flex-direction:column"><div style="flex:1.3;background:' + BLUE + ';display:flex;align-items:center;justify-content:center"><div style="width:34px;height:20px;background:#fff;border-radius:3px"></div></div><div style="flex:1;background:' + YELLOW + ';border-top-left-radius:50% 40%"></div></div>';
    if (id === 'vente') return '<div style="height:100%;display:flex;flex-direction:column"><div style="flex:1.5;background:' + BLUE + ';position:relative"><div style="position:absolute;left:50%;top:50%;width:30px;height:30px;margin:-15px 0 0 -15px;border-radius:50%;background:#f4be26"></div></div><div style="flex:1;background:#12326a"></div></div>';
    if (id === 'reunion') return '<div style="height:100%;background:#fffaf0;display:flex;flex-direction:column"><div style="flex:1;padding:6px 8px"><div style="font:800 30px/1 Sora,sans-serif;color:' + BLUE + '">21</div></div><div style="height:16px;background:' + BLUE + '"></div></div>';
    return '<div style="height:100%;display:flex;flex-direction:column"><div style="flex:1.3;background:' + YELLOW + ';padding:8px"><div style="height:7px;width:70%;background:' + BLUE + ';border-radius:2px"></div><div style="height:12px;width:90%;background:' + BLUE + ';border-radius:2px;margin-top:5px"></div></div><div style="flex:1;background:' + BLUE + '"></div></div>';
  }

  ORDER.forEach(function (id) {
    var b = document.createElement('button');
    b.type = 'button'; b.className = 'pb-tpl'; b.setAttribute('role', 'radio'); b.dataset.tpl = id;
    b.innerHTML = '<div class="pb-tpl-thumb">' + thumb(id) + '</div><span class="pb-tpl-name">' + esc(TPL[id].label) + '</span>';
    b.addEventListener('click', function () { setTemplate(id); });
    elTpls.appendChild(b);
  });
  THEMES.forEach(function (t) {
    var b = document.createElement('button');
    b.type = 'button'; b.className = 'pb-color'; b.setAttribute('role', 'radio');
    b.setAttribute('aria-label', t.name); b.title = t.name; b.dataset.theme = t.id;
    b.style.background = 'linear-gradient(135deg,' + t.bg + ' 50%,' + t.hl + ' 50%)';
    b.style.setProperty('--c', t.bg === '#1a1510' ? '#6b5c47' : t.bg);
    b.addEventListener('click', function () { themeTouched = true; S.theme = t.id; refresh(); });
    elColors.appendChild(b);
  });

  function setTemplate(id) {
    S.tpl = id;
    if (!themeTouched) S.theme = TPL[id].theme;
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
    qr: document.getElementById('pbQr'),
    link: document.getElementById('pbLink'),
    light: document.getElementById('pbLight')
  };
  function syncInputs() {
    f.title.value = S.title; f.date.value = S.date; f.time.value = S.time;
    f.place.value = S.place; f.msg.value = S.msg; f.qr.checked = S.qr; f.link.value = S.link; f.light.checked = S.light;
  }
  ['title', 'date', 'time', 'place', 'msg'].forEach(function (k) {
    var onEdit = function () {
      S[k] = f[k].value;
      if (k === 'msg') msgIsDefault = false;
      if (k === 'title') titleIsDefault = false;
      refresh();
    };
    f[k].addEventListener('input', onEdit);
    f[k].addEventListener('change', onEdit);
  });
  f.link.addEventListener('input', function () { S.link = f.link.value; refresh(); });
  f.light.addEventListener('change', function () { S.light = f.light.checked; refresh(); });
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
    S.date = s ? s.getFullYear() + '-' + ('0' + (s.getMonth() + 1)).slice(-2) + '-' + ('0' + s.getDate()).slice(-2) : '';
    S.time = '';
    if (s && (s.getHours() || s.getMinutes())) {
      S.time = e && e > s ? 'de ' + hm(s) + ' à ' + hm(e) : 'à partir de ' + hm(s);
    }
    var tpl = suggestTemplate(ev.title);
    if (tpl) { S.tpl = tpl; if (!themeTouched) S.theme = TPL[tpl].theme; }
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
    [].forEach.call(elColors.children, function (b) { b.setAttribute('aria-checked', b.dataset.theme === S.theme ? 'true' : 'false'); });
    fitPreview();
  }
  window.addEventListener('resize', fitPreview);

  /* ───────────── export ───────────── */
  var elOut = document.getElementById('pbMsgOut');
  function say(t) { elOut.textContent = t || ''; }

  function fontsReady() {
    return (document.fonts && document.fonts.ready) ? document.fonts.ready : Promise.resolve();
  }

  /* Dessine l'affiche hors écran (sans mise à l'échelle) et renvoie un canvas. */
  function renderCanvas(scale) {
    if (!window.html2canvas) return Promise.reject(new Error('html2canvas'));
    var holder = document.createElement('div');
    holder.style.cssText = 'position:fixed;left:-10000px;top:0;width:' + W + 'px;height:' + H + 'px';
    holder.innerHTML = posterHtml();
    document.body.appendChild(holder);
    return fontsReady().then(function () {
      return window.html2canvas(holder.firstElementChild, { scale: scale, backgroundColor: null, logging: false, useCORS: true });
    }).then(function (cv) { holder.remove(); return cv; }, function (e) { holder.remove(); throw e; });
  }

  function exportPng() {
    say('Création de l\'image…');
    renderCanvas(2).then(function (cv) {
      cv.toBlob(function (blob) {
        var a = document.createElement('a');
        a.href = URL.createObjectURL(blob);
        a.download = slug(S.title) + '.png';
        document.body.appendChild(a); a.click(); a.remove();
        setTimeout(function () { URL.revokeObjectURL(a.href); }, 4000);
        say('Image téléchargée.');
      }, 'image/png');
    }).catch(function () { say("L'image n'a pas pu être créée."); });
  }

  /* PDF : l'affiche est rendue à ~300 dpi (A4) puis placée sur une page A4 ou A3. */
  function exportPdf(format) {
    var lib = window.jspdf && window.jspdf.jsPDF;
    if (!lib) { say("L'export PDF n'est pas disponible."); return; }
    say('Création du PDF…');
    renderCanvas(3).then(function (cv) {
      var mm = format === 'a3' ? [297, 420] : [210, 297];
      var pdf = new lib({ unit: 'mm', format: format, orientation: 'portrait', compress: true });
      pdf.addImage(cv.toDataURL('image/jpeg', .93), 'JPEG', 0, 0, mm[0], mm[1], undefined, 'FAST');
      pdf.setProperties({ title: S.title || 'Affiche', author: 'Touraine-Ukraine' });
      pdf.save(slug(S.title) + '-' + format + '.pdf');
      say('PDF ' + format.toUpperCase() + ' téléchargé.');
    }).catch(function () { say("Le PDF n'a pas pu être créé."); });
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
