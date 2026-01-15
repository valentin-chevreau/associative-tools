<?php
require_once 'db.php';
require_once 'header.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$isAdmin = !empty($_SESSION['is_admin']);

// Récupération des convois + stats cartons par type
$sql = "
  SELECT 
    c.*,
    COUNT(b.id) AS total_boxes,
    COALESCE(SUM(CASE WHEN b.kind = 'denrees' THEN 1 ELSE 0 END), 0) AS total_boxes_denrees,
    COALESCE(SUM(CASE WHEN b.kind = 'pharma'  THEN 1 ELSE 0 END), 0) AS total_boxes_pharma
  FROM convoys c
  LEFT JOIN boxes b ON b.convoy_id = c.id
  GROUP BY c.id
  ORDER BY c.created_at DESC
";
$stmt    = $pdo->query($sql);
$convoys = $stmt->fetchAll();

function formatSignedDiff(int $n): string {
    if ($n > 0) return '+' . $n;
    if ($n < 0) return (string)$n;
    return '0';
}

// Fonction de calcul palettes pour un convoi
function computePalettesForConvoy(array $c): array {
    $denrees = (int)$c['total_boxes_denrees'];
    $pharma  = (int)$c['total_boxes_pharma'];

    $palDenrees = ($denrees > 0 && defined('CARTONS_PAR_PALETTE_DENREES') && CARTONS_PAR_PALETTE_DENREES > 0)
        ? ceil($denrees / CARTONS_PAR_PALETTE_DENREES)
        : 0;

    $palPharma = ($pharma > 0 && defined('CARTONS_PAR_PALETTE_PHARMA') && CARTONS_PAR_PALETTE_PHARMA > 0)
        ? ceil($pharma / CARTONS_PAR_PALETTE_PHARMA)
        : 0;

    $estTotal = $palDenrees + $palPharma;

    // Palettes déclarées (NULL => 0)
    $realDenrees = $c['real_palettes_denrees'] ?? null;
    $realPharma  = $c['real_palettes_pharma'] ?? null;

    $realDenrees = $realDenrees !== null ? (int)$realDenrees : 0;
    $realPharma  = $realPharma  !== null ? (int)$realPharma  : 0;

    $realTotal = $realDenrees + $realPharma;

    return [
        'est_denrees'  => $palDenrees,
        'est_pharma'   => $palPharma,
        'est_total'    => $estTotal,
        'real_denrees' => $realDenrees,
        'real_pharma'  => $realPharma,
        'real_total'   => $realTotal,
    ];
}

// Agrégats globaux (tous convois confondus)
$global = [
    'boxes_total'        => 0,
    'boxes_denrees'      => 0,
    'boxes_pharma'       => 0,
    'pal_est_denrees'    => 0,
    'pal_est_pharma'     => 0,
    'pal_est_total'      => 0,
    'pal_real_denrees'   => 0,
    'pal_real_pharma'    => 0,
    'pal_real_total'     => 0,
];

foreach ($convoys as $c) {
    $global['boxes_total']   += (int)$c['total_boxes'];
    $global['boxes_denrees'] += (int)$c['total_boxes_denrees'];
    $global['boxes_pharma']  += (int)$c['total_boxes_pharma'];

    $pal = computePalettesForConvoy($c);

    $global['pal_est_denrees']  += $pal['est_denrees'];
    $global['pal_est_pharma']   += $pal['est_pharma'];
    $global['pal_est_total']    += $pal['est_total'];

    $global['pal_real_denrees'] += $pal['real_denrees'];
    $global['pal_real_pharma']  += $pal['real_pharma'];
    $global['pal_real_total']   += $pal['real_total'];
}
?>
<div class="d-flex justify-content-between align-items-center mb-3">
  <div>
    <h1 class="h4 mb-1">Statistiques des convois</h1>
    <p class="text-muted mb-0">
      Vue d’ensemble de tous les convois : cartons, palettes estimées et palettes réellement chargées.
    </p>
  </div>
</div>

<?php if (empty($convoys)): ?>
  <div class="alert alert-info">
    Aucun convoi n'est encore enregistré. Créez un premier convoi pour voir apparaître des statistiques.
  </div>
<?php else: ?>

<div class="row g-3 mb-4">
  <div class="col-md-4">
    <div class="card shadow-sm h-100">
      <div class="card-body">
        <h2 class="h6 mb-2">Cartons (tous convois)</h2>
        <p class="mb-1">
          <strong>Total :</strong> <?= $global['boxes_total'] ?>
        </p>
        <p class="mb-0 text-muted small">
          Denrées : <?= $global['boxes_denrees'] ?> &nbsp;•&nbsp;
          Pharmacie : <?= $global['boxes_pharma'] ?>
        </p>
      </div>
    </div>
  </div>

  <div class="col-md-4">
    <div class="card shadow-sm h-100">
      <div class="card-body">
        <h2 class="h6 mb-2">Palettes estimées</h2>
        <p class="mb-1">
          <strong>Total :</strong> <?= $global['pal_est_total'] ?>
        </p>
        <p class="mb-0 text-muted small">
          Denrées : <?= $global['pal_est_denrees'] ?> &nbsp;•&nbsp;
          Pharmacie : <?= $global['pal_est_pharma'] ?>
        </p>
      </div>
    </div>
  </div>

  <div class="col-md-4">
    <div class="card shadow-sm h-100">
      <div class="card-body">
        <h2 class="h6 mb-2">Palettes déclarées</h2>
        <p class="mb-1">
          <strong>Total :</strong> <?= $global['pal_real_total'] ?>
        </p>
        <p class="mb-1 text-muted small">
          Denrées : <?= $global['pal_real_denrees'] ?> &nbsp;•&nbsp;
          Pharmacie : <?= $global['pal_real_pharma'] ?>
        </p>
        <p class="mb-0 text-muted small">
          Écart global : <?= formatSignedDiff($global['pal_real_total'] - $global['pal_est_total']) ?> palette(s)
        </p>
      </div>
    </div>
  </div>
</div>

<h2 class="h5 mt-3 mb-2">Détail par convoi</h2>
<div class="table-responsive">
  <table class="table table-sm align-middle table-striped">
    <thead>
      <tr>
        <th>Convoi</th>
        <th>Destination</th>
        <th>Date</th>
        <th class="text-center">Cartons (D / P / Total)</th>
        <th class="text-center">Pal. estimées (D / P / Tot.)</th>
        <th class="text-center">Pal. déclarées (D / P / Tot.)</th>
        <th class="text-center">Écart (Tot.)</th>
        <th class="text-end">Actions</th>
      </tr>
    </thead>
    <tbody>
      <?php foreach ($convoys as $c): ?>
        <?php $pal = computePalettesForConvoy($c); ?>
        <tr>
          <td><?= htmlspecialchars($c['name']) ?></td>
          <td><?= htmlspecialchars($c['destination'] ?? '–') ?></td>
          <td>
            <?= $c['departure_date']
                ? htmlspecialchars($c['departure_date'])
                : '<span class="text-muted">à définir</span>' ?>
          </td>
          <td class="text-center">
            <?= (int)$c['total_boxes_denrees'] ?> /
            <?= (int)$c['total_boxes_pharma'] ?> /
            <strong><?= (int)$c['total_boxes'] ?></strong>
          </td>
          <td class="text-center">
            <?= $pal['est_denrees'] ?> /
            <?= $pal['est_pharma'] ?> /
            <strong><?= $pal['est_total'] ?></strong>
          </td>
          <td class="text-center">
            <?= $pal['real_denrees'] ?> /
            <?= $pal['real_pharma'] ?> /
            <strong><?= $pal['real_total'] ?></strong>
          </td>
          <td class="text-center">
            <?= formatSignedDiff($pal['real_total'] - $pal['est_total']) ?>
          </td>
          <td class="text-end">
            <a href="convoi.php?id=<?= (int)$c['id'] ?>" class="btn btn-sm btn-outline-primary">
              Ouvrir
            </a>
          </td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>

<?php endif; ?>

<?php require 'footer.php'; ?>