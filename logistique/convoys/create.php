<?php
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../header.php';

// Seul l'admin peut créer un convoi
if (empty($isAdmin)) {
    ?>
    <div class="alert alert-danger">
        Accès réservé à l’administrateur.<br>
        Seul un administrateur peut créer un nouveau convoi.
    </div>
    <a href="../index.php" class="btn btn-secondary">Retour à la liste des convois</a>
    <?php
    require __DIR__ . '/../footer.php';
    exit;
}

// Vérifier s'il existe déjà un convoi en préparation
$openStmt = $pdo->query("SELECT COUNT(*) AS nb FROM convoys WHERE status = 'preparation'");
$openRow = $openStmt->fetch();
$hasOpenConvoy = $openRow && (int)$openRow['nb'] > 0;

$name           = '';
$departure_date = '';
$destination    = '';
$notes          = '';
$errors         = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name           = trim($_POST['name'] ?? '');
    $departure_date = trim($_POST['departure_date'] ?? '');
    $destination    = trim($_POST['destination'] ?? '');
    $notes          = trim($_POST['notes'] ?? '');
    $forceMultiple  = !empty($_POST['force_multiple']); // option pour forcer plusieurs convois en préparation

    if ($name === '') {
        $errors[] = "Le nom du convoi est obligatoire.";
    }

    // Si un convoi est déjà en préparation et que l’admin ne coche pas la case d’exception
    if ($hasOpenConvoy && !$forceMultiple) {
        $errors[] = "Un convoi est déjà en préparation. Clôturez-le ou cochez la case d’exception pour créer un second convoi.";
    }

    if (empty($errors)) {
        $stmt = $pdo->prepare("
            INSERT INTO convoys (name, departure_date, destination, status, notes)
            VALUES (?, ?, ?, 'preparation', ?)
        ");
        $stmt->execute([
            $name,
            $departure_date !== '' ? $departure_date : null,  // date vraiment facultative
            $destination !== '' ? $destination : null,
            $notes !== '' ? $notes : null,
        ]);
        $id = $pdo->lastInsertId();
        header('Location: ' . APP_BASE . '/convoys/view.php?id=' . $id);
        exit;
    }
}
?>
<h1 class="h4 mb-3">Nouveau convoi</h1>

<div class="mb-3">
  <span class="badge bg-primary">Admin</span>
  <span class="ms-2 text-muted small">
    La création d’un convoi est réservée à l’administrateur.
  </span>
</div>

<?php if (!empty($errors)): ?>
  <div class="alert alert-danger">
    <ul class="mb-0">
      <?php foreach ($errors as $e): ?>
        <li><?= htmlspecialchars($e) ?></li>
      <?php endforeach; ?>
    </ul>
  </div>
<?php endif; ?>

<?php if ($hasOpenConvoy): ?>
  <div class="alert alert-warning">
    Un convoi est déjà <strong>en préparation</strong>.<br>
    Par défaut, un seul convoi en préparation est autorisé.  
    Vous pouvez tout de même créer un nouveau convoi en cochant la case d’exception ci-dessous.
  </div>
<?php endif; ?>

<form method="post" class="card shadow-sm p-3">
  <div class="mb-3">
    <label class="form-label">Nom du convoi *</label>
    <input type="text" name="name" class="form-control" required
           value="<?= htmlspecialchars($name) ?>">
    <div class="form-text">Ex : Convoi mars 2026, Convoi n°12, etc.</div>
  </div>

  <div class="row">
    <div class="col-md-6 mb-3">
      <label class="form-label">Date de départ (prévisionnelle)</label>
      <input type="date" name="departure_date" class="form-control"
             value="<?= htmlspecialchars($departure_date) ?>">
      <div class="form-text">Facultative, vous pouvez la renseigner plus tard.</div>
    </div>

    <div class="col-md-6 mb-3">
      <label class="form-label">Destination</label>
      <input type="text" name="destination" class="form-control"
             placeholder="Ville / région / partenaire..."
             value="<?= htmlspecialchars($destination) ?>">
    </div>
  </div>

  <div class="mb-3">
    <label class="form-label">Notes</label>
    <textarea name="notes" class="form-control" rows="3"
              placeholder="Infos utiles : type de chargement, partenaire transport, remarques..."><?= htmlspecialchars($notes) ?></textarea>
  </div>

  <?php if ($hasOpenConvoy): ?>
    <div class="mb-3 form-check">
      <input class="form-check-input" type="checkbox" value="1" id="force_multiple" name="force_multiple"
             <?= !empty($_POST['force_multiple']) ? 'checked' : '' ?>>
      <label class="form-check-label" for="force_multiple">
        Autoriser exceptionnellement un second convoi en préparation.
      </label>
      <div class="form-text">
        À utiliser uniquement si vous avez besoin d’organiser plusieurs convois en parallèle.
      </div>
    </div>
  <?php endif; ?>

  <button class="btn btn-primary">Créer le convoi</button>
  <a href="../index.php" class="btn btn-secondary">Annuler</a>
</form>

<?php require_once __DIR__ . '/footer.php'; ?>