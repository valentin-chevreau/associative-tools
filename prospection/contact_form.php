<?php
/**
 * prospection/contact_form.php — création / modification manuelle d'une fiche.
 */
require_once __DIR__ . '/../shared/bootstrap.php';
require_admin_plus();

require_once __DIR__ . '/../config_db.php';
require_once 'functions_prospection.php';

function h($s): string { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

$id = $_GET['id'] ?? null;
$contact = null; $mode = 'creation';
if ($id) {
    $contact = get_contact_prospection($conn, $id);
    if (!$contact) { header('Location: index.php'); exit; }
    $mode = 'modification';
}

$categories = get_categories_prospection($conn);
$error = '';
$categorieIdPreselect = ($mode === 'creation') ? (int)($_GET['categorie_id'] ?? 0) : 0;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $data = [
        'nom'              => trim($_POST['nom'] ?? ''),
        'commune'          => trim($_POST['commune'] ?? ''),
        'adresse'          => trim($_POST['adresse'] ?? ''),
        'telephone'        => trim($_POST['telephone'] ?? ''),
        'email'            => trim($_POST['email'] ?? ''),
        'site_web'         => trim($_POST['site_web'] ?? ''),
        'contact_referent' => trim($_POST['contact_referent'] ?? ''),
        'priorite'         => trim($_POST['priorite'] ?? ''),
        'statut'           => $_POST['statut'] ?? 'a_contacter',
    ];

    if ($data['nom'] === '') {
        $error = 'Le nom est obligatoire.';
    } elseif ($mode === 'creation') {
        $categorieId = (int)($_POST['categorie_id'] ?? 0);
        if (!$categorieId) {
            $error = 'La catégorie est obligatoire.';
        } else {
            $newId = creer_contact_prospection($conn, $categorieId, $data);
            if ($newId) { header("Location: contact_detail.php?id=$newId&success=1"); exit; }
            $error = 'Erreur lors de la création.';
        }
    } else {
        if (modifier_contact_prospection($conn, (int)$id, $data)) { header("Location: contact_detail.php?id=$id&success=1"); exit; }
        $error = 'Erreur lors de la modification.';
    }
}

$base = function_exists('suite_base') ? suite_base() : '';
$suiteNavV2 = __DIR__ . '/../shared/suite_nav.php';
?>
<!DOCTYPE html>
<html lang="fr">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?= $mode === 'creation' ? 'Nouvelle fiche' : 'Modifier' ?> — Prospection</title>
  <link rel="stylesheet" href="<?= h($base) ?>/assets/css/suite_nav.css">
</head>
<body class="tu-v2">
<?php if (is_file($suiteNavV2)): require_once $suiteNavV2; suite_nav_render('prospection', 'prospection-toutes'); endif; ?>

<div class="tu-main">
  <div class="tu-topbar">
    <div class="tu-bc">
      <a href="<?= h($base . '/index.php') ?>" style="color:inherit;text-decoration:none;">Accueil</a>
      <span class="tu-bc-sep">›</span>
      <a href="index.php" style="color:inherit;text-decoration:none;">Prospection</a>
      <span class="tu-bc-sep">›</span>
      <span class="tu-bc-cur"><?= $mode === 'creation' ? 'Nouvelle fiche' : 'Modifier' ?></span>
    </div>
    <div class="tu-topbar-acts">
      <a href="<?= $mode === 'creation' ? 'index.php' : 'contact_detail.php?id=' . h($id) ?>" class="tu-btn tu-btn-g tu-btn-sm">← Retour</a>
    </div>
  </div>

  <div class="tu-pg">
    <div class="tu-ph"><div class="tu-ph-title"><?= $mode === 'creation' ? 'Nouvelle fiche' : 'Modifier la fiche' ?></div></div>

    <?php if ($error): ?>
      <div style="background:var(--tu-red-soft);border:1.5px solid rgba(192,67,42,.25);border-radius:14px;padding:12px 16px;margin-bottom:16px;color:var(--tu-red-main);font-size:13px;"><?= h($error) ?></div>
    <?php endif; ?>

    <form method="POST">
    <div style="display:grid;grid-template-columns:1fr 280px;gap:16px;align-items:start;">
      <div class="tu-card" style="padding:22px;">

        <?php if ($mode === 'creation'): ?>
          <div class="tu-sec-div"><span class="tu-sec-div-lbl">Catégorie</span><div class="tu-sec-div-line"></div></div>
          <div class="tu-form-field tu-mb4">
            <span class="tu-lbl">Catégorie *</span>
            <select class="tu-input" name="categorie_id" required>
              <option value="">-- Sélectionner --</option>
              <?php foreach ($categories as $c): ?>
                <option value="<?= (int)$c['id'] ?>" <?= $categorieIdPreselect === (int)$c['id'] ? 'selected' : '' ?>><?= h(prospection_libelle_categorie($c['famille_label'])) ?> — <?= h(prospection_libelle_categorie($c['label'])) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
        <?php else: ?>
          <div class="tu-sec-div"><span class="tu-sec-div-lbl">Catégorie</span><div class="tu-sec-div-line"></div></div>
          <p style="font-size:13px;margin:0 0 16px;color:var(--tu-ink-500);"><?= h(prospection_libelle_categorie($contact['famille_label'])) ?> — <?= h(prospection_libelle_categorie($contact['categorie_label'])) ?> <span style="color:var(--tu-ink-300);">(non modifiable)</span></p>
        <?php endif; ?>

        <div class="tu-sec-div"><span class="tu-sec-div-lbl">Identité</span><div class="tu-sec-div-line"></div></div>
        <div class="tu-form-field tu-mb4">
          <span class="tu-lbl">Nom *</span>
          <input class="tu-input" type="text" name="nom" required value="<?= h($contact['nom'] ?? '') ?>" placeholder="Structure, société, enseigne…"/>
        </div>
        <div class="tu-form-grid tu-mb4">
          <div class="tu-form-field"><span class="tu-lbl">Commune</span><input class="tu-input" name="commune" value="<?= h($contact['commune'] ?? '') ?>"/></div>
          <div class="tu-form-field"><span class="tu-lbl">Adresse</span><input class="tu-input" name="adresse" value="<?= h($contact['adresse'] ?? '') ?>"/></div>
        </div>

        <div class="tu-sec-div"><span class="tu-sec-div-lbl">Contact</span><div class="tu-sec-div-line"></div></div>
        <div class="tu-form-grid tu-mb4">
          <div class="tu-form-field"><span class="tu-lbl">Téléphone</span><input class="tu-input" type="tel" name="telephone" value="<?= h($contact['telephone'] ?? '') ?>"/></div>
          <div class="tu-form-field"><span class="tu-lbl">Email</span><input class="tu-input" type="email" name="email" value="<?= h($contact['email'] ?? '') ?>"/></div>
        </div>
        <div class="tu-form-grid tu-mb4">
          <div class="tu-form-field"><span class="tu-lbl">Site web</span><input class="tu-input" type="text" name="site_web" value="<?= h($contact['site_web'] ?? '') ?>"/></div>
          <div class="tu-form-field"><span class="tu-lbl">Contact / référent</span><input class="tu-input" name="contact_referent" value="<?= h($contact['contact_referent'] ?? '') ?>"/></div>
        </div>

        <div class="tu-sec-div"><span class="tu-sec-div-lbl">Suivi</span><div class="tu-sec-div-line"></div></div>
        <div class="tu-form-grid tu-mb4">
          <div class="tu-form-field"><span class="tu-lbl">Priorité</span><input class="tu-input" name="priorite" value="<?= h($contact['priorite'] ?? '') ?>" placeholder="Ex: 1, Haute…"/></div>
          <?php if ($mode === 'modification'): ?>
            <div class="tu-form-field">
              <span class="tu-lbl">Statut</span>
              <select class="tu-input" name="statut">
                <?php foreach (PROSPECTION_STATUTS as $code => $label): ?>
                  <option value="<?= h($code) ?>" <?= ($contact['statut'] ?? '') === $code ? 'selected' : '' ?>><?= h($label) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
          <?php endif; ?>
        </div>

      </div>
      <div class="tu-card" style="padding:18px;">
        <div style="font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.07em;color:var(--tu-ink-300);margin-bottom:12px;">Actions</div>
        <div style="display:flex;flex-direction:column;gap:8px;">
          <button type="submit" class="tu-btn tu-btn-p tu-w100" style="justify-content:center;"><?= $mode === 'creation' ? 'Créer la fiche' : 'Enregistrer' ?></button>
          <a href="<?= $mode === 'creation' ? 'index.php' : 'contact_detail.php?id=' . h($id) ?>" class="tu-btn tu-btn-s tu-w100" style="justify-content:center;">Annuler</a>
        </div>
      </div>
    </div>
    </form>
  </div>
</div>
</body>
</html>
