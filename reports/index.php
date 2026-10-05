<?php
declare(strict_types=1);

// reports/index.php — Hub des rapports (CRA, bilan bénévoles, bilan dons)
// L'accès est contrôlé par shared/bootstrap.php (droit « Rapports », gérable par utilisateur).

require_once dirname(__DIR__) . '/shared/bootstrap.php';
require_admin();
require_once __DIR__ . '/includes/metrics.php';

$pdo = _bootstrap_get_pdo();
$base = suite_base();
$year = (int)date('Y');

// Aperçu rapide de l'année en cours (jamais bloquant si une table manque)
$m = ($pdo instanceof PDO)
    ? rep_period_metrics($pdo, ...rep_period($year))
    : [];
$fmt0 = static fn($n) => number_format((float)$n, 0, ',', ' ');
$fmt1 = static fn($n) => number_format((float)$n, 1, ',', ' ');

$reports = [
    [
        'title' => "Compte-rendu d'activité",
        'desc'  => "Synthèse AG et financeurs : actions, bénévolat, recettes, dons, impact terrain, comparaison avec l'année précédente.",
        'href'  => $base . '/reports/activity.php?year=' . $year,
        'stat'  => $fmt0($m['actions'] ?? 0) . ' actions · ' . $fmt0(($m['permanences'] ?? 0)) . ' permanences en ' . $year,
    ],
    [
        'title' => 'Bilan bénévoles',
        'desc'  => 'Qui s\'est mobilisé : heures, présences, nouveaux bénévoles, fidélisation, évolution mensuelle.',
        'href'  => $base . '/reports/volunteers.php?year=' . $year,
        'stat'  => $fmt0($m['volunteers'] ?? 0) . ' bénévoles · ' . $fmt1($m['hours'] ?? 0) . ' h en ' . $year,
    ],
    [
        'title' => 'Bilan dons',
        'desc'  => 'Dons financiers par source, campagne et mois, donateurs récurrents, reçus fiscaux à émettre.',
        'href'  => $base . '/reports/donations.php?year=' . $year,
        'stat'  => $fmt0($m['donations_count'] ?? 0) . ' dons · ' . number_format((float)($m['donations_amount'] ?? 0), 0, ',', ' ') . ' € en ' . $year,
    ],
];

$title = 'Rapports';
$suiteActiveItem = 'reports-home';
ob_start();
?>
<div class="tu-topbar">
  <div class="tu-bc">
    <a href="<?= rep_h($base) ?>/index.php" style="color:inherit;text-decoration:none;">Accueil</a>
    <span class="tu-bc-sep">›</span>
    <span class="tu-bc-cur">Rapports</span>
  </div>
</div>

<div class="tu-pg">
  <div class="tu-ph">
    <div>
      <div class="tu-ph-title">Rapports</div>
      <div class="tu-ph-sub">Chiffres consolidés de l'association — planning, caisse, dons, adhésions, convois</div>
    </div>
  </div>

  <div class="rep-hub">
    <?php foreach ($reports as $r): ?>
      <a href="<?= rep_h($r['href']) ?>">
        <div class="tu-card">
          <div style="font-size:15px;font-weight:700;margin-bottom:6px;"><?= rep_h($r['title']) ?></div>
          <div style="font-size:12.5px;color:var(--tu-ink-500);line-height:1.5;margin-bottom:12px;"><?= rep_h($r['desc']) ?></div>
          <span class="tu-bdg tu-bdg-amber"><?= rep_h($r['stat']) ?></span>
        </div>
      </a>
    <?php endforeach; ?>
  </div>
</div>
<?php
$content = ob_get_clean();
include __DIR__ . '/includes/layout.php';
