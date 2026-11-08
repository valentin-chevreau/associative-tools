/* Éditeur de blocs du module Mailing : état des blocs, aperçu serveur de chaque bloc, panneau de réglages. */
(function () {
  'use strict';
  var dataEl = document.getElementById('mlData');
  if (!dataEl) return;
  var D = JSON.parse(dataEl.textContent);
  var canvas = document.getElementById('mlCanvas');
  var panel = document.getElementById('mlPanel');
  var field = document.getElementById('mlBlocksInput');
  var uid = 0;

  var TYPES = [
    { t: 'title', l: 'Titre', i: 'T' }, { t: 'text', l: 'Texte', i: '¶' }, { t: 'image', l: 'Image', i: '▣' },
    { t: 'button', l: 'Bouton', i: '▭' }, { t: 'columns', l: 'Colonnes', i: '◫' }, { t: 'quote', l: 'Citation', i: '❝' },
    { t: 'stats', l: 'Chiffres', i: '123' }, { t: 'event', l: 'Évènement', i: '◷' }, { t: 'divider', l: 'Séparateur', i: '—' }
  ];
  function label(t) { for (var k = 0; k < TYPES.length; k++) if (TYPES[k].t === t) return TYPES[k].l; return t; }

  function make(t) {
    var b = { type: t };
    if (t === 'title' || t === 'text') b.text = '';
    if (t === 'image') { b.file = ''; b.alt = ''; b.caption = ''; b.url = ''; }
    if (t === 'button') { b.label = ''; b.url = ''; }
    if (t === 'columns') { b.left = ''; b.right = ''; }
    if (t === 'quote') { b.text = ''; b.author = ''; }
    if (t === 'stats') b.items = [{ value: '', label: '' }, { value: '', label: '' }, { value: '', label: '' }];
    if (t === 'event') { b.event_id = 0; b.show_time = true; b.show_desc = false; b.label = ''; b.url = ''; }
    return tag(b);
  }
  function tag(b) { b._u = ++uid; return b; }

  var blocks = (D.blocks || []).map(tag);
  var html = {};          // _u -> HTML renvoyé par le serveur
  var sel = blocks.length ? 0 : -1;
  var openAt = -1;        // position du « + » dont le sélecteur est ouvert
  var timer = null;

  function el(tagName, attrs, kids) {
    var n = document.createElement(tagName);
    Object.keys(attrs || {}).forEach(function (k) {
      if (k === 'class') n.className = attrs[k];
      else if (k === 'text') n.textContent = attrs[k];
      else if (k === 'html') n.innerHTML = attrs[k];
      else n.setAttribute(k, attrs[k]);
    });
    (kids || []).forEach(function (c) { n.appendChild(c); });
    return n;
  }
  function clean() { return blocks.map(function (b) { var c = {}; Object.keys(b).forEach(function (k) { if (k !== '_u') c[k] = b[k]; }); return c; }); }
  function sync() { field.value = JSON.stringify(clean()); }

  /* ----- Aperçu : chaque bloc est rendu par le serveur ----- */
  function refresh() {
    clearTimeout(timer);
    timer = setTimeout(function () {
      var fd = new FormData();
      fd.append('csrf', D.csrf);
      fd.append('blocks', JSON.stringify(clean()));
      var sent = blocks.map(function (b) { return b._u; });
      fetch(D.previewUrl, { method: 'POST', body: fd, credentials: 'same-origin' })
        .then(function (r) { return r.json(); })
        .then(function (res) {
          if (!res.html || res.html.length !== sent.length) return;
          sent.forEach(function (u, i) { html[u] = res.html[i]; });
          paintHtml();
        }).catch(function () {});
    }, 300);
  }
  function changed() { sync(); refresh(); }

  function paintHtml() {
    blocks.forEach(function (b) {
      var holder = canvas.querySelector('[data-u="' + b._u + '"] .mle-html');
      if (!holder) return;
      var h = html[b._u];
      if (h) holder.innerHTML = h;
      else holder.innerHTML = '<div class="mle-empty">Bloc « ' + label(b.type).toLowerCase() + ' » vide : renseignez-le dans le panneau.</div>';
    });
  }

  /* ----- Canevas ----- */
  function inserter(i) {
    var wrap = el('div', { class: 'mle-ins' + (openAt === i ? ' is-open' : '') });
    var btn = el('button', { type: 'button', 'aria-label': 'Ajouter un bloc ici', text: '+' });
    btn.addEventListener('click', function (e) { e.stopPropagation(); openAt = (openAt === i ? -1 : i); renderCanvas(); });
    wrap.appendChild(btn);
    if (openAt === i) {
      var pop = el('div', { class: 'mle-pop' }, [el('h4', { text: 'Ajouter un bloc ici' })]);
      TYPES.forEach(function (T) {
        var b = el('button', { type: 'button' }, [el('b', { text: T.i }), document.createTextNode(T.l)]);
        b.addEventListener('click', function (e) {
          e.stopPropagation();
          blocks.splice(i, 0, make(T.t));
          sel = i; openAt = -1;
          changed(); renderAll();
        });
        pop.appendChild(b);
      });
      wrap.appendChild(pop);
    }
    return wrap;
  }

  function renderCanvas() {
    canvas.innerHTML = '';
    var card = el('div', { class: 'mle-card' });
    canvas.classList.toggle('is-blank', blocks.length === 0);
    card.appendChild(inserter(0));
    blocks.forEach(function (b, i) {
      var w = el('div', { class: 'mle-block' + (i === sel ? ' is-sel' : ''), 'data-u': b._u }, [el('div', { class: 'mle-html' })]);
      if (i === sel) {
        var bar = el('div', { class: 'mle-bar' }, [el('span', { text: label(b.type) })]);
        [['↑', 'Monter', function () { move(i, -1); }, i === 0], ['↓', 'Descendre', function () { move(i, 1); }, i === blocks.length - 1],
         ['⧉', 'Dupliquer', function () { dup(i); }, false], ['✕', 'Supprimer', function () { del(i); }, false]].forEach(function (a) {
          var bt = el('button', { type: 'button', title: a[1], text: a[0] });
          bt.disabled = a[3];
          bt.addEventListener('click', function (e) { e.stopPropagation(); a[2](); });
          bar.appendChild(bt);
        });
        w.appendChild(bar);
      }
      w.addEventListener('click', function () { sel = i; openAt = -1; renderAll(); });
      card.appendChild(w);
      card.appendChild(inserter(i + 1));
    });
    var mail = el('div', { class: 'mle-mail' }, [
      el('div', { class: 'mle-brand', html: '<span>●</span>&nbsp; Touraine-Ukraine' }), card,
      el('div', { class: 'mle-foot', html: 'Vous recevez ce message de la part de l\'association Touraine-Ukraine.<br><u>Se désinscrire des e-mails de l\'association</u>' })
    ]);
    canvas.appendChild(mail);
    if (!blocks.length) card.insertBefore(el('div', { class: 'mle-empty', text: 'Votre message est vide : touchez le « + » pour ajouter un premier bloc.' }), card.firstChild.nextSibling);
    paintHtml();
  }

  function move(i, d) { var j = i + d; if (j < 0 || j >= blocks.length) return; var t = blocks[i]; blocks[i] = blocks[j]; blocks[j] = t; sel = j; changed(); renderAll(); }
  function dup(i) { var c = tag(JSON.parse(JSON.stringify(blocks[i]))); blocks.splice(i + 1, 0, c); sel = i + 1; changed(); renderAll(); }
  function del(i) { blocks.splice(i, 1); sel = blocks.length ? Math.min(i, blocks.length - 1) : -1; changed(); renderAll(); }

  /* ----- Panneau de réglages ----- */
  function f(lbl, input, hint) {
    var d = el('div', { class: 'mle-f' }, [el('label', { text: lbl }), input]);
    if (hint) d.appendChild(el('small', { text: hint }));
    return d;
  }
  function text(b, key, ph, max) {
    var i = el('input', { type: 'text', class: 'tu-input', maxlength: max || 190, placeholder: ph || '' });
    i.value = b[key] || '';
    i.addEventListener('input', function () { b[key] = i.value; changed(); });
    return i;
  }
  function area(b, key, rows, fmt) {
    var t = el('textarea', { class: 'tu-input', rows: rows || 6 });
    t.value = b[key] || '';
    t.addEventListener('input', function () { b[key] = t.value; changed(); });
    if (!fmt) return t;
    var wrap = el('div');
    var bar = el('div', { class: 'mle-fmt' });
    [['G', '**', 'Gras'], ['I', '*', 'Italique']].forEach(function (x) {
      var bt = el('button', { type: 'button', title: x[2], html: x[0] === 'G' ? '<b>G</b>' : '<i>I</i>' });
      bt.addEventListener('click', function () {
        var s = t.selectionStart, e = t.selectionEnd, v = t.value;
        t.value = v.slice(0, s) + x[1] + v.slice(s, e) + x[1] + v.slice(e);
        t.focus(); t.setSelectionRange(s + x[1].length, e + x[1].length);
        b[key] = t.value; changed();
      });
      bar.appendChild(bt);
    });
    wrap.appendChild(bar); wrap.appendChild(t);
    return wrap;
  }
  function check(b, key, lbl) {
    var c = el('input', { type: 'checkbox' }); c.checked = !!b[key];
    c.addEventListener('change', function () { b[key] = c.checked; changed(); });
    return el('label', { class: 'mle-chk' }, [c, document.createTextNode(lbl)]);
  }

  function renderPanel() {
    panel.innerHTML = '';
    if (sel < 0 || !blocks[sel]) {
      panel.appendChild(el('div', { class: 'mle-panel-h', text: 'Réglages du bloc' }));
      panel.appendChild(el('div', { class: 'mle-panel-b', html: '<span style="color:var(--tu-ink-300)">Cliquez sur un bloc pour le régler, ou sur le « + » pour en ajouter un. Les images sont allégées automatiquement.</span>' }));
      return;
    }
    var b = blocks[sel], body = el('div', { class: 'mle-panel-b' });
    panel.appendChild(el('div', { class: 'mle-panel-h', text: label(b.type) }));
    panel.appendChild(body);
    var add = function (n) { body.appendChild(n); };

    switch (b.type) {
      case 'title': add(f('Titre', text(b, 'text', 'Titre de la section'))); break;
      case 'text': add(f('Texte', area(b, 'text', 8, true), 'Une ligne vide sépare deux paragraphes. Les adresses web deviennent des liens.')); break;
      case 'columns':
        add(f('Colonne de gauche', area(b, 'left', 5, false)));
        add(f('Colonne de droite', area(b, 'right', 5, false)), 'Une ligne vide sépare deux paragraphes ; **gras** et *italique* fonctionnent aussi.');
        break;
      case 'quote': add(f('Citation', area(b, 'text', 4, false))); add(f('Auteur (facultatif)', text(b, 'author', '', 120))); break;
      case 'button':
        add(f('Texte du bouton', text(b, 'label', 'Faire un don', 60)));
        add(f('Lien', text(b, 'url', 'https://', 500), 'Adresse complète, commençant par http:// ou https://.'));
        break;
      case 'stats':
        b.items.forEach(function (it, k) {
          var v = el('input', { type: 'text', class: 'tu-input', maxlength: 20, placeholder: '12 t' }); v.value = it.value || '';
          var l = el('input', { type: 'text', class: 'tu-input', maxlength: 60, placeholder: 'envoyées en 2025' }); l.value = it.label || '';
          v.addEventListener('input', function () { it.value = v.value; changed(); });
          l.addEventListener('input', function () { it.label = l.value; changed(); });
          add(f('Chiffre ' + (k + 1), el('div', { class: 'mle-stat' }, [v, l]), k === 2 ? 'Laissez un chiffre vide pour ne pas l\'afficher.' : ''));
        });
        break;
      case 'divider': add(el('span', { text: 'Ligne de séparation entre deux sections. Aucun réglage.' })); break;
      case 'image': imagePanel(b, add); break;
      case 'event': eventPanel(b, add); break;
    }
  }

  function imagePanel(b, add) {
    if (b.file) add(el('img', { class: 'mle-thumb', src: D.imageBase + encodeURIComponent(b.file), alt: '' }));
    var pick = el('input', { type: 'file', accept: 'image/jpeg,image/png,image/webp', hidden: 'hidden' });
    var btn = el('button', { type: 'button', class: 'tu-btn tu-btn-s', text: b.file ? 'Remplacer l\'image' : 'Choisir une image' });
    var msg = el('small', { text: 'JPEG, PNG ou WebP, 10 Mo maximum.' });
    btn.addEventListener('click', function () { pick.click(); });
    pick.addEventListener('change', function () {
      if (!pick.files[0]) return;
      var fd = new FormData(); fd.append('csrf', D.csrf); fd.append('image', pick.files[0]);
      btn.disabled = true; msg.textContent = 'Envoi en cours…';
      fetch(D.uploadUrl, { method: 'POST', body: fd, credentials: 'same-origin' })
        .then(function (r) { return r.json(); })
        .then(function (res) {
          if (res.error) { msg.textContent = res.error; btn.disabled = false; return; }
          b.file = res.file; changed(); renderPanel();
        }).catch(function () { msg.textContent = 'Le téléversement a échoué.'; btn.disabled = false; });
    });
    add(el('div', { class: 'mle-f' }, [btn, pick, msg]));
    add(f('Texte alternatif', text(b, 'alt', 'Description de l\'image'), 'Lu par les lecteurs d\'écran et affiché si l\'image est bloquée.'));
    add(f('Légende (facultatif)', text(b, 'caption')));
    add(f('Lien au clic (facultatif)', text(b, 'url', 'https://', 500)));
  }

  function eventPanel(b, add) {
    var s = el('select', { class: 'tu-input' });
    s.appendChild(el('option', { value: '0', text: 'Choisir un évènement…' }));
    var known = false;
    D.events.forEach(function (ev) {
      var o = el('option', { value: String(ev.id), text: ev.when + ' · ' + ev.title });
      if (ev.id === b.event_id) { o.selected = true; known = true; }
      s.appendChild(o);
    });
    if (b.event_id && !known) { var o2 = el('option', { value: String(b.event_id), text: 'Évènement #' + b.event_id }); o2.selected = true; s.appendChild(o2); }
    s.addEventListener('change', function () { b.event_id = parseInt(s.value, 10) || 0; changed(); });
    add(f('Évènement du planning', s, 'Titre, date et description viennent du planning au moment de l\'enregistrement du brouillon.'));
    add(check(b, 'show_time', 'Afficher les horaires'));
    add(check(b, 'show_desc', 'Afficher la description'));
    add(f('Texte du bouton (facultatif)', text(b, 'label', 'En savoir plus', 60)));
    add(f('Lien du bouton', text(b, 'url', 'https://', 500), 'Aucun bouton sans texte et sans lien. Le planning étant réservé aux bénévoles déclarés, ne l\'indiquez que pour eux.'));
  }

  function renderAll() { renderCanvas(); renderPanel(); }

  document.addEventListener('click', function () { if (openAt !== -1) { openAt = -1; renderCanvas(); } });
  sync(); renderAll();
  if (blocks.length) refresh();
})();
