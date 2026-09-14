<?php
/**
 * prospection/index.php — module Prospection (démarchage matériel/dons)
 * Liste harmonisée des 9 anciens onglets Excel, avec filtres.
 */
require_once __DIR__ . '/../shared/bootstrap.php';
require_admin_plus(); // module réservé admin+

require_once __DIR__ . '/../config_db.php';
require_once 'functions_prospection.php';

function h($s): string { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

/* ------------------------------------------------------------------
   Actions en masse (case à cocher + barre d'actions) : traitées avant
   tout affichage pour pouvoir rediriger sur la même vue filtrée avec
   un message de résultat, sans re-soumission possible au F5.
------------------------------------------------------------------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['bulk_action'])) {
    $bulkAction = (string)$_POST['bulk_action'];
    $ids = $_POST['ids'] ?? [];
    $options = [];
    if ($bulkAction === 'priorite') $options['priorite'] = trim((string)($_POST['priorite_masse'] ?? ''));
    if ($bulkAction === 'statut')   $options['statut']   = (string)($_POST['statut_masse'] ?? '');

    $resultatMasse = ['ok' => 0, 'fail' => 0];
    if (is_array($ids) && !empty($ids) && in_array($bulkAction, ['desactiver', 'supprimer', 'priorite', 'statut'], true)) {
        $resultatMasse = prospection_action_masse($conn, $bulkAction, $ids, $options);
    }

    $redirectQs = (string)($_POST['redirect_qs'] ?? '');
    $sep = $redirectQs !== '' ? '&' : '';
    header('Location: index.php?' . $redirectQs . $sep . 'bulk_action=' . urlencode($bulkAction) . '&bulk_ok=' . (int)$resultatMasse['ok'] . '&bulk_fail=' . (int)$resultatMasse['fail']);
    exit;
}

$categorieCode = trim((string)($_GET['categorie'] ?? ''));
$familleFiltre = trim((string)($_GET['famille'] ?? ''));
$statutFiltre  = trim((string)($_GET['statut'] ?? ''));
$recherche     = trim((string)($_GET['q'] ?? ''));
$contactAnnee  = trim((string)($_GET['contact_annee'] ?? ''));

$colonnesTri = prospection_colonnes_tri();
$triCol = trim((string)($_GET['tri'] ?? ''));
if (!isset($colonnesTri[$triCol])) $triCol = '';
$triDir = strtolower((string)($_GET['dir'] ?? '')) === 'desc' ? 'desc' : 'asc';

$categories = get_categories_prospection($conn);
$categorieActuelle = null;
$filters = [];
if ($categorieCode !== '') {
    foreach ($categories as $c) { if ($c['code'] === $categorieCode) { $categorieActuelle = $c; break; } }
    if ($categorieActuelle) $filters['categorie_id'] = $categorieActuelle['id'];
}
if ($familleFiltre !== '') $filters['famille'] = $familleFiltre;
if ($statutFiltre !== '')  $filters['statut']  = $statutFiltre;
if ($recherche !== '')     $filters['recherche'] = $recherche;
if ($contactAnnee !== '' && str_contains($contactAnnee, ':')) {
    [$sensContact, $anneeContactFiltre] = explode(':', $contactAnnee, 2);
    $anneeContactFiltre = (int)$anneeContactFiltre;
    if ($anneeContactFiltre > 0) {
        if ($sensContact === 'oui') $filters['annee_contact'] = $anneeContactFiltre;
        elseif ($sensContact === 'non') $filters['annee_non_contact'] = $anneeContactFiltre;
    }
}

$contacts = get_liste_contacts_prospection($conn, $filters, $triCol ?: null, $triDir);
$stats    = get_stats_prospection($conn, $filters);

// KPI "contactées / non contactées" pour l'année en cours et l'année N-1,
// sur le périmètre des filtres hors ceux liés au contact par année.
$filtresKpi = $filters;
unset($filtresKpi['annee_contact'], $filtresKpi['annee_non_contact']);
$anneeCourante = (int)date('Y');
$statsAnneeCourante = get_stats_annee_prospection($conn, $filtresKpi, $anneeCourante);
$statsAnneePrecedente = get_stats_annee_prospection($conn, $filtresKpi, $anneeCourante - 1);

// Familles distinctes (pour le filtre), dans l'ordre des catégories
$familles = [];
foreach ($categories as $c) {
    if (!in_array($c['famille_label'], $familles, true)) $familles[] = $c['famille_label'];
}

$base = function_exists('suite_base') ? suite_base() : '';
$suiteNavV2 = __DIR__ . '/../shared/suite_nav.php';

$pageTitle = $categorieActuelle ? prospection_libelle_categorie($categorieActuelle['label']) : ($familleFiltre !== '' ? $familleFiltre : 'Toutes les fiches');

$bulkActionLabels = [
    'desactiver' => 'désactivée(s)',
    'supprimer'  => 'supprimée(s) définitivement',
    'priorite'   => 'mise(s) à jour (priorité)',
    'statut'     => 'mise(s) à jour (statut)',
];

// Query string des filtres actuels (tri inclus), pour revenir sur la même
// vue après une action en masse, et pour construire les liens de tri.
$qsParts = [];
foreach (['categorie' => $categorieCode, 'famille' => $familleFiltre, 'statut' => $statutFiltre, 'q' => $recherche, 'contact_annee' => $contactAnnee, 'tri' => $triCol, 'dir' => $triCol ? $triDir : ''] as $k => $v) {
    if ($v !== '') $qsParts[] = $k . '=' . urlencode($v);
}
$currentQs = implode('&', $qsParts);

/**
 * Construit le lien d'en-tête de colonne triable : clique une 1ère fois pour
 * trier croissant, une 2e fois sur la même colonne pour inverser.
 */
function prospection_lien_tri(string $col, string $label, string $triColActuel, string $triDirActuel, array $qsBase): string {
    $prochaineDir = ($triColActuel === $col && $triDirActuel === 'asc') ? 'desc' : 'asc';
    $qs = $qsBase;
    $qs['tri'] = $col; $qs['dir'] = $prochaineDir;
    $qs = array_filter($qs, fn($v) => $v !== '');
    $fleche = '';
    if ($triColActuel === $col) $fleche = $triDirActuel === 'asc' ? ' ↑' : ' ↓';
    return '<a href="index.php?' . htmlspecialchars(http_build_query($qs), ENT_QUOTES, 'UTF-8') . '" style="color:inherit;text-decoration:none;display:flex;align-items:center;gap:2px;">' . htmlspecialchars($label, ENT_QUOTES, 'UTF-8') . $fleche . '</a>';
}
$qsBaseTri = ['categorie' => $categorieCode, 'famille' => $familleFiltre, 'statut' => $statutFiltre, 'q' => $recherche, 'contact_annee' => $contactAnnee];
?>
<!DOCTYPE html>
<html lang="fr">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Prospection — Touraine-Ukraine</title>
  <link rel="stylesheet" href="<?= h($base) ?>/assets/css/suite_nav.css">
</head>
<body class="tu-v2">
<?php if (is_file($suiteNavV2)): require_once $suiteNavV2; suite_nav_render('prospection', 'prospection-' . ($categorieCode ?: 'toutes')); endif; ?>

<div class="tu-main">
  <div class="tu-topbar">
    <div class="tu-bc">
      <a href="<?= h($base . '/index.php') ?>" style="color:inherit;text-decoration:none;">Accueil</a>
      <span class="tu-bc-sep">›</span>
      <span class="tu-bc-cur">Prospection</span>
    </div>
    <div class="tu-topbar-acts">
      <a href="import.php" class="tu-btn tu-btn-s tu-btn-sm">📥 Importer un CSV</a>
      <a href="contact_form.php<?= $categorieActuelle ? '?categorie_id=' . (int)$categorieActuelle['id'] : '' ?>" class="tu-btn tu-btn-p tu-btn-sm">+ Nouvelle fiche</a>
    </div>
  </div>

  <div class="tu-pg">

    <?php if (!empty($_GET['deleted'])): ?>
      <div style="background:var(--tu-green-soft);border:1.5px solid rgba(42,125,74,.25);border-radius:14px;padding:12px 16px;margin-bottom:16px;color:var(--tu-green-main);font-size:13px;">Fiche supprimée définitivement.</div>
    <?php elseif (!empty($_GET['deactivated'])): ?>
      <div style="background:var(--tu-green-soft);border:1.5px solid rgba(42,125,74,.25);border-radius:14px;padding:12px 16px;margin-bottom:16px;color:var(--tu-green-main);font-size:13px;">Fiche désactivée.</div>
    <?php elseif (!empty($_GET['bulk_action'])):
      $bAction = (string)$_GET['bulk_action']; $bOk = (int)($_GET['bulk_ok'] ?? 0); $bFail = (int)($_GET['bulk_fail'] ?? 0);
      $bLabel = $bulkActionLabels[$bAction] ?? 'traitée(s)';
    ?>
      <div style="background:var(--tu-green-soft);border:1.5px solid rgba(42,125,74,.25);border-radius:14px;padding:12px 16px;margin-bottom:16px;color:var(--tu-green-main);font-size:13px;">
        <?= (int)$bOk ?> fiche<?= $bOk > 1 ? 's' : '' ?> <?= h($bLabel) ?><?= $bFail > 0 ? ', ' . (int)$bFail . ' échec(s)' : '' ?>.
      </div>
    <?php endif; ?>

    <div class="tu-ph">
      <div>
        <div class="tu-ph-title"><?= h($pageTitle) ?></div>
        <div class="tu-ph-sub">Démarchage matériel &amp; dons — <?= (int)$stats['total'] ?> fiche<?= $stats['total'] > 1 ? 's' : '' ?></div>
      </div>
    </div>

    <!-- KPIs -->
    <div class="tu-kpi-grid tu-mb4">
      <div class="tu-kpi"><div class="tu-kpi-val"><?= (int)$stats['par_statut']['a_contacter'] ?></div><div class="tu-kpi-lbl">À contacter</div></div>
      <div class="tu-kpi blue"><div class="tu-kpi-val"><?= (int)$stats['par_statut']['contacte'] ?></div><div class="tu-kpi-lbl">Contactées</div></div>
      <div class="tu-kpi amber"><div class="tu-kpi-val"><?= (int)$stats['par_statut']['a_rappeler'] ?></div><div class="tu-kpi-lbl">À rappeler</div></div>
      <div class="tu-kpi green"><div class="tu-kpi-val"><?= (int)$stats['par_statut']['accorde'] ?></div><div class="tu-kpi-lbl">Accordées</div></div>
      <div class="tu-kpi red"><div class="tu-kpi-val"><?= (int)$stats['par_statut']['refuse'] ?></div><div class="tu-kpi-lbl">Refusées</div></div>
    </div>

    <!-- KPI contact par année, sur le périmètre des filtres actifs -->
    <div class="tu-card tu-mb4" style="padding:16px 18px;">
      <div style="font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.07em;color:var(--tu-ink-300);margin-bottom:12px;">Suivi des contacts</div>
      <div style="display:flex;gap:24px;flex-wrap:wrap;">
        <?php foreach ([$anneeCourante => $statsAnneeCourante, $anneeCourante - 1 => $statsAnneePrecedente] as $annee => $s): ?>
          <div>
            <div style="font-family:var(--tu-font-d);font-size:13px;font-weight:700;color:var(--tu-ink-700);margin-bottom:4px;"><?= (int)$annee ?></div>
            <div style="font-size:13px;color:var(--tu-ink-500);">
              <span style="color:#2f6b3e;font-weight:700;"><?= (int)$s['contactees'] ?></span> contactée(s)
              &nbsp;·&nbsp;
              <span style="color:#a3402f;font-weight:700;"><?= (int)$s['non_contactees'] ?></span> non contactée(s)
              <span style="color:var(--tu-ink-300);">/ <?= (int)$s['total'] ?> fiche(s)</span>
            </div>
          </div>
        <?php endforeach; ?>
      </div>
    </div>

    <!-- Filtres -->
    <form method="get" class="tu-card tu-mb4" style="padding:16px 18px;">
      <div style="display:flex;gap:10px;align-items:flex-end;flex-wrap:wrap;">
        <div class="tu-form-field">
          <span class="tu-lbl">Catégorie</span>
          <select class="tu-input" name="categorie" onchange="this.form.submit()">
            <option value="">Toutes</option>
            <?php $curFamille = null; foreach ($categories as $c): ?>
              <option value="<?= h($c['code']) ?>" <?= $categorieCode === $c['code'] ? 'selected' : '' ?>>
                <?= h(prospection_libelle_categorie($c['famille_label'])) ?> — <?= h(prospection_libelle_categorie($c['label'])) ?>
              </option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="tu-form-field">
          <span class="tu-lbl">Statut</span>
          <select class="tu-input" name="statut" onchange="this.form.submit()">
            <option value="">Tous</option>
            <?php foreach (PROSPECTION_STATUTS as $code => $label): ?>
              <option value="<?= h($code) ?>" <?= $statutFiltre === $code ? 'selected' : '' ?>><?= h($label) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="tu-form-field">
          <span class="tu-lbl">Contact</span>
          <select class="tu-input" name="contact_annee" onchange="this.form.submit()">
            <option value="">Peu importe</option>
            <?php for ($a = $anneeCourante; $a >= $anneeCourante - 3; $a--): ?>
              <option value="oui:<?= $a ?>" <?= $contactAnnee === "oui:$a" ? 'selected' : '' ?>>Contactée en <?= $a ?></option>
              <option value="non:<?= $a ?>" <?= $contactAnnee === "non:$a" ? 'selected' : '' ?>>Non contactée en <?= $a ?></option>
            <?php endfor; ?>
          </select>
        </div>
        <div class="tu-form-field" style="flex:1;min-width:200px;">
          <span class="tu-lbl">Recherche</span>
          <input class="tu-input" type="text" name="q" value="<?= h($recherche) ?>" placeholder="Nom, commune, email…"/>
        </div>
        <button type="submit" class="tu-btn tu-btn-s tu-btn-sm">Filtrer</button>
        <?php if ($categorieCode !== '' || $statutFiltre !== '' || $recherche !== '' || $contactAnnee !== ''): ?>
          <a href="index.php" class="tu-btn tu-btn-g tu-btn-sm">Réinitialiser</a>
        <?php endif; ?>
      </div>
    </form>

    <!-- Liste -->
    <form id="bulkForm" method="POST" action="index.php">
      <input type="hidden" name="bulk_action" id="bulkActionInput" value=""/>
      <input type="hidden" name="redirect_qs" value="<?= h($currentQs) ?>"/>
      <input type="hidden" name="priorite_masse" id="prioriteMasseInput" value=""/>
      <input type="hidden" name="statut_masse" id="statutMasseInput" value=""/>

      <div class="tu-card prosp-tbl-wrap">
        <table class="tu-tbl prosp-tbl">
          <colgroup>
            <col style="width:36px;">
            <col style="width:19%;">
            <col style="width:13%;">
            <col style="width:19%;">
            <col style="width:19%;">
            <col style="width:10%;">
            <col style="width:12%;">
          </colgroup>
          <thead>
            <tr>
              <th><input type="checkbox" id="checkAll" onclick="prospToggleAll(this)"/></th>
              <th><?= prospection_lien_tri('nom', 'Nom', $triCol, $triDir, $qsBaseTri) ?></th>
              <th><?= prospection_lien_tri('categorie', 'Catégorie', $triCol, $triDir, $qsBaseTri) ?></th>
              <th><?= prospection_lien_tri('adresse', 'Adresse', $triCol, $triDir, $qsBaseTri) ?></th>
              <th><?= prospection_lien_tri('contact', 'Contact', $triCol, $triDir, $qsBaseTri) ?></th>
              <th><?= prospection_lien_tri('priorite', 'Priorité', $triCol, $triDir, $qsBaseTri) ?></th>
              <th><?= prospection_lien_tri('statut', 'Statut', $triCol, $triDir, $qsBaseTri) ?></th>
            </tr>
          </thead>
          <tbody>
            <?php $count = 0; while ($row = mysqli_fetch_assoc($contacts)):
              $count++;
              $categorieTxt = prospection_libelle_categorie($row['famille_label']) . ' — ' . prospection_libelle_categorie($row['categorie_label']);
              $adresseDecoupee = prospection_decouper_adresse($row['adresse'] ?? null);
              $adresseVoie    = $adresseDecoupee['voie'];
              $adresseCpVille = $adresseDecoupee['cp_ville'] ?: ($adresseVoie === '' ? ($row['commune'] ?? '') : '');
              $adresseTitre   = trim($adresseVoie . ($adresseCpVille ? ', ' . $adresseCpVille : ''));
              $emailTxt     = $row['email'] ?? '';
              $prioriteLabel = $row['priorite'] ? prospection_libelle_priorite($row['priorite']) : '';
              $prioriteClass = match ($prioriteLabel) {
                  'Haute'   => 'prosp-tr-haute',
                  'Moyenne' => 'prosp-tr-moyenne',
                  'Basse'   => 'prosp-tr-basse',
                  default   => '',
              };
            ?>
              <tr class="<?= $prioriteClass ?>" style="cursor:pointer;" onclick="window.location='contact_detail.php?id=<?= (int)$row['id'] ?>'">
                <td onclick="event.stopPropagation();">
                  <input type="checkbox" name="ids[]" value="<?= (int)$row['id'] ?>" class="prosp-row-check" onchange="prospUpdateBar()"/>
                </td>
                <td class="prosp-td-trunc" style="font-weight:700;font-size:13px;" title="<?= h($row['nom']) ?>"><?= h($row['nom']) ?></td>
                <td class="prosp-td-trunc" style="font-size:12px;color:var(--tu-ink-300);" title="<?= h($categorieTxt) ?>"><?= h($categorieTxt) ?></td>
                <td title="<?= h($adresseTitre) ?>">
                  <?php if ($adresseTitre === ''): ?>—
                  <?php else: ?>
                    <?php if ($adresseVoie !== ''): ?><div class="prosp-td-trunc" style="font-size:13px;"><?= h($adresseVoie) ?></div><?php endif; ?>
                    <?php if ($adresseCpVille !== ''): ?><div class="prosp-td-trunc" style="font-size:11.5px;color:var(--tu-ink-300);"><?= h($adresseCpVille) ?></div><?php endif; ?>
                  <?php endif; ?>
                </td>
                <td style="font-size:12px;">
                  <?php if (!empty($row['telephone'])): ?><div class="prosp-td-trunc"><?= h(format_phone($row['telephone'])) ?></div><?php endif; ?>
                  <?php if ($emailTxt !== ''): ?><div class="prosp-td-trunc" style="color:var(--tu-ink-300);" title="<?= h($emailTxt) ?>"><?= h($emailTxt) ?></div><?php endif; ?>
                  <?php if (empty($row['telephone']) && $emailTxt === ''): ?>—<?php endif; ?>
                </td>
                <td class="prosp-td-trunc" style="font-size:12px;"><?= $row['priorite'] ? h(prospection_libelle_priorite($row['priorite'])) : '—' ?></td>
                <td class="prosp-td-trunc"><span class="tu-bdg <?= h(PROSPECTION_STATUT_BADGES[$row['statut']] ?? 'tu-bdg-ink') ?>"><?= h(PROSPECTION_STATUTS[$row['statut']] ?? $row['statut']) ?></span></td>
              </tr>
            <?php endwhile; ?>
            <?php if ($count === 0): ?>
              <tr><td colspan="7" style="text-align:center;padding:28px;color:var(--tu-ink-300);">Aucune fiche pour ces critères.</td></tr>
            <?php endif; ?>
          </tbody>
        </table>
      </div>
    </form>

    <!-- Barre d'actions en masse (apparaît quand ≥1 fiche est cochée) -->
    <div id="prospBulkBar" class="tu-bulk-bar" hidden>
      <span id="prospBulkCount" style="font-weight:700;font-size:13px;"></span>
      <div style="display:flex;gap:8px;flex-wrap:wrap;">
        <button type="button" class="tu-btn tu-btn-s tu-btn-sm" onclick="prospOpenPrioriteModal()">Changer la priorité</button>
        <button type="button" class="tu-btn tu-btn-s tu-btn-sm" onclick="prospOpenConfirmModal('desactiver')">Désactiver</button>
        <button type="button" class="tu-btn tu-btn-s tu-btn-sm" style="color:#fff;background:var(--tu-red-main);border-color:var(--tu-red-main);" onclick="prospOpenConfirmModal('supprimer')">🗑 Supprimer définitivement</button>
        <button type="button" class="tu-btn tu-btn-g tu-btn-sm" onclick="prospClearSelection()">Annuler la sélection</button>
      </div>
    </div>

    <!-- Modale : confirmation désactiver / supprimer -->
    <div id="prospModalConfirm" class="tu-modal-backdrop" hidden onclick="if(event.target===this) prospCloseModals()">
      <div class="tu-modal">
        <div id="prospModalConfirmTitle" class="tu-modal-title"></div>
        <div id="prospModalConfirmBody" class="tu-modal-body"></div>
        <div class="tu-modal-actions">
          <button type="button" class="tu-btn tu-btn-g tu-btn-sm" onclick="prospCloseModals()">Annuler</button>
          <button type="button" id="prospModalConfirmBtn" class="tu-btn tu-btn-p tu-btn-sm">Confirmer</button>
        </div>
      </div>
    </div>

    <!-- Modale : changer la priorité en masse -->
    <div id="prospModalPriorite" class="tu-modal-backdrop" hidden onclick="if(event.target===this) prospCloseModals()">
      <div class="tu-modal">
        <div class="tu-modal-title">Changer la priorité</div>
        <div class="tu-modal-body">
          <p style="font-size:13px;margin:0 0 12px;color:var(--tu-ink-500);">Nouvelle priorité pour les <span id="prospModalPrioriteCount"></span> fiche(s) sélectionnée(s) :</p>
          <input type="text" id="prospModalPrioriteInput" class="tu-input" placeholder="Ex: 1, Haute…"/>
        </div>
        <div class="tu-modal-actions">
          <button type="button" class="tu-btn tu-btn-g tu-btn-sm" onclick="prospCloseModals()">Annuler</button>
          <button type="button" class="tu-btn tu-btn-p tu-btn-sm" onclick="prospConfirmPriorite()">Appliquer</button>
        </div>
      </div>
    </div>

  </div><!-- /tu-pg -->
</div><!-- /tu-main -->

<style>
/* Liste Prospection : défilement horizontal propre sur petit écran plutôt
   que du texte qui retourne à la ligne et déforme les rangées ; colonnes à
   largeur fixe avec troncature + info-bulle (title) pour garder chaque ligne
   sur une hauteur constante. */
.prosp-tbl-wrap { overflow-x: auto; -webkit-overflow-scrolling: touch; }
.prosp-tbl { table-layout: fixed; min-width: 720px; }
.prosp-tbl th, .prosp-tbl td { overflow: hidden; }
.prosp-td-trunc {
  white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
}
@media (max-width: 640px) {
  .prosp-tbl { min-width: 560px; }
  .prosp-tbl th, .prosp-tbl td { padding: 8px 10px; font-size: 12px; }
}
/* Colorimétrie légère par priorité, pour repérer les fiches prioritaires
   d'un coup d'œil sans surcharger le tableau. */
.prosp-tbl tbody tr.prosp-tr-haute td   { background: var(--tu-red-soft); }
.prosp-tbl tbody tr.prosp-tr-moyenne td { background: #faf3dd; }
.prosp-tbl tbody tr.prosp-tr-basse td   { background: var(--tu-green-soft); }
.prosp-tbl tbody tr.prosp-tr-haute:hover td   { background: #efd7d0; }
.prosp-tbl tbody tr.prosp-tr-moyenne:hover td { background: #f4e8c4; }
.prosp-tbl tbody tr.prosp-tr-basse:hover td   { background: #d5e9dc; }
.prosp-tbl tbody tr.prosp-tr-haute td:first-child   { box-shadow: inset 3px 0 0 var(--tu-red-main); }
.prosp-tbl tbody tr.prosp-tr-moyenne td:first-child { box-shadow: inset 3px 0 0 var(--tu-amber-600); }
.prosp-tbl tbody tr.prosp-tr-basse td:first-child   { box-shadow: inset 3px 0 0 var(--tu-green-main); }
.tu-bulk-bar {
  position: sticky; bottom: 16px; margin-top: 16px;
  align-items: center; justify-content: space-between; gap: 16px; flex-wrap: wrap;
  background: var(--tu-ink-900, #201a14); color: #fff;
  border-radius: 14px; padding: 14px 20px; box-shadow: 0 8px 24px rgba(0,0,0,.25);
}
.tu-bulk-bar[hidden] { display: none; }
.tu-bulk-bar:not([hidden]) { display: flex; }
.tu-bulk-bar #prospBulkCount { color: #fff; }
.tu-modal-backdrop {
  position: fixed; inset: 0; background: rgba(20,15,10,.45); z-index: 1000;
  align-items: center; justify-content: center; padding: 16px;
}
.tu-modal-backdrop[hidden] { display: none; }
.tu-modal-backdrop:not([hidden]) { display: flex; }
.tu-modal {
  background: var(--tu-sand-50, #fff); border-radius: 16px; padding: 22px;
  max-width: 420px; width: 100%; box-shadow: 0 16px 48px rgba(0,0,0,.3);
}
.tu-modal-title { font-family: var(--tu-font-d); font-weight: 700; font-size: 16px; margin-bottom: 10px; }
.tu-modal-body { font-size: 13px; color: var(--tu-ink-500); margin-bottom: 18px; }
.tu-modal-actions { display: flex; justify-content: flex-end; gap: 8px; }
</style>

<script>
function prospGetChecked() {
  return Array.from(document.querySelectorAll('.prosp-row-check:checked'));
}
function prospToggleAll(cb) {
  document.querySelectorAll('.prosp-row-check').forEach(el => { el.checked = cb.checked; });
  prospUpdateBar();
}
function prospUpdateBar() {
  const n = prospGetChecked().length;
  const bar = document.getElementById('prospBulkBar');
  document.getElementById('prospBulkCount').textContent = n + ' fiche' + (n > 1 ? 's' : '') + ' sélectionnée' + (n > 1 ? 's' : '');
  bar.hidden = (n === 0);
  const all = document.querySelectorAll('.prosp-row-check');
  document.getElementById('checkAll').checked = (all.length > 0 && n === all.length);
}
function prospClearSelection() {
  document.querySelectorAll('.prosp-row-check').forEach(el => { el.checked = false; });
  document.getElementById('checkAll').checked = false;
  prospUpdateBar();
}
function prospCloseModals() {
  document.getElementById('prospModalConfirm').hidden = true;
  document.getElementById('prospModalPriorite').hidden = true;
}
function prospSubmitBulk(action) {
  const ids = prospGetChecked();
  if (ids.length === 0) return;
  document.getElementById('bulkActionInput').value = action;
  document.getElementById('bulkForm').submit();
}
function prospOpenConfirmModal(action) {
  const n = prospGetChecked().length;
  if (n === 0) return;
  const titles = { desactiver: 'Désactiver ces fiches ?', supprimer: 'Supprimer définitivement ces fiches ?' };
  const bodies = {
    desactiver: n + ' fiche' + (n > 1 ? 's' : '') + ' seront désactivée' + (n > 1 ? 's' : '') + ' et disparaîtront des listes. Leur historique est conservé, tu pourras les réactiver plus tard depuis la base de données si besoin.',
    supprimer: 'Cette action supprimera définitivement ' + n + ' fiche' + (n > 1 ? 's' : '') + ' et tout leur historique de suivi. Cette action est irréversible.'
  };
  document.getElementById('prospModalConfirmTitle').textContent = titles[action];
  document.getElementById('prospModalConfirmBody').textContent = bodies[action];
  const btn = document.getElementById('prospModalConfirmBtn');
  btn.textContent = action === 'supprimer' ? '🗑 Supprimer définitivement' : 'Désactiver';
  btn.style.background = action === 'supprimer' ? 'var(--tu-red-main)' : '';
  btn.style.borderColor = action === 'supprimer' ? 'var(--tu-red-main)' : '';
  btn.style.color = action === 'supprimer' ? '#fff' : '';
  btn.onclick = function () { prospSubmitBulk(action); };
  document.getElementById('prospModalConfirm').hidden = false;
}
function prospOpenPrioriteModal() {
  const n = prospGetChecked().length;
  if (n === 0) return;
  document.getElementById('prospModalPrioriteCount').textContent = n;
  document.getElementById('prospModalPrioriteInput').value = '';
  document.getElementById('prospModalPriorite').hidden = false;
}
function prospConfirmPriorite() {
  const val = document.getElementById('prospModalPrioriteInput').value.trim();
  document.getElementById('prioriteMasseInput').value = val;
  prospSubmitBulk('priorite');
}
document.addEventListener('keydown', function (e) { if (e.key === 'Escape') prospCloseModals(); });
</script>

</body>
</html>
