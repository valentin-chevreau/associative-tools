<?php
declare(strict_types=1);

// reports/volunteers.php — Bilan bénévoles (heures, présences, nouveaux, fidélisation, mensuel)
// Données nominatives : réservé aux utilisateurs ayant le droit « Rapports ».

require_once dirname(__DIR__) . '/shared/bootstrap.php';
require_admin();
require_once __DIR__ . '/includes/metrics.php';

$pdo = _bootstrap_get_pdo();
if (!($pdo instanceof PDO)) { http_response_code(500); echo 'Connexion base de données indisponible.'; exit; }

$year = isset($_GET['year']) ? (int)$_GET['year'] : (int)date('Y');
if ($year < 2000 || $year > (int)date('Y') + 1) $year = (int)date('Y');
$base = suite_base() . '/reports';

$smicByYear = [2025 => 11.88, 2026 => 12.02];
$smic = $smicByYear[$year] ?? end($smicByYear);

[$cs, $ce, $cn] = rep_period($year);
[$ps, $pe, $pn, $prevAtDate] = rep_prev_period($year);
$cur  = rep_period_metrics($pdo, $cs, $ce, $cn);
$prev = rep_period_metrics($pdo, $ps, $pe, $pn);
$prevLabel = ($year - 1) . ($prevAtDate ? ' (même date)' : '');
$hasPrev = ($prev['presences'] + $prev['volunteers']) > 0;
$dl = static fn(string $k, string $u = '', int $d = 0): string => $hasPrev ? rep_delta_html((float)$cur[$k], (float)$prev[$k], $prevLabel, $u, $d) : '';

$vs = rep_volunteer_stats($pdo, $year);
$sum = $vs['summary'];
$monthly = rep_monthly($pdo, $year);
$monthLabels = ['Jan','Fév','Mar','Avr','Mai','Juin','Juil','Août','Sep','Oct','Nov','Déc'];

$fmt1 = static fn($n) => number_format((float)$n, 1, ',', ' ');
$fmt0 = static fn($n) => number_format((float)$n, 0, ',', ' ');

function vol_kpi(string $value, string $label, string $sub = '', string $tone = '', string $delta = ''): void {
    echo '<div class="tu-kpi' . ($tone !== '' ? ' ' . rep_h($tone) : '') . '"' . ($sub !== '' ? ' title="' . rep_h($sub) . '"' : '') . '>'
       . '<div class="tu-kpi-val">' . $value . '</div><div class="tu-kpi-lbl">' . rep_h($label) . '</div>'
       . ($delta !== '' ? '<div>' . $delta . '</div>' : '') . '</div>';
}

$title = "Bilan bénévoles $year";
$suiteActiveItem = 'reports-vol';
ob_start();
?>
<style>
.cra-delta { display:inline-block; font-size:10.5px; font-weight:700; margin-top:3px; }
.cra-delta.up { color:var(--tu-green-main, #2a7d4a); } .cra-delta.down { color:var(--tu-red-main, #b3402f); } .cra-delta.flat { color:var(--tu-ink-400, #8a7a68); }
.tu-kpi-grid { grid-template-columns: repeat(5, minmax(0, 1fr)); gap: 12px; margin-bottom: 16px; }
.tu-kpi { padding: 14px 16px; border-radius: 12px; box-shadow: none; }
.tu-kpi-val { font-size: 24px; line-height: 1.15; }
.tu-kpi-lbl { font-size: 12px; margin-top: 3px; }
@media (max-width: 1400px) { .tu-kpi-grid { grid-template-columns: repeat(4, minmax(0, 1fr)); } }
@media (max-width: 1000px) { .tu-kpi-grid { grid-template-columns: repeat(3, minmax(0, 1fr)); } }
@media (max-width: 640px)  { .tu-kpi-grid { grid-template-columns: repeat(2, minmax(0, 1fr)); } }
.vol-sec { margin:22px 0 10px; font-size:11px; font-weight:700; text-transform:uppercase; letter-spacing:.07em; color:var(--tu-ink-300); }
.vol-pad { padding:18px 20px; } .vol-title { font-weight:700; font-size:15px; margin-bottom:6px; }
.vol-tbl td.num, .vol-tbl th.num { text-align:right; white-space:nowrap; }
</style>

<div class="tu-topbar">
  <div class="tu-bc">
    <a href="<?= rep_h(suite_base()) ?>/index.php" style="color:inherit;text-decoration:none;">Accueil</a><span class="tu-bc-sep">›</span>
    <a href="<?= rep_h($base) ?>/index.php" style="color:inherit;text-decoration:none;">Rapports</a><span class="tu-bc-sep">›</span>
    <span class="tu-bc-cur">Bilan bénévoles</span>
  </div>
  <div class="tu-topbar-acts">
    <a class="tu-btn tu-btn-s tu-btn-sm" href="<?= rep_h($base) ?>/volunteers.php?year=<?= $year - 1 ?>">← <?= $year - 1 ?></a>
    <a class="tu-btn tu-btn-s tu-btn-sm" href="<?= rep_h($base) ?>/volunteers.php?year=<?= $year + 1 ?>"><?= $year + 1 ?> →</a>
    <a class="tu-btn tu-btn-s tu-btn-sm" href="<?= rep_h($base) ?>/index.php">← Rapports</a>
  </div>
</div>

<div class="tu-pg">
  <div class="tu-ph">
    <div>
      <div class="tu-ph-title">Bilan bénévoles <?= (int)$year ?></div>
      <div class="tu-ph-sub">Mobilisation, fidélisation et répartition de l'effort — événements passés uniquement</div>
    </div>
  </div>

  <div class="tu-kpi-grid">
    <?php
      vol_kpi((string)(int)$cur['volunteers'], 'Bénévoles mobilisés', 'Au moins 1 présence', 'teal', $dl('volunteers'));
      vol_kpi((string)(int)$cur['presences'], 'Présences', 'Total des « présent »', '', $dl('presences'));
      vol_kpi($fmt1($cur['hours']) . ' h', 'Heures de bénévolat', 'Durée × présence', 'amber', $dl('hours', ' h', 1));
      vol_kpi($fmt0($cur['hours'] * $smic) . ' €', 'Valorisation', 'Heures × SMIC horaire brut ' . $smic . ' €', 'green', $dl('hours', ' h', 1));
      vol_kpi((string)(int)$sum['new'], 'Nouveaux bénévoles', 'Première présence enregistrée cette année', 'teal');
      vol_kpi((string)(int)$sum['regulars'], 'Réguliers', (int)$sum['regular_threshold'] . ' présences ou plus');
      vol_kpi($sum['retention'] !== null ? (int)$sum['retention'] . ' %' : '—', 'Fidélisation', 'Bénévoles de ' . ($year - 1) . ' revenus cette année');
      vol_kpi((string)(int)$sum['lost'], 'Non revenus', 'Actifs en ' . ($year - 1) . ', aucune présence cette année');
      vol_kpi($sum['top3_share'] !== null ? (int)$sum['top3_share'] . ' %' : '—', 'Poids des 3 plus actifs', 'Part des heures', ((int)($sum['top3_share'] ?? 0) >= 60) ? 'red' : '');
      vol_kpi($fmt1($sum['avg_hours']) . ' h', 'Heures / bénévole', 'Moyenne');
    ?>
  </div>
  <?php if ($hasPrev): ?><p class="cra-note" style="font-size:12px;color:var(--tu-ink-300);margin:-6px 2px 8px;">Tendances comparées à <?= rep_h($prevLabel) ?>.</p><?php endif; ?>

  <div class="vol-sec">Évolution mensuelle</div>
  <div class="rep-grid2">
    <div class="tu-card vol-pad"><div class="vol-title">Heures par mois</div>
      <?= rep_bars_html($monthLabels, array_map(fn($x) => $x['hours'], array_values($monthly)), 'h', 'amber') ?></div>
    <div class="tu-card vol-pad"><div class="vol-title">Présences par mois</div>
      <?= rep_bars_html($monthLabels, array_map(fn($x) => $x['presences'], array_values($monthly)), '', 'teal') ?></div>
  </div>

  <div class="vol-sec">Détail par bénévole</div>
  <div class="tu-card" style="overflow-x:auto;">
    <?php if (empty($vs['list'])): ?>
      <div class="vol-pad" style="color:var(--tu-ink-400);font-size:13px;">Aucune présence enregistrée sur cette période.</div>
    <?php else: ?>
      <table class="tu-tbl vol-tbl">
        <thead><tr><th>Bénévole</th><th>Statut</th><th class="num">Événements</th><th class="num">Présences</th><th class="num">Heures</th><th class="num">Valorisation</th></tr></thead>
        <tbody>
        <?php foreach ($vs['list'] as $v): ?>
          <tr>
            <td><strong><?= rep_h(trim($v['first_name'] . ' ' . $v['last_name'])) ?></strong></td>
            <td>
              <?php if ($v['is_new']): ?><span class="tu-bdg tu-bdg-teal">Nouveau</span>
              <?php elseif ($v['returning']): ?><span class="tu-bdg tu-bdg-ink">Revenu</span>
              <?php else: ?><span class="tu-bdg tu-bdg-ink">Ancien</span><?php endif; ?>
              <?php if ($v['regular']): ?><span class="tu-bdg tu-bdg-green">Régulier</span><?php endif; ?>
            </td>
            <td class="num"><?= (int)$v['events'] ?></td>
            <td class="num"><?= (int)$v['presences'] ?></td>
            <td class="num"><?= rep_h($fmt1($v['hours'])) ?> h</td>
            <td class="num"><?= rep_h($fmt0($v['hours'] * $smic)) ?> €</td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    <?php endif; ?>
  </div>
  <p style="font-size:12px;color:var(--tu-ink-300);margin:10px 2px;">
    Document interne : contient des données nominatives. Pour l'AG ou les financeurs, utiliser le compte-rendu d'activité en version publique (anonymisée).
  </p>
</div>
<?php
$content = ob_get_clean();
include __DIR__ . '/includes/layout.php';
