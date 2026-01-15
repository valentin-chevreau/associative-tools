<?php
// families/edit.php — V1 (create/update)
// PHP 7.4+

if (session_status() === PHP_SESSION_NONE) {
  session_start();
}

require_once __DIR__ . '/../db.php';

$isAdmin = !empty($_SESSION['is_admin']);

function h(string $s): string { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }

// Guard
if (!$isAdmin) {
  http_response_code(403);
  echo "<div class='container' style='max-width:900px;'><div class='alert alert-danger'>Accès interdit.</div></div>";
  require __DIR__ . '/../footer.php';
  exit;
}

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

// Load existing family (if edit)
$family = null;
if ($id > 0) {
  $st = $pdo->prepare("SELECT * FROM families WHERE id = ?");
  $st->execute([$id]);
  $family = $st->fetch(PDO::FETCH_ASSOC);

  if (!$family) {
    $_SESSION['flash_error'] = "Famille introuvable.";
    header('Location: ' . APP_BASE . '/families/index.php');
    exit;
  }
}

// Defaults
$form = [
  'public_ref'    => $family['public_ref']    ?? '',
  'lastname'      => $family['lastname']      ?? '',
  'firstname'     => $family['firstname']     ?? '',
  'phone'         => $family['phone']         ?? '',
  'email'         => $family['email']         ?? '',
  'city'          => $family['city']          ?? '',
  'housing_notes' => $family['housing_notes'] ?? '',
  'notes'         => $family['notes']         ?? '',
  'status'        => $family['status']        ?? 'active',
];

$errors = [];

// POST
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $action = $_POST['action'] ?? 'save';

  if ($action === 'toggle_status' && $id > 0) {
    $newStatus = ($_POST['status'] ?? '') === 'inactive' ? 'inactive' : 'active';
    $upd = $pdo->prepare("UPDATE families SET status = ? WHERE id = ?");
    $upd->execute([$newStatus, $id]);
    $_SESSION['flash_success'] = "Statut mis à jour.";
    header('Location: ' . APP_BASE . '/families/edit.php?id=' . $id);
    exit;
  }

  // Save (create/update)
  $form['public_ref']    = trim((string)($_POST['public_ref'] ?? ''));
  $form['lastname']      = trim((string)($_POST['lastname'] ?? ''));
  $form['firstname']     = trim((string)($_POST['firstname'] ?? ''));
  $form['phone']         = trim((string)($_POST['phone'] ?? ''));
  $form['email']         = trim((string)($_POST['email'] ?? ''));
  $form['city']          = trim((string)($_POST['city'] ?? ''));
  $form['housing_notes'] = trim((string)($_POST['housing_notes'] ?? ''));
  $form['notes']         = trim((string)($_POST['notes'] ?? ''));
  $form['status']        = (($_POST['status'] ?? 'active') === 'inactive') ? 'inactive' : 'active';

  // Validation light
  if ($form['lastname'] === '' && $form['firstname'] === '' && $form['public_ref'] === '') {
    $errors[] = "Renseigne au moins un nom/prénom ou une référence (public_ref).";
  }
  if ($form['email'] !== '' && !filter_var($form['email'], FILTER_VALIDATE_EMAIL)) {
    $errors[] = "Email invalide.";
  }

  // Unicité public_ref si renseignée
  if ($form['public_ref'] !== '') {
    if ($id > 0) {
      $chk = $pdo->prepare("SELECT id FROM families WHERE public_ref = ? AND id <> ? LIMIT 1");
      $chk->execute([$form['public_ref'], $id]);
    } else {
      $chk = $pdo->prepare("SELECT id FROM families WHERE public_ref = ? LIMIT 1");
      $chk->execute([$form['public_ref']]);
    }
    if ($chk->fetch(PDO::FETCH_ASSOC)) {
      $errors[] = "Cette référence (public_ref) est déjà utilisée.";
    }
  }

  if (empty($errors)) {
    if ($id > 0) {
      $upd = $pdo->prepare("
        UPDATE families
        SET public_ref = ?, lastname = ?, firstname = ?, phone = ?, email = ?,
            city = ?, housing_notes = ?, notes = ?, status = ?
        WHERE id = ?
      ");
      $upd->execute([
        $form['public_ref'] !== '' ? $form['public_ref'] : null,
        $form['lastname'] !== '' ? $form['lastname'] : null,
        $form['firstname'] !== '' ? $form['firstname'] : null,
        $form['phone'] !== '' ? $form['phone'] : null,
        $form['email'] !== '' ? $form['email'] : null,
        $form['city'] !== '' ? $form['city'] : null,
        $form['housing_notes'] !== '' ? $form['housing_notes'] : null,
        $form['notes'] !== '' ? $form['notes'] : null,
        $form['status'],
        $id
      ]);
      $_SESSION['flash_success'] = "Famille mise à jour.";
      header('Location: ' . APP_BASE . '/families/edit.php?id=' . $id);
      exit;
    } else {
      $ins = $pdo->prepare("
        INSERT INTO families (public_ref, lastname, firstname, phone, email, city, housing_notes, notes, status)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
      ");
      $ins->execute([
        $form['public_ref'] !== '' ? $form['public_ref'] : null,
        $form['lastname'] !== '' ? $form['lastname'] : null,
        $form['firstname'] !== '' ? $form['firstname'] : null,
        $form['phone'] !== '' ? $form['phone'] : null,
        $form['email'] !== '' ? $form['email'] : null,
        $form['city'] !== '' ? $form['city'] : null,
        $form['housing_notes'] !== '' ? $form['housing_notes'] : null,
        $form['notes'] !== '' ? $form['notes'] : null,
        $form['status'],
      ]);
      $newId = (int)$pdo->lastInsertId();
      $_SESSION['flash_success'] = "Famille créée.";
      header('Location: ' . APP_BASE . '/families/edit.php?id=' . $newId);
      exit;
    }
  }
}

// Flash (display)
if (!empty($_SESSION['flash_success'])) {
  echo '<div class="container" style="max-width:900px;"><div class="alert alert-success">' . h($_SESSION['flash_success']) . '</div></div>';
  unset($_SESSION['flash_success']);
}
if (!empty($_SESSION['flash_error'])) {
  echo '<div class="container" style="max-width:900px;"><div class="alert alert-danger">' . h($_SESSION['flash_error']) . '</div></div>';
  unset($_SESSION['flash_error']);
}

$title = $id > 0 ? "Éditer une famille" : "Nouvelle famille";
require_once __DIR__ . '/../header.php';
?>

<div class="container" style="max-width: 900px;">
  <div class="d-flex justify-content-between align-items-start gap-2 mb-3">
    <div>
      <h1 class="h4 mb-1"><?= h($title) ?></h1>
      <div class="text-muted small">
        <?= $id > 0 ? ("ID #" . (int)$id) : "Création d’une nouvelle fiche famille" ?>
      </div>
    </div>
    <div class="text-end">
      <a class="btn btn-outline-secondary btn-sm" href="<?= h(APP_BASE) ?>/families/index.php">← Retour</a>
      <?php if ($id > 0): ?>
        <a class="btn btn-outline-primary btn-sm" href="<?= h(APP_BASE) ?>/families/view.php?id=<?= (int)$id ?>">Ouvrir</a>
      <?php endif; ?>
    </div>
  </div>

  <?php if (!empty($errors)): ?>
    <div class="alert alert-danger">
      <ul class="mb-0">
        <?php foreach ($errors as $e): ?>
          <li><?= h($e) ?></li>
        <?php endforeach; ?>
      </ul>
    </div>
  <?php endif; ?>

  <div class="card shadow-sm mb-3">
    <div class="card-body">
      <form method="post">
        <input type="hidden" name="action" value="save">

        <div class="row g-3">
          <div class="col-12 col-md-4">
            <label class="form-label">Référence (public_ref)</label>
            <input class="form-control" name="public_ref" value="<?= h((string)$form['public_ref']) ?>" placeholder="FAM-2026-001">
            <div class="form-text">Optionnel, mais pratique pour retrouver vite.</div>
          </div>

          <div class="col-12 col-md-4">
            <label class="form-label">Nom</label>
            <input class="form-control" name="lastname" value="<?= h((string)$form['lastname']) ?>">
          </div>

          <div class="col-12 col-md-4">
            <label class="form-label">Prénom</label>
            <input class="form-control" name="firstname" value="<?= h((string)$form['firstname']) ?>">
          </div>

          <div class="col-12 col-md-4">
            <label class="form-label">Téléphone</label>
            <input class="form-control" name="phone" value="<?= h((string)$form['phone']) ?>">
          </div>

          <div class="col-12 col-md-4">
            <label class="form-label">Email</label>
            <input class="form-control" name="email" value="<?= h((string)$form['email']) ?>">
          </div>

          <div class="col-12 col-md-4">
            <label class="form-label">Ville</label>
            <input class="form-control" name="city" value="<?= h((string)$form['city']) ?>">
          </div>

          <div class="col-12">
            <label class="form-label">Infos logement</label>
            <input class="form-control" name="housing_notes" value="<?= h((string)$form['housing_notes']) ?>" placeholder="T2 - 4e sans ascenseur, entrée 2…">
          </div>

          <div class="col-12">
            <label class="form-label">Notes</label>
            <textarea class="form-control" name="notes" rows="4"><?= h((string)$form['notes']) ?></textarea>
          </div>

          <div class="col-12 col-md-4">
            <label class="form-label">Statut</label>
            <select class="form-select" name="status">
              <option value="active" <?= $form['status']==='active'?'selected':'' ?>>Active</option>
              <option value="inactive" <?= $form['status']==='inactive'?'selected':'' ?>>Inactive</option>
            </select>
          </div>
        </div>

        <div class="d-flex justify-content-end gap-2 mt-3">
          <a class="btn btn-outline-secondary" href="<?= h(APP_BASE) ?>/families/index.php">Annuler</a>
          <button class="btn btn-primary px-4">Enregistrer</button>
        </div>
      </form>
    </div>
  </div>

  <?php if ($id > 0): ?>
    <div class="card shadow-sm mb-4">
      <div class="card-body d-flex justify-content-between align-items-center gap-3">
        <div>
          <div class="fw-semibold">Actions rapides</div>
          <div class="text-muted small">Activer / désactiver la famille sans tout ré-éditer.</div>
        </div>
        <form method="post" class="mb-0">
          <input type="hidden" name="action" value="toggle_status">
          <input type="hidden" name="status" value="<?= $form['status']==='active' ? 'inactive' : 'active' ?>">
          <button class="btn btn-outline-<?= $form['status']==='active' ? 'danger' : 'success' ?>"
                  onclick="return confirm('Confirmer le changement de statut ?');">
            <?= $form['status']==='active' ? 'Passer inactive' : 'Passer active' ?>
          </button>
        </form>
      </div>
    </div>
  <?php endif; ?>
</div>

<?php require __DIR__ . '/../footer.php'; ?>
