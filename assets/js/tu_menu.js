/*
 * Menu « 3 points » de la Suite (sans dépendance).
 * Markup : voir le commentaire « MENU 3 POINTS » dans assets/css/suite_nav.css.
 * - Le menu est positionné en « fixed » : il n'est jamais rogné par un tableau ou une carte (overflow:hidden).
 * - Se ferme au clic extérieur, à Échap, au défilement ou au redimensionnement.
 * - ↑ ↓ naviguent dans les actions, Échap rend le focus au bouton.
 */
(function () {
  'use strict';

  var current = null; // { btn, menu }

  function items(menu) {
    return Array.prototype.slice.call(menu.querySelectorAll('.tu-kebab-item:not([aria-current="true"]):not(:disabled)'));
  }

  function close(returnFocus) {
    if (!current) return;
    current.menu.classList.remove('open');
    current.btn.setAttribute('aria-expanded', 'false');
    if (returnFocus) current.btn.focus();
    current = null;
  }

  function place(btn, menu) {
    menu.style.visibility = 'hidden';
    menu.classList.add('open');
    var r = btn.getBoundingClientRect();
    var w = menu.offsetWidth, h = menu.offsetHeight;
    var left = Math.max(8, Math.min(window.innerWidth - w - 8, r.right - w));
    var top = r.bottom + 6;
    if (top + h > window.innerHeight - 8) top = Math.max(8, r.top - h - 6);
    menu.style.left = left + 'px';
    menu.style.top = top + 'px';
    menu.style.visibility = '';
  }

  function open(btn) {
    var menu = btn.parentNode.querySelector('.tu-kebab-menu');
    if (!menu) return;
    close(false);
    place(btn, menu);
    btn.setAttribute('aria-expanded', 'true');
    current = { btn: btn, menu: menu };
  }

  document.addEventListener('click', function (e) {
    var btn = e.target.closest ? e.target.closest('.tu-kebab-btn') : null;
    if (btn) {
      e.preventDefault();
      if (current && current.btn === btn) close(false); else open(btn);
      return;
    }
    if (current && !current.menu.contains(e.target)) close(false);
  });

  document.addEventListener('keydown', function (e) {
    if (!current) return;
    if (e.key === 'Escape') { e.preventDefault(); close(true); return; }
    if (e.key === 'ArrowDown' || e.key === 'ArrowUp') {
      e.preventDefault();
      var list = items(current.menu);
      if (!list.length) return;
      var i = list.indexOf(document.activeElement);
      i = e.key === 'ArrowDown' ? (i + 1) % list.length : (i <= 0 ? list.length - 1 : i - 1);
      list[i].focus();
    }
  });

  window.addEventListener('resize', function () { close(false); });
  window.addEventListener('scroll', function (e) {
    if (current && !current.menu.contains(e.target)) close(false);
  }, true);
})();
