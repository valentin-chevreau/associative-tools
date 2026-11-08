/* tu_confirm.js — modale de confirmation commune (remplace window.confirm).
 *
 * JS :   tuConfirm('Message').then(ok => { if (ok) … })
 *        tuConfirm({title, message, confirmLabel, cancelLabel, tone:'danger'|'default'})
 * HTML : <form data-confirm="Supprimer ?"> · <button data-confirm="…"> · <a data-confirm="…">
 *        options : data-confirm-title, data-confirm-label, data-confirm-cancel,
 *                  data-confirm-tone="danger|default" (danger déduit des mots « supprimer… »).
 */
(function () {
  'use strict';
  if (window.tuConfirm) return;

  // Feuille de style chargée à côté du script (aucune dépendance au layout de la page).
  try {
    var cs = document.currentScript;
    if (cs && cs.src && !document.querySelector('link[data-tu-cf]')) {
      var lk = document.createElement('link');
      lk.rel = 'stylesheet';
      lk.href = cs.src.replace('/js/tu_confirm.js', '/css/tu_confirm.css');
      lk.setAttribute('data-tu-cf', '1');
      document.head.appendChild(lk);
    }
  } catch (e) {}

  var DANGER_RE = /supprim|effac|retir|annul|d[ée]finitiv|irr[ée]versible|d[ée]sactiv|r[ée]initialis|vider|purge|rejet/i;
  var chain = Promise.resolve();
  var uid = 0;

  function el(tag, cls, text) {
    var n = document.createElement(tag);
    if (cls) n.className = cls;
    if (text != null) n.textContent = text;
    return n;
  }

  function show(o) {
    return new Promise(function (resolve) {
      var id = 'tucf' + (++uid);
      var danger = o.tone ? o.tone === 'danger' : DANGER_RE.test(o.message + ' ' + (o.title || ''));
      var prev = document.activeElement;

      var overlay = el('div', 'tu-cf-overlay');
      var box = el('div', 'tu-cf-box' + (danger ? ' is-danger' : ''));
      box.setAttribute('role', 'alertdialog');
      box.setAttribute('aria-modal', 'true');
      box.setAttribute('aria-labelledby', id + 't');
      box.setAttribute('aria-describedby', id + 'm');
      var title = el('h2', 'tu-cf-title', o.title || (o.info ? (danger ? 'Erreur' : 'Information') : (danger ? 'Confirmer la suppression' : 'Confirmation')));
      title.id = id + 't';
      var msg = el('p', 'tu-cf-msg', o.message);
      msg.id = id + 'm';
      var actions = el('div', 'tu-cf-actions');
      var cancel = el('button', 'tu-cf-btn tu-cf-cancel', o.cancelLabel || 'Annuler');
      cancel.type = 'button';
      var ok = el('button', 'tu-cf-btn tu-cf-ok', o.confirmLabel || (danger ? 'Supprimer' : 'Confirmer'));
      ok.type = 'button';
      if (o.info) { ok.textContent = o.confirmLabel || 'OK'; } else { actions.appendChild(cancel); }
      actions.appendChild(ok);
      box.appendChild(title);
      box.appendChild(msg);
      box.appendChild(actions);
      overlay.appendChild(box);

      var done = false;
      function close(result) {
        if (done) return;
        done = true;
        document.removeEventListener('keydown', onKey, true);
        overlay.classList.remove('is-open');
        setTimeout(function () { if (overlay.parentNode) overlay.parentNode.removeChild(overlay); }, 140);
        document.documentElement.classList.remove('tu-cf-lock');
        if (prev && prev.focus) { try { prev.focus(); } catch (e) {} }
        resolve(result);
      }
      function onKey(e) {
        if (e.key === 'Escape') { e.preventDefault(); e.stopPropagation(); close(false); }
        else if (e.key === 'Tab') {
          var f = o.info ? [ok] : [cancel, ok];
          var i = f.indexOf(document.activeElement);
          e.preventDefault();
          f[(i + (e.shiftKey ? f.length - 1 : 1)) % f.length].focus();
        }
      }
      cancel.addEventListener('click', function () { close(false); });
      ok.addEventListener('click', function () { close(true); });
      overlay.addEventListener('mousedown', function (e) { if (e.target === overlay) close(false); });
      document.addEventListener('keydown', onKey, true);

      document.body.appendChild(overlay);
      document.documentElement.classList.add('tu-cf-lock');
      requestAnimationFrame(function () {
        overlay.classList.add('is-open');
        (danger && !o.info ? cancel : ok).focus();
      });
    });
  }

  window.tuConfirm = function (o) {
    if (typeof o === 'string') o = { message: o };
    o = o || {};
    o.message = String(o.message == null ? '' : o.message);
    var p = chain.then(function () { return show(o); });
    chain = p.then(function () {}, function () {});
    return p;
  };

  // Remplace alert() : message d'information avec un seul bouton. tone:'danger' pour une erreur.
  window.tuAlert = function (o) {
    if (typeof o === 'string') o = { message: o };
    o = o || {};
    o.info = true;
    return window.tuConfirm(o).then(function () {});
  };

  function optsOf(node) {
    var d = node.dataset;
    return {
      message: d.confirm,
      title: d.confirmTitle,
      confirmLabel: d.confirmLabel,
      cancelLabel: d.confirmCancel,
      tone: d.confirmTone
    };
  }

  // Formulaires (et boutons d'envoi) : on bloque l'envoi, puis on le rejoue après accord.
  document.addEventListener('submit', function (e) {
    var form = e.target;
    if (!form || form.nodeType !== 1) return;
    if (form.__tuOk) { form.__tuOk = false; return; }
    var src = (e.submitter && e.submitter.dataset && e.submitter.dataset.confirm) ? e.submitter
            : (form.dataset && form.dataset.confirm ? form : null);
    if (!src) return;
    e.preventDefault();
    e.stopImmediatePropagation();
    var submitter = e.submitter || null;
    window.tuConfirm(optsOf(src)).then(function (yes) {
      if (!yes) return;
      form.__tuOk = true;
      if (form.requestSubmit) { submitter ? form.requestSubmit(submitter) : form.requestSubmit(); }
      else { form.submit(); }
    });
  }, true);

  // Liens et boutons hors formulaire (type=button, <a>) : on rejoue le clic après accord.
  document.addEventListener('click', function (e) {
    var t = e.target && e.target.closest ? e.target.closest('[data-confirm]') : null;
    if (!t || t.tagName === 'FORM') return;
    if (t.__tuOk) { t.__tuOk = false; return; }
    var isSubmit = (t.tagName === 'BUTTON' && (t.type || 'submit') === 'submit' && t.form)
                || (t.tagName === 'INPUT' && t.type === 'submit' && t.form);
    if (isSubmit) return; // géré par l'évènement submit
    e.preventDefault();
    e.stopImmediatePropagation();
    window.tuConfirm(optsOf(t)).then(function (yes) {
      if (!yes) return;
      t.__tuOk = true;
      t.click();
    });
  }, true);
})();
