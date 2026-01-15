<?php
require_once __DIR__ . '/../db.php';

if (session_status() === PHP_SESSION_NONE) session_start();

$isAdmin = !empty($_SESSION['is_admin']);
if (!$isAdmin) {
  http_response_code(403);
  echo "<div class='alert alert-danger'>Accès interdit.</div>";
  require __DIR__ . '/../footer.php';
  exit;
}

function h(string $s): string { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }
function post_int(string $key, int $default = 0): int { return isset($_POST[$key]) ? (int)$_POST[$key] : $default; }
function post_str(string $key, string $default = ''): string { return isset($_POST[$key]) ? trim((string)$_POST[$key]) : $default; }

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$isEdit = ($id > 0);

$item = [
  'id' => 0,
  'category_id' => 0,
  'location_id' => null,
  'title' => '',
  'description' => '',
  'quantity' => 1,
  'status' => 'available',
  'condition_state' => 'good',
  'is_active' => 1,
  'ref_code' => '',
  'source_type' => 'donation',
  'source_notes' => '',
];

if ($isEdit) {
  $stmt = $pdo->prepare("SELECT * FROM stock_items WHERE id = ?");
  $stmt->execute([$id]);
  $row = $stmt->fetch(PDO::FETCH_ASSOC);
  if (!$row) {
    echo "<div class='alert alert-warning'>Objet introuvable.</div>";
    require __DIR__ . '/../footer.php';
    exit;
  }
  $item = array_merge($item, $row);
  $item['category_id'] = (int)$row['category_id'];
  $item['location_id'] = $row['location_id'] !== null ? (int)$row['location_id'] : null;
  $item['quantity'] = (int)$row['quantity'];
  $item['is_active'] = (int)$row['is_active'];
}

/* Dropdowns */
$catsStmt = $pdo->query("
  SELECT id, parent_id, label
  FROM stock_categories
  WHERE is_active = 1
  ORDER BY COALESCE(parent_id, id), parent_id IS NULL DESC, label
");
$cats = $catsStmt->fetchAll(PDO::FETCH_ASSOC);

$locStmt = $pdo->query("
  SELECT id, label
  FROM stock_locations
  WHERE is_active = 1
  ORDER BY label
");
$locations = $locStmt->fetchAll(PDO::FETCH_ASSOC);

/* Cat full label (Parent > Enfant) */
$catLabels = [];
$catParents = [];
foreach ($cats as $c) {
  $catLabels[(int)$c['id']] = (string)$c['label'];
  $catParents[(int)$c['id']] = $c['parent_id'] !== null ? (int)$c['parent_id'] : null;
}
function catFullLabel(int $id, array $labels, array $parents): string {
  $label = $labels[$id] ?? ('#' . $id);
  $pid = $parents[$id] ?? null;
  if ($pid !== null) {
    $p = $labels[$pid] ?? ('#' . $pid);
    return $p . ' > ' . $label;
  }
  return $label;
}

/* Save */
$errors = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $action = post_str('action');
  if ($action === 'save') {
    $category_id = post_int('category_id');
    $location_id_raw = post_str('location_id', '');
    $location_id = ($location_id_raw === '' || $location_id_raw === '0') ? null : (int)$location_id_raw;

    $title = post_str('title');
    $description = post_str('description');
    $quantity = post_int('quantity', 1);

    $status = post_str('status', 'available');
    $condition_state = post_str('condition_state', 'good');
    $is_active = post_int('is_active', 1) ? 1 : 0;

    $ref_code = post_str('ref_code');
    $source_type = post_str('source_type', 'donation');
    $source_notes = post_str('source_notes');

    $allowedStatus = ['available','reserved','allocated','out','discarded'];
    $allowedCond = ['new','very_good','good','fair','needs_repair'];
    $allowedSource = ['donation','purchase','other'];

    if ($category_id <= 0) $errors[] = "Catégorie obligatoire.";
    if ($title === '') $errors[] = "Titre obligatoire.";
    if ($quantity <= 0) $errors[] = "Quantité invalide.";
    if (!in_array($status, $allowedStatus, true)) $errors[] = "Statut invalide.";
    if (!in_array($condition_state, $allowedCond, true)) $errors[] = "État invalide.";
    if (!in_array($source_type, $allowedSource, true)) $errors[] = "Source invalide.";

    if (empty($errors)) {
      // NB: on conserve la colonne unit en base, mais on force 'u' (UI supprimée)
      $unit = 'u';

      if ($isEdit) {
        $upd = $pdo->prepare("
          UPDATE stock_items
          SET category_id = ?, location_id = ?, title = ?, description = ?,
              quantity = ?, unit = ?, status = ?, condition_state = ?,
              is_active = ?, ref_code = ?, source_type = ?, source_notes = ?
          WHERE id = ?
        ");
        $upd->execute([
          $category_id,
          $location_id,
          $title,
          ($description !== '' ? $description : null),
          $quantity,
          $unit,
          $status,
          $condition_state,
          $is_active,
          ($ref_code !== '' ? $ref_code : null),
          $source_type,
          ($source_notes !== '' ? $source_notes : null),
          $id
        ]);
        $_SESSION['flash_success'] = "Objet mis à jour.";
      } else {
        $ins = $pdo->prepare("
          INSERT INTO stock_items
            (category_id, location_id, title, description, quantity, unit, status, condition_state, is_active, ref_code, source_type, source_notes)
          VALUES
            (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $ins->execute([
          $category_id,
          $location_id,
          $title,
          ($description !== '' ? $description : null),
          $quantity,
          $unit,
          $status,
          $condition_state,
          $is_active,
          ($ref_code !== '' ? $ref_code : null),
          $source_type,
          ($source_notes !== '' ? $source_notes : null),
        ]);
        $_SESSION['flash_success'] = "Objet ajouté au stock.";
      }

      header('Location: ' . APP_BASE . '/stock/index.php');
      exit;
    } else {
      $item = array_merge($item, [
        'category_id' => $category_id,
        'location_id' => $location_id,
        'title' => $title,
        'description' => $description,
        'quantity' => $quantity,
        'status' => $status,
        'condition_state' => $condition_state,
        'is_active' => $is_active,
        'ref_code' => $ref_code,
        'source_type' => $source_type,
        'source_notes' => $source_notes,
      ]);
    }
  }
}
    
require_once __DIR__ . '/../header.php';
?>

<div class="d-flex justify-content-between align-items-start mb-3">
  <div>
    <h1 class="h4 mb-1"><?= $isEdit ? "Modifier un objet" : "Ajouter un objet" ?></h1>
    <div class="text-muted small">Stock local</div>
  </div>
  <div class="d-flex gap-2">
    <a class="btn btn-outline-secondary btn-sm" href="<?= h(APP_BASE) ?>/stock/index.php">← Retour</a>
    <a class="btn btn-outline-secondary btn-sm" href="<?= h(APP_BASE) ?>/stock/categories/index.php">Catégories</a>
    <a class="btn btn-outline-secondary btn-sm" href="<?= h(APP_BASE) ?>/stock/locations/index.php">Lieux</a>
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
      <input type="hidden" name="action" value="save">

      <div class="row g-3">
        <div class="col-12 col-md-7">
          <label class="form-label">Titre *</label>
          <input type="text" name="title" class="form-control" value="<?= h((string)$item['title']) ?>" required>
        </div>

        <div class="col-12 col-md-2">
          <label class="form-label">Quantité *</label>
          <input type="number" name="quantity" class="form-control" min="1" value="<?= (int)$item['quantity'] ?>" required>
        </div>

        <div class="col-12 col-md-3">
          <label class="form-label">Statut</label>
          <select name="status" class="form-select">
            <option value="available" <?= $item['status'] === 'available' ? 'selected' : '' ?>>Disponible</option>
            <option value="reserved"  <?= $item['status'] === 'reserved' ? 'selected' : '' ?>>Réservé</option>
            <option value="allocated" <?= $item['status'] === 'allocated' ? 'selected' : '' ?>>Attribué</option>
            <option value="out"       <?= $item['status'] === 'out' ? 'selected' : '' ?>>Sorti</option>
            <option value="discarded" <?= $item['status'] === 'discarded' ? 'selected' : '' ?>>Jeté / HS</option>
          </select>
        </div>

        <div class="col-12">
          <label class="form-label">Description</label>
          <textarea name="description" class="form-control" rows="3"><?= h((string)($item['description'] ?? '')) ?></textarea>
        </div>

        <div class="col-12 col-md-6">
          <label class="form-label">Catégorie *</label>
          <select name="category_id" class="form-select" required>
            <option value="">— Choisir —</option>
            <?php foreach ($cats as $c): ?>
              <?php
                $cid = (int)$c['id'];
                $full = catFullLabel($cid, $catLabels, $catParents);
              ?>
              <option value="<?= $cid ?>" <?= (int)$item['category_id'] === $cid ? 'selected' : '' ?>>
                <?= h($full) ?>
              </option>
            <?php endforeach; ?>
          </select>
          <div class="form-text">Tu peux sélectionner une sous-catégorie.</div>
        </div>

        <div class="col-12 col-md-6">
          <label class="form-label">Lieu</label>
          <select name="location_id" class="form-select">
            <option value="">— Aucun —</option>
            <?php foreach ($locations as $l): ?>
              <?php $lid = (int)$l['id']; ?>
              <option value="<?= $lid ?>" <?= ((int)($item['location_id'] ?? 0) === $lid) ? 'selected' : '' ?>>
                <?= h($l['label']) ?>
              </option>
            <?php endforeach; ?>
          </select>
        </div>

        <div class="col-12 col-md-4">
          <label class="form-label">État</label>
          <select name="condition_state" class="form-select">
            <option value="new"          <?= $item['condition_state'] === 'new' ? 'selected' : '' ?>>Neuf</option>
            <option value="very_good"    <?= $item['condition_state'] === 'very_good' ? 'selected' : '' ?>>Très bon</option>
            <option value="good"         <?= $item['condition_state'] === 'good' ? 'selected' : '' ?>>Bon</option>
            <option value="fair"         <?= $item['condition_state'] === 'fair' ? 'selected' : '' ?>>Correct</option>
            <option value="needs_repair" <?= $item['condition_state'] === 'needs_repair' ? 'selected' : '' ?>>À réparer</option>
          </select>
        </div>

        <div class="col-12 col-md-4">
          <label class="form-label">Source</label>
          <select name="source_type" class="form-select">
            <option value="donation" <?= $item['source_type'] === 'donation' ? 'selected' : '' ?>>Don</option>
            <option value="purchase" <?= $item['source_type'] === 'purchase' ? 'selected' : '' ?>>Achat</option>
            <option value="other"    <?= $item['source_type'] === 'other' ? 'selected' : '' ?>>Autre</option>
          </select>
        </div>

        <div class="col-12 col-md-4">
          <label class="form-label">Référence interne</label>
          <input type="text" name="ref_code" class="form-control" maxlength="40"
                 value="<?= h((string)($item['ref_code'] ?? '')) ?>" placeholder="Optionnel">
        </div>

        <div class="col-12">
          <label class="form-label">Notes source</label>
          <input type="text" name="source_notes" class="form-control" maxlength="255"
                 value="<?= h((string)($item['source_notes'] ?? '')) ?>" placeholder="Optionnel">
        </div>

        <div class="col-12">
          <div class="form-check">
            <input class="form-check-input" type="checkbox" name="is_active" value="1" id="isActive"
              <?= ((int)$item['is_active'] === 1) ? 'checked' : '' ?>>
            <label class="form-check-label" for="isActive">Actif (visible)</label>
          </div>
        </div>

        <div class="col-12 text-end mt-2">
          <button class="btn btn-primary px-4" type="submit">Enregistrer</button>
        </div>
      </div>
    </form>
  </div>
</div>

<?php require __DIR__ . '/../footer.php'; ?>
