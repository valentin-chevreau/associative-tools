<?php
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../header.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$isAdmin = !empty($_SESSION['is_admin']);

$convoy_id = isset($_GET['convoy_id']) ? (int)$_GET['convoy_id'] : 0;
$root_id   = isset($_GET['root_id']) ? (int)$_GET['root_id'] : 0;
$prefilledCategoryId = isset($_GET['category_id']) ? (int)$_GET['category_id'] : 0;

if ($convoy_id <= 0 || $root_id <= 0) {
    echo "<div class='alert alert-danger'>Paramètres invalides.</div>";
    require __DIR__ . '/../footer.php';
    exit;
}

// Convoi
$stmt = $pdo->prepare("SELECT * FROM convoys WHERE id = ?");
$stmt->execute([$convoy_id]);
$convoi = $stmt->fetch();

if (!$convoi) {
    echo "<div class='alert alert-danger'>Convoi introuvable.</div>";
    require __DIR__ . '/../footer.php';
    exit;
}

// 🔒 Modification autorisée seulement si admin ou convoi en préparation
if (!$isAdmin && ($convoi['status'] ?? '') !== 'preparation') {
    http_response_code(403);
    echo "<div class='alert alert-warning'>Lecture seule : ce convoi n'est plus en préparation.</div>";
    echo "<a class='btn btn-outline-secondary btn-sm' href='convoi.php?id=" . (int)$convoy_id . "'>Retour</a>";
    require __DIR__ . '/../footer.php';
    exit;
}

// Catégories actives de cette racine
$catStmt = $pdo->prepare("
    SELECT id, label
    FROM categories
    WHERE root_id = ?
      AND is_active = 1
    ORDER BY label
");
$catStmt->execute([$root_id]);
$categories = $catStmt->fetchAll();

if (empty($categories)) {
    echo "<div class='alert alert-warning'>Aucune catégorie active pour cette racine.</div>";
    echo "<a class='btn btn-outline-secondary btn-sm' href='convoi.php?id=" . (int)$convoy_id . "'>Retour</a>";
    require __DIR__ . '/../footer.php';
    exit;
}

// Valeurs formulaire
$category_id = '';
$quantity    = '1';
$errors      = [];

// Pré-sélection (GET)
if ($_SERVER['REQUEST_METHOD'] !== 'POST' && $prefilledCategoryId > 0) {
    foreach ($categories as $cat) {
        if ((int)$cat['id'] === $prefilledCategoryId) {
            $category_id = $prefilledCategoryId;
            break;
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $category_id = (int)($_POST['category_id'] ?? 0);
    $quantity    = trim($_POST['quantity'] ?? '1');

    if ($category_id <= 0) {
        $errors[] = "La catégorie est obligatoire.";
    }

    if ($quantity === '' || !ctype_digit($quantity) || (int)$quantity < 1) {
        $errors[] = "Le nombre de cartons doit être un entier positif.";
    } else {
        $quantity = (int)$quantity;
    }

    // Vérifier que la catégorie appartient bien à la racine
    if ($category_id > 0) {
        $check = $pdo->prepare("SELECT root_id FROM categories WHERE id = ?");
        $check->execute([$category_id]);
        $catRow = $check->fetch();
        if (!$catRow || (int)$catRow['root_id'] !== $root_id) {
            $errors[] = "Catégorie invalide pour cette racine.";
        }
    }

    if (empty($errors)) {
        for ($i = 0; $i < $quantity; $i++) {
            $insert = $pdo->prepare("
                INSERT INTO boxes (convoy_id, category_id, root_category_id, code)
                VALUES (?, ?, ?, NULL)
            ");
            $insert->execute([$convoy_id, $category_id, $root_id]);

            $boxId = (int)$pdo->lastInsertId();
            $code  = 'C' . str_pad((string)$boxId, 6, '0', STR_PAD_LEFT);

            $update = $pdo->prepare("UPDATE boxes SET code = ? WHERE id = ?");
            $update->execute([$code, $boxId]);
        }

        $_SESSION['flash_success'] = "Carton(s) ajouté(s).";
        header('Location: ' . APP_BASE . '/convoys/view.php?id=' . $convoy_id);
        exit;
    }
}
?>

<h1 class="h4 mb-3">Ajouter des cartons</h1>
<p class="text-muted mb-3">Convoi : <?= htmlspecialchars($convoi['name']) ?></p>

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
        </option>
      <?php endforeach; ?>
    </select>
  </div>

  <div class="mb-3">
    <label class="form-label">Nombre de cartons *</label>
    <input type="number" name="quantity" class="form-control" min="1" value="<?= htmlspecialchars($quantity) ?>">
    <div class="form-text">Ex : si vous avez 5 cartons, indiquez <strong>5</strong>.</div>
  </div>

  <button class="btn btn-primary">Enregistrer</button>
  <a href="convoi.php?id=<?= (int)$convoy_id ?>" class="btn btn-secondary">Annuler</a>
</form>

<?php require_once __DIR__ . '/../footer.php'; ?>