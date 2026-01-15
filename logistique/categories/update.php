<?php
if (session_status() === PHP_SESSION_NONE) session_start();

require_once __DIR__ . '/../config.php';

require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../header.php';

$isAdmin = !empty($_SESSION['is_admin']);
if (!$isAdmin) {
    http_response_code(403);
    echo "<div class='alert alert-danger'>Accès interdit.</div>";
    require_once __DIR__ . '/../footer.php';
    exit;
}

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($id <= 0) {
    echo "<div class='alert alert-danger'>Catégorie introuvable.</div>";
    require_once __DIR__ . '/../footer.php';
    exit;
}

$stmt = $pdo->prepare("SELECT * FROM categories WHERE id = ?");
$stmt->execute([$id]);
$cat = $stmt->fetch();
if (!$cat) {
    echo "<div class='alert alert-danger'>Catégorie introuvable.</div>";
    require_once __DIR__ . '/../footer.php';
    exit;
}

$errors = [];

$label    = (string)($cat['label'] ?? '');
$label_ua = (string)($cat['label_ua'] ?? '');
$is_active = (int)($cat['is_active'] ?? 1);

// Parent (racine si NULL)
$currentParentId = $cat['parent_id'] ?? null;
$currentParentId = ($currentParentId === null) ? null : (int)$currentParentId;

// Parents possibles = racines actives (sauf soi-même)
$parentsStmt = $pdo->prepare("
  SELECT id, label
  FROM categories
  WHERE parent_id IS NULL
    AND is_active = 1
    AND id <> ?
  ORDER BY label
");
$parentsStmt->execute([$id]);
$possibleParents = $parentsStmt->fetchAll();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $label    = trim($_POST['label'] ?? '');
    $label_ua = trim($_POST['label_ua'] ?? '');
    $is_active = isset($_POST['is_active']) ? 1 : 0;

    $parentRaw = $_POST['parent_id'] ?? '';
    $parent_id = null;
    if ($parentRaw !== '' && ctype_digit($parentRaw)) {
        $parent_id = (int)$parentRaw;
    }

    if ($label === '') $errors[] = "Le libellé FR est obligatoire.";

    // Si parent_id renseigné, il doit exister et être une racine active
    if ($parent_id !== null) {
        $chk = $pdo->prepare("SELECT id FROM categories WHERE id = ? AND parent_id IS NULL AND is_active = 1");
        $chk->execute([$parent_id]);
        if (!$chk->fetch()) {
            $errors[] = "Catégorie parente invalide (doit être une racine active).";
        }
    }

    if (empty($errors)) {
        /**
         * Règle 2 niveaux :
         * - Si parent_id est NULL -> catégorie racine => root_id = id
         * - Sinon -> sous-catégorie => root_id = parent_id
         */
        $pdo->beginTransaction();
        try {
            $root_id = ($parent_id === null) ? $id : $parent_id;

            $upd = $pdo->prepare("
              UPDATE categories
              SET label = ?, label_ua = ?, parent_id = ?, root_id = ?, is_active = ?
              WHERE id = ?
            ");
            $upd->execute([
                $label,
                ($label_ua === '' ? null : $label_ua),
                $parent_id,
                $root_id,
                $is_active,
                $id
            ]);

            // Si c'est devenu une racine, on force root_id=id sur elle-même
            if ($parent_id === null) {
                $pdo->prepare("UPDATE categories SET root_id = id WHERE id = ?")->execute([$id]);
            }

            $pdo->commit();

            $_SESSION['flash_success'] = "Catégorie mise à jour.";
            header('Location: ' . APP_BASE . '/categories/view.php');
            exit;

        } catch (Exception $e) {
            $pdo->rollBack();
            $errors[] = "Erreur lors de la mise à jour.";
        }
    }
}
?>

<div class="container">
  <h1 class="h4 mb-3">Modifier une catégorie</h1>

  <?php if (!empty($errors)): ?>
    <div class="alert alert-danger">
      <ul class="mb-0">
        <?php foreach ($errors as $e): ?>
          <li><?= htmlspecialchars($e) ?></li>
        <?php endforeach; ?>
      </ul>
    </div>
  <?php endif; ?>

  <form method="post" class="card shadow-sm p-3">
    <div class="row g-3">
      <div class="col-12 col-md-6">
        <label class="form-label">Libellé FR *</label>
        <input class="form-control" name="label" value="<?= htmlspecialchars($label) ?>" required>
      </div>

      <div class="col-12 col-md-6">
        <label class="form-label">Libellé UA (optionnel)</label>
        <input class="form-control" name="label_ua" value="<?= htmlspecialchars($label_ua) ?>">
        <div class="form-text">
          Recommandé pour les étiquettes et (plus tard) les exports douane.
        </div>
      </div>

      <div class="col-12 col-md-6">
        <label class="form-label">Catégorie parente</label>
        <select class="form-select" name="parent_id">
          <option value="">— Racine (aucune parente) —</option>
          <?php foreach ($possibleParents as $p): ?>
            <option value="<?= (int)$p['id'] ?>"
              <?= ($currentParentId !== null && (int)$p['id'] === (int)$currentParentId) ? 'selected' : '' ?>>
              <?= htmlspecialchars($p['label']) ?>
            </option>
          <?php endforeach; ?>
        </select>
        <div class="form-text">Seules les racines actives sont proposées.</div>
      </div>

      <div class="col-12 col-md-6 d-flex align-items-end">
        <div class="form-check">
          <input class="form-check-input" type="checkbox" id="is_active" name="is_active" <?= $is_active ? 'checked' : '' ?>>
          <label class="form-check-label" for="is_active">Active</label>
        </div>
      </div>
    </div>

    <div class="d-flex justify-content-end gap-2 mt-3">
      <a class="btn btn-outline-secondary" href="<?= APP_BASE ?>/categories/view.php">Annuler</a>
      <button class="btn btn-primary">Enregistrer</button>
    </div>
  </form>
</div>

<?php require_once __DIR__ . '/../footer.php'; ?>