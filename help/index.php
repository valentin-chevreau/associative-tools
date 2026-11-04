<?php
/**
 * help/index.php — centre d'aide : toutes les questions accessibles à l'utilisateur, avec recherche.
 */
require_once __DIR__ . '/../shared/bootstrap.php';
require_once __DIR__ . '/../shared/db.php';
require_once __DIR__ . '/../shared/help.php';

require_admin();

$base   = suite_base();
$topics = help_topics_for_user();
$total  = 0;
foreach ($topics as $t) $total += count($t['entries']);
$v = static fn(string $rel): string => (string)(int)@filemtime(dirname(__DIR__) . '/' . $rel);
?>
<!DOCTYPE html>
<html lang="fr">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
  <title>Aide — Touraine-Ukraine</title>
  <link rel="stylesheet" href="<?= help_e($base) ?>/assets/css/suite_nav.css<?= function_exists('suite_css_v') ? suite_css_v() : '' ?>"><?= function_exists('suite_pwa_head') ? suite_pwa_head() : '' ?>
  <link rel="stylesheet" href="<?= help_e($base) ?>/assets/css/suite_help.css?v=<?= $v('assets/css/suite_help.css') ?>">
</head>
<body class="tu-v2">

<?php require_once dirname(__DIR__) . '/shared/suite_nav.php'; suite_nav_v2_render('aide', 'help'); ?>

<div class="tu-main">
  <div class="tu-topbar">
    <div class="tu-bc">
      <a href="<?= help_e($base . '/index.php') ?>" style="color:inherit;text-decoration:none;">Accueil</a>
      <span class="tu-bc-sep">›</span>
      <span class="tu-bc-cur">Aide</span>
    </div>
  </div>

  <div class="tu-pg" id="hpPage">
    <div class="tu-ph">
      <div>
        <div class="tu-ph-title">Aide</div>
        <div class="tu-ph-sub">Pas de panique : chaque fiche indique ce qui peut se rattraper et ce qui ne le peut pas.</div>
      </div>
    </div>

    <div class="tu-card tu-mb4" style="padding:16px 18px;">
      <div class="hp-page-tools">
        <div class="tu-form-field hp-psearch">
          <span class="tu-lbl">Rechercher</span>
          <input type="search" class="tu-input" id="hpSearch" placeholder="Ex : rendre la monnaie, imprimer une étiquette… (Échap = effacer)" autocomplete="off" style="width:100%;">
        </div>
      </div>
      <div class="hp-pills" role="group" aria-label="Filtrer par sujet">
        <button type="button" class="hp-pill is-on" data-topic="">Tout · <?= $total ?></button>
        <?php foreach ($topics as $t): ?>
          <button type="button" class="hp-pill" data-topic="<?= help_e($t['key']) ?>"><?= help_e($t['label']) ?> · <?= count($t['entries']) ?></button>
        <?php endforeach; ?>
      </div>
    </div>

    <?php foreach ($topics as $t): ?>
      <section class="hp-topic" data-topic="<?= help_e($t['key']) ?>">
        <h2><?= help_e($t['label']) ?></h2>
        <?php foreach ($t['entries'] as $slug => $e) echo help_render_entry($t, (string)$slug, $e); ?>
      </section>
    <?php endforeach; ?>

    <div class="hp-empty" id="hpEmpty" hidden>Aucune question ne correspond. Essayez un autre mot, ou contactez Valentin.</div>
  </div>
</div>

<script>
(function(){
  var page = document.getElementById('hpPage');
  var input = document.getElementById('hpSearch');
  var pills = Array.prototype.slice.call(page.querySelectorAll('.hp-pill'));
  var sections = Array.prototype.slice.call(page.querySelectorAll('.hp-topic'));
  var empty = document.getElementById('hpEmpty');
  var state = { q: '', topic: '' };
  function norm(s){ return (s||'').toLowerCase().normalize('NFD').replace(/[̀-ͯ]/g,''); }
  function apply(){
    var q = norm(state.q.trim()), any = false;
    sections.forEach(function(sec){
      var sectionOk = !state.topic || sec.dataset.topic === state.topic, shown = 0;
      sec.querySelectorAll('.hp-entry').forEach(function(e){
        var ok = sectionOk && (!q || norm(e.dataset.search).indexOf(q) !== -1);
        e.hidden = !ok; if (ok) shown++;
      });
      sec.hidden = shown === 0;
      if (shown) any = true;
    });
    pills.forEach(function(p){ p.classList.toggle('is-on', p.dataset.topic === state.topic); });
    empty.hidden = any;
  }
  input.addEventListener('input', function(){ state.q = input.value; apply(); });
  input.addEventListener('keydown', function(e){ if (e.key === 'Escape') { input.value = ''; state.q = ''; apply(); } });
  pills.forEach(function(p){ p.addEventListener('click', function(){ state.topic = p.dataset.topic; apply(); }); });
  // Lien direct vers une fiche (#topic-slug) : on l'ouvre
  if (location.hash.length > 1) {
    var target = document.getElementById(decodeURIComponent(location.hash.slice(1)));
    if (target && target.tagName === 'DETAILS') { target.open = true; target.scrollIntoView({block:'center'}); }
  }
  apply();
})();
</script>
</body>
</html>
