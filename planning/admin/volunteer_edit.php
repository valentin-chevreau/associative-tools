<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$title = "Admin – Éditer bénévole";
ob_start();

require_once __DIR__ . '/../includes/app.php';
$config = require __DIR__ . '/../config/config.php';

if (empty($_SESSION['is_admin']) && empty($_SESSION['admin_authenticated'])) {
    header('Location: ' . $config['base_url'] . '/admin/login.php');
    exit;
}

global $pdo;

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

$volunteer = [
    'first_name' => '',
    'last_name'  => '',
    'email'      => '',
    'phone'      => '',
    'is_active'  => 1,
];

if ($id > 0) {
    $stmt = $pdo->prepare("SELECT * FROM volunteers WHERE id = :id");
    $stmt->execute(['id' => $id]);
    $existing = $stmt->fetch();
    if (!$existing) {
        die("Bénévole introuvable");
    }
    $volunteer = $existing;
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $first_name = trim($_POST['first_name'] ?? '');
    $last_name  = trim($_POST['last_name'] ?? '');
    $email      = trim($_POST['email'] ?? '');
    $phone      = trim($_POST['phone'] ?? '');
    $is_active  = isset($_POST['is_active']) ? 1 : 0;

    $volunteer['first_name'] = $first_name;
    $volunteer['last_name']  = $last_name;
    $volunteer['email']      = $email;
    $volunteer['phone']      = $phone;
    $volunteer['is_active']  = $is_active;

    if ($first_name) {
        if ($id > 0) {
            $stmt = $pdo->prepare("UPDATE volunteers SET
                first_name = :first_name,
                last_name  = :last_name,
                email      = :email,
                phone      = :phone,
                is_active  = :is_active
                WHERE id   = :id");
            $stmt->execute([
                'first_name' => $first_name,
                'last_name'  => $last_name,
                'email'      => $email,
                'phone'      => $phone,
                'is_active'  => $is_active,
                'id'         => $id,
            ]);
        } else {
            $stmt = $pdo->prepare("INSERT INTO volunteers
                (first_name, last_name, email, phone, is_active)
                VALUES (:first_name, :last_name, :email, :phone, :is_active)");
            $stmt->execute([
                'first_name' => $first_name,
                'last_name'  => $last_name,
                'email'      => $email,
                'phone'      => $phone,
                'is_active'  => $is_active,
            ]);
        }

        header("Location: {$config['base_url']}/admin/volunteers_list.php");
        exit;
    } else {
        $error = "Le prénom est obligatoire.";
    }
}
?>
<div class="card">
  <h2><?= $id > 0 ? 'Modifier un bénévole' : 'Ajouter un bénévole' ?></h2>
  <p class="muted">
    Seul le prénom est obligatoire. Les autres champs sont utiles pour pouvoir recontacter le bénévole.
  </p>

  <?php if (!empty($error)): ?>
    <p style="color:#b91c1c; margin-top:8px;"><?= htmlspecialchars($error) ?></p>
  <?php endif; ?>

  <form method="post" class="form">
    <div class="form-section">
      <div class="form-section-title">Identité</div>
      <div class="form-grid form-grid--2">
        <div class="field">
          <label for="first_name" class="field-label">Prénom *</label>
          <input id="first_name" type="text" name="first_name" required
                 placeholder="Ex : Marie"
                 value="<?= htmlspecialchars($volunteer['first_name']) ?>">
        </div>
        <div class="field">
          <label for="last_name" class="field-label">Nom</label>
          <input id="last_name" type="text" name="last_name"
                 placeholder="Ex : Dupont"
                 value="<?= htmlspecialchars($volunteer['last_name']) ?>">
        </div>
      </div>
    </div>

    <div class="form-section">
      <div class="form-section-title">Contact</div>
      <div class="form-grid form-grid--2">
        <div class="field">
          <label for="email" class="field-label">Email</label>
          <input id="email" type="email" name="email"
                 placeholder="Ex : prenom.nom@mail.fr"
                 value="<?= htmlspecialchars($volunteer['email']) ?>">
        </div>
        <div class="field">
          <label for="phone" class="field-label">Téléphone</label>
          <input id="phone" type="text" name="phone"
                 placeholder="Ex : 06 00 00 00 00"
                 value="<?= htmlspecialchars($volunteer['phone']) ?>">
        </div>
      </div>
    </div>

    <div class="form-section">
      <div class="form-section-title">Statut</div>
      <div class="field-inline" style="margin-top:4px;">
        <input id="is_active" type="checkbox" name="is_active" <?= $volunteer['is_active'] ? 'checked' : '' ?>>
        <label for="is_active">Bénévole actif</label>
      </div>
      <div class="field-hint" style="margin-top:4px;">
        Décoche ce champ pour archiver un bénévole qui ne vient plus.
      </div>
    </div>

    <div style="margin-top:4px;">
      <button type="submit" class="primary">Enregistrer</button>
      <a href="<?= $config['base_url'] ?>/admin/volunteers_list.php">
        <button type="button">Annuler</button>
      </a>
    </div>
  </form>
</div>

<?php
$content = ob_get_clean();
include __DIR__ . '/../includes/layout.php';