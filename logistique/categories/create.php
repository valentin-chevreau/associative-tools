<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../header.php';

$isAdmin = !empty($_SESSION['is_admin']);
if (!$isAdmin) {
    http_response_code(403);
    echo "<div class='alert alert-danger'>Accès interdit.</div>";
    require __DIR__ . '/../footer.php';
    exit;
}

// ✅ Racines actives uniquement
$rootsStmt = $pdo->query("
    SELECT id, label
    FROM categories
    WHERE parent_id IS NULL
      AND is_active = 1
    ORDER BY label
");
$roots = $rootsStmt->fetchAll();

$errors = [];
$label = '';
$label_ua = '';
$mode = 'root';
$parent_id = 0;

if (isset($_GET['parent_id']) && (int)$_GET['parent_id'] > 0) {
    $mode = 'child';
    $parent_id = (int)$_GET['parent_id'];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $label = trim($_POST['label'] ?? '');
    $label_ua = trim($_POST['label_ua'] ?? '');
    $mode  = ($_POST['mode'] ?? 'root') === 'child' ? 'child' : 'root';
    $parent_id = (int)($_POST['parent_id'] ?? 0);

    if ($label === '') {
        $errors[] = "Le libellé FR est obligatoire.";
    }

    if ($mode === 'child') {
        if ($parent_id <= 0) {
            $errors[] = "Veuillez choisir une catégorie racine.";
        } else {
            // ✅ Parent doit être racine ET active
            $chk = $pdo->prepare("
                SELECT id
                FROM categories
                WHERE id = ?
                  AND parent_id IS NULL
                  AND is_active = 1
            ");
            $chk->execute([$parent_id]);
            if (!$chk->fetch()) {
                $errors[] = "La catégorie parente doit être une racine active.";
            }
        }
    }

    if (empty($errors)) {
        try {
            $pdo->beginTransaction();

            $labelUaValue = ($label_ua === '') ? null : $label_ua;

            if ($mode === 'root') {
                $ins = $pdo->prepare("
                    INSERT INTO categories (label, label_ua, parent_id, root_id, is_active)
                    VALUES (?, ?, NULL, NULL, 1)
                ");
                $ins->execute([$label, $labelUaValue]);

                $newId = (int)$pdo->lastInsertId();

                $upd = $pdo->prepare("UPDATE categories SET root_id = ? WHERE id = ?");
                $upd->execute([$newId, $newId]);

                $pdo->commit();
                $_SESSION['flash_success'] = "Catégorie racine créée.";
                header('Location: ' . APP_BASE . '/categories/view.php');
                exit;
            }

            $ins = $pdo->prepare("
                INSERT INTO categories (label, label_ua, parent_id, root_id, is_active)
                VALUES (?, ?, ?, ?, 1)
            ");
            $ins->execute([$label, $labelUaValue, $parent_id, $parent_id]);

            $pdo->commit();
            $_SESSION['flash_success'] = "Sous-catégorie créée.";
            header('Location: ' . APP_BASE . '/categories/view.php');
            exit;

        } catch (Exception $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            $errors[] = "Erreur lors de l'enregistrement.";
        }
    }
}
?>

<h1 class="h4 mb-3">Créer une catégorie</h1>

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
  <div class="mb-3">
    <label class="form-label">Libellé FR *</label>
    <input type="text" name="label" class="form-control" value="<?= htmlspecialchars($label) ?>" required>
  </div>

  <div class="mb-3">
    <label class="form-label">Libellé UA (optionnel)</label>
    <input type="text" name="label_ua" class="form-control" value="<?= htmlspecialchars($label_ua) ?>">
    <div class="form-text">Recommandé pour les étiquettes et les exports douane.</div>
  </div>

  <div class="mb-3">
    <label class="form-label">Type</label>
    <select name="mode" class="form-select" id="modeSelect">
      <option value="root"  <?= $mode === 'root' ? 'selected' : '' ?>>Catégorie racine</option>
      <option value="child" <?= $mode === 'child' ? 'selected' : '' ?>>Sous-catégorie</option>
    </select>
  </div>

  <div class="mb-3" id="parentBlock" style="<?= $mode === 'child' ? '' : 'display:none;' ?>">
    <label class="form-label">Racine parente *</label>
    <select name="parent_id" class="form-select">
      <option value="0">-- Choisir une racine active --</option>
      <?php foreach ($roots as $r): ?>
        <option value="<?= (int)$r['id'] ?>" <?= (int)$parent_id === (int)$r['id'] ? 'selected' : '' ?>>
          <?= htmlspecialchars($r['label']) ?>
        </option>
      <?php endforeach; ?>
    </select>
  </div>

  <div class="d-flex gap-2">
    <button class="btn btn-primary">Enregistrer</button>
    <a href="<?= APP_BASE ?>/categories/view.php" class="btn btn-secondary">Annuler</a>
  </div>
</form>

<script>
(function () {
  const mode = document.getElementById('modeSelect');
  const parent = document.getElementById('parentBlock');
  mode.addEventListener('change', () => {
    parent.style.display = (mode.value === 'child') ? '' : 'none';
  });
})();
</script>

<?php require __DIR__ . '/../footer.php'; ?>