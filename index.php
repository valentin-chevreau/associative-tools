<?php
declare(strict_types=1);

require_once __DIR__ . '/shared/bootstrap.php';

if (!is_admin()) {
    $next     = $_SERVER['REQUEST_URI'] ?? '';
    $loginUrl = suite_login_url();
    if ($next && strpos($loginUrl, '?') === false) $loginUrl .= '?next=' . urlencode($next);
    header('Location: ' . $loginUrl);
    exit;
}

require_once __DIR__ . '/shared/db.php';
require_once __DIR__ . '/shared/suite_nav.php';
require_once __DIR__ . '/shared/dashboard.php';

function h(string $s): string { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }

$base = function_exists('suite_base') ? suite_base() : '';
$pdo  = suite_pdo();

$convoi   = dash_convoi($pdo);
$creneaux = dash_creneaux($pdo);
$caisse   = dash_caisse($pdo);
$todo     = dash_a_traiter($pdo, $base);

$volunteerName = function_exists('current_volunteer_name') ? current_volunteer_name() : '';
$firstName     = ($volunteerName !== '' && $volunteerName !== 'Inconnu') ? trim(explode(' ', $volunteerName)[0]) : '';
$todayLabel    = dash_jour_fr(new DateTimeImmutable('today'), true);

$convoiStatus = [
    'collecte'    => ['Collecte', 'tu-bdg-ink'],
    'preparation' => ['En préparation', 'tu-bdg-amber'],
    'docs'        => ['Docs douane', 'tu-bdg-blue'],
];

$cssFile = __DIR__ . '/assets/css/suite_dashboard.css';
$hasAny  = $convoi || $creneaux !== null || $caisse || $todo;
?>
<!DOCTYPE html>
<html lang="fr">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
  <title>Suite Touraine-Ukraine</title>
  <link rel="stylesheet" href="<?= h($base) ?>/assets/css/suite_nav.css<?= function_exists('suite_css_v') ? suite_css_v() : '' ?>">
  <link rel="stylesheet" href="<?= h($base) ?>/assets/css/suite_dashboard.css?v=<?= (int)@filemtime($cssFile) ?>"><?= function_exists('suite_pwa_head') ? suite_pwa_head() : '' ?>
</head>
<body class="tu-v2">

<?php suite_nav_render('', ''); ?>

<div class="tu-main">

  <div class="tu-topbar">
    <div class="tu-bc">
      <span class="tu-bc-cur">Accueil</span>
    </div>
    <div class="tu-topbar-acts"><span class="dh-date"><?= h($todayLabel) ?></span></div>
  </div>

  <div class="tu-pg">

    <div class="tu-ph">
      <div>
        <div class="tu-ph-title">Bonjour<?= $firstName !== '' ? ' ' . h($firstName) : '' ?></div>
        <div class="tu-ph-sub">Voici l'état de la suite aujourd'hui.</div>
      </div>
    </div>

    <?php if (!$hasAny): ?>
      <div class="tu-card dh-card dh-empty">Rien à signaler pour le moment. Utilisez le menu pour accéder aux modules.</div>
    <?php endif; ?>

    <div class="dh-row">

      <?php if ($convoi): ?>
      <?php [$cLabel, $cClass] = $convoiStatus[$convoi['status']] ?? [(string)$convoi['status'], 'tu-bdg-ink']; ?>
      <section class="tu-card dh-card dh-wide">
        <div class="dh-head">
          <span class="dh-kicker">Prochain convoi</span>
          <span class="tu-bdg <?= h($cClass) ?>"><?= h($cLabel) ?></span>
          <?php if ($convoi['days'] !== null): ?>
            <span class="dh-head-r">
              <?php if ($convoi['days'] > 0): ?>Départ dans <b><?= (int)$convoi['days'] ?> jour<?= $convoi['days'] > 1 ? 's' : '' ?></b>
              <?php elseif ($convoi['days'] === 0): ?><b>Départ aujourd'hui</b>
              <?php else: ?>Départ prévu il y a <b><?= abs((int)$convoi['days']) ?> jour<?= abs($convoi['days']) > 1 ? 's' : '' ?></b><?php endif; ?>
            </span>
          <?php endif; ?>
        </div>
        <div>
          <div class="dh-title"><?= h((string)$convoi['name']) ?></div>
          <?php if (!empty($convoi['departure_date']) || !empty($convoi['destination'])): ?>
            <div class="dh-sub">
              <?= !empty($convoi['departure_date']) ? h(dash_jour_fr(new DateTimeImmutable((string)$convoi['departure_date']), true)) : '' ?>
              <?= (!empty($convoi['departure_date']) && !empty($convoi['destination'])) ? ' · ' : '' ?>
              <?= !empty($convoi['destination']) ? h((string)$convoi['destination']) : '' ?>
            </div>
          <?php endif; ?>
        </div>
        <div class="dh-stats">
          <div class="dh-stat"><div class="dh-stat-v"><?= (int)$convoi['boxes'] ?></div><div class="dh-stat-l">carton<?= $convoi['boxes'] > 1 ? 's' : '' ?></div></div>
          <div class="dh-stat"><div class="dh-stat-v"><?= (int)$convoi['cats'] ?></div><div class="dh-stat-l">catégorie<?= $convoi['cats'] > 1 ? 's' : '' ?></div></div>
        </div>
        <div class="dh-actions">
          <a href="<?= h($base) ?>/logistique/convoys/view.php?id=<?= (int)$convoi['id'] ?>" class="tu-btn tu-btn-p">Ouvrir le convoi</a>
        </div>
      </section>
      <?php endif; ?>

      <?php if ($creneaux !== null): ?>
      <section class="tu-card dh-card dh-narrow">
        <div class="dh-head">
          <span class="dh-kicker">Créneaux à pourvoir</span>
          <?php if ($creneaux['missing'] > 0): ?><span class="tu-bdg tu-bdg-red"><?= (int)$creneaux['missing'] ?> manquant<?= $creneaux['missing'] > 1 ? 's' : '' ?></span><?php endif; ?>
        </div>
        <?php if (!$creneaux['rows']): ?>
          <div class="dh-ok">Tous les créneaux à venir ont le minimum de bénévoles.</div>
        <?php else: ?>
          <div class="dh-list">
          <?php foreach ($creneaux['rows'] as $s):
              $start = new DateTimeImmutable((string)$s['start_datetime']);
              $end   = !empty($s['end_datetime']) ? new DateTimeImmutable((string)$s['end_datetime']) : null;
              $reg = (int)$s['registered']; $min = (int)$s['min_volunteers'];
          ?>
            <div class="dh-slot">
              <div class="dh-slot-d"><span><?= h(dash_jour_fr($start)) ?></span><b><?= (int)$start->format('j') ?></b></div>
              <div class="dh-slot-t">
                <div class="dh-slot-n"><?= h((string)$s['title']) ?></div>
                <div class="dh-slot-h"><?= h($start->format('H:i')) ?><?= $end ? ' → ' . h($end->format('H:i')) : '' ?></div>
              </div>
              <span class="tu-bdg <?= $reg === 0 ? 'tu-bdg-red' : 'tu-bdg-amber' ?>"><?= $reg ?> / <?= $min ?></span>
            </div>
          <?php endforeach; ?>
          </div>
        <?php endif; ?>
        <a href="<?= h($base) ?>/planning/events.php" class="dh-more">Voir tout le planning →</a>
      </section>
      <?php endif; ?>
    </div>

    <?php if ($caisse || $todo): ?>
    <div class="dh-row">

      <?php if ($caisse): ?>
      <section class="tu-card dh-card dh-narrow">
        <div class="dh-head">
          <span class="dh-kicker">Caisse</span>
          <?php if ($caisse['is_open']): ?><span class="tu-bdg tu-bdg-amber">En cours</span>
          <?php else: ?><span class="tu-bdg tu-bdg-green">Clôturé</span><?php endif; ?>
        </div>
        <div>
          <div class="dh-title dh-title-s"><?= h((string)$caisse['nom']) ?></div>
          <div class="dh-sub"><?= $caisse['is_open'] ? 'Évènement ouvert' : 'Dernier évènement' ?> · <?= (int)$caisse['sales'] ?> vente<?= $caisse['sales'] > 1 ? 's' : '' ?></div>
        </div>
        <div class="dh-stats">
          <div class="dh-stat"><div class="dh-stat-v"><?= h(number_format($caisse['total'], 2, ',', ' ')) ?> €</div><div class="dh-stat-l">encaissé</div></div>
          <?php if (!$caisse['is_open'] && $caisse['ecart'] !== null): ?>
            <div class="dh-stat"><div class="dh-stat-v <?= abs($caisse['ecart']) >= 0.005 ? 'dh-neg' : '' ?>"><?= ($caisse['ecart'] > 0 ? '+' : '') . h(number_format($caisse['ecart'], 2, ',', ' ')) ?> €</div><div class="dh-stat-l">écart de caisse</div></div>
          <?php endif; ?>
        </div>
        <div class="dh-actions">
          <a href="<?= h($base) ?>/caisse/<?= $caisse['is_open'] ? 'index.php' : 'evenements.php' ?>" class="tu-btn <?= $caisse['is_open'] ? 'tu-btn-p' : 'tu-btn-s' ?>"><?= $caisse['is_open'] ? 'Ouvrir la caisse' : 'Voir les évènements' ?></a>
        </div>
      </section>
      <?php endif; ?>

      <?php if ($todo): ?>
      <section class="tu-card dh-card dh-wide">
        <div class="dh-head">
          <span class="dh-kicker">À traiter</span>
          <span class="tu-bdg tu-bdg-ink"><?= count($todo) ?></span>
        </div>
        <div class="dh-todo">
          <?php foreach ($todo as $t): ?>
            <a href="<?= h($t['href']) ?>" class="dh-todo-i">
              <span class="dh-todo-t"><b><?= h($t['label']) ?></b><span><?= h($t['hint']) ?></span></span>
              <span class="dh-chev" aria-hidden="true">›</span>
            </a>
          <?php endforeach; ?>
        </div>
      </section>
      <?php endif; ?>

    </div>
    <?php endif; ?>

  </div>
</div>

</body>
</html>
