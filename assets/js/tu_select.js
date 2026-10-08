/*
 * Liste déroulante de la Suite (sans dépendance) — remplace le rendu natif des <select>.
 * - S'applique aux <select class="tu-input"> de 8 options ou plus (champ de recherche inclus)
 *   et à tout <select> portant la classe .tu-select-enhance ou l'attribut data-tu-select.
 * - Le <select> d'origine reste dans le DOM (invisible) : il porte la valeur envoyée,
 *   les attributs required/disabled et reçoit un évènement « change » à chaque choix.
 * - Recherche insensible à la casse et aux accents ; ↑ ↓ Entrée Échap au clavier.
 */
(function () {
  'use strict';

  var SEARCH_FROM = 8; // nombre d'options à partir duquel le champ de recherche apparaît
  var openState = null;

  var norm = function (s) {
    return String(s).normalize('NFD').replace(/[̀-ͯ]/g, '').toLowerCase();
  };

  function enhance(select) {
    if (select.dataset.tuSelectDone || select.multiple || select.size > 1) return;
    var many = select.options.length >= SEARCH_FROM;
    if (!(many || select.classList.contains('tu-select-enhance') || select.hasAttribute('data-tu-select'))) return;
    select.dataset.tuSelectDone = '1';

    var wrap = select.closest('.tu-select-wrap');
    if (!wrap) {
      wrap = document.createElement('div');
      wrap.className = 'tu-select-wrap';
      select.parentNode.insertBefore(wrap, select);
      wrap.appendChild(select);
    }
    select.classList.add('tu-select-native');
    select.tabIndex = -1;

    var trigger = document.createElement('div');
    trigger.className = 'tu-input tu-select-trigger';
    trigger.tabIndex = 0;
    trigger.setAttribute('role', 'combobox');
    trigger.setAttribute('aria-haspopup', 'listbox');
    trigger.innerHTML = '<span class="tu-select-label"></span>'
      + '<svg class="tu-select-chevron" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="6 9 12 15 18 9"></polyline></svg>';
    wrap.appendChild(trigger);
    var label = trigger.querySelector('.tu-select-label');

    var panel = document.createElement('div');
    panel.className = 'tu-select-panel';
    var search = null;
    if (many) {
      search = document.createElement('input');
      search.type = 'text';
      search.className = 'tu-select-search';
      search.placeholder = 'Rechercher…';
      search.autocomplete = 'off';
      panel.appendChild(search);
    }
    var list = document.createElement('div');
    list.className = 'tu-select-list';
    list.setAttribute('role', 'listbox');
    panel.appendChild(list);
    document.body.appendChild(panel);

    var rows = [];
    var focused = -1;

    function sync() {
      var opt = select.options[select.selectedIndex];
      label.textContent = opt ? opt.text : '';
      label.classList.toggle('placeholder', !opt || opt.value === '');
      trigger.classList.toggle('disabled', select.disabled);
      rows.forEach(function (r) { r.el.classList.toggle('selected', r.opt.value === select.value); });
    }

    function build() {
      list.innerHTML = '';
      rows = Array.from(select.options).map(function (opt) {
        var el = document.createElement('div');
        el.className = 'tu-select-opt' + (opt.value === select.value ? ' selected' : '');
        el.setAttribute('role', 'option');
        el.innerHTML = '<span></span><span class="tu-select-check">✓</span>';
        el.firstChild.textContent = opt.text;
        if (opt.disabled) el.classList.add('is-disabled');
        el.addEventListener('mousedown', function (e) {
          e.preventDefault();
          if (!opt.disabled) choose(opt);
        });
        list.appendChild(el);
        return { opt: opt, el: el, key: norm(opt.text) };
      });
    }

    function choose(opt) {
      var changed = select.value !== opt.value;
      select.value = opt.value;
      sync();
      close();
      trigger.focus();
      if (changed) select.dispatchEvent(new Event('change', { bubbles: true }));
    }

    function setFocus(i, scroll) {
      var vis = rows.filter(function (r) { return !r.el.hidden && !r.opt.disabled; });
      if (!vis.length) { focused = -1; return; }
      i = Math.max(0, Math.min(vis.length - 1, i));
      rows.forEach(function (r) { r.el.classList.remove('focused'); });
      vis[i].el.classList.add('focused');
      focused = i;
      if (scroll !== false) vis[i].el.scrollIntoView({ block: 'nearest' });
    }

    function filter() {
      var q = norm(search ? search.value.trim() : '');
      var any = false;
      rows.forEach(function (r) {
        var show = !q || r.key.indexOf(q) !== -1;
        r.el.hidden = !show;
        if (show) any = true;
      });
      var empty = list.querySelector('.tu-select-empty');
      if (!any && !empty) {
        empty = document.createElement('div');
        empty.className = 'tu-select-empty';
        empty.textContent = 'Aucun résultat';
        list.appendChild(empty);
      } else if (any && empty) empty.remove();
      setFocus(0);
    }

    function reposition() {
      var r = trigger.getBoundingClientRect(), m = 8;
      var below = window.innerHeight - r.bottom - m, above = r.top - m;
      var up = below < 200 && above > below;
      panel.style.left = Math.round(r.left) + 'px';
      panel.style.width = Math.round(Math.max(r.width, 220)) + 'px';
      if (up) {
        panel.style.top = ''; panel.style.bottom = Math.round(window.innerHeight - r.top + 4) + 'px';
        list.style.maxHeight = Math.max(120, Math.min(280, above - (search ? 50 : 0))) + 'px';
      } else {
        panel.style.bottom = ''; panel.style.top = Math.round(r.bottom + 4) + 'px';
        list.style.maxHeight = Math.max(120, Math.min(280, below - (search ? 50 : 0))) + 'px';
      }
    }

    function open() {
      if (select.disabled) return;
      if (openState) openState.close();
      build();
      wrap.classList.add('open');
      panel.classList.add('open');
      if (search) { search.value = ''; }
      reposition();
      filter();
      var sel = rows.findIndex(function (r) { return r.opt.value === select.value; });
      var vis = rows.filter(function (r) { return !r.el.hidden && !r.opt.disabled; });
      var idx = vis.indexOf(rows[sel]);
      setFocus(idx >= 0 ? idx : 0);
      if (search) search.focus({ preventScroll: true });
      openState = { close: close };
    }
    function close() {
      wrap.classList.remove('open');
      panel.classList.remove('open');
      if (openState && openState.close === close) openState = null;
    }
    function isOpen() { return panel.classList.contains('open'); }

    function key(e) {
      var vis = rows.filter(function (r) { return !r.el.hidden && !r.opt.disabled; });
      if (e.key === 'ArrowDown') { e.preventDefault(); setFocus(focused + 1); }
      else if (e.key === 'ArrowUp') { e.preventDefault(); setFocus(focused - 1); }
      else if (e.key === 'Enter') { e.preventDefault(); if (vis[focused]) choose(vis[focused].opt); }
      else if (e.key === 'Escape') { e.preventDefault(); close(); trigger.focus(); }
      else if (e.key === 'Tab') close();
    }

    trigger.addEventListener('click', function () { isOpen() ? close() : open(); });
    trigger.addEventListener('keydown', function (e) {
      if (isOpen()) { if (!search) key(e); return; }
      if (e.key === 'Enter' || e.key === ' ' || e.key === 'ArrowDown') { e.preventDefault(); open(); }
    });
    panel.addEventListener('keydown', key);
    if (search) search.addEventListener('input', filter);
    document.addEventListener('mousedown', function (e) {
      if (isOpen() && !panel.contains(e.target) && !trigger.contains(e.target)) close();
    });
    window.addEventListener('resize', function () { if (isOpen()) close(); });
    window.addEventListener('scroll', function (e) { if (isOpen() && !panel.contains(e.target)) close(); }, true);
    if (select.form) select.form.addEventListener('reset', function () { setTimeout(sync, 0); });
    select.addEventListener('change', sync);

    select._syncCustomSelect = sync;
    build();
    sync();
  }

  function scan(root) { (root || document).querySelectorAll('select').forEach(enhance); }

  function start() {
    scan(document);
    new MutationObserver(function (muts) {
      muts.forEach(function (m) {
        m.addedNodes.forEach(function (n) {
          if (n.nodeType !== 1) return;
          if (n.tagName === 'SELECT') enhance(n); else if (n.querySelectorAll) scan(n);
        });
      });
    }).observe(document.body, { childList: true, subtree: true });
  }

  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', start); else start();
})();
