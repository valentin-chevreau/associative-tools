<?php
require_once __DIR__ . '/../../db.php';

if (session_status() === PHP_SESSION_NONE) session_start();

$isAdmin = !empty($_SESSION['is_admin']);
if (!$isAdmin) {
    http_response_code(403);
    exit('Accès interdit');
}

function h(string $s): string { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }
function post_str(string $k, string $d=''): string { return isset($_POST[$k]) ? trim((string)$_POST[$k]) : $d; }
function post_int(string $k, int $d=0): int { return isset($_POST[$k]) ? (int)$_POST[$k] : $d; }

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$isEdit = ($id > 0);

/* Valeurs par défaut */
$cat = [
    'id' => 0,
    'label' => '',
    'parent_id' => null,
    'sort_order' => 100,
    'is_active' => 1,
];

/* Chargement si édition */
if ($isEdit) {
    $stmt = $pdo->prepare("SELECT * FROM stock_categories WHERE id = ?");
    $stmt->execute([$id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        $_SESSION['flash_error'] = "Catégorie introuvable.";
        header('Location: index.php');
        exit;
    }
    $cat = array_merge($cat, $row);
    $cat['parent_id'] = $row['parent_id'] !== null ? (int)$row['parent_id'] : null;
}

/* Liste des catégories parentes possibles */
$parentsStmt = $pdo->prepare("
    SELECT id, label
    FROM stock_categories
    WHERE id != ?
    ORDER BY label
");
$parentsStmt->execute([$id]);
$parents = $parentsStmt->fetchAll(PDO::FETCH_ASSOC);

/* ENREGISTREMENT (AVANT header.php) */
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $label = post_str('label');
    $parent_raw = post_str('parent_id', '');
    $parent_id = ($parent_raw === '' || $parent_raw === '0') ? null : (int)$parent_raw;
    $sort_order = post_int('sort_order', 100);
    $is_active = post_int('is_active', 1) ? 1 : 0;

    if ($label === '') {
        $errors[] = "Le libellé est obligatoire.";
    }

    if (empty($errors)) {
        if ($isEdit) {
            $upd = $pdo->prepare("
                UPDATE stock_categories
                SET label = ?, parent_id = ?, sort_order = ?, is_active = ?
                WHERE id = ?
            ");
            $upd->execute([$label, $parent_id, $sort_order, $is_active, $id]);
            $_SESSION['flash_success'] = "Catégorie mise à jour.";
        } else {
            $ins = $pdo->prepare("
                INSERT INTO stock_categories (label, parent_id, sort_order, is_active)
                VALUES (?, ?, ?, ?)
            ");
            $ins->execute([$label, $parent_id, $sort_order, $is_active]);
            $_SESSION['flash_success'] = "Catégorie créée.";
        }

        header('Location: index.php');
        exit;
    }

    // Réaffichage si erreurs
    $cat = array_merge($cat, [
        'label' => $label,
        'parent_id' => $parent_id,
        'sort_order' => $sort_order,
        'is_active' => $is_active,
    ]);
}

/* AFFICHAGE HTML */
require_once __DIR__ . '/../../header.php';
?>

<div class="d-flex justify-content-between align-items-start mb-3">
  <div>
    <h1 class="h4 mb-1"><?= $isEdit ? "Modifier une catégorie" : "Nouvelle catégorie" ?></h1>
    <div class="text-muted small">Stock local</div>
  </div>
  <div>
    <a class="btn btn-outline-secondary btn-sm" href="index.php">← Retour</a>
  </div>
</div>

<?php if (!empty($errors)): ?>
  <div class="alert alert-danger">
    <ul class="mb-0">
      <?php foreach ($errors as $e): ?><li><?= h($e) ?></li><?php endforeach; ?>
    </ul>
  </div>
<?php endif; ?>

<div class="card shadow-sm">
  <div class="card-body">
    <form method="post">
      <div class="row g-3">

        <div class="col-12 col-md-6">
          <label class="form-label">Libellé *</label>
          <input type="text" name="label" class="form-control"
                 value="<?= h((string)$cat['label']) ?>" required>
        </div>

        <div class="col-12 col-md-6">
          <label class="form-label">Catégorie parente</label>
          <select name="parent_id" class="form-select">
            <option value="">— Racine —</option>
            <?php foreach ($parents as $p): ?>
              <option value="<?= (int)$p['id'] ?>"
                <?= ((int)($cat['parent_id'] ?? 0) === (int)$p['id']) ? 'selected' : '' ?>>
                <?= h($p['label']) ?>
              </option>
            <?php endforeach; ?>
          </select>
        </div>

        <div class="col-12 col-md-4">
          <label class="form-label">Ordre d’affichage</label>
          <input type="number" name="sort_order" class="form-control"
                 value="<?= (int)$cat['sort_order'] ?>">
        </div>

        <div class="col-12 col-md-4 d-flex align-items-end">
          <div class="form-check">
            <input class="form-check-input" type="checkbox" name="is_active" value="1"
                   id="isActive" <?= ((int)$cat['is_active'] === 1) ? 'checked' : '' ?>>
            <label class="form-check-label" for="isActive">
              Catégorie active
            </label>
          </div>
        </div>

        <div class="col-12 text-end mt-2">
          <button class="btn btn-primary px-4">Enregistrer</button>
        </div>

      </div>
    </form>
  </div>
</div>

<?php require __DIR__ . '/../../footer.php'; ?>
