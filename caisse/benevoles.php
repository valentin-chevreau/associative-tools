<?php
require 'config.php';
$page = 'benevoles';

$adminError = '';
$adminSuccess = '';

// Ajout d'un bénévole (admin)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_benevole'])) {
    if (!is_admin()) {
        $adminError = "Mode administrateur requis pour ajouter un bénévole.";
    } else {
        $nom = trim($_POST['nom'] ?? '');
        if ($nom === '') {
            $adminError = "Le nom du bénévole est obligatoire.";
        } else {
            $stmt = $pdo->prepare("INSERT INTO benevoles (nom) VALUES (?)");
            $stmt->execute([$nom]);
            header('Location: benevoles.php?ok=1');
            exit;
        }
    }
}

// Suppression d'un bénévole (admin)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_benevole_id'])) {
    if (!is_admin()) {
        $adminError = "Mode administrateur requis pour supprimer un bénévole.";
    } else {
        $id = (int)$_POST['delete_benevole_id'];
        $stmt = $pdo->prepare("DELETE FROM benevoles WHERE id = ?");
        $stmt->execute([$id]);
        header('Location: benevoles.php?ok=1');
        exit;
    }
}

if (isset($_GET['ok']) && !$adminError) {
    $adminSuccess = "Action effectuée.";
}

// Liste des bénévoles
$benevoles = $pdo->query("SELECT id, nom FROM benevoles ORDER BY nom")->fetchAll(PDO::FETCH_ASSOC);
?>
<!doctype html>
<html lang="fr">
<head>
<meta charset="utf-8"/>
<meta name="viewport" content="width=device-width,initial-scale=1"/>
<title>Bénévoles – Mini Caisse</title>
<style>
body{font-family:system-ui,Segoe UI,Roboto;background:#f5f7fb;margin:0;padding:10px}
.app{max-width:1100px;margin:0 auto}
.card{background:#fff;border-radius:12px;padding:12px;box-shadow:0 5px 15px rgba(15,23,42,0.08);margin-bottom:10px}
h2,h3{margin:10px 0}
input,button{padding:8px;border-radius:8px;border:1px solid #d1d5db;font-size:14px}
button{border:0;background:#2563eb;color:#fff;font-weight:700;cursor:pointer}
button.danger{background:#dc2626}
button.secondary{background:#e5e7eb;color:#111}
.small{font-size:12px;color:#6b7280}
.alert{padding:8px 10px;border-radius:8px;margin-bottom:8px;font-size:13px}
.alert.error{background:#fee2e2;color:#b91c1c}
.alert.success{background:#dcfce7;color:#166534}
.benevole-row{
  display:flex;
  align-items:center;
  justify-content:space-between;
  padding:8px 10px;
  border-bottom:1px solid #e5e7eb;
}
.benevole-name{
  font-size:14px;
}

/* Barre mode admin identique à évènements */
.admin-bar{
  max-width:1100px;
  margin:0 auto 10px auto;
  display:flex;
  align-items:center;
  justify-content:space-between;
  background:#e0ecff;
  border-radius:999px;
  padding:6px 12px;
  border:1px solid #bfdbfe;
  box-shadow:0 3px 8px rgba(15,23,42,0.10);
}
.admin-badge{
  display:flex;
  align-items:center;
  gap:6px;
  font-size:13px;
  font-weight:600;
  color:#1d4ed8;
}
.admin-toggle-btn{
  background:#2563eb;
  color:#fff;
  border-radius:999px;
  padding:6px 14px;
  font-size:13px;
  font-weight:600;
  cursor:pointer;
  border:none;
}
.admin-link{
  font-size:13px;
  color:#1d4ed8;
  text-decoration:none;
  font-weight:500;
}
.admin-bar-right{
  display:flex;
  align-items:center;
  gap:8px;
}
.add-benevole-form{
  display:flex;
  flex-wrap:wrap;
  gap:8px;
  align-items:center;
  margin-top:8px;
}
.add-benevole-form input[type="text"]{
  min-width:180px;
}
</style>
</head>
<body>

<?php require_once __DIR__ . '/../shared/suite_nav.php'; ?>

<?php include 'nav.php'; ?>
<?php include 'admin_modal.php'; ?>

<div class="app">
  <div class="card">
    <h2>👥 Bénévoles</h2>
    <p class="small">
      <a href="index.php" style="text-decoration:none;color:#2563eb;font-weight:600;">
        ← Retour à la caisse
      </a>
    </p>

    <?php if($adminError): ?>
      <div class="alert error"><?= htmlspecialchars($adminError) ?></div>
    <?php endif; ?>
    <?php if($adminSuccess): ?>
      <div class="alert success"><?= htmlspecialchars($adminSuccess) ?></div>
    <?php endif; ?>

    <?php if (is_admin()): ?>
      <form method="post" class="add-benevole-form">
        <input type="hidden" name="add_benevole" value="1">
        <span class="small" style="flex-basis:100%;font-weight:600;">Ajouter un bénévole</span>
        <input type="text" name="nom" placeholder="Nom" required>
        <button type="submit">Ajouter</button>
      </form>
    <?php else: ?>
      <p class="small">
        Active le <strong>mode administrateur</strong> pour ajouter ou supprimer des bénévoles.
      </p>
    <?php endif; ?>

    <h3 style="margin-top:16px;">Liste des bénévoles</h3>

    <div>
      <?php foreach($benevoles as $b): ?>
        <div class="benevole-row">
          <span class="benevole-name"><?= htmlspecialchars($b['nom']) ?></span>
          <?php if (is_admin()): ?>
            <form method="post" onsubmit="return confirm('Supprimer ce bénévole ?');">
              <input type="hidden" name="delete_benevole_id" value="<?= (int)$b['id'] ?>">
              <button type="submit" class="danger">Supprimer</button>
            </form>
          <?php else: ?>
            <span class="small">Admin requis</span>
          <?php endif; ?>
        </div>
      <?php endforeach; ?>
      <?php if (empty($benevoles)): ?>
        <p class="small">Aucun bénévole pour le moment.</p>
      <?php endif; ?>
    </div>

  </div>
</div>

</body>
</html>