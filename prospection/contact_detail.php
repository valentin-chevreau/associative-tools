<?php
/**
 * prospection/contact_detail.php — fiche structure + historique de suivi par année.
 */
require_once __DIR__ . '/../shared/bootstrap.php';
require_admin_plus();

require_once __DIR__ . '/../config_db.php';
require_once 'functions_prospection.php';

function h($s): string { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

$id = $_GET['id'] ?? null;
if (!$id) { header('Location: index.php'); exit; }
$contact = get_contact_prospection($conn, $id);
if (!$contact) { header('Location: index.php'); exit; }

$message = $error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    $auteurCourant = current_volunteer_name();

    if ($action === 'ajouter_suivi') {
        $type = $_POST['type'] ?? 'note';
        $texte = trim($_POST['commentaire'] ?? '');
        $dateSuivi = trim($_POST['date_suivi'] ?? '');
        $valeur = $dateSuivi !== '' ? $dateSuivi . ' — ' . $texte : $texte;
        if ($texte === '') {
            $error = 'Le commentaire est obligatoire.';
        } elseif (ajouter_suivi_prospection($conn, (int)$id, $type, $valeur, $auteurCourant)) {
            $message = 'Suivi ajouté.';
        } else {
            $error = 'Erreur lors de l\'ajout du suivi.';
        }
    }

    if ($action === 'marquer_annee') {
        $annee = (int)($_POST['annee'] ?? 0);
        $contacte = ($_POST['contacte'] ?? '') === '1';
        if ($annee > 0 && prospection_marquer_annee_contact($conn, (int)$id, $annee, $contacte, $auteurCourant)) {
            $message = $contacte ? "Année $annee marquée contactée." : "Année $annee démarquée.";
        } else {
            $error = 'Erreur lors de la mise à jour de l\'année.';
        }
    }

    if ($action === 'supprimer_suivi') {
        $suiviId = (int)($_POST['suivi_id'] ?? 0);
        if ($suiviId > 0 && supprimer_suivi_prospection($conn, $suiviId, (int)$id)) {
            $message = 'Entrée d\'historique supprimée.';
        } else {
            $error = 'Erreur lors de la suppression de l\'entrée.';
        }
    }

    if ($action === 'desactiver' && is_admin_plus()) {
        if (desactiver_contact_prospection($conn, (int)$id)) { header("Location: index.php?deactivated=1"); exit; }
        $error = 'Erreur lors de la désactivation.';
    }

    if ($action === 'supprimer' && is_admin_plus()) {
        if (supprimer_contact_prospection($conn, (int)$id)) { header("Location: index.php?deleted=1"); exit; }
        $error = 'Erreur lors de la suppression.';
    }

    $contact = get_contact_prospection($conn, $id);
}

if (!empty($_GET['success'])) $message = 'Fiche enregistrée.';

$historique = get_historique_suivi_prospection($conn, (int)$id);
$resumeAnnees = prospection_resume_annees_contact($historique);

$typeLabels = ['contact' => '📞 Contact', 'rappel' => '🔔 À rappeler', 'note' => '📝 Note'];

$base = function_exists('suite_base') ? suite_base() : '';
$suiteNavV2 = __DIR__ . '/../shared/suite_nav.php';
?>
<!DOCTYPE html>
<html lang="fr">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?= h($contact['nom']) ?> — Prospection</title>
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
      <span class="tu-bc-cur"><?= h($contact['nom']) ?></span>
    </div>
    <div class="tu-topbar-acts">
      <a href="contact_form.php?id=<?= h($id) ?>" class="tu-btn tu-btn-s tu-btn-sm">Modifier</a>
      <a href="index.php" class="tu-btn tu-btn-g tu-btn-sm">← Retour</a>
    </div>
  </div>

  <div class="tu-pg">

    <?php if ($message): ?><div style="background:var(--tu-green-soft);border:1.5px solid rgba(42,125,74,.25);border-radius:14px;padding:12px 16px;margin-bottom:16px;color:var(--tu-green-main);font-size:13px;"><?= h($message) ?></div><?php endif; ?>
    <?php if ($error): ?><div style="background:var(--tu-red-soft);border:1.5px solid rgba(192,67,42,.25);border-radius:14px;padding:12px 16px;margin-bottom:16px;color:var(--tu-red-main);font-size:13px;"><?= h($error) ?></div><?php endif; ?>

    <div class="tu-ph">
      <div>
        <div class="tu-ph-title"><?= h($contact['nom']) ?></div>
        <div class="tu-ph-sub"><?= h(prospection_libelle_categorie($contact['famille_label'])) ?> — <?= h(prospection_libelle_categorie($contact['categorie_label'])) ?></div>
      </div>
      <span class="tu-bdg <?= h(PROSPECTION_STATUT_BADGES[$contact['statut']] ?? 'tu-bdg-ink') ?>" style="align-self:center;padding:6px 12px;">
        <?= h(PROSPECTION_STATUTS[$contact['statut']] ?? $contact['statut']) ?>
      </span>
    </div>

    <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px;margin-bottom:16px;">
      <div class="tu-card" style="padding:20px;">
        <div style="font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.07em;color:var(--tu-ink-300);margin-bottom:14px;">Contact</div>
        <div style="margin-bottom:12px;">
          <div style="font-size:11px;color:var(--tu-ink-300);margin-bottom:3px;">Téléphone</div>
          <div style="font-size:14px;">
            <?php if (!empty($contact['telephone'])): ?>
              <a href="tel:<?= h($contact['telephone']) ?>" style="color:var(--tu-amber-500);"><?= h(format_phone($contact['telephone'])) ?></a>
            <?php else: ?><em style="color:var(--tu-ink-200);">Non renseigné</em><?php endif; ?>
          </div>
        </div>
        <div style="margin-bottom:12px;">
          <div style="font-size:11px;color:var(--tu-ink-300);margin-bottom:3px;">Email</div>
          <div style="font-size:14px;">
            <?php if (!empty($contact['email'])): ?>
              <a href="mailto:<?= h($contact['email']) ?>" style="color:var(--tu-amber-500);"><?= h($contact['email']) ?></a>
            <?php else: ?><em style="color:var(--tu-ink-200);">Non renseigné</em><?php endif; ?>
          </div>
        </div>
        <?php
          $adresseDecoupee = prospection_decouper_adresse($contact['adresse'] ?? null);
          $adresseVoie = $adresseDecoupee['voie'];
          $adresseCpVille = $adresseDecoupee['cp_ville'] ?: ($adresseVoie === '' ? (string)($contact['commune'] ?? '') : '');
        ?>
        <?php if ($adresseVoie !== '' || $adresseCpVille !== ''): ?>
        <div>
          <div style="font-size:11px;color:var(--tu-ink-300);margin-bottom:3px;">Adresse</div>
          <div style="font-size:13px;">
            <?= h($adresseVoie) ?><?= $adresseVoie !== '' && $adresseCpVille !== '' ? '<br>' : '' ?><?= h($adresseCpVille) ?>
          </div>
        </div>
        <?php endif; ?>
      </div>
      <div class="tu-card" style="padding:20px;">
        <div style="font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.07em;color:var(--tu-ink-300);margin-bottom:14px;">Informations complémentaires</div>
        <?php foreach (['site_web' => 'Site web', 'contact_referent' => 'Contact / référent', 'priorite' => 'Priorité'] as $field => $lbl): ?>
          <div style="margin-bottom:12px;">
            <div style="font-size:11px;color:var(--tu-ink-300);margin-bottom:3px;"><?= $lbl ?></div>
            <div style="font-size:13px;"><?= !empty($contact[$field]) ? h($field === 'priorite' ? prospection_libelle_priorite($contact[$field]) : $contact[$field]) : '<em style="color:var(--tu-ink-200);">—</em>' ?></div>
          </div>
        <?php endforeach; ?>
        <?php if (!empty($contact['extra_json'])):
          $extra = json_decode($contact['extra_json'], true) ?: [];
          if ($extra): ?>
          <div>
            <div style="font-size:11px;color:var(--tu-ink-300);margin-bottom:6px;">Autres données (import d'origine)</div>
            <div style="font-size:12px;color:var(--tu-ink-500);line-height:1.6;">
              <?php foreach ($extra as $k => $v): ?>
                <div><strong><?= h((string)$k) ?> :</strong> <?= h((string)$v) ?></div>
              <?php endforeach; ?>
            </div>
          </div>
        <?php endif; endif; ?>
      </div>
    </div>

    <!-- Résumé visuel : a-t-on contacté cette structure chaque année ? -->
    <div class="tu-card" style="padding:20px;margin-bottom:16px;">
      <div style="font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.07em;color:var(--tu-ink-300);margin-bottom:14px;">Contact par année</div>
      <div style="display:flex;flex-wrap:wrap;gap:10px;">
        <?php foreach ($resumeAnnees as $annee => $r):
          $ok = $r['contacte'];
          $bg = $ok ? '#e6f4ea' : '#fbe9e7';
          $bd = $ok ? '#8fcf9f' : '#e4a79b';
          $fg = $ok ? '#2f6b3e' : '#a3402f';
          $icone = $ok ? '✔' : '✘';
        ?>
          <div style="min-width:110px;padding:10px 14px;border-radius:10px;background:<?= $bg ?>;border:1px solid <?= $bd ?>;text-align:center;">
            <div style="font-family:var(--tu-font-d);font-size:13px;font-weight:700;color:var(--tu-ink-700);"><?= (int)$annee ?></div>
            <div style="font-size:18px;line-height:1.4;color:<?= $fg ?>;"><?= $icone ?></div>
            <div style="font-size:11px;color:<?= $fg ?>;">
              <?= $ok ? ($r['nb_contacts'] > 1 ? $r['nb_contacts'] . ' contacts' : 'Contactée') : 'Non contactée' ?>
            </div>
            <?php if ($r['derniere_date']): ?>
              <div style="font-size:10px;color:var(--tu-ink-300);margin-top:2px;"><?= date('d/m', strtotime($r['derniere_date'])) ?></div>
            <?php endif; ?>
            <div style="margin-top:8px;">
              <?php if (!$ok): ?>
                <form method="POST">
                  <input type="hidden" name="action" value="marquer_annee"/>
                  <input type="hidden" name="annee" value="<?= (int)$annee ?>"/>
                  <input type="hidden" name="contacte" value="1"/>
                  <button type="submit" class="tu-btn tu-btn-g tu-btn-sm" style="font-size:11px;padding:4px 8px;">Marquer contactée</button>
                </form>
              <?php elseif ($r['peut_demarquer']): ?>
                <form method="POST" onsubmit="return confirm('Retirer le marquage \'contactée\' pour <?= (int)$annee ?> ?');">
                  <input type="hidden" name="action" value="marquer_annee"/>
                  <input type="hidden" name="annee" value="<?= (int)$annee ?>"/>
                  <input type="hidden" name="contacte" value="0"/>
                  <button type="submit" class="tu-btn tu-btn-g tu-btn-sm" style="font-size:11px;padding:4px 8px;">Démarquer</button>
                </form>
              <?php endif; ?>
            </div>
          </div>
        <?php endforeach; ?>
      </div>
    </div>

    <!-- Historique de suivi, groupé par année -->
    <div class="tu-card" style="padding:20px;margin-bottom:16px;">
      <div style="font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.07em;color:var(--tu-ink-300);margin-bottom:14px;">Historique de suivi (détail)</div>

      <?php if (empty($historique)): ?>
        <p style="font-size:13px;color:var(--tu-ink-300);font-style:italic;margin:0 0 16px;">Aucun suivi enregistré pour l'instant.</p>
      <?php else: foreach ($historique as $annee => $entries): ?>
        <div style="margin-bottom:16px;">
          <div style="font-family:var(--tu-font-d);font-size:13px;font-weight:700;margin-bottom:8px;color:var(--tu-ink-700);">Année <?= (int)$annee ?></div>
          <div style="display:flex;flex-direction:column;gap:6px;">
            <?php foreach ($entries as $e): ?>
              <div style="display:flex;gap:10px;align-items:center;padding:8px 12px;background:var(--tu-sand-50);border:1px solid var(--tu-ink-100);border-radius:8px;font-size:13px;">
                <span style="flex-shrink:0;color:var(--tu-ink-300);font-size:12px;width:80px;"><?= date('d/m/Y', strtotime($e['date_suivi'])) ?></span>
                <span style="flex-shrink:0;"><?= $typeLabels[$e['type']] ?? $e['type'] ?></span>
                <span style="flex:1;"><?= h($e['commentaire']) ?></span>
                <?php if (!empty($e['auteur'])): ?><span style="flex-shrink:0;color:var(--tu-ink-300);font-size:12px;"><?= h($e['auteur']) ?></span><?php endif; ?>
                <form method="POST" onsubmit="return confirm('Supprimer cette entrée d\'historique ?');" style="flex-shrink:0;">
                  <input type="hidden" name="action" value="supprimer_suivi"/>
                  <input type="hidden" name="suivi_id" value="<?= (int)$e['id'] ?>"/>
                  <button type="submit" title="Supprimer" style="border:none;background:transparent;color:var(--tu-ink-300);cursor:pointer;font-size:14px;line-height:1;padding:2px 4px;">🗑</button>
                </form>
              </div>
            <?php endforeach; ?>
          </div>
        </div>
      <?php endforeach; endif; ?>

      <!-- Ajout d'un suivi -->
      <form method="POST" style="padding-top:14px;border-top:1px solid var(--tu-ink-100);">
        <input type="hidden" name="action" value="ajouter_suivi"/>
        <div style="display:flex;gap:8px;flex-wrap:wrap;align-items:flex-end;">
          <div class="tu-form-field">
            <span class="tu-lbl">Type</span>
            <select class="tu-input" name="type" style="width:150px;">
              <option value="contact">📞 Contact</option>
              <option value="rappel">🔔 À rappeler</option>
              <option value="note">📝 Note</option>
            </select>
          </div>
          <div class="tu-form-field">
            <span class="tu-lbl">Date</span>
            <input class="tu-input" type="date" name="date_suivi" style="width:150px;" value="<?= date('Y-m-d') ?>"/>
          </div>
          <div class="tu-form-field" style="flex:1;min-width:200px;">
            <span class="tu-lbl">Commentaire *</span>
            <input class="tu-input" type="text" name="commentaire" required placeholder="Ex: Appelé, rappeler en janvier…"/>
          </div>
          <button type="submit" class="tu-btn tu-btn-p tu-btn-sm">+ Ajouter</button>
        </div>
      </form>
    </div>

    <?php if (is_admin_plus()): ?>
    <div class="tu-card" style="padding:18px;border-color:rgba(192,67,42,.2);">
      <div style="font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.07em;color:var(--tu-red-main);margin-bottom:12px;">Zone dangereuse</div>
      <div style="display:flex;gap:10px;flex-wrap:wrap;">
        <form method="POST" onsubmit="return confirm('Désactiver cette fiche ? Elle disparaîtra des listes mais l\'historique est conservé.');">
          <input type="hidden" name="action" value="desactiver"/>
          <button type="submit" class="tu-btn tu-btn-s tu-btn-sm" style="color:var(--tu-red-main);">Désactiver cette fiche</button>
        </form>
        <form method="POST" onsubmit="return confirm('Supprimer DÉFINITIVEMENT cette fiche et tout son historique de suivi ? Cette action est irréversible.');">
          <input type="hidden" name="action" value="supprimer"/>
          <button type="submit" class="tu-btn tu-btn-s tu-btn-sm" style="color:#fff;background:var(--tu-red-main);border-color:var(--tu-red-main);">🗑 Supprimer définitivement</button>
        </form>
      </div>
    </div>
    <?php endif; ?>

  </div>
</div>
</body>
</html>
