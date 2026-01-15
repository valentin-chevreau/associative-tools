<?php
require_once __DIR__ . '/../../db.php';

if (session_status() === PHP_SESSION_NONE) session_start();

$isAdmin = !empty($_SESSION['is_admin']);
if (!$isAdmin) {
    http_response_code(403);
    echo "<div class='alert alert-danger'>Accès interdit.</div>";
    require __DIR__ . '/../../footer.php';
    exit;
}

function h(string $s): string { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }
function post_str(string $k, string $d=''): string { return isset($_POST[$k]) ? trim((string)$_POST[$k]) : $d; }
function post_int(string $k, int $d=0): int { return isset($_POST[$k]) ? (int)$_POST[$k] : $d; }

/* Flash */
if (!empty($_SESSION['flash_success'])) {
    echo '<div class="alert alert-success">' . h($_SESSION['flash_success']) . '</div>';
    unset($_SESSION['flash_success']);
}
if (!empty($_SESSION['flash_error'])) {
    echo '<div class="alert alert-danger">' . h($_SESSION['flash_error']) . '</div>';
    unset($_SESSION['flash_error']);
}

/* Filtre */
$allowed = ['active','inactive','all'];
$filter = $_GET['status'] ?? 'active';
if (!in_array($filter, $allowed, true)) $filter = 'active';

/* Actions */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = post_str('action');

    if ($action === 'create') {
        $label = post_str('label');
        $notes = post_str('notes');
        $sort  = post_int('sort_order', 100);

        if ($label === '') {
            $_SESSION['flash_error'] = "Libellé obligatoire.";
            header('Location: index.php');
            exit;
        }

        try {
            $ins = $pdo->prepare("
                INSERT INTO stock_locations (label, notes, is_active, sort_order)
                VALUES (?, ?, 1, ?)
            ");
            $ins->execute([$label, ($notes !== '' ? $notes : null), $sort]);
            $_SESSION['flash_success'] = "Lieu ajouté.";
        } catch (Throwable $e) {
            // unique label
            $_SESSION['flash_error'] = "Impossible d'ajouter (libellé déjà utilisé ?).";
        }
        header('Location: index.php');
        exit;
    }

    if ($action === 'update') {
        $id = post_int('id');
        $label = post_str('label');
        $notes = post_str('notes');
        $sort  = post_int('sort_order', 100);

        if ($id <= 0) {
            $_SESSION['flash_error'] = "Lieu introuvable.";
            header('Location: index.php');
            exit;
        }
        if ($label === '') {
            $_SESSION['flash_error'] = "Libellé obligatoire.";
            header('Location: index.php');
            exit;
        }

        try {
            $upd = $pdo->prepare("
                UPDATE stock_locations
                SET label = ?, notes = ?, sort_order = ?
                WHERE id = ?
            ");
            $upd->execute([$label, ($notes !== '' ? $notes : null), $sort, $id]);
            $_SESSION['flash_success'] = "Lieu mis à jour.";
        } catch (Throwable $e) {
            $_SESSION['flash_error'] = "Impossible de mettre à jour (libellé déjà utilisé ?).";
        }
        header('Location: index.php?status=' . urlencode($filter));
        exit;
    }

    if ($action === 'toggle_active') {
        $id = post_int('id');
        $to = post_int('to'); // 1 ou 0
        if ($id <= 0) {
            $_SESSION['flash_error'] = "Lieu introuvable.";
            header('Location: index.php');
            exit;
        }
        $to = $to ? 1 : 0;

        $upd = $pdo->prepare("UPDATE stock_locations SET is_active = ? WHERE id = ?");
        $upd->execute([$to, $id]);
        $_SESSION['flash_success'] = $to ? "Lieu réactivé." : "Lieu archivé.";
        header('Location: index.php?status=' . urlencode($filter));
        exit;
    }
}

/* Charger liste */
$where = '';
$params = [];
if ($filter === 'active') { $where = "WHERE is_active = 1"; }
if ($filter === 'inactive') { $where = "WHERE is_active = 0"; }

$stmt = $pdo->prepare("
    SELECT id, label, notes, is_active, sort_order, created_at
    FROM stock_locations
    $where
    ORDER BY sort_order ASC, label ASC, id ASC
");
$stmt->execute($params);
$locations = $stmt->fetchAll(PDO::FETCH_ASSOC);

/* Helper URL filtre */
function filterUrl(string $s): string {
    return 'index.php' . ($s === 'active' ? '' : ('?status=' . urlencode($s)));
}
    
require_once __DIR__ . '/../../header.php';
?>

<div class="container" style="max-width: 1100px;">

  <div class="d-flex flex-column flex-md-row justify-content-between align-items-md-start gap-2 mb-3">
    <div>
      <h1 class="h4 mb-1">Lieux (stock)</h1>
      <div class="text-muted small">Gérer les lieux physiques : local, garage, box…</div>
    </div>
  </div>

  <div class="mb-3">
    <div class="btn-group btn-group-sm" role="group" aria-label="Filtre">
      <a class="btn btn-outline-secondary <?= $filter==='active'?'active':'' ?>" href="<?= h(filterUrl('active')) ?>">Actifs</a>
      <a class="btn btn-outline-secondary <?= $filter==='inactive'?'active':'' ?>" href="<?= h(filterUrl('inactive')) ?>">Archivés</a>
      <a class="btn btn-outline-secondary <?= $filter==='all'?'active':'' ?>" href="<?= h(filterUrl('all')) ?>">Tous</a>
    </div>
  </div>

  <div class="card shadow-sm mb-3">
    <div class="card-body">
      <h2 class="h6 mb-3">Ajouter un lieu</h2>
      <form method="post" class="row g-2 align-items-end">
        <input type="hidden" name="action" value="create">

        <div class="col-12 col-md-4">
          <label class="form-label">Libellé *</label>
          <input type="text" name="label" class="form-control" placeholder="Ex : Local Tours, Box Nord…" required>
        </div>

        <div class="col-12 col-md-5">
          <label class="form-label">Notes</label>
          <input type="text" name="notes" class="form-control" placeholder="Ex : accès vendredi, code portail…">
        </div>

        <div class="col-12 col-md-2">
          <label class="form-label">Ordre</label>
          <input type="number" name="sort_order" class="form-control" value="100">
        </div>

        <div class="col-12 col-md-1 d-grid">
          <button class="btn btn-primary">+ Ajouter</button>
        </div>
      </form>
    </div>
  </div>

  <div class="card shadow-sm">
    <div class="card-body">
      <div class="d-flex justify-content-between align-items-center mb-2">
        <h2 class="h6 mb-0">Liste</h2>
        <div class="text-muted small"><?= count($locations) ?> lieu(x)</div>
      </div>

      <?php if (empty($locations)): ?>
        <div class="text-muted">Aucun lieu dans ce filtre.</div>
      <?php else: ?>
        <div class="table-responsive">
          <table class="table table-sm align-middle mb-0">
            <thead>
              <tr>
                <th style="width:70px;">ID</th>
                <th>Libellé</th>
                <th>Notes</th>
                <th style="width:110px;">Ordre</th>
                <th style="width:120px;">Statut</th>
                <th class="text-end" style="width:260px;">Actions</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($locations as $l): ?>
                <?php
                  $id = (int)$l['id'];
                  $isActive = ((int)$l['is_active'] === 1);
                ?>
                <tr>
                  <td><?= $id ?></td>

                  <td>
                    <div class="fw-semibold"><?= h((string)$l['label']) ?></div>
                    <div class="text-muted small">Créé : <?= h((string)$l['created_at']) ?></div>
                  </td>

                  <td><?= h((string)($l['notes'] ?? '')) ?></td>

                  <td><?= (int)($l['sort_order'] ?? 100) ?></td>

                  <td>
                    <span class="badge bg-<?= $isActive ? 'success' : 'secondary' ?>">
                      <?= $isActive ? 'Actif' : 'Archivé' ?>
                    </span>
                  </td>

                  <td class="text-end">
                    <!-- Edit inline -->
                    <button class="btn btn-sm btn-outline-primary"
                            type="button"
                            data-bs-toggle="collapse"
                            data-bs-target="#editLoc<?= $id ?>"
                            aria-expanded="false">
                      Éditer
                    </button>

                    <form method="post" class="d-inline">
                      <input type="hidden" name="action" value="toggle_active">
                      <input type="hidden" name="id" value="<?= $id ?>">
                      <input type="hidden" name="to" value="<?= $isActive ? 0 : 1 ?>">
                      <button class="btn btn-sm btn-outline-<?= $isActive ? 'danger' : 'success' ?>"
                              onclick="return confirm('<?= $isActive ? "Archiver" : "Réactiver" ?> ce lieu ?');">
                        <?= $isActive ? 'Archiver' : 'Réactiver' ?>
                      </button>
                    </form>

                    <div class="collapse mt-2" id="editLoc<?= $id ?>">
                      <div class="border rounded p-2 bg-light">
                        <form method="post" class="row g-2 align-items-end">
                          <input type="hidden" name="action" value="update">
                          <input type="hidden" name="id" value="<?= $id ?>">

                          <div class="col-12 col-md-4">
                            <label class="form-label small mb-1">Libellé *</label>
                            <input class="form-control form-control-sm" name="label"
                                   value="<?= h((string)$l['label']) ?>" required>
                          </div>

                          <div class="col-12 col-md-5">
                            <label class="form-label small mb-1">Notes</label>
                            <input class="form-control form-control-sm" name="notes"
                                   value="<?= h((string)($l['notes'] ?? '')) ?>">
                          </div>

                          <div class="col-12 col-md-2">
                            <label class="form-label small mb-1">Ordre</label>
                            <input class="form-control form-control-sm" type="number" name="sort_order"
                                   value="<?= (int)($l['sort_order'] ?? 100) ?>">
                          </div>

                          <div class="col-12 col-md-1 d-grid">
                            <button class="btn btn-sm btn-primary">OK</button>
                          </div>
                        </form>
                      </div>
                    </div>

                  </td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      <?php endif; ?>
    </div>
  </div>

</div>

<?php require __DIR__ . '/../../footer.php'; ?>
