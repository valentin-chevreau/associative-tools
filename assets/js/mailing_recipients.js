/* Liste nominative des destinataires d'une newsletter : recherche, filtre, exclusion d'une personne pour cet envoi. */
(function () {
  'use strict';
  var dataEl = document.getElementById('mlData');
  var body = document.getElementById('mlrBody');
  if (!dataEl || !body) return;
  var D = JSON.parse(dataEl.textContent);
  var PAGE = 25;
  var rows = D.directory;                       // [{e, n, l:[clés de listes]}]
  var excluded = {};
  (D.excluded || []).forEach(function (e) { excluded[e] = true; });
  var field = document.getElementById('mlExcludedInput');
  var boxes = document.querySelectorAll('[data-ml-aud]');
  var q = document.getElementById('mlrSearch'), mode = 'all', page = 0;
  var total = document.getElementById('mlTotal');

  function selectedKeys() { var k = {}; boxes.forEach(function (b) { if (b.checked) k[b.value] = true; }); return k; }
  function inScope() {
    var k = selectedKeys();
    return rows.filter(function (r) { return r.l.some(function (x) { return k[x]; }); });
  }
  function esc(s) { var d = document.createElement('div'); d.textContent = s; return d.innerHTML; }

  function render() {
    var scope = inScope();
    var off = scope.filter(function (r) { return excluded[r.e]; }).length;
    var n = scope.length - off;
    field.value = JSON.stringify(Object.keys(excluded));

    total.innerHTML = scope.length === 0 ? 'Aucun destinataire sélectionné'
      : '<span id="mlTotalN">' + n + '</span> destinataire' + (n > 1 ? 's' : '') + ' au total'
        + (off ? ' <span style="font-weight:400;color:var(--tu-ink-300)">· ' + scope.length + ' dans les listes, ' + off + ' exclu' + (off > 1 ? 's' : '') + ' de cet envoi</span>' : ' <span style="font-weight:400;color:var(--tu-ink-300)">(doublons et désinscrits exclus)</span>');
    if (scope.length === 0) total.insertAdjacentHTML('beforeend', '<span id="mlTotalN" hidden>0</span>');

    var term = (q.value || '').trim().toLowerCase();
    var list = scope.filter(function (r) {
      if (mode === 'off' && !excluded[r.e]) return false;
      return !term || r.e.indexOf(term) !== -1 || r.n.toLowerCase().indexOf(term) !== -1;
    });
    var pages = Math.max(1, Math.ceil(list.length / PAGE));
    page = Math.min(page, pages - 1);
    var slice = list.slice(page * PAGE, page * PAGE + PAGE);

    body.innerHTML = slice.map(function (r) {
      var off = !!excluded[r.e];
      return '<tr class="' + (off ? 'mlr-row-off' : '') + '"><td style="width:34px"><input type="checkbox" data-e="' + esc(r.e) + '"' + (off ? '' : ' checked') + ' aria-label="Inclure ' + esc(r.e) + '"></td>'
        + '<td class="mlr-name" style="font-weight:600">' + esc(r.n) + '</td><td>' + esc(r.e) + '</td>'
        + '<td>' + r.l.map(function (k) { return '<span class="ml-pill" style="margin-right:4px">' + esc(D.audiences[k]) + '</span>'; }).join('') + '</td>'
        + '<td style="text-align:right">' + (off ? '<span class="ml-pill ml-pill-amber">Exclu de cet envoi</span>' : '') + '</td></tr>';
    }).join('') || '<tr><td colspan="5" class="ml-muted" style="padding:18px">Aucune personne.</td></tr>';

    document.getElementById('mlrCount').textContent = list.length ? (page * PAGE + 1) + ' – ' + (page * PAGE + slice.length) + ' sur ' + list.length : '0';
    document.getElementById('mlrOff').textContent = 'Exclus (' + off + ')';
    document.getElementById('mlrPrev').disabled = page === 0;
    document.getElementById('mlrNext').disabled = page >= pages - 1;
    document.getElementById('mlrAll').classList.toggle('is-on', mode === 'all');
    document.getElementById('mlrOff').classList.toggle('is-on', mode === 'off');
  }

  body.addEventListener('change', function (e) {
    var c = e.target; if (!c.dataset.e) return;
    if (c.checked) delete excluded[c.dataset.e]; else excluded[c.dataset.e] = true;
    render();
  });
  boxes.forEach(function (b) { b.addEventListener('change', function () { page = 0; render(); }); });
  q.addEventListener('input', function () { page = 0; render(); });
  document.getElementById('mlrAll').addEventListener('click', function () { mode = 'all'; page = 0; render(); });
  document.getElementById('mlrOff').addEventListener('click', function () { mode = 'off'; page = 0; render(); });
  document.getElementById('mlrPrev').addEventListener('click', function () { page--; render(); });
  document.getElementById('mlrNext').addEventListener('click', function () { page++; render(); });
  document.getElementById('mlrReset').addEventListener('click', function () {
    inScope().forEach(function (r) { delete excluded[r.e]; }); render();
  });
  render();
})();
