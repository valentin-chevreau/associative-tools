/*
 * Saisie des numéros de téléphone : regroupement automatique par 2 chiffres pendant la frappe.
 * S'applique à tous les <input type="tel"> (ou [data-tu-phone]).
 *   0612345678     -> 06 12 34 56 78
 *   +33612345678   -> +33 6 12 34 56 78   (le « 0 » après +33 est ignoré)
 *   0033612345678  -> +33 6 12 34 56 78
 *   +380501234567  -> +380 50 123 45 67   (Ukraine)
 *   autre indicatif -> +XX XX XX … (par 2)
 * Même règle que format_phone() côté PHP (shared/bootstrap.php) : saisie et affichage restent identiques.
 */
(function () {
  'use strict';

  var MAX_DIGITS = 15;

  function format(raw) {
    var plus = /^\s*\+/.test(raw);
    var d = String(raw).replace(/\D+/g, '');
    if (!plus && d.indexOf('00') === 0) { plus = true; d = d.slice(2); }
    d = d.slice(0, MAX_DIGITS);
    if (plus && d.indexOf('33') === 0) {
      var rest = d.slice(2);
      if (rest.charAt(0) === '0') rest = rest.slice(1);
      var out = '+33';
      if (rest.length) {
        out += ' ' + rest.charAt(0);
        var tail = rest.slice(1);
        if (tail) out += ' ' + tail.match(/.{1,2}/g).join(' ');
      }
      return out;
    }
    if (plus && d.indexOf('380') === 0) {
      var r = d.slice(3), o = '+380';
      if (r.length) {
        o += ' ' + r.slice(0, 2);
        var t = r.slice(2);
        if (t) {
          o += ' ' + t.slice(0, 3);
          var e = t.slice(3);
          if (e) o += ' ' + e.match(/.{1,2}/g).join(' ');
        }
      }
      return o;
    }
    var groups = d.match(/.{1,2}/g) || [];
    return (plus ? '+' : '') + groups.join(' ');
  }

  function digitsBefore(str, pos) {
    return str.slice(0, pos).replace(/\D+/g, '').length;
  }

  function caretFor(str, digitCount) {
    if (digitCount <= 0) return /^\+/.test(str) ? 1 : 0;
    var seen = 0;
    for (var i = 0; i < str.length; i++) {
      if (/\d/.test(str.charAt(i))) { seen++; if (seen === digitCount) return i + 1; }
    }
    return str.length;
  }

  function onInput(e) {
    var el = e.target;
    if (e.inputType && e.inputType.indexOf('delete') === 0 && el.selectionStart === el.value.length) {
      // suppression en fin de saisie : on reformate sans toucher au curseur
      el.value = format(el.value);
      return;
    }
    var before = digitsBefore(el.value, el.selectionStart);
    var formatted = format(el.value);
    if (formatted === el.value) return;
    el.value = formatted;
    var pos = caretFor(formatted, before);
    try { el.setSelectionRange(pos, pos); } catch (err) { /* type=tel : supporté, sinon sans effet */ }
  }

  function bind(el) {
    if (el.dataset.tuPhoneDone) return;
    el.dataset.tuPhoneDone = '1';
    el.setAttribute('inputmode', 'tel');
    if (!el.getAttribute('autocomplete')) el.setAttribute('autocomplete', 'tel');
    if (el.value) el.value = format(el.value);
    el.addEventListener('input', onInput);
  }

  function init() {
    document.querySelectorAll('input[type="tel"], input[data-tu-phone]').forEach(bind);
  }

  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init); else init();
  // champs ajoutés dynamiquement (fenêtres modales, etc.)
  new MutationObserver(init).observe(document.documentElement, { childList: true, subtree: true });
})();
