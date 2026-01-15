<?php
require_once __DIR__ . '/../db.php';

if (session_status() === PHP_SESSION_NONE) {
  session_start();
}

$isAdmin = !empty($_SESSION['is_admin']);
$simpleMode = !empty($_SESSION['simple_mode']);

// Helpers
function h(string $s): string { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }

// Flash
if (!empty($_SESSION['flash_success'])) {
  echo '<div class="alert alert-success">' . h($_SESSION['flash_success']) . '</div>';
  unset($_SESSION['flash_success']);
}
if (!empty($_SESSION['flash_error'])) {
  echo '<div class="alert alert-danger">' . h($_SESSION['flash_error']) . '</div>';
  unset($_SESSION['flash_error']);
}

// Filtres
$allowedStatus = ['all','active','inactive'];
$status = $_GET['status'] ?? 'active';
if (!in_array($status, $allowedStatus, true)) $status = 'active';

$q = trim((string)($_GET['q'] ?? ''));
$qLike = '%' . $q . '%';

// Pagination simple
$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = 25;
$offset = ($page - 1) * $perPage;

// Construire WHERE dynamique
$where = [];
$params = [];

if ($status !== 'all') {
  $where[] = "f.status = ?";
  $params[] = $status;
}
if ($q !== '') {
  $where[] = "(f.lastname LIKE ? OR f.firstname LIKE ? OR f.public_ref LIKE ? OR f.phone LIKE ? OR f.email LIKE ? OR f.city LIKE ?)";
  array_push($params, $qLike, $qLike, $qLike, $qLike, $qLike, $qLike);
}

$whereSql = $where ? ("WHERE " . implode(" AND ", $where)) : "";

// Count
$countStmt = $pdo->prepare("SELECT COUNT(*) AS c FROM families f $whereSql");
$countStmt->execute($params);
$total = (int)($countStmt->fetch()['c'] ?? 0);
$totalPages = max(1, (int)ceil($total / $perPage));

// List
$listSql = "
  SELECT f.*
  FROM families f
  $whereSql
  ORDER BY (f.status='active') DESC, f.lastname ASC, f.firstname ASC, f.id DESC
  LIMIT $perPage OFFSET $offset
";
$listStmt = $pdo->prepare($listSql);
$listStmt->execute($params);
$families = $listStmt->fetchAll(PDO::FETCH_ASSOC);

function buildUrl(array $overrides = []): string {
  $base = $_GET;
  foreach ($overrides as $k => $v) {
    if ($v === null) unset($base[$k]);
    else $base[$k] = $v;
  }
  $qs = http_build_query($base);
  return 'index.php' . ($qs ? ('?' . $qs) : '');
}
require_once __DIR__ . '/../header.php';

?>
<div class="container" style="max-width: 1100px;">

  <div class="d-flex flex-column flex-md-row justify-content-between align-items-md-start gap-2 mb-3">
    <div>
      <h1 class="h4 mb-1">Familles</h1>
      <div class="text-muted small">
        Suivi des familles accompagnées (logement / équipement). Réservations d’objets : étape suivante.
      </div>
    </div>

    <div class="text-md-end">
      <?php if ($isAdmin): ?>
        <a class="btn btn-primary btn-sm" href="<?= h(APP_BASE) ?>/families/edit.php">+ Nouvelle famille</a>
      <?php endif; ?>
    </div>
  </div>

  <div class="card shadow-sm mb-3">
    <div class="card-body">
      <form method="get" class="row g-2 align-items-end">
        <div class="col-12 col-md-5">
          <label class="form-label">Recherche</label>
          <input type="text" name="q" class="form-control" value="<?= h($q) ?>"
                 placeholder="Nom, prénom, réf, ville, tel, email…">
        </div>

        <div class="col-12 col-md-3">
          <label class="form-label">Statut</label>
          <select name="status" class="form-select">
            <option value="active" <?= $status==='active'?'selected':'' ?>>Actives</option>
            <option value="inactive" <?= $status==='inactive'?'selected':'' ?>>Inactives</option>
            <option value="all" <?= $status==='all'?'selected':'' ?>>Toutes</option>
          </select>
        </div>

        <div class="col-12 col-md-4 d-flex gap-2">
          <button class="btn btn-outline-primary w-100">Filtrer</button>
          <a class="btn btn-outline-secondary w-100" href="index.php">Réinitialiser</a>
        </div>
      </form>
    </div>
  </div>

  <?php if ($total === 0): ?>
    <div class="alert alert-info mb-0">Aucune famille trouvée.</div>
    <?php require __DIR__ . '/../footer.php'; exit; ?>
  <?php endif; ?>

  <div class="d-flex justify-content-between align-items-center mb-2">
    <div class="text-muted small">
      Résultats : <b><?= (int)$total ?></b>
      <?php if ($q !== ''): ?> • Recherche : <b><?= h($q) ?></b><?php endif; ?>
      <?php if ($status !== 'all'): ?> • Statut : <b><?= h($status) ?></b><?php endif; ?>
    </div>

    <div class="text-muted small">
      Page <?= (int)$page ?> / <?= (int)$totalPages ?>
    </div>
  </div>

  <div class="table-responsive">
    <table class="table table-hover align-middle">
      <thead>
        <tr>
          <th>Famille</th>
          <th>Ville</th>
          <?php if (!$simpleMode): ?>
            <th>Contact</th>
          <?php endif; ?>
          <th>Statut</th>
          <th class="text-end">Actions</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($families as $f): ?>
          <?php
            $name = trim((string)($f['lastname'] ?? '') . ' ' . (string)($f['firstname'] ?? ''));
            $ref  = trim((string)($f['public_ref'] ?? ''));
            $city = trim((string)($f['city'] ?? ''));
            $phone = trim((string)($f['phone'] ?? ''));
            $email = trim((string)($f['email'] ?? ''));
            $st = (string)($f['status'] ?? 'active');
            $badge = $st === 'active' ? 'success' : 'secondary';
          ?>
          <tr>
            <td>
              <div class="fw-semibold">
                <?= h($name !== '' ? $name : '—') ?>
                <?php if ($ref !== ''): ?>
                  <span class="text-muted small">• <?= h($ref) ?></span>
                <?php endif; ?>
              </div>
              <?php if (!$simpleMode): ?>
                <?php $hn = trim((string)($f['housing_notes'] ?? '')); ?>
                <?php if ($hn !== ''): ?>
                  <div class="text-muted small"><?= h($hn) ?></div>
                <?php endif; ?>
              <?php endif; ?>
            </td>

            <td><?= h($city !== '' ? $city : '—') ?></td>

            <?php if (!$simpleMode): ?>
              <td>
                <div class="small">
                  <?= $phone !== '' ? h($phone) : '<span class="text-muted">—</span>' ?>
                  <?php if ($email !== ''): ?>
                    <div class="text-muted small"><?= h($email) ?></div>
                  <?php endif; ?>
                </div>
              </td>
            <?php endif; ?>

            <td>
              <span class="badge bg-<?= h($badge) ?>">
                <?= h($st === 'active' ? 'Active' : 'Inactive') ?>
              </span>
            </td>

            <td class="text-end">
              <a class="btn btn-sm btn-outline-primary"
                 href="<?= h(APP_BASE) ?>/families/view.php?id=<?= (int)$f['id'] ?>">Ouvrir</a>
              <?php if ($isAdmin): ?>
                <a class="btn btn-sm btn-primary"
                   href="<?= h(APP_BASE) ?>/families/edit.php?id=<?= (int)$f['id'] ?>">Éditer</a>
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
          // fenêtre simple
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
