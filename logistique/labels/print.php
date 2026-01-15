<?php
if (session_status() === PHP_SESSION_NONE) session_start();

if (file_exists(__DIR__ . '/../config.php')) require_once __DIR__ . '/../config.php';
if (!defined('APP_BASE')) {
    $script = $_SERVER['SCRIPT_NAME'] ?? '';
    $base = str_replace('\\', '/', dirname(dirname($script)));
    $base = rtrim($base, '/');
    define('APP_BASE', $base === '' ? '' : $base);
}

require_once __DIR__ . '/../db.php';

function h(string $s): string { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }

// Texte bas (fixe)
$ASSO_LINE_FR = "CONVOI HUMANITAIRE EN AIDE AU PEUPLE UKRAINIEN";
$ASSO_LINE_UA = "ГУМАНІТАРНА ДОПОМОГА В ПІДТРИМКУ УКРАЇНИ";
$CITY_LINE_UA = "ФРАНЦІЯ МІСТО ТУР";

// Logo (mets idéalement ton logo en /assets/logo.png)
$logoCandidates = [
    __DIR__ . '/../assets/logo.png',
    __DIR__ . '/../assets/img/logo.png',
    __DIR__ . '/../assets/logo.jpg',
    __DIR__ . '/../assets/img/logo.jpg',
];
$logoUrl = null;
foreach ($logoCandidates as $p) {
    if (file_exists($p)) {
        $rootReal = realpath(__DIR__ . '/..');
        $fileReal = realpath($p);
        if ($rootReal && $fileReal && str_starts_with($fileReal, $rootReal)) {
            $rel = str_replace($rootReal, '', $fileReal);
            $rel = str_replace('\\', '/', $rel);
            $logoUrl = APP_BASE . $rel;
            break;
        }
    }
}

// Sous-catégories actives uniquement (pas les racines)
$catsStmt = $pdo->query("
  SELECT id, label, COALESCE(label_ua,'') AS label_ua
  FROM categories
  WHERE parent_id IS NOT NULL
    AND is_active = 1
  ORDER BY label
");
$subcats = $catsStmt->fetchAll();

$errors = [];

$category_id = isset($_GET['category_id']) ? (int)$_GET['category_id'] : 0;

$label_fr = '';
$label_ua = '';
$pages = 1;
$isPrint = false;

function normalizeLabel(string $s): string {
    $s = trim($s);
    $s = preg_replace("/\s*\/\s*/u", "\n", $s);
    $s = preg_replace("/\s*,\s*/u", "\n", $s);
    $lines = preg_split("/\R/u", $s);
    $lines = array_values(array_filter(array_map('trim', $lines), fn($x) => $x !== ''));
    return implode("\n", $lines);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $category_id = (int)($_POST['category_id'] ?? 0);
    $label_fr = normalizeLabel($_POST['label_fr'] ?? '');
    $label_ua = normalizeLabel($_POST['label_ua'] ?? '');

    $pagesRaw = trim($_POST['pages'] ?? '1');
    if ($pagesRaw === '' || !ctype_digit($pagesRaw) || (int)$pagesRaw < 1 || (int)$pagesRaw > 50) {
        $errors[] = "Le nombre de pages doit être un entier entre 1 et 50.";
    } else {
        $pages = (int)$pagesRaw;
    }

    if ($label_fr === '') $errors[] = "Le libellé FR est obligatoire.";
    if ($label_ua === '') $errors[] = "Le libellé UA est obligatoire.";

    if (empty($errors) && isset($_POST['do_print'])) {
        $isPrint = true;
    }
} else {
    if ($category_id > 0) {
        $one = $pdo->prepare("
          SELECT label, COALESCE(label_ua,'') AS label_ua
          FROM categories
          WHERE id = ? AND is_active = 1
        ");
        $one->execute([$category_id]);
        $row = $one->fetch();
        if ($row) {
            $label_fr = normalizeLabel((string)$row['label']);
            $label_ua = normalizeLabel((string)$row['label_ua']);
        }
    }
}

if (!$isPrint) require_once __DIR__ . '/../header.php';
?>

<style>
/* ===== PRINT / PDF : A4 paysage STABLE (Safari-proof) ===== */
@media print {
  @page { size: A4 landscape; margin: 0; }

  html, body {
    margin: 0 !important;
    padding: 0 !important;
    background: #fff !important;
    -webkit-print-color-adjust: exact;
    print-color-adjust: exact;
  }

  .no-print { display: none !important; }

  .print-page{
    width: 297mm;
    height: 210mm;
    position: relative;
    overflow: hidden;
    page-break-after: always;
  }

  /* marge globale feuille */
  .print-canvas{
    position: absolute;
    inset: 0;
    padding: 8mm;
    box-sizing: border-box;
  }

  /* 2x2 */
  .label-grid{
    width: 100%;
    height: 100%;
    display: grid;
    grid-template-columns: 1fr 1fr;
    grid-template-rows: 1fr 1fr;
    gap: 10mm;
  }

  /* IMPORTANT : positionnement “maquette” */
  .label{
    position: relative;
    border: none !important;
    border-radius: 0 !important;
    overflow: hidden;
  }

  /* ===== ZONE HAUTE ===== */
  .label-top{
    position: absolute;
    top: 6mm;          /* ajuste la “hauteur” comme Canva */
    left: 8mm;
    right: 8mm;

    display: grid;
    grid-template-columns: 70mm 1fr; /* logo fixe comme Canva */
    column-gap: 10mm;
    align-items: center;
  }

  .label-logo{
    height: 32mm;      /* hauteur logo contrôlée */
    display: flex;
    align-items: center;
    justify-content: flex-start;
  }
  .label-logo img{
    height: 32mm;
    width: auto;
    max-width: 70mm;
    object-fit: contain;
  }

  .label-titles{
    text-align: center;
    font-family: Arial, Helvetica, sans-serif;
    font-weight: 900;
    text-transform: uppercase;
    line-height: 1.12;
    padding-top: 1mm;  /* micro-ajustement */
  }

  /* Titres catégorie : 19pt */
  .label-titles .fr{
    font-size: 19pt;
    margin-bottom: 2mm;
    white-space: pre-line;
  }
  .label-titles .ua{
    font-size: 19pt;
    white-space: pre-line;
  }

  /* ===== ZONE BAS ===== */
  .label-bottom{
    position: absolute;
    left: 8mm;
    right: 8mm;
    bottom: 12mm;      /* ajuste le “bloc convoi” */
    text-align: center;
    font-family: Arial, Helvetica, sans-serif;
    line-height: 1.12;
  }

  /* Convoi : 14pt, FR pas gras */
  .label-bottom .line-fr{
    font-size: 14pt;
    font-weight: 400;
    text-transform: uppercase;
    margin-bottom: 2.5mm;
    letter-spacing: 0.2px;
  }
  .label-bottom .line-ua{
    font-size: 14pt;
    font-weight: 900;
    text-transform: uppercase;
    margin-bottom: 1.8mm;
    letter-spacing: 0.2px;
  }
  .label-bottom .line-city{
    font-size: 14pt;
    font-weight: 900;
    text-transform: uppercase;
    letter-spacing: 0.2px;
  }

  /* Safari portrait “bug” : on laisse la rotation, mais elle devient stable */
  @media print and (orientation: portrait) {
    @page { size: A4 portrait; margin: 0; }
    .print-page { width: 210mm; height: 297mm; }
    .print-canvas{
      width: 297mm;
      height: 210mm;
      padding: 8mm;
      transform-origin: top left;
      transform: rotate(90deg) translateY(-210mm);
    }
  }
}

/* ===== ÉCRAN (preview) ===== */
.label-preview-wrap { max-width: 1100px; margin: 0 auto; }
.preview-a4 { background:#fff; border:1px solid #ddd; padding: 14px; }

.label-grid { display:grid; grid-template-columns:1fr 1fr; grid-template-rows:auto auto; gap:14px; }
.label { border:1px solid #e5e5e5; border-radius:0; padding: 12px; display:flex; flex-direction:column; justify-content:space-between; min-height: 260px; }

.label-top { display:grid; grid-template-columns:48% 52%; column-gap:14px; align-items:center; }
.label-logo { display:flex; align-items:center; justify-content:flex-start; height:140px; }
.label-logo img { max-height:140px; max-width:100%; object-fit:contain; }

.label-titles { text-align:center; font-family: Arial, Helvetica, sans-serif; font-weight:900; text-transform:uppercase; line-height:1.12; }

/* Approx écran : proche 19pt */
.label-titles .fr { font-size: 25px; white-space: pre-line; margin-bottom: 6px; }
.label-titles .ua { font-size: 25px; white-space: pre-line; }

/* Approx écran : proche 14pt */
.label-bottom { text-align:center; font-family: Arial, Helvetica, sans-serif; }
.label-bottom .line-fr { font-size: 18px; font-weight: 400; text-transform:uppercase; margin-bottom: 6px; letter-spacing: 0.2px; }
.label-bottom .line-ua { font-size: 18px; font-weight: 900; text-transform:uppercase; margin-bottom: 4px; letter-spacing: 0.2px; }
.label-bottom .line-city { font-size: 18px; font-weight: 900; text-transform:uppercase; letter-spacing: 0.2px; }
</style>

<?php if (!$isPrint): ?>
<div class="container label-preview-wrap">
  <div class="no-print mb-3">
    <h1 class="h4 mb-1">Étiquettes (modèle Canva)</h1>
    <div class="text-muted small">1 modèle → 4 étiquettes sur A4 paysage.</div>
  </div>

  <?php if (!empty($errors)): ?>
    <div class="alert alert-danger no-print">
      <ul class="mb-0">
        <?php foreach ($errors as $e): ?><li><?= h($e) ?></li><?php endforeach; ?>
      </ul>
    </div>
  <?php endif; ?>

  <form method="post" class="card shadow-sm p-3 no-print mb-3">
    <div class="row g-3">
      <div class="col-12 col-md-6">
        <label class="form-label">Sous-catégorie (optionnel)</label>
        <select class="form-select" name="category_id" onchange="location.href='?category_id='+this.value;">
          <option value="0">— Choisir pour préremplir —</option>
          <?php foreach ($subcats as $c): ?>
            <option value="<?= (int)$c['id'] ?>" <?= (int)$c['id'] === (int)$category_id ? 'selected' : '' ?>>
              <?= h($c['label']) ?>
            </option>
          <?php endforeach; ?>
        </select>
        <div class="form-text">On ne propose que les sous-catégories actives.</div>
      </div>

      <div class="col-12 col-md-3">
        <label class="form-label">Pages A4 (x4)</label>
        <input type="number" min="1" max="50" class="form-control" name="pages" value="<?= (int)$pages ?>">
      </div>

      <div class="col-12 col-md-6">
        <label class="form-label">Libellé FR *</label>
        <textarea class="form-control" name="label_fr" rows="4" required><?= h($label_fr) ?></textarea>
      </div>

      <div class="col-12 col-md-6">
        <label class="form-label">Libellé UA *</label>
        <textarea class="form-control" name="label_ua" rows="4" required><?= h($label_ua) ?></textarea>
      </div>

      <div class="col-12 d-flex justify-content-end gap-2">
        <button class="btn btn-primary" name="do_print" value="1">Générer PDF</button>
      </div>
    </div>
  </form>

  <?php if ($label_fr !== '' && $label_ua !== '' && empty($errors)): ?>
    <div class="preview-a4">
      <div class="label-grid">
        <?php for ($i=0; $i<4; $i++): ?>
          <div class="label">
            <div class="label-top">
              <div class="label-logo">
                <?php if ($logoUrl): ?>
                  <img src="<?= h($logoUrl) ?>" alt="Logo">
                <?php else: ?>
                  <div style="border:1px dashed #bbb; padding:10px; font-weight:800;">LOGO</div>
                <?php endif; ?>
              </div>
              <div class="label-titles">
                <div class="fr"><?= h(mb_strtoupper($label_fr)) ?></div>
                <div class="ua"><?= h(mb_strtoupper($label_ua)) ?></div>
              </div>
            </div>
            <div class="label-bottom">
              <div class="line-fr"><?= h($ASSO_LINE_FR) ?></div>
              <div class="line-ua"><?= h($ASSO_LINE_UA) ?></div>
              <div class="line-city"><?= h($CITY_LINE_UA) ?></div>
            </div>
          </div>
        <?php endfor; ?>
      </div>
    </div>
  <?php endif; ?>

</div>

<?php require_once __DIR__ . '/../footer.php'; ?>

<?php else: ?>
<!doctype html>
<html lang="fr">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Étiquettes Touraine-Ukraine</title>
</head>
<body>
  <div class="no-print" style="padding:10px; font-family: Arial, Helvetica, sans-serif;">
    <strong>PDF prêt.</strong> Clique “Imprimer” puis choisis “Enregistrer en PDF”.
    (<?= (int)$pages ?> page(s), <?= (int)$pages * 4 ?> étiquettes)
    <div style="margin-top:8px; font-size: 12px; color:#444;">
      Mets <strong>Paysage</strong> et <strong>Échelle 100%</strong>.
    </div>
    <div style="margin-top:8px;">
      <button onclick="window.print()" style="padding:8px 12px;">Imprimer / Enregistrer PDF</button>
    </div>
  </div>

  <?php for ($p=0; $p<$pages; $p++): ?>
    <div class="print-page">
      <div class="print-canvas">
        <div class="label-grid">
          <?php for ($i=0; $i<4; $i++): ?>
            <div class="label">
              <div class="label-top">
                <div class="label-logo">
                  <?php if ($logoUrl): ?>
                    <img src="<?= h($logoUrl) ?>" alt="Logo">
                  <?php else: ?>
                    <div style="border:1px dashed #bbb; padding:10px; font-weight:800;">LOGO</div>
                  <?php endif; ?>
                </div>
                <div class="label-titles">
                  <div class="fr"><?= h(mb_strtoupper($label_fr)) ?></div>
                  <div class="ua"><?= h(mb_strtoupper($label_ua)) ?></div>
                </div>
              </div>

              <div class="label-bottom">
                <div class="line-fr"><?= h($ASSO_LINE_FR) ?></div>
                <div class="line-ua"><?= h($ASSO_LINE_UA) ?></div>
                <div class="line-city"><?= h($CITY_LINE_UA) ?></div>
              </div>
            </div>
          <?php endfor; ?>
        </div>
      </div>
    </div>
  <?php endfor; ?>

  <script>
    window.addEventListener('load', () => setTimeout(() => window.print(), 250));
  </script>
</body>
</html>
<?php endif; ?>