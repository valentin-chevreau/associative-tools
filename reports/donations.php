<?php
declare(strict_types=1);

// reports/donations.php — Bilan des dons (sources, campagnes, mois, donateurs, reçus fiscaux)

require_once dirname(__DIR__) . '/shared/bootstrap.php';
require_admin();
require_once __DIR__ . '/includes/metrics.php';

$pdo = _bootstrap_get_pdo();
if (!($pdo instanceof PDO)) { http_response_code(500); echo 'Connexion base de données indisponible.'; exit; }

$year = isset($_GET['year']) ? (int)$_GET['year'] : (int)date('Y');
if ($year < 2000 || $year > (int)date('Y') + 1) $year = (int)date('Y');
$base = suite_base() . '/reports';

[$cs, $ce, $cn] = rep_period($year);
[$ps, $pe, $pn, $prevAtDate] = rep_prev_period($year);
$d  = rep_donations_stats($pdo, $cs, $ce, $cn);
$pd = rep_donations_stats($pdo, $ps, $pe, $pn);
$prevLabel = ($year - 1) . ($prevAtDate ? ' (même date)' : '');
$hasPrev = $pd['enabled'] && $pd['count'] > 0;
$dl = static fn(float $c, float $p, string $u = '', int $dec = 0): string => $hasPrev ? rep_delta_html($c, $p, $prevLabel, $u, $dec) : '';

$fmt0 = static fn($n) => number_format((float)$n, 0, ',', ' ');
$fmt2 = static fn($n) => number_format((float)$n, 2, ',', ' ');
$monthLabels = ['Jan','Fév','Mar','Avr','Mai','Juin','Juil','Août','Sep','Oct','Nov','Déc'];

function don_kpi(string $value, string $label, string $sub = '', string $tone = '', string $delta = ''): void {
    echo '<div class="tu-kpi' . ($tone !== '' ? ' ' . rep_h($tone) : '') . '"' . ($sub !== '' ? ' title="' . rep_h($sub) . '"' : '') . '>'
       . '<div class="tu-kpi-val">' . $value . '</div><div class="tu-kpi-lbl">' . rep_h($label) . '</div>'
       . ($delta !== '' ? '<div>' . $delta . '</div>' : '') . '</div>';
}
/** Tableau de ventilation (clé → nombre, montant) avec barre de part. */
function don_breakdown(array $rows, float $total): void {
    if (!$rows) { echo '<div style="color:var(--tu-ink-400);font-size:13px;padding:6px 0;">Aucune donnée.</div>'; return; }
    echo '<table class="tu-tbl"><thead><tr><th>Libellé</th><th style="text-align:right">Dons</th><th style="text-align:right">Montant</th><th style="text-align:right">Part</th></tr></thead><tbody>';
    foreach ($rows as $k => $r) {
        $pct = $total > 0 ? $r['amount'] / $total * 100 : 0;
        echo '<tr><td>' . rep_h($k) . '</td><td style="text-align:right">' . (int)$r['count'] . '</td>'
           . '<td style="text-align:right;white-space:nowrap">' . number_format($r['amount'], 2, ',', ' ') . ' €</td>'
           . '<td style="text-align:right;white-space:nowrap">' . number_format($pct, 0, ',', ' ') . ' %</td></tr>';
    }
    echo '</tbody></table>';
}

$title = "Bilan dons $year";
$suiteActiveItem = 'reports-don';
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
.don-sec { margin:22px 0 10px; font-size:11px; font-weight:700; text-transform:uppercase; letter-spacing:.07em; color:var(--tu-ink-300); }
.don-pad { padding:18px 20px; } .don-title { font-weight:700; font-size:15px; margin-bottom:8px; }
</style>

<div class="tu-topbar">
  <div class="tu-bc">
    <a href="<?= rep_h(suite_base()) ?>/index.php" style="color:inherit;text-decoration:none;">Accueil</a><span class="tu-bc-sep">›</span>
    <a href="<?= rep_h($base) ?>/index.php" style="color:inherit;text-decoration:none;">Rapports</a><span class="tu-bc-sep">›</span>
    <span class="tu-bc-cur">Bilan dons</span>
  </div>
  <div class="tu-topbar-acts">
    <a class="tu-btn tu-btn-s tu-btn-sm" href="<?= rep_h($base) ?>/donations.php?year=<?= $year - 1 ?>">← <?= $year - 1 ?></a>
    <a class="tu-btn tu-btn-s tu-btn-sm" href="<?= rep_h($base) ?>/donations.php?year=<?= $year + 1 ?>"><?= $year + 1 ?> →</a>
    <a class="tu-btn tu-btn-s tu-btn-sm" href="<?= rep_h(suite_base()) ?>/donations/index.php">Gestion des dons</a>
    <a class="tu-btn tu-btn-s tu-btn-sm" href="<?= rep_h($base) ?>/index.php">← Rapports</a>
  </div>
</div>

<div class="tu-pg">
  <div class="tu-ph">
    <div>
      <div class="tu-ph-title">Bilan dons <?= (int)$year ?></div>
      <div class="tu-ph-sub">Dons financiers payés — HelloAsso et saisies manuelles</div>
    </div>
  </div>

<?php if (!$d['enabled']): ?>
  <div class="tu-card don-pad">La table « donations » est introuvable : le module Dons n'est pas installé sur cette base.</div>
<?php else: ?>
  <?php if ($d['error']): ?><div class="tu-card don-pad" style="color:var(--tu-red-main);">Erreur de calcul : <?= rep_h($d['error']) ?></div><?php endif; ?>

  <div class="tu-kpi-grid">
    <?php
      don_kpi($fmt2($d['amount']) . ' €', 'Total des dons', 'Dons payés sur la période', 'green', $dl($d['amount'], $pd['amount'], ' €'));
      don_kpi((string)(int)$d['count'], 'Nombre de dons', '', '', $dl((float)$d['count'], (float)$pd['count']));
      don_kpi((string)(int)$d['donors'], 'Donateurs', 'Donateurs distincts (e-mail, sinon nom)', 'teal', $dl((float)$d['donors'], (float)$pd['donors']));
      don_kpi($fmt2($d['avg']) . ' €', 'Don moyen', 'Total / nombre de dons', '', $dl($d['avg'], $pd['avg'], ' €', 2));
      don_kpi($fmt2($d['max']) . ' €', 'Plus gros don', '');
      don_kpi((string)(int)$d['new_donors'], 'Nouveaux donateurs', 'Aucun don payé avant le début de la période', 'teal');
      don_kpi((string)(int)$d['recurring_donors'], 'Donateurs récurrents', '2 dons ou plus sur la période');
      don_kpi($fmt2($d['eligible_amount']) . ' €', 'Éligibles reçu fiscal', (int)$d['eligible_count'] . ' don(s)', 'green');
    ?>
  </div>
  <?php if ($hasPrev): ?><p style="font-size:12px;color:var(--tu-ink-300);margin:-6px 2px 8px;">Tendances comparées à <?= rep_h($prevLabel) ?>.</p><?php endif; ?>

  <?php if ($d['no_receipt'] > 0 || $d['possible_membership']['count'] > 0): ?>
    <div class="tu-card don-pad" style="border-color:rgba(232,146,74,.45);background:rgba(232,146,74,.07);margin-bottom:14px;">
      <div class="don-title">À vérifier</div>
      <?php if ($d['no_receipt'] > 0): ?>
        <div style="font-size:13px;margin-bottom:4px;"><strong><?= (int)$d['no_receipt'] ?></strong> don(s) éligible(s) sans reçu fiscal émis — à générer depuis le module Dons.</div>
      <?php endif; ?>
      <?php if ($d['possible_membership']['count'] > 0): ?>
        <div style="font-size:13px;"><strong><?= (int)$d['possible_membership']['count'] ?></strong> don(s) (<?= rep_h($fmt2($d['possible_membership']['amount'])) ?> €) dont la campagne évoque une adhésion : possibles cotisations HelloAsso comptées comme dons.</div>
      <?php endif; ?>
    </div>
  <?php endif; ?>

  <div class="don-sec">Évolution mensuelle</div>
  <div class="tu-card don-pad">
    <div class="don-title">Montant des dons par mois (€)</div>
    <?= rep_bars_html($monthLabels, array_values($d['by_month']), '€', 'green') ?>
  </div>

  <div class="don-sec">Ventilation</div>
  <div class="rep-grid2">
    <div class="tu-card don-pad"><div class="don-title">Par source</div><?php don_breakdown($d['by_source'], $d['amount']); ?></div>
    <div class="tu-card don-pad"><div class="don-title">Par campagne</div><?php don_breakdown($d['by_campaign'], $d['amount']); ?></div>
    <div class="tu-card don-pad"><div class="don-title">Par moyen de paiement</div><?php don_breakdown($d['by_method'], $d['amount']); ?></div>
    <div class="tu-card don-pad">
      <div class="don-title">Par tranche de montant</div>
      <?= rep_bars_html(array_keys($d['buckets']), array_values($d['buckets']), 'don(s)', 'amber', 90) ?>
    </div>
  </div>
<?php endif; ?>
</div>
<?php
$content = ob_get_clean();
include __DIR__ . '/includes/layout.php';
