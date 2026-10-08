/*
 * Liste déroulante de la Suite (sans dépendance) — remplace le rendu natif des <select>.
 * - S'applique à tous les <select class="tu-input"> (ou .tu-select-enhance / data-tu-select) ;
 *   le champ de recherche apparaît à partir de 8 options. Les filtres compacts (.tu-fsel) restent natifs.
 * - Le <select> d'origine reste dans le DOM (invisible) : il porte la valeur envoyée,
 *   les attributs required/disabled et reçoit un évènement « change » à chaque choix.
 * - Recherche insensible à la casse et aux accents ; ↑ ↓ Entrée Échap au clavier.
 * - <select multiple class="tu-input"> : sélection multiple sous forme de pastilles (chips).
 *     data-placeholder="…"   texte quand rien n'est choisi
 *     data-ordered           l'ordre de sélection compte (numérotation + boutons ◂ ▸) ; data-order="3,1,2" = ordre initial
 *     data-bulk              liens « Tout » / « Aucun » dans la liste
 *     name="x[]"             la valeur est postée dans l'ordre des pastilles (champs cachés générés)
 *     option data-img="url"  petite vignette dans la liste et sur la pastille
 *   API : select._tuMulti.values() / .set([...]) ; évènement « change » à chaque modification.
 */
(function () {
  'use strict';

  var SEARCH_FROM = 8; // nombre d'options à partir duquel le champ de recherche apparaît
  var openState = null;

  var norm = function (s) {
    return String(s).normalize('NFD').replace(/[̀-ͯ]/g, '').toLowerCase();
  };

  function enhance(select) {
    if (select.dataset.tuSelectDone) return;
    if (select.multiple) { if (select.classList.contains('tu-input') || select.hasAttribute('data-tu-multi')) enhanceMulti(select); return; }
    if (select.size > 1) return;
    var many = select.options.length >= SEARCH_FROM;
    if (!(select.classList.contains('tu-input') || select.classList.contains('tu-select-enhance') || select.hasAttribute('data-tu-select'))) return;
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
    trigger.style.cssText = select.style.cssText.replace(/(^|;)\s*(width|min-width|max-width|flex)[^;]*/g, '');
    if (select.style.width) { wrap.style.width = select.style.width; wrap.style.display = 'inline-block'; }
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


  function enhanceMulti(select) {
    select.dataset.tuSelectDone = '1';
    var ordered = select.hasAttribute('data-ordered');
    var bulk = select.hasAttribute('data-bulk');
    var placeholder = select.getAttribute('data-placeholder') || 'Sélectionner…';
    var fieldName = select.getAttribute('name') || '';
    if (fieldName) select.removeAttribute('name'); // les valeurs sont postées par les champs cachés ci-dessous, dans l'ordre des pastilles

    var wrap = select.closest('.tu-select-wrap');
    if (!wrap) {
      wrap = document.createElement('div');
      wrap.className = 'tu-select-wrap';
      select.parentNode.insertBefore(wrap, select);
      wrap.appendChild(select);
    }
    select.classList.add('tu-select-native');
    select.tabIndex = -1;

    var opts = Array.from(select.options).filter(function (o) { return o.value !== ''; });
    var byValue = {};
    opts.forEach(function (o) { byValue[o.value] = o; });

    // Ordre initial : data-order puis le reste des options sélectionnées dans l'ordre du DOM
    var order = [];
    (select.getAttribute('data-order') || '').split(',').forEach(function (v) {
      v = v.trim(); if (v && byValue[v] && byValue[v].selected && order.indexOf(v) === -1) order.push(v);
    });
    opts.forEach(function (o) { if (o.selected && order.indexOf(o.value) === -1) order.push(o.value); });

    var trigger = document.createElement('div');
    trigger.className = 'tu-input tu-select-trigger tu-multi-trigger';
    trigger.tabIndex = 0;
    trigger.setAttribute('role', 'combobox');
    trigger.setAttribute('aria-haspopup', 'listbox');
    var chipsEl = document.createElement('div');
    chipsEl.className = 'tu-multi-chips';
    trigger.appendChild(chipsEl);
    trigger.insertAdjacentHTML('beforeend', '<svg class="tu-select-chevron" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="6 9 12 15 18 9"></polyline></svg>');
    wrap.appendChild(trigger);

    var hidden = document.createElement('div');
    hidden.hidden = true;
    wrap.appendChild(hidden);

    var panel = document.createElement('div');
    panel.className = 'tu-select-panel';
    var search = document.createElement('input');
    search.type = 'text'; search.className = 'tu-select-search'; search.placeholder = 'Rechercher…'; search.autocomplete = 'off';
    panel.appendChild(search);
    var list = document.createElement('div');
    list.className = 'tu-select-list';
    panel.appendChild(list);
    var foot = document.createElement('div');
    foot.className = 'tu-multi-foot';
    foot.innerHTML = '<span class="tu-multi-count"></span>' + (bulk ? '<span><button type="button" class="tu-btn-link" data-all>Tout</button> · <button type="button" class="tu-btn-link" data-none>Aucun</button></span>' : '');
    panel.appendChild(foot);
    document.body.appendChild(panel);

    var rows = [];
    var focused = -1;

    function labelOf(o) { return o.text; }

    function syncNative() {
      opts.forEach(function (o) { o.selected = order.indexOf(o.value) !== -1; });
      hidden.innerHTML = '';
      if (fieldName) {
        order.forEach(function (v) {
          var i = document.createElement('input');
          i.type = 'hidden'; i.name = fieldName; i.value = v;
          hidden.appendChild(i);
        });
      }
    }

    function renderChips() {
      chipsEl.innerHTML = '';
      if (!order.length) {
        var ph = document.createElement('span');
        ph.className = 'tu-multi-ph'; ph.textContent = placeholder;
        chipsEl.appendChild(ph);
      }
      order.forEach(function (v, idx) {
        var o = byValue[v];
        var chip = document.createElement('span');
        chip.className = 'tu-chip';
        var html = '';
        if (ordered) html += '<b class="tu-chip-n">' + (idx + 1) + '</b>';
        if (o.dataset.img) html += '<img src="" alt="">';
        html += '<span class="tu-chip-t"></span>';
        if (ordered && order.length > 1) {
          html += '<button type="button" class="tu-chip-mv" data-mv="-1" aria-label="Avancer"' + (idx === 0 ? ' disabled' : '') + '>◂</button>'
               + '<button type="button" class="tu-chip-mv" data-mv="1" aria-label="Reculer"' + (idx === order.length - 1 ? ' disabled' : '') + '>▸</button>';
        }
        html += '<button type="button" class="tu-chip-x" aria-label="Retirer">×</button>';
        chip.innerHTML = html;
        chip.querySelector('.tu-chip-t').textContent = labelOf(o);
        var img = chip.querySelector('img');
        if (img) img.src = o.dataset.img;
        chip.addEventListener('click', function (e) {
          var b = e.target.closest('button');
          if (!b) return;
          e.stopPropagation();
          if (b.classList.contains('tu-chip-x')) { remove(v); }
          else {
            var j = idx + (+b.dataset.mv);
            if (j >= 0 && j < order.length) { order.splice(idx, 1); order.splice(j, 0, v); changed(); }
          }
        });
        chipsEl.appendChild(chip);
      });
    }

    function renderRows() {
      list.innerHTML = '';
      rows = opts.map(function (o) {
        var el = document.createElement('div');
        el.className = 'tu-select-opt tu-multi-opt' + (order.indexOf(o.value) !== -1 ? ' selected' : '');
        el.setAttribute('role', 'option');
        el.innerHTML = (o.dataset.img ? '<img class="tu-opt-img" src="" alt="">' : '') + '<span class="tu-opt-t"></span><span class="tu-select-check">✓</span>';
        el.querySelector('.tu-opt-t').textContent = labelOf(o);
        var im = el.querySelector('img'); if (im) im.src = o.dataset.img;
        if (o.disabled) el.classList.add('is-disabled');
        el.addEventListener('mousedown', function (e) { e.preventDefault(); if (!o.disabled) toggle(o.value); });
        list.appendChild(el);
        return { opt: o, el: el, key: norm(o.text) };
      });
      updateFoot();
    }

    function updateFoot() {
      foot.querySelector('.tu-multi-count').textContent = order.length + ' sélectionné' + (order.length > 1 ? 's' : '');
    }

    function refresh() {
      syncNative(); renderChips();
      rows.forEach(function (r) { r.el.classList.toggle('selected', order.indexOf(r.opt.value) !== -1); });
      updateFoot();
      if (isOpen()) reposition();
    }
    function changed() {
      refresh();
      select.dispatchEvent(new Event('change', { bubbles: true }));
    }
    function toggle(v) {
      var i = order.indexOf(v);
      if (i === -1) order.push(v); else order.splice(i, 1);
      changed();
    }
    function remove(v) {
      var i = order.indexOf(v);
      if (i !== -1) { order.splice(i, 1); changed(); }
    }

    function visible() { return rows.filter(function (r) { return !r.el.hidden && !r.opt.disabled; }); }
    function setFocus(i) {
      var vis = visible();
      rows.forEach(function (r) { r.el.classList.remove('focused'); });
      if (!vis.length) { focused = -1; return; }
      focused = Math.max(0, Math.min(vis.length - 1, i));
      vis[focused].el.classList.add('focused');
      vis[focused].el.scrollIntoView({ block: 'nearest' });
    }
    function filter() {
      var q = norm(search.value.trim()), any = false;
      rows.forEach(function (r) { var show = !q || r.key.indexOf(q) !== -1; r.el.hidden = !show; if (show) any = true; });
      var empty = list.querySelector('.tu-select-empty');
      if (!any && !empty) { empty = document.createElement('div'); empty.className = 'tu-select-empty'; empty.textContent = 'Aucun résultat'; list.appendChild(empty); }
      else if (any && empty) empty.remove();
      setFocus(0);
    }

    function reposition() {
      var r = trigger.getBoundingClientRect(), m = 8;
      var below = window.innerHeight - r.bottom - m, above = r.top - m;
      var up = below < 240 && above > below;
      panel.style.left = Math.round(r.left) + 'px';
      panel.style.width = Math.round(Math.max(r.width, 240)) + 'px';
      var room = (up ? above : below) - 100;
      list.style.maxHeight = Math.max(120, Math.min(280, room)) + 'px';
      if (up) { panel.style.top = ''; panel.style.bottom = Math.round(window.innerHeight - r.top + 4) + 'px'; }
      else { panel.style.bottom = ''; panel.style.top = Math.round(r.bottom + 4) + 'px'; }
    }
    function isOpen() { return panel.classList.contains('open'); }
    function open() {
      if (openState) openState.close();
      renderRows();
      wrap.classList.add('open'); panel.classList.add('open');
      search.value = '';
      reposition(); filter();
      search.focus({ preventScroll: true });
      openState = { close: close };
    }
    function close() {
      wrap.classList.remove('open'); panel.classList.remove('open');
      if (openState && openState.close === close) openState = null;
    }

    trigger.addEventListener('click', function (e) {
      if (e.target.closest('.tu-chip button')) return;
      isOpen() ? close() : open();
    });
    trigger.addEventListener('keydown', function (e) {
      if (e.target !== trigger) return;
      if (e.key === 'Enter' || e.key === ' ' || e.key === 'ArrowDown') { e.preventDefault(); open(); }
    });
    panel.addEventListener('keydown', function (e) {
      var vis = visible();
      if (e.key === 'ArrowDown') { e.preventDefault(); setFocus(focused + 1); }
      else if (e.key === 'ArrowUp') { e.preventDefault(); setFocus(focused - 1); }
      else if (e.key === 'Enter') { e.preventDefault(); if (vis[focused]) toggle(vis[focused].opt.value); }
      else if (e.key === 'Escape') { e.preventDefault(); close(); trigger.focus(); }
      else if (e.key === 'Backspace' && !search.value && order.length) { remove(order[order.length - 1]); }
      else if (e.key === 'Tab') close();
    });
    search.addEventListener('input', filter);
    if (bulk) foot.addEventListener('click', function (e) {
      if (e.target.matches('[data-all]')) {
        visible().forEach(function (r) { if (order.indexOf(r.opt.value) === -1) order.push(r.opt.value); });
        changed();
      } else if (e.target.matches('[data-none]')) {
        var keep = visible().map(function (r) { return r.opt.value; });
        order = order.filter(function (v) { return keep.indexOf(v) === -1; });
        changed();
      }
    });
    document.addEventListener('mousedown', function (e) {
      if (isOpen() && !panel.contains(e.target) && !trigger.contains(e.target)) close();
    });
    window.addEventListener('resize', function () { if (isOpen()) close(); });
    window.addEventListener('scroll', function (e) { if (isOpen() && !panel.contains(e.target)) close(); }, true);
    if (select.form) select.form.addEventListener('reset', function () {
      setTimeout(function () { order = opts.filter(function (o) { return o.defaultSelected; }).map(function (o) { return o.value; }); refresh(); }, 0);
    });

    select._tuMulti = {
      values: function () { return order.slice(); },
      set: function (vals, silent) {
        order = vals.map(String).filter(function (v, i, a) { return byValue[v] && a.indexOf(v) === i; });
        refresh();
        if (!silent) select.dispatchEvent(new Event('change', { bubbles: true }));
      }
    };
    syncNative(); renderChips(); renderRows();
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
