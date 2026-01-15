<?php
// index.php — Listing des convois (V2)
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/header.php';

$isAdmin    = !empty($_SESSION['is_admin']);
$simpleMode = !empty($_SESSION['simple_mode']);

function h(string $s): string { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }

function labelStatutConvoi(string $status): string {
    switch ($status) {
        case 'preparation': return 'En préparation';
        case 'expedie':     return 'Expédié';
        case 'livre':       return 'Livré';
        case 'archive':     return 'Archivé';
        default:            return $status;
    }
}

function badgeStatutConvoi(string $status): string {
    switch ($status) {
        case 'preparation': return 'warning';
        case 'expedie':     return 'primary';
        case 'livre':       return 'success';
        case 'archive':     return 'secondary';
        default:            return 'light';
    }
}

// Flash
if (!empty($_SESSION['flash_success'])) {
    echo '<div class="alert alert-success">' . h((string)$_SESSION['flash_success']) . '</div>';
    unset($_SESSION['flash_success']);
}
if (!empty($_SESSION['flash_error'])) {
    echo '<div class="alert alert-danger">' . h((string)$_SESSION['flash_error']) . '</div>';
    unset($_SESSION['flash_error']);
}

/**
 * Filtre statut (lecture pour tous)
 */
$allowedFilters = ['all', 'preparation', 'expedie', 'livre', 'archive'];
$filter = $_GET['status'] ?? 'all';
if (!in_array($filter, $allowedFilters, true)) $filter = 'all';

function statusUrl(string $s): string {
    return $s === 'all' ? 'index.php' : ('index.php?status=' . urlencode($s));
}

/**
 * Convois (filtrés)
 */
$params = [];
$sql = "SELECT id, name, destination, departure_date, status, created_at FROM convoys";
if ($filter !== 'all') {
    $sql .= " WHERE status = ? ";
    $params[] = $filter;
}
$sql .= " ORDER BY created_at DESC, id DESC";

$convoysStmt = $pdo->prepare($sql);
$convoysStmt->execute($params);
$convoys = $convoysStmt->fetchAll();

/**
 * Stats cartons par convoi + par catégorie racine
 */
$statsStmt = $pdo->query("
    SELECT
        b.convoy_id,
        r.id    AS root_id,
        r.label AS root_label,
        COUNT(*) AS nb_boxes
    FROM boxes b
    JOIN categories c ON c.id = b.category_id
    JOIN categories r ON r.id = c.root_id
    GROUP BY b.convoy_id, r.id, r.label
");
$statsRows = $statsStmt->fetchAll();

$statsByConvoy = [];
foreach ($statsRows as $row) {
    $cid = (int)$row['convoy_id'];
    if (!isset($statsByConvoy[$cid])) {
        $statsByConvoy[$cid] = ['total' => 0, 'by_root' => []];
    }
    $nb = (int)$row['nb_boxes'];
    $statsByConvoy[$cid]['total'] += $nb;
    $statsByConvoy[$cid]['by_root'][] = [
        'root_id' => (int)$row['root_id'],
        'label'   => (string)$row['root_label'],
        'nb'      => $nb,
    ];
}
foreach ($statsByConvoy as $cid => $data) {
    usort($statsByConvoy[$cid]['by_root'], fn($a,$b) => strcasecmp($a['label'], $b['label']));
}

/**
 * Palettes déclarées (somme par convoi)
 */
$palRows = $pdo->query("
    SELECT convoy_id, COALESCE(SUM(real_count),0) AS total_palettes
    FROM convoy_palettes
    GROUP BY convoy_id
")->fetchAll();

$palettesByConvoy = [];
foreach ($palRows as $r) {
    $palettesByConvoy[(int)$r['convoy_id']] = (int)$r['total_palettes'];
}

?>
<style>
  .convoy-list { display:flex; flex-direction:column; gap:14px; }
  .convoy-card { border-radius:14px; }
  .convoy-row { position:relative; }
  .convoy-row:hover { box-shadow: 0 10px 26px rgba(0,0,0,.08); }
  .convoy-kpi { white-space: nowrap; font-weight: 800; }
  .convoy-kpi small { font-weight: 600; }
  .chip-wrap { display:flex; flex-wrap:wrap; gap:8px; margin-top:10px; }
  .chip { background:#f1f3f5; border:1px solid #e9ecef; border-radius:999px; padding:6px 10px; font-size:13px; line-height:1; }
  .chip b { font-weight: 800; }
  .meta-line { color:#6c757d; font-size: 13px; }
  .muted-small { color:#6c757d; font-size: 12px; }
  .actions-col { min-width: 150px; }
  @media (max-width: 576px){
    .actions-col{ min-width: 120px; }
  }
</style>

<div class="d-flex flex-column flex-md-row justify-content-between align-items-md-start mb-3 gap-2">
  <div>
    <h1 class="h4 mb-1">Logistique — Convois</h1>
    <p class="text-muted mb-0">
      Consultation libre • modification uniquement en <strong>préparation</strong> (sauf admin).
    </p>
  </div>

  <div class="d-flex flex-wrap align-items-center gap-2 justify-content-md-end">
    <a href="<?= h(statusUrl($filter === 'preparation' ? 'all' : 'preparation')) ?>"
       class="btn btn-sm <?= $filter === 'preparation' ? 'btn-primary' : 'btn-outline-primary' ?>">
      <?= $filter === 'preparation' ? 'Voir tous' : 'Préparation uniquement' ?>
    </a>

    <?php if ($isAdmin): ?>
      <a href="<?= h(APP_BASE) ?>/convoys/create.php" class="btn btn-primary btn-sm">+ Nouveau convoi</a>
    <?php endif; ?>
  </div>
</div>

<?php if (empty($convoys)): ?>
  <div class="alert alert-info">Aucun convoi dans ce filtre.</div>
  <?php require __DIR__ . '/footer.php'; exit; ?>
<?php endif; ?>

<div class="convoy-list">
  <?php foreach ($convoys as $c): ?>
    <?php
      $cid = (int)$c['id'];
      $st = $statsByConvoy[$cid] ?? ['total' => 0, 'by_root' => []];
      $total = (int)$st['total'];
      $roots = $st['by_root'];

      $palDeclared = $palettesByConvoy[$cid] ?? 0;
      $isReadOnly = (!$isAdmin && (string)$c['status'] !== 'preparation');

      $dest = trim((string)($c['destination'] ?? ''));
      $date = trim((string)($c['departure_date'] ?? ''));
      $metaParts = [];
      $metaParts[] = 'Destination : ' . ($dest !== '' ? '<strong>' . h($dest) . '</strong>' : '<em>à définir</em>');
      $metaParts[] = 'Date : ' . ($date !== '' ? '<strong>' . h($date) . '</strong>' : '<em>à définir</em>');
    ?>
    <div class="card shadow-sm convoy-card">
      <div class="card-body convoy-row">
        <a class="stretched-link" href="<?= h(APP_BASE) ?>/convoys/view.php?id=<?= (int)$cid ?>" aria-label="Ouvrir le convoi"></a>

        <div class="d-flex justify-content-between align-items-start gap-3">
          <div style="min-width:0;">
            <div class="d-flex flex-wrap align-items-center gap-2">
              <h2 class="h6 mb-0" style="max-width: 100%; overflow:hidden; text-overflow: ellipsis; white-space: nowrap;">
                <?= h((string)$c['name']) ?>
              </h2>

              <span class="badge bg-<?= h(badgeStatutConvoi((string)$c['status'])) ?>">
                <?= h(labelStatutConvoi((string)$c['status'])) ?>
              </span>

              <?php if ($isReadOnly): ?>
                <span class="badge bg-light text-dark">Lecture seule</span>
              <?php endif; ?>
            </div>

            <div class="meta-line mt-1">
              <?= implode(' &nbsp;•&nbsp; ', $metaParts) ?>
            </div>

            <?php
              // Chips : on montre les X premières catégories, puis "Voir tout (N)"
              $maxChips = 10;
              $nbCats = count($roots);
              $chips = array_slice($roots, 0, $maxChips);
            ?>
            <?php if ($total === 0): ?>
              <div class="muted-small mt-2">Aucun carton saisi.</div>
            <?php else: ?>
              <div class="muted-small mt-2"><?= (int)$nbCats ?> catégorie(s)</div>
              <div class="chip-wrap">
                <?php foreach ($chips as $r): ?>
                  <div class="chip"><?= h((string)$r['label']) ?> <b><?= (int)$r['nb'] ?></b></div>
                <?php endforeach; ?>

                <?php if ($nbCats > $maxChips): ?>
                  <div class="chip" style="background:#fff;">
                    Voir tout (<?= (int)$nbCats ?>)
                  </div>
                <?php endif; ?>
              </div>
            <?php endif; ?>
          </div>

          <div class="text-end actions-col">
            <div class="convoy-kpi"><?= (int)$total ?>&nbsp;carton(s)</div>
            <?php if ($palDeclared > 0): ?>
              <div class="muted-small"><?= (int)$palDeclared ?>&nbsp;palette(s)</div>
            <?php else: ?>
              <div class="muted-small">&nbsp;</div>
            <?php endif; ?>

            <div class="d-flex flex-column gap-2 mt-2" style="position:relative; z-index:2;">
              <a class="btn btn-sm btn-outline-primary" href="<?= h(APP_BASE) ?>/convoys/view.php?id=<?= (int)$cid ?>">Ouvrir</a>
              <a class="btn btn-sm btn-outline-secondary" href="<?= h(APP_BASE) ?>/convoys/customs.php?id=<?= (int)$cid ?>">Douane</a>
            </div>
          </div>
        </div>
      </div>
    </div>
  <?php endforeach; ?>
</div>

<?php require __DIR__ . '/footer.php'; ?>
