<?php
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../header.php';

if (empty($isAdmin)) {
    echo "<div class='alert alert-danger'>Accès réservé à l’administrateur.</div>";
    require __DIR__ . '/../footer.php';
    exit;
}

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($id <= 0) {
    echo "<p>Carton introuvable.</p>";
    require __DIR__ . '/../footer.php';
    exit;
}

// Récupérer le carton + convoi + catégorie
$stmt = $pdo->prepare("
  SELECT b.*, c.type AS category_type, c.label AS category_label, v.name AS convoy_name
  FROM boxes b
  JOIN categories c ON c.id = b.category_id
  JOIN convoys v ON v.id = b.convoy_id
  WHERE b.id = ?
");
$stmt->execute([$id]);
$box = $stmt->fetch();

if (!$box) {
    echo "<p>Carton introuvable.</p>";
    require __DIR__ . '/../footer.php';
    exit;
}

$convoy_id = (int)$box['convoy_id'];
$kind      = $box['kind']; // 'denrees' ou 'pharma'
$category_id = (int)$box['category_id'];
$weight_kg   = $box['weight_kg'] !== null ? (string)$box['weight_kg'] : '';
$comment     = $box['comment'] ?? '';

$errors = [];

// Charger les catégories du même type (y compris celle actuelle même si inactive)
$catStmt = $pdo->prepare("
  SELECT * FROM categories
  WHERE type = ? AND (is_active = 1 OR id = ?)
  ORDER BY label
");
$catStmt->execute([$kind, $category_id]);
$categories = $catStmt->fetchAll();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $category_id = (int)($_POST['category_id'] ?? 0);
    $weight_kg   = trim($_POST['weight_kg'] ?? '');
    $comment     = trim($_POST['comment'] ?? '');

    if ($category_id <= 0) {
        $errors[] = "La catégorie est obligatoire.";
    }

    $weightValue = null;
    if ($weight_kg !== '') {
        if (!is_numeric($weight_kg)) {
            $errors[] = "Le poids doit être un nombre (en kg).";
        } else {
            $weightValue = (float)$weight_kg;
        }
    }

    if (empty($errors)) {
        $upd = $pdo->prepare("
          UPDATE boxes
          SET category_id = ?, weight_kg = ?, comment = ?
          WHERE id = ?
        ");
        $upd->execute([
            $category_id,
            $weightValue,
            $comment !== '' ? $comment : null,
            $id
        ]);

        header('Location: ' . APP_BASE . '/convoys/view.php?id=' . $convoy_id);
        exit;
    }
}
?>
<h1 class="h4 mb-3">Modifier un carton</h1>
<p class="text-muted mb-2">
  Convoi : <?= htmlspecialchars($box['convoy_name']) ?><br>
  Code carton : <strong><?= htmlspecialchars($box['code']) ?></strong> (<?= htmlspecialchars($kind) ?>)
</p>

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
    <label class="form-label">Catégorie *</label>
    <select name="category_id" class="form-select" required>
      <option value="">-- Choisir une catégorie --</option>
      <?php foreach ($categories as $cat): ?>
        <option value="<?= (int)$cat['id'] ?>"
          <?= (int)$cat['id'] === (int)$category_id ? 'selected' : '' ?>>
          <?= htmlspecialchars($cat['label']) ?>
          <?php if (!$cat['is_active']): ?> (inactive)<?php endif; ?>
        </option>
      <?php endforeach; ?>
    </select>
  </div>

  <div class="mb-3">
    <label class="form-label">Poids du carton (kg)</label>
    <input type="text" name="weight_kg" class="form-control"
           value="<?= htmlspecialchars($weight_kg) ?>">
  </div>

  <div class="mb-3">
    <label class="form-label">Commentaire</label>
    <textarea name="comment" class="form-control" rows="2"><?= htmlspecialchars($comment) ?></textarea>
  </div>

  <button class="btn btn-primary">Enregistrer</button>
  <a href="../convoys/view.php?id=<?= $convoy_id ?>" class="btn btn-secondary">Annuler</a>
</form>

<?php require_once __DIR__ . '/../footer.php'; ?>