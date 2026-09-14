<?php
/**
 * prospection/import.php — import CSV en 2 étapes :
 *   1) upload du fichier + choix de la catégorie
 *   2) mappage des colonnes détectées vers les champs de la fiche (avec une
 *      proposition automatique, ajustable à la main avant de confirmer)
 * Le fichier parsé est gardé en session entre les deux étapes (aucun fichier
 * temporaire écrit sur le disque).
 */
require_once __DIR__ . '/../shared/bootstrap.php';
require_admin_plus();

require_once __DIR__ . '/../config_db.php';
require_once 'functions_prospection.php';
require_once 'import_profiles.php';

function h($s): string { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

/**
 * Lit un CSV uploadé (gère BOM UTF-8, encodage Windows-1252 fréquent avec
 * les exports Excel français, et le délimiteur ; ou ,) et retourne
 * [ 'headers' => [...], 'rows' => [ [entête=>valeur], ... ] ].
 */
/**
 * Tente plusieurs encodages source candidats (Windows-1252, MacRoman,
 * ISO-8859-1) et retourne la conversion UTF-8 la plus plausible : celle avec
 * le plus de lettres accentuées françaises correctes et le moins de
 * caractères "mojibake" typiques d'un mauvais choix d'encodage.
 */
function prospection_detecter_reencoder(string $content): string {
    $candidats = ['Windows-1252', 'MACINTOSH', 'ISO-8859-1'];
    $meilleur = null; $meilleurScore = -INF;

    foreach ($candidats as $enc) {
        $converti = @iconv($enc, 'UTF-8//IGNORE', $content);
        if ($converti === false || $converti === '') continue;
        $bon = preg_match_all('/[éèàùôêîçâïûœÉÈÀÂÇÙÔÊÎÏÛŒ]/u', $converti);
        $mauvais = preg_match_all('/[ŽžšŸ‚ƒ„†‡‰‹‘’“”•–—˜™›\x{FFFD}]/u', $converti);
        $score = $bon - ($mauvais * 3);
        if ($score > $meilleurScore) { $meilleurScore = $score; $meilleur = $converti; }
    }

    return $meilleur ?? (mb_convert_encoding($content, 'UTF-8', 'Windows-1252') ?: $content);
}

function prospection_lire_csv(string $path): array {
    $content = file_get_contents($path);
    if ($content === false) return ['headers' => [], 'rows' => []];

    // BOM UTF-8
    if (substr($content, 0, 3) === "\xEF\xBB\xBF") $content = substr($content, 3);

    // Ré-encodage si le fichier n'est pas de l'UTF-8 valide. Les exports Excel
    // non-UTF8 sont soit en Windows-1252 (Excel Windows), soit en MacRoman
    // (Excel Mac) — les deux se ressemblent visuellement mais placent les
    // caractères accentués sur des octets différents. On essaie les deux
    // conversions et on garde celle qui produit le plus de vrais caractères
    // français accentués et le moins de symboles "mojibake" caractéristiques.
    if (!mb_check_encoding($content, 'UTF-8')) {
        $content = prospection_detecter_reencoder($content);
    }

    $lines = preg_split('/\r\n|\r|\n/', $content);
    $lines = array_filter($lines, fn($l) => trim($l) !== '');
    if (empty($lines)) return ['headers' => [], 'rows' => []];

    $firstLine = reset($lines);
    $delimiter = substr_count($firstLine, ';') >= substr_count($firstLine, ',') ? ';' : ',';

    $headers = str_getcsv(array_shift($lines), $delimiter);
    $headers = array_map(fn($h) => trim($h), $headers);

    // Rend chaque entête unique et non vide (un export Excel avec cellules
    // fusionnées ou colonnes sans titre produit des entêtes vides ou en
    // double sur la ligne de titres ; sans ceci, ces colonnes s'écrasent
    // entre elles dans $row et leurs données sont silencieusement perdues).
    $seen = [];
    foreach ($headers as $i => $h) {
        $label = $h !== '' ? $h : '(colonne sans nom)';
        if (isset($seen[$label])) {
            $seen[$label]++;
            $label = $label . ' (' . $seen[$label] . ')';
        } else {
            $seen[$label] = 1;
        }
        $headers[$i] = $label;
    }

    $rows = [];
    foreach ($lines as $line) {
        $values = str_getcsv($line, $delimiter);
        $row = [];
        foreach ($headers as $i => $h) {
            $row[$h] = $values[$i] ?? '';
        }
        $rows[] = $row;
    }

    return ['headers' => $headers, 'rows' => $rows];
}

$categories = get_categories_prospection($conn);
$error = '';
$resultat = null;
$etape = 'upload'; // upload | mappage

// Une visite simple (GET) repart toujours de zéro — évite de rester coincé
// sur un mappage abandonné si on revient sur la page ou qu'on clique "Annuler".
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    unset($_SESSION['prospection_import_pending']);
}
$pending = $_SESSION['prospection_import_pending'] ?? null;

/* ------------------------------------------------------------------
   Étape 1 → 2 : upload du fichier, on stocke le CSV parsé en session
   et on affiche l'écran de mappage.
------------------------------------------------------------------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['step'] ?? '') === 'upload' && isset($_FILES['fichier_csv'])) {
    $categorieId = (int)($_POST['categorie_id'] ?? 0);
    $categorie = null;
    foreach ($categories as $c) { if ((int)$c['id'] === $categorieId) { $categorie = $c; break; } }

    if (!$categorie) {
        $error = 'Choisis une catégorie.';
    } elseif ($_FILES['fichier_csv']['error'] !== UPLOAD_ERR_OK) {
        $error = 'Erreur lors du téléversement du fichier.';
    } else {
        $csv = prospection_lire_csv($_FILES['fichier_csv']['tmp_name']);
        if (empty($csv['rows'])) {
            $error = 'Le fichier CSV est vide ou illisible.';
        } else {
            $profile = prospection_import_profile($categorie['code']) ?? prospection_guess_profile($csv['headers']);
            $suggestion = prospection_import_mappage_suggere($csv['headers'], $profile);

            $_SESSION['prospection_import_pending'] = [
                'categorie_id' => $categorieId,
                'headers'      => $csv['headers'],
                'rows'         => $csv['rows'],
                'suggestion'   => $suggestion,
                'nom_fichier'  => $_FILES['fichier_csv']['name'],
            ];
            $pending = $_SESSION['prospection_import_pending'];
            $etape = 'mappage';
        }
    }
}

/* ------------------------------------------------------------------
   Étape 2 → confirmation : applique le mappage choisi par l'utilisateur
   à chaque ligne stockée en session, puis nettoie la session.
------------------------------------------------------------------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['step'] ?? '') === 'confirmer') {
    if (!$pending) {
        $error = "Session d'import expirée, recommence l'upload.";
    } else {
        $categorieId = (int)$pending['categorie_id'];
        $categorie = null;
        foreach ($categories as $c) { if ((int)$c['id'] === $categorieId) { $categorie = $c; break; } }

        $mapping = [];
        foreach ($pending['headers'] as $h) {
            $mapping[$h] = trim((string)($_POST['mapping'][$h] ?? 'ignorer'));
        }

        if (!in_array('nom', $mapping, true)) {
            $error = 'Associe au moins une colonne au champ "Nom" avant de continuer.';
            $etape = 'mappage';
        } elseif (!$categorie) {
            $error = 'Catégorie introuvable, recommence l\'upload.';
        } else {
            $nbCrees = 0; $nbIgnores = 0;
            foreach ($pending['rows'] as $row) {
                $id = prospection_import_appliquer_ligne_mapping($conn, $categorieId, $mapping, $row);
                if ($id) $nbCrees++; else $nbIgnores++;
            }

            if (function_exists('audit_log')) {
                audit_log('prospection', 'import_csv', 'categorie', $categorieId, $categorie['label'], [
                    'fichier' => $pending['nom_fichier'],
                    'lignes_creees' => $nbCrees,
                    'lignes_ignorees' => $nbIgnores,
                    'mappage' => $mapping,
                ]);
            }

            $resultat = [
                'nb_crees'   => $nbCrees,
                'nb_ignores' => $nbIgnores,
                'categorie'  => $categorie,
                'mapping'    => $mapping,
            ];
            unset($_SESSION['prospection_import_pending']);
            $pending = null;
        }
    }
}

/* Revenir à l'étape mappage si on a une session pendante et pas de résultat */
if ($pending && !$resultat) $etape = 'mappage';

$champsCibles = prospection_import_champs_cibles();
$base = function_exists('suite_base') ? suite_base() : '';
$suiteNavV2 = __DIR__ . '/../shared/suite_nav.php';
?>
<!DOCTYPE html>
<html lang="fr">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Importer un CSV — Prospection</title>
  <link rel="stylesheet" href="<?= h($base) ?>/assets/css/suite_nav.css">
</head>
<body class="tu-v2">
<?php if (is_file($suiteNavV2)): require_once $suiteNavV2; suite_nav_render('prospection', 'prospection-import'); endif; ?>

<div class="tu-main">
  <div class="tu-topbar">
    <div class="tu-bc">
      <a href="<?= h($base . '/index.php') ?>" style="color:inherit;text-decoration:none;">Accueil</a>
      <span class="tu-bc-sep">›</span>
      <a href="index.php" style="color:inherit;text-decoration:none;">Prospection</a>
      <span class="tu-bc-sep">›</span>
      <span class="tu-bc-cur">Importer un CSV</span>
    </div>
    <div class="tu-topbar-acts">
      <a href="index.php" class="tu-btn tu-btn-g tu-btn-sm">← Retour</a>
    </div>
  </div>

  <div class="tu-pg">
    <div class="tu-ph">
      <div>
        <div class="tu-ph-title">Importer un CSV</div>
        <div class="tu-ph-sub">Exporte chaque onglet du fichier Excel en CSV, puis importe-le ici en choisissant la catégorie correspondante.</div>
      </div>
    </div>

    <?php if ($error): ?>
      <div style="background:var(--tu-red-soft);border:1.5px solid rgba(192,67,42,.25);border-radius:14px;padding:12px 16px;margin-bottom:16px;color:var(--tu-red-main);font-size:13px;"><?= h($error) ?></div>
    <?php endif; ?>

    <?php if ($resultat): ?>
      <div class="tu-card" style="padding:20px;margin-bottom:16px;border-color:rgba(42,125,74,.3);">
        <div style="font-family:var(--tu-font-d);font-weight:700;margin-bottom:8px;">✓ Import terminé — <?= h(prospection_libelle_categorie($resultat['categorie']['label'])) ?></div>
        <p style="font-size:13px;margin:0 0 4px;"><?= (int)$resultat['nb_crees'] ?> fiche(s) créée(s)<?= $resultat['nb_ignores'] ? ', ' . (int)$resultat['nb_ignores'] . ' ligne(s) ignorée(s) (nom vide sur cette ligne)' : '' ?>.</p>
        <div style="margin-top:14px;">
          <a href="index.php?categorie=<?= h($resultat['categorie']['code']) ?>" class="tu-btn tu-btn-p tu-btn-sm">Voir les fiches importées</a>
          <a href="import.php" class="tu-btn tu-btn-g tu-btn-sm">Importer un autre fichier</a>
        </div>
      </div>
    <?php endif; ?>

    <?php if ($etape === 'upload' && !$resultat): ?>
    <div class="tu-card" style="padding:22px;max-width:560px;">
      <form method="POST" enctype="multipart/form-data">
        <input type="hidden" name="step" value="upload"/>
        <div class="tu-sec-div"><span class="tu-sec-div-lbl">Catégorie</span><div class="tu-sec-div-line"></div></div>
        <div class="tu-form-field tu-mb4">
          <span class="tu-lbl">Catégorie *</span>
          <select class="tu-input" name="categorie_id" required>
            <option value="">-- Sélectionner --</option>
            <?php foreach ($categories as $c): ?>
              <option value="<?= (int)$c['id'] ?>"><?= h(prospection_libelle_categorie($c['famille_label'])) ?> — <?= h(prospection_libelle_categorie($c['label'])) ?></option>
            <?php endforeach; ?>
          </select>
          <span class="tu-hint">L'étape suivante te proposera un mappage automatique des colonnes, que tu pourras corriger.</span>
        </div>

        <div class="tu-sec-div"><span class="tu-sec-div-lbl">Fichier</span><div class="tu-sec-div-line"></div></div>
        <div class="tu-form-field tu-mb4">
          <span class="tu-lbl">Fichier CSV *</span>
          <input class="tu-input" type="file" name="fichier_csv" accept=".csv" required/>
          <span class="tu-hint">Exporté depuis Excel (Fichier → Enregistrer sous → CSV), un onglet à la fois.</span>
        </div>

        <button type="submit" class="tu-btn tu-btn-p tu-w100" style="justify-content:center;">Continuer →</button>
      </form>
    </div>
    <?php endif; ?>

    <?php if ($etape === 'mappage' && $pending && !$resultat):
      $totalRempli = 0;
      foreach ($pending['headers'] as $h) {
          foreach ($pending['rows'] as $r) { if (trim((string)($r[$h] ?? '')) !== '') { $totalRempli++; break; } }
      }
    ?>
    <div class="tu-card" style="padding:22px;">
      <div style="font-family:var(--tu-font-d);font-weight:700;margin-bottom:4px;">Associer les colonnes de « <?= h($pending['nom_fichier']) ?> »</div>
      <p style="font-size:12.5px;color:var(--tu-ink-300);margin:0 0 18px;">
        Pour chaque colonne détectée dans ton fichier, choisis à quel champ de la fiche elle correspond.
        Une proposition automatique est déjà sélectionnée — corrige-la si besoin. Les colonnes laissées sur
        « Ignorer » restent quand même conservées en donnée brute sur la fiche.
      </p>

      <?php if ($totalRempli === 0): ?>
        <div style="margin-bottom:16px;padding:12px 14px;background:var(--tu-red-soft);border:1.5px solid rgba(192,67,42,.25);border-radius:10px;font-size:12.5px;color:var(--tu-red-main);">
          <strong>⚠️ Ce fichier semble ne contenir aucune donnée exploitable</strong> (toutes les colonnes sont vides sur les <?= count($pending['rows']) ?> ligne<?= count($pending['rows']) > 1 ? 's' : '' ?> lues).
          C'est peut-être un onglet récapitulatif/résumé plutôt qu'une liste de structures — vérifie que c'est le bon export avant de continuer.
        </div>
      <?php endif; ?>

      <form method="POST">
        <input type="hidden" name="step" value="confirmer"/>
        <div style="overflow-x:auto;">
          <table class="tu-tbl">
            <thead>
              <tr>
                <th>Colonne du fichier</th>
                <th>Exemple de valeur</th>
                <th>Champ de la fiche</th>
              </tr>
            </thead>
            <tbody>
              <?php $aucuneColonneRemplie = true; foreach ($pending['headers'] as $h):
                $exemple = ''; $nbRempli = 0;
                foreach ($pending['rows'] as $r) {
                    if (trim((string)($r[$h] ?? '')) !== '') {
                        $nbRempli++;
                        if ($exemple === '') $exemple = $r[$h];
                    }
                }
                if ($nbRempli > 0) $aucuneColonneRemplie = false;
                $suggere = $pending['suggestion'][$h] ?? 'ignorer';
              ?>
                <tr>
                  <td style="font-weight:700;font-size:13px;"><?= h($h) ?></td>
                  <td style="font-size:12px;color:var(--tu-ink-300);max-width:220px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">
                    <?php if ($nbRempli === 0): ?><em>— aucune valeur —</em><?php else: ?><?= h($exemple) ?> <span style="color:var(--tu-ink-200);">(<?= $nbRempli ?>/<?= count($pending['rows']) ?> lignes)</span><?php endif; ?>
                  </td>
                  <td>
                    <select class="tu-input" name="mapping[<?= h($h) ?>]" style="min-width:220px;">
                      <?php foreach ($champsCibles as $code => $label): ?>
                        <option value="<?= h($code) ?>" <?= $suggere === $code ? 'selected' : '' ?>><?= h($label) ?></option>
                      <?php endforeach; ?>
                    </select>
                  </td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>

        <div style="margin-top:18px;display:flex;gap:8px;">
          <button type="submit" class="tu-btn tu-btn-p tu-btn-sm">✓ Lancer l'import (<?= count($pending['rows']) ?> ligne<?= count($pending['rows']) > 1 ? 's' : '' ?>)</button>
          <a href="import.php" class="tu-btn tu-btn-g tu-btn-sm">Annuler</a>
        </div>
      </form>
    </div>
    <?php endif; ?>

  </div>
</div>
</body>
</html>
