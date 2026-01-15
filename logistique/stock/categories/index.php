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

/* Actions (archive/unarchive) */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $action = $_POST['action'] ?? '';
  $catId = isset($_POST['id']) ? (int)$_POST['id'] : 0;

  if ($catId > 0 && in_array($action, ['archive','unarchive'], true)) {
    $val = ($action === 'archive') ? 0 : 1;
    $st = $pdo->prepare("UPDATE stock_categories SET is_active = ? WHERE id = ?");
    $st->execute([$val, $catId]);
    $_SESSION['flash_success'] = ($val === 0) ? "Catégorie archivée." : "Catégorie réactivée.";
    header('Location: index.php');
    exit;
  }
}

/* Liste brute */
$stmt = $pdo->query("
  SELECT id, parent_id, label, is_active, sort_order
  FROM stock_categories
  ORDER BY COALESCE(parent_id, id), parent_id IS NULL DESC, sort_order ASC, label ASC
");
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

/* Build tree */
$byParent = [];
$nodes = [];
foreach ($rows as $r) {
  $id = (int)$r['id'];
  $pid = $r['parent_id'] !== null ? (int)$r['parent_id'] : 0;
  $nodes[$id] = $r;
  if (!isset($byParent[$pid])) $byParent[$pid] = [];
  $byParent[$pid][] = $id;
}
function walkCats(int $parentId, array $byParent, array $nodes, int $depth = 0, array &$out = []): void {
  if (empty($byParent[$parentId])) return;
  foreach ($byParent[$parentId] as $id) {
    $out[] = [$nodes[$id], $depth];
    walkCats($id, $byParent, $nodes, $depth + 1, $out);
  }
}
$flat = [];
walkCats(0, $byParent, $nodes, 0, $flat);

/* Flash */
if (!empty($_SESSION['flash_success'])) {
  echo '<div class="alert alert-success">' . h($_SESSION['flash_success']) . '</div>';
  unset($_SESSION['flash_success']);
}
if (!empty($_SESSION['flash_error'])) {
  echo '<div class="alert alert-danger">' . h($_SESSION['flash_error']) . '</div>';
  unset($_SESSION['flash_error']);
}

require_once __DIR__ . '/../../header.php';
?>

<div class="d-flex justify-content-between align-items-start mb-3">
  <div>
    <h1 class="h4 mb-1">Stock — Catégories</h1>
    <div class="text-muted small">Catégories principales + sous-catégories (indentées).</div>
  </div>
  <div class="d-flex gap-2">
    <a class="btn btn-outline-secondary btn-sm" href="<?= h(APP_BASE) ?>/stock/index.php">← Stock</a>
    <a class="btn btn-primary btn-sm" href="edit.php">+ Nouvelle catégorie</a>
  </div>
</div>

<div class="card shadow-sm">
  <div class="card-body">
    <?php if (empty($flat)): ?>
      <div class="alert alert-info mb-0">Aucune catégorie. Crée la première.</div>
    <?php else: ?>
      <div class="table-responsive">
        <table class="table table-sm align-middle mb-0">
          <thead>
            <tr>
              <th>Libellé</th>
              <th style="width:120px;">Statut</th>
              <th class="text-end" style="width:220px;">Actions</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($flat as [$c, $depth]): ?>
              <?php
                $id = (int)$c['id'];
                $active = ((int)$c['is_active'] === 1);
              ?>
              <tr>
                <td>
                  <div style="padding-left:<?= (int)($depth * 18) ?>px;">
                    <?php if ($depth === 0): ?>
                      <span class="badge bg-info text-dark me-2">Principale</span>
                      <strong><?= h((string)$c['label']) ?></strong>
                    <?php else: ?>
                      <span class="text-muted me-2">↳</span><?= h((string)$c['label']) ?>
                    <?php endif; ?>
                  </div>
                </td>
                <td>
                  <span class="badge bg-<?= $active ? 'success' : 'secondary' ?>">
                    <?= $active ? 'Active' : 'Archivée' ?>
                  </span>
                </td>
                <td class="text-end">
                  <a class="btn btn-sm btn-outline-primary" href="edit.php?id=<?= $id ?>">Modifier</a>

                  <form method="post" class="d-inline">
                    <input type="hidden" name="id" value="<?= $id ?>">
                    <?php if ($active): ?>
                      <input type="hidden" name="action" value="archive">
                      <button class="btn btn-sm btn-outline-secondary"
                              onclick="return confirm('Archiver cette catégorie ?');">
                        Archiver
                      </button>
                    <?php else: ?>
                      <input type="hidden" name="action" value="unarchive">
                      <button class="btn btn-sm btn-outline-success">Réactiver</button>
                    <?php endif; ?>
                  </form>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>
  </div>
</div>

<?php require __DIR__ . '/../../footer.php'; ?>
