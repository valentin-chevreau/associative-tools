/* Centre d'aide : panneau latéral « ? » */
(function () {
  var fab = document.getElementById('hp-fab');
  var panel = document.getElementById('hp-panel');
  if (!fab || !panel) return;
  var overlay = document.getElementById('hp-overlay');
  var closeBtn = document.getElementById('hp-close');
  var input = document.getElementById('hp-q');
  var entries = Array.prototype.slice.call(panel.querySelectorAll('.hp-entry'));
  var empty = document.getElementById('hp-empty');
  var more = document.getElementById('hp-more');
  var hasCtx = entries.some(function (e) { return e.dataset.ctx === '1'; });
  var showAll = !hasCtx;

  function norm(s) { return (s || '').toLowerCase().normalize('NFD').replace(/[̀-ͯ]/g, ''); }

  function apply() {
    var q = norm(input.value.trim());
    var shown = 0;
    entries.forEach(function (e) {
      var inScope = q !== '' || showAll || e.dataset.ctx === '1';
      var match = !q || norm(e.dataset.search).indexOf(q) !== -1;
      var ok = inScope && match;
      e.hidden = !ok;
      if (ok) shown++;
    });
    empty.hidden = shown !== 0;
    more.hidden = showAll || q !== '' || !hasCtx;
  }

  function open() {
    panel.classList.add('is-open');
    overlay.classList.add('is-open');
    panel.setAttribute('aria-hidden', 'false');
    apply();
    setTimeout(function () { input.focus({ preventScroll: true }); }, 220);
  }
  function close() {
    panel.classList.remove('is-open');
    overlay.classList.remove('is-open');
    panel.setAttribute('aria-hidden', 'true');
    fab.focus({ preventScroll: true });
  }

  fab.addEventListener('click', function () { panel.classList.contains('is-open') ? close() : open(); });
  closeBtn.addEventListener('click', close);
  overlay.addEventListener('click', close);
  document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape' && panel.classList.contains('is-open')) close();
  });
  input.addEventListener('input', apply);
  more.addEventListener('click', function () { showAll = true; apply(); });
  apply();
})();
