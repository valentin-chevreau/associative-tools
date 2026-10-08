/*
 * Sélecteur de date / date-heure de la Suite (sans dépendance).
 * Remplace l'affichage des <input type="date"> et <input type="datetime-local"> :
 * l'input d'origine reste dans le DOM (type="hidden") avec le même id, name et la même valeur
 * ISO ("YYYY-MM-DD" ou "YYYY-MM-DDTHH:MM"), donc formulaires et scripts existants sont inchangés.
 * Lire/écrire input.value fonctionne ; un évènement « change » est émis à chaque modification.
 */
(function () {
  'use strict';

  var MONTHS = ['janvier', 'février', 'mars', 'avril', 'mai', 'juin', 'juillet', 'août', 'septembre', 'octobre', 'novembre', 'décembre'];
  var DAYS = ['L', 'M', 'M', 'J', 'V', 'S', 'D'];
  var QUICK = ['08:00', '09:00', '10:00', '12:00', '14:00', '16:00', '18:00', '20:00'];
  var pad = function (n) { return String(n).padStart(2, '0'); };
  var valueDesc = Object.getOwnPropertyDescriptor(HTMLInputElement.prototype, 'value');

  var openPicker = null; // { close() }

  function parse(v) {
    var m = /^(\d{4})-(\d{2})-(\d{2})(?:[T ](\d{2}):(\d{2}))?/.exec(v || '');
    if (!m) return null;
    return { y: +m[1], mo: +m[2] - 1, d: +m[3], h: m[4] ? +m[4] : 9, mi: m[5] ? +m[5] : 0 };
  }

  function upgrade(input) {
    if (input.dataset.tuPicker) return;
    var withTime = input.type === 'datetime-local';
    var native = input.value;
    input.dataset.tuPicker = '1';

    var display = document.createElement('input');
    display.type = 'text';
    display.inputMode = 'numeric';
    display.maxLength = withTime ? 16 : 10;
    display.autocomplete = 'off';
    display.className = input.className + ' tu-dp-display';
    display.style.cssText = input.style.cssText;
    display.placeholder = withTime ? 'jj/mm/aaaa hh:mm' : 'jj/mm/aaaa';
    if (input.disabled) display.disabled = true;
    if (input.required) display.required = true;

    input.type = 'hidden';
    input.parentNode.insertBefore(display, input);

    function fmt(v) {
      var p = parse(v);
      if (!p) return '';
      var s = pad(p.d) + '/' + pad(p.mo + 1) + '/' + p.y;
      return withTime ? s + ' ' + pad(p.h) + ':' + pad(p.mi) : s;
    }
    function iso(p) {
      var s = p.y + '-' + pad(p.mo + 1) + '-' + pad(p.d);
      return withTime ? s + 'T' + pad(p.h) + ':' + pad(p.mi) : s;
    }
    function sync() { display.value = fmt(valueDesc.get.call(input)); }

    // Les scripts existants font input.value = '…' : on garde l'affichage synchronisé.
    Object.defineProperty(input, 'value', {
      get: function () { return valueDesc.get.call(input); },
      set: function (v) { valueDesc.set.call(input, v); sync(); },
      configurable: true
    });
    input.value = native;

    function commit(p) {
      valueDesc.set.call(input, p ? iso(p) : '');
      sync();
      input.dispatchEvent(new Event('input', { bubbles: true }));
      input.dispatchEvent(new Event('change', { bubbles: true }));
    }

    function open() {
      if (display.disabled) return;
      if (openPicker) openPicker.close();

      var cur = parse(input.value);
      var now = new Date();
      var state = cur || { y: now.getFullYear(), mo: now.getMonth(), d: now.getDate(), h: 9, mi: 0 };
      var view = { y: state.y, mo: state.mo };
      var hasValue = !!cur;

      var pop = document.createElement('div');
      pop.className = 'tu-dp';
      pop.setAttribute('role', 'dialog');
      document.body.appendChild(pop);

      function render() {
        var first = new Date(view.y, view.mo, 1);
        var offset = (first.getDay() + 6) % 7; // lundi = 0
        var daysIn = new Date(view.y, view.mo + 1, 0).getDate();
        var h = '<div class="tu-dp-head">'
          + '<button type="button" class="tu-dp-nav" data-nav="-1" aria-label="Mois précédent">‹</button>'
          + '<div class="tu-dp-title">' + MONTHS[view.mo] + ' ' + view.y + '</div>'
          + '<button type="button" class="tu-dp-nav" data-nav="1" aria-label="Mois suivant">›</button></div>'
          + '<div class="tu-dp-grid">';
        DAYS.forEach(function (d) { h += '<span class="tu-dp-dow">' + d + '</span>'; });
        for (var i = 0; i < offset; i++) h += '<span></span>';
        for (var d = 1; d <= daysIn; d++) {
          var cls = 'tu-dp-day';
          if (hasValue && state.y === view.y && state.mo === view.mo && state.d === d) cls += ' on';
          if (now.getFullYear() === view.y && now.getMonth() === view.mo && now.getDate() === d) cls += ' today';
          if ((offset + d - 1) % 7 >= 5) cls += ' we';
          h += '<button type="button" class="' + cls + '" data-day="' + d + '">' + d + '</button>';
        }
        h += '</div>';
        if (withTime) {
          h += '<div class="tu-dp-time"><div class="tu-dp-timerow"><span>Heure</span>'
            + '<button type="button" class="tu-dp-step" data-step="-15" aria-label="15 minutes plus tôt">−</button>'
            + '<input type="text" class="tu-dp-timeinput" data-time inputmode="numeric" maxlength="5" autocomplete="off" value="' + pad(state.h) + ':' + pad(state.mi) + '" aria-label="Heure (hh:mm)">'
            + '<button type="button" class="tu-dp-step" data-step="15" aria-label="15 minutes plus tard">+</button></div>'
            + '<div class="tu-dp-chips">';
          QUICK.forEach(function (t) {
            var on = t === pad(state.h) + ':' + pad(state.mi);
            h += '<button type="button" class="tu-dp-chip' + (on ? ' on' : '') + '" data-quick="' + t + '">' + t.replace(':00', ' h').replace(':', ' h ') + '</button>';
          });
          h += '</div></div>';
        }
        h += '<div class="tu-dp-foot">'
          + '<button type="button" class="tu-dp-link" data-act="today">' + (withTime ? 'Maintenant' : 'Aujourd’hui') + '</button>'
          + '<button type="button" class="tu-dp-link" data-act="clear">Effacer</button>'
          + '<button type="button" class="tu-dp-ok" data-act="ok">OK</button></div>';
        pop.innerHTML = h;
      }
      function place() {
        var r = display.getBoundingClientRect();
        var pw = pop.offsetWidth, ph = pop.offsetHeight;
        var left = Math.min(Math.max(8, r.left), window.innerWidth - pw - 8);
        var top = r.bottom + 6;
        if (top + ph > window.innerHeight - 8 && r.top - ph - 6 > 8) top = r.top - ph - 6;
        pop.style.left = left + window.pageXOffset + 'px';
        pop.style.top = top + window.pageYOffset + 'px';
      }

      function close() {
        document.removeEventListener('mousedown', onDoc, true);
        document.removeEventListener('keydown', onKey, true);
        window.removeEventListener('resize', place);
        pop.remove();
        openPicker = null;
      }
      function onDoc(e) { if (!pop.contains(e.target) && e.target !== display) close(); }
      function onKey(e) { if (e.key === 'Escape') { e.stopPropagation(); close(); display.focus(); } }

      pop.addEventListener('click', function (e) {
        var t = e.target.closest('button');
        if (!t) return;
        if (t.dataset.nav) {
          view.mo += +t.dataset.nav;
          if (view.mo < 0) { view.mo = 11; view.y--; }
          if (view.mo > 11) { view.mo = 0; view.y++; }
          render();
        } else if (t.dataset.day) {
          state.y = view.y; state.mo = view.mo; state.d = +t.dataset.day;
          hasValue = true;
          commit(state);
          if (withTime) render(); else close();
        } else if (t.dataset.quick) {
          var q = t.dataset.quick.split(':');
          setTime(+q[0], +q[1]);
        } else if (t.dataset.step) {
          var tot = ((state.h * 60 + state.mi + (+t.dataset.step)) % 1440 + 1440) % 1440;
          setTime(Math.floor(tot / 60), tot % 60);
        } else if (t.dataset.act === 'today') {
          var n = new Date();
          state = { y: n.getFullYear(), mo: n.getMonth(), d: n.getDate(), h: n.getHours(), mi: Math.round(n.getMinutes() / 5) * 5 % 60 };
          view = { y: state.y, mo: state.mo }; hasValue = true;
          commit(state);
          if (withTime) render(); else close();
        } else if (t.dataset.act === 'clear') {
          hasValue = false; commit(null); close();
        } else if (t.dataset.act === 'ok') {
          close();
        }
      });
      function setTime(h, mi) {
        state.h = h; state.mi = mi;
        if (!hasValue) hasValue = true;
        commit(state);
        render();
      }
      function parseTime(txt) {
        var m = /^\s*(\d{1,2})\s*(?:[:hH.]\s*(\d{1,2})?)?\s*$/.exec(txt) || /^\s*(\d{2})(\d{2})\s*$/.exec(txt);
        if (!m) return null;
        var hh = +m[1], mm = m[2] ? +m[2] : 0;
        return (hh > 23 || mm > 59) ? null : { h: hh, mi: mm };
      }
      pop.addEventListener('keydown', function (e) {
        if (!e.target.matches('[data-time]')) return;
        if (e.key === 'Enter') {
          e.preventDefault();
          var t = parseTime(e.target.value);
          if (t) { setTime(t.h, t.mi); close(); }
        }
      });
      pop.addEventListener('change', function (e) {
        if (!e.target.matches('[data-time]')) return;
        var t = parseTime(e.target.value);
        if (t) setTime(t.h, t.mi);
        else e.target.value = pad(state.h) + ':' + pad(state.mi);
      });
      pop.addEventListener('focusin', function (e) { if (e.target.matches('[data-time]')) e.target.select(); });

      render();
      place();
      document.addEventListener('mousedown', onDoc, true);
      document.addEventListener('keydown', onKey, true);
      window.addEventListener('resize', place);
      openPicker = { close: close };
    }

    // ── Saisie au clavier avec masque jj/mm/aaaa [hh:mm] : séparateurs ajoutés automatiquement ──
    var maxDigits = withTime ? 12 : 8;
    function mask(digits, grow) {
      var out = '';
      for (var i = 0; i < digits.length; i++) {
        if (i === 2 || i === 4) out += '/';
        if (i === 8) out += ' ';
        if (i === 10) out += ':';
        out += digits[i];
      }
      // séparateur déjà posé dès que le groupe est complet (uniquement en saisie, pas en effaçant)
      if (grow && [2, 4, 8, 10].indexOf(digits.length) !== -1 && digits.length < maxDigits) {
        out += digits.length === 8 ? ' ' : (digits.length === 10 ? ':' : '/');
      }
      return out;
    }
    function fromDigits(digits) {
      if (digits.length < 8) return null;
      var d = +digits.slice(0, 2), mo = +digits.slice(2, 4) - 1, y = +digits.slice(4, 8);
      var h = digits.length >= 12 ? +digits.slice(8, 10) : null, mi = digits.length >= 12 ? +digits.slice(10, 12) : null;
      var dt = new Date(y, mo, d);
      if (y < 1900 || dt.getFullYear() !== y || dt.getMonth() !== mo || dt.getDate() !== d) return null;
      if (withTime && h !== null && (h > 23 || mi > 59)) return null;
      return { y: y, mo: mo, d: d, h: h, mi: mi };
    }
    var prevLen = 0;
    display.addEventListener('input', function () {
      if (openPicker) openPicker.close();
      var raw = display.value, digits;
      var iso = /^\s*(\d{4})-(\d{2})-(\d{2})(?:[T ](\d{2}):(\d{2}))?/.exec(raw); // collage d'une date ISO
      digits = iso ? iso[3] + iso[2] + iso[1] + (withTime && iso[4] ? iso[4] + iso[5] : '') : raw.replace(/\D/g, '').slice(0, maxDigits);
      display.value = mask(digits, raw.length > prevLen);
      prevLen = display.value.length;
      if (digits.length === 0) { if (input.value) commit(null); return; }
      var p = fromDigits(digits);
      if (p && (!withTime || p.h !== null)) commit(p);
    });
    display.addEventListener('blur', function () {
      var digits = display.value.replace(/\D/g, '');
      var p = fromDigits(digits);
      if (p && withTime && p.h === null) { // date saisie sans heure : on garde l'heure actuelle ou 09:00
        var cur = parse(input.value);
        p.h = cur ? cur.h : 9; p.mi = cur ? cur.mi : 0;
      }
      if (p && (!withTime || p.h !== null)) commit(p);
      else if (digits.length) sync(); // saisie incomplète ou invalide : on revient à la dernière valeur valide
      prevLen = display.value.length;
    });
    display.addEventListener('click', open);
    display.addEventListener('keydown', function (e) {
      if (e.key === 'ArrowDown' && !openPicker) { e.preventDefault(); open(); }
      if (e.key === 'Enter' && openPicker) { e.preventDefault(); openPicker.close(); }
    });
  }

  function scan(root) {
    (root || document).querySelectorAll('input[type="datetime-local"], input[type="date"]').forEach(upgrade);
  }

  function start() {
    scan(document);
    new MutationObserver(function (muts) {
      muts.forEach(function (m) {
        m.addedNodes.forEach(function (n) {
          if (n.nodeType !== 1) return;
          if (n.matches && n.matches('input[type="datetime-local"], input[type="date"]')) upgrade(n);
          else if (n.querySelectorAll) scan(n);
        });
      });
    }).observe(document.body, { childList: true, subtree: true });
  }

  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', start);
  else start();
})();
