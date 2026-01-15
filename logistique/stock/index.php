<?php
require_once __DIR__ . '/../db.php';

if (session_status() === PHP_SESSION_NONE) session_start();

$isAdmin    = !empty($_SESSION['is_admin']);
$simpleMode = !empty($_SESSION['simple_mode']);

function h(string $s): string { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }

/* Flash */
if (!empty($_SESSION['flash_success'])) {
  echo '<div class="alert alert-success">' . h($_SESSION['flash_success']) . '</div>';
  unset($_SESSION['flash_success']);
}
if (!empty($_SESSION['flash_error'])) {
  echo '<div class="alert alert-danger">' . h($_SESSION['flash_error']) . '</div>';
  unset($_SESSION['flash_error']);
}

/* -----------------------------
   Dropdowns (catégories / lieux)
------------------------------*/
$catsStmt = $pdo->query("
  SELECT id, parent_id, label, is_active, sort_order
  FROM stock_categories
  WHERE is_active = 1
  ORDER BY sort_order ASC, label ASC, id ASC
");
$cats = $catsStmt->fetchAll(PDO::FETCH_ASSOC);

$locStmt = $pdo->query("
  SELECT id, label, is_active, sort_order
  FROM stock_locations
  WHERE is_active = 1
  ORDER BY sort_order ASC, label ASC, id ASC
");
$locations = $locStmt->fetchAll(PDO::FETCH_ASSOC);

/* Helpers label hiérarchique */
$catLabels = [];
$catParents = [];
foreach ($cats as $c) {
  $cid = (int)$c['id'];
  $catLabels[$cid] = (string)$c['label'];
  $catParents[$cid] = $c['parent_id'] !== null ? (int)$c['parent_id'] : null;
}
function catFullLabel(int $id, array $labels, array $parents): string {
  $label = $labels[$id] ?? ('#' . $id);
  $pid = $parents[$id] ?? null;
  if ($pid !== null) {
    $pl = $labels[$pid] ?? ('#' . $pid);
    return $pl . ' > ' . $label;
  }
  return $label;
}

/* -----------------------------
   Filtres
------------------------------*/
$q = trim((string)($_GET['q'] ?? ''));
$status = (string)($_GET['status'] ?? 'available');
$categoryId = (int)($_GET['category_id'] ?? 0);
$locationId = (int)($_GET['location_id'] ?? 0);

$allowedStatus = ['all','available','reserved','allocated','out','discarded'];
if (!in_array($status, $allowedStatus, true)) $status = 'available';

$qLike = '%' . $q . '%';

$where = [];
$params = [];

$where[] = "si.is_active = 1"; // V1: on affiche uniquement actifs

if ($status !== 'all') {
  $where[] = "si.status = ?";
  $params[] = $status;
}

if ($categoryId > 0) {
  // Filtre sur une catégorie précise (parent ou enfant)
  $where[] = "si.category_id = ?";
  $params[] = $categoryId;
}

if ($locationId > 0) {
  $where[] = "si.location_id = ?";
  $params[] = $locationId;
}

if ($q !== '') {
  $where[] = "(si.title LIKE ? OR si.ref_code LIKE ? OR si.source_notes LIKE ?)";
  array_push($params, $qLike, $qLike, $qLike);
}

$whereSql = $where ? ("WHERE " . implode(" AND ", $where)) : "";

/* Pagination */
$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = 25;
$offset = ($page - 1) * $perPage;

/* Count */
$countStmt = $pdo->prepare("
  SELECT COUNT(*) AS c
  FROM stock_items si
  $whereSql
");
$countStmt->execute($params);
$total = (int)($countStmt->fetch()['c'] ?? 0);
$totalPages = max(1, (int)ceil(max(1, $total) / $perPage));

/* List */
$listSql = "
  SELECT
    si.*,
    sc.label AS category_label,
    sc.parent_id AS category_parent_id,
    sl.label AS location_label
  FROM stock_items si
  JOIN stock_categories sc ON sc.id = si.category_id
  LEFT JOIN stock_locations sl ON sl.id = si.location_id
  $whereSql
  ORDER BY
    (si.status='available') DESC,
    sc.label ASC,
    si.title ASC,
    si.id DESC
  LIMIT $perPage OFFSET $offset
";
$listStmt = $pdo->prepare($listSql);
$listStmt->execute($params);
$items = $listStmt->fetchAll(PDO::FETCH_ASSOC);

/* URL builder */
function buildUrl(array $overrides = []): string {
  $base = $_GET;
  foreach ($overrides as $k => $v) {
    if ($v === null) unset($base[$k]);
    else $base[$k] = $v;
  }
  $qs = http_build_query($base);
  return 'index.php' . ($qs ? ('?' . $qs) : '');
}

/* Badges */
function statusBadge(string $s): array {
  switch ($s) {
    case 'available': return ['success','Disponible'];
    case 'reserved':  return ['warning','Réservé'];
    case 'allocated': return ['primary','Attribué'];
    case 'out':       return ['secondary','Sorti'];
    case 'discarded': return ['dark','Jeté/HS'];
    default:          return ['light', $s];
  }
}
function condLabel(string $c): string {
  switch ($c) {
    case 'new': return 'Neuf';
    case 'very_good': return 'Très bon';
    case 'good': return 'Bon';
    case 'fair': return 'Correct';
    case 'needs_repair': return 'À réparer';
    default: return $c;
  }
}
require_once __DIR__ . '/../header.php';
?>

<div class="container" style="max-width: 1100px;">

  <div class="d-flex flex-column flex-md-row justify-content-between align-items-md-start gap-2 mb-3">
    <div>
      <h1 class="h4 mb-1">Stock (local)</h1>
      <div class="text-muted small">
        Suivi du matériel disponible / réservé / attribué.
      </div>
    </div>

    <div class="text-md-end d-flex flex-wrap gap-2 justify-content-md-end">
      <?php if ($isAdmin): ?>
        <a class="btn btn-primary btn-sm" href="<?= h(APP_BASE) ?>/stock/item_edit.php">+ Ajouter un objet</a>
        <a class="btn btn-outline-secondary btn-sm" href="<?= h(APP_BASE) ?>/stock/categories/index.php">Catégories</a>
        <a class="btn btn-outline-secondary btn-sm" href="<?= h(APP_BASE) ?>/stock/locations/index.php">Lieux</a>
      <?php endif; ?>
    </div>
  </div>

  <div class="card shadow-sm mb-3">
    <div class="card-body">
      <form method="get" class="row g-2 align-items-end">
        <div class="col-12 col-md-4">
          <label class="form-label">Recherche</label>
          <input type="text" name="q" class="form-control" value="<?= h($q) ?>"
                 placeholder="Titre, code, notes source…">
        </div>

        <div class="col-12 col-md-3">
          <label class="form-label">Statut</label>
          <select name="status" class="form-select">
            <option value="available" <?= $status==='available'?'selected':'' ?>>Disponibles</option>
            <option value="reserved"  <?= $status==='reserved'?'selected':'' ?>>Réservés</option>
            <option value="allocated" <?= $status==='allocated'?'selected':'' ?>>Attribués</option>
            <option value="out"       <?= $status==='out'?'selected':'' ?>>Sortis</option>
            <option value="discarded" <?= $status==='discarded'?'selected':'' ?>>Jetés / HS</option>
            <option value="all"       <?= $status==='all'?'selected':'' ?>>Tous</option>
          </select>
        </div>

        <div class="col-12 col-md-3">
          <label class="form-label">Catégorie</label>
          <select name="category_id" class="form-select">
            <option value="0">Toutes</option>
            <?php foreach ($cats as $c): ?>
              <?php $cid = (int)$c['id']; ?>
              <option value="<?= $cid ?>" <?= $categoryId===$cid?'selected':'' ?>>
                <?= h(catFullLabel($cid, $catLabels, $catParents)) ?>
              </option>
            <?php endforeach; ?>
          </select>
        </div>

        <div class="col-12 col-md-2">
          <label class="form-label">Lieu</label>
          <select name="location_id" class="form-select">
            <option value="0">Tous</option>
            <?php foreach ($locations as $l): ?>
              <?php $lid = (int)$l['id']; ?>
              <option value="<?= $lid ?>" <?= $locationId===$lid?'selected':'' ?>>
                <?= h((string)$l['label']) ?>
              </option>
            <?php endforeach; ?>
          </select>
        </div>

        <div class="col-12 d-flex gap-2 mt-2">
          <button class="btn btn-outline-primary w-100">Filtrer</button>
          <a class="btn btn-outline-secondary w-100" href="index.php">Réinitialiser</a>
        </div>
      </form>
    </div>
  </div>

  <div class="d-flex justify-content-between align-items-center mb-2">
    <div class="text-muted small">
      Résultats : <b><?= (int)$total ?></b>
      <?php if ($q !== ''): ?> • Recherche : <b><?= h($q) ?></b><?php endif; ?>
      <?php if ($status !== 'all'): ?> • Statut : <b><?= h($status) ?></b><?php endif; ?>
    </div>
    <div class="text-muted small">Page <?= (int)$page ?> / <?= (int)$totalPages ?></div>
  </div>

  <?php if ($total === 0): ?>
    <div class="alert alert-info mb-0">Aucun objet trouvé.</div>
    <?php require __DIR__ . '/../footer.php'; exit; ?>
  <?php endif; ?>

  <div class="table-responsive">
    <table class="table table-hover align-middle">
      <thead>
        <tr>
          <th>Objet</th>
          <th>Catégorie</th>
          <th>Lieu</th>
          <th class="text-end" style="width:110px;">Qté</th>
          <?php if (!$simpleMode): ?>
            <th style="width:140px;">État</th>
          <?php endif; ?>
          <th style="width:140px;">Statut</th>
          <th class="text-end" style="width:160px;">Actions</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($items as $it): ?>
          <?php
            $sid = (int)$it['id'];
            [$badge, $lbl] = statusBadge((string)$it['status']);
            $catId = (int)$it['category_id'];
            $catLabel = catFullLabel($catId, $catLabels, $catParents);

            $locLabel = trim((string)($it['location_label'] ?? ''));
            $qty = (int)($it['quantity'] ?? 1);

            $ref = trim((string)($it['ref_code'] ?? ''));
            $desc = trim((string)($it['description'] ?? ''));
          ?>
          <tr>
            <td>
              <div class="fw-semibold">
                <?= h((string)$it['title']) ?>
                <?php if ($ref !== ''): ?>
                  <span class="text-muted small">• <?= h($ref) ?></span>
                <?php endif; ?>
              </div>
              <?php if (!$simpleMode): ?>
                <?php if ($desc !== ''): ?>
                  <div class="text-muted small"><?= h(mb_strimwidth($desc, 0, 120, '…', 'UTF-8')) ?></div>
                <?php endif; ?>
              <?php endif; ?>
            </td>

            <td><?= h($catLabel) ?></td>
            <td><?= $locLabel !== '' ? h($locLabel) : '<span class="text-muted">—</span>' ?></td>

            <td class="text-end"><?= $qty ?></td>

            <?php if (!$simpleMode): ?>
              <td><?= h(condLabel((string)($it['condition_state'] ?? 'good'))) ?></td>
            <?php endif; ?>

            <td>
              <span class="badge bg-<?= h($badge) ?>"><?= h($lbl) ?></span>
            </td>

            <td class="text-end">
              <?php if ($isAdmin): ?>
                <a class="btn btn-sm btn-outline-primary"
                   href="<?= h(APP_BASE) ?>/stock/item_edit.php?id=<?= $sid ?>">Éditer</a>
              <?php else: ?>
                <span class="text-muted small">—</span>
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>

  <?php if ($totalPages > 1): ?>
    <nav aria-label="Pagination" class="mt-3">
      <ul class="pagination pagination-sm justify-content-center">
        <li class="page-item <?= $page<=1?'disabled':'' ?>">
          <a class="page-link" href="<?= h(buildUrl(['page' => max(1, $page-1)])) ?>">‹</a>
        </li>

        <?php
          $start = max(1, $page - 2);
          $end = min($totalPages, $page + 2);

          if ($start > 1) {
            echo '<li class="page-item"><a class="page-link" href="' . h(buildUrl(['page'=>1])) . '">1</a></li>';
            if ($start > 2) echo '<li class="page-item disabled"><span class="page-link">…</span></li>';
          }

          for ($p=$start; $p<=$end; $p++) {
            $active = $p === $page ? 'active' : '';
            echo '<li class="page-item ' . $active . '"><a class="page-link" href="' . h(buildUrl(['page'=>$p])) . '">' . (int)$p . '</a></li>';
          }

          if ($end < $totalPages) {
            if ($end < $totalPages - 1) echo '<li class="page-item disabled"><span class="page-link">…</span></li>';
            echo '<li class="page-item"><a class="page-link" href="' . h(buildUrl(['page'=>$totalPages])) . '">' . (int)$totalPages . '</a></li>';
          }
        ?>

        <li class="page-item <?= $page>=$totalPages?'disabled':'' ?>">
          <a class="page-link" href="<?= h(buildUrl(['page' => min($totalPages, $page+1)])) ?>">›</a>
        </li>
      </ul>
    </nav>
  <?php endif; ?>

</div>

<?php require __DIR__ . '/../footer.php'; ?>
