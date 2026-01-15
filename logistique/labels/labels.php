<?php
// labels.php — génération d’étiquettes A4 paysage (x4) + recherche (PHP 7.4+)

if (session_status() === PHP_SESSION_NONE) session_start();
require_once __DIR__ . '/../db.php'; // adapte si ton fichier est ailleurs

// APP_BASE (pour /preprod-logistique/ vs /logistique/)
if (!defined('APP_BASE')) {
  $script = $_SERVER['SCRIPT_NAME'] ?? '';
  // labels.php est dans /.../labels/labels.php => on remonte d'un niveau
  $base = str_replace('\\', '/', dirname(dirname($script)));
  $base = rtrim($base, '/');
  define('APP_BASE', $base === '' ? '' : $base);
}

function h(string $s): string { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }

function startsWith(string $haystack, string $needle): bool {
  if ($needle === '') return true;
  return strpos($haystack, $needle) === 0;
}

function normalizeLabel(string $s): string {
  $s = trim($s);
  // "/" et "," deviennent des retours à la ligne
  $s = preg_replace("/\s*\/\s*/u", "\n", $s);
  $s = preg_replace("/\s*,\s*/u", "\n", $s);
  $lines = preg_split("/\R/u", $s);
  $lines = array_values(array_filter(array_map('trim', $lines), function($x){ return $x !== ''; }));
  return implode("\n", $lines);
}

function upperMultiline(string $s): string {
  if (function_exists('mb_strtoupper')) return mb_strtoupper($s, 'UTF-8');
  return strtoupper($s);
}

// Texte bas (fixe)
$ASSO_LINE_FR = "CONVOI HUMANITAIRE EN AIDE AU PEUPLE UKRAINIEN";
$ASSO_LINE_UA = "ГУМАНІТАРНА ДОПОМОГА В ПІДТРИМКУ УКРАЇНИ";
$CITY_LINE_UA = "ФРАНЦІЯ МІСТО ТУР";

// Logo (cherche dans assets)
$logoCandidates = [
  __DIR__ . '/../assets/logo.png',
  __DIR__ . '/../assets/img/logo.png',
  __DIR__ . '/../assets/logo.jpg',
  __DIR__ . '/../assets/img/logo.jpg',
];

$logoUrl = null;
foreach ($logoCandidates as $p) {
  if (!file_exists($p)) continue;

  $rootReal = realpath(__DIR__ . '/..');     // racine appli
  $fileReal = realpath($p);

  if ($rootReal && $fileReal && startsWith($fileReal, $rootReal)) {
    $rel = str_replace($rootReal, '', $fileReal);
    $rel = str_replace('\\', '/', $rel);
    $logoUrl = APP_BASE . $rel;
    break;
  }
}

// Sous-catégories actives uniquement (parent_id IS NOT NULL)
$cats = $pdo->query("
  SELECT id, label, COALESCE(label_ua,'') AS label_ua
  FROM categories
  WHERE parent_id IS NOT NULL AND is_active = 1
  ORDER BY label
")->fetchAll(PDO::FETCH_ASSOC);

$errors = [];
$isPrint = false;

// items: [{category_id,label_fr,label_ua,qty}]
$items = [];
$pages = 1;

// === MODE DIRECT depuis l'URL (depuis Catégories) ===
// ex: labels.php?category_id=123&qty=4&do_print=1
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
  $cidGet = isset($_GET['category_id']) ? (int)$_GET['category_id'] : 0;
  $qtyGet = isset($_GET['qty']) ? (int)$_GET['qty'] : 0;
  $doPrintGet = isset($_GET['do_print']) ? (int)$_GET['do_print'] : 0;

  if ($cidGet > 0 && $doPrintGet === 1) {
    // Index des sous-catégories actives
    $index = [];
    foreach ($cats as $c) $index[(int)$c['id']] = $c;

    if (!isset($index[$cidGet])) {
      $errors[] = "Catégorie introuvable, inactive, ou non éligible à l’étiquette.";
    } else {
      $fr = normalizeLabel((string)$index[$cidGet]['label']);
      $ua = normalizeLabel((string)$index[$cidGet]['label_ua']);

      if ($ua === '') {
        $errors[] = "Traduction UA manquante pour : " . $index[$cidGet]['label'];
      } else {
        if ($qtyGet < 1) $qtyGet = 4;
        if ($qtyGet > 200) $qtyGet = 200;

        $items = [[
          'category_id' => $cidGet,
          'label_fr' => $fr,
          'label_ua' => $ua,
          'qty' => $qtyGet,
        ]];

        $isPrint = true;
      }
    }
  }
}


if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $pagesRaw = trim($_POST['pages'] ?? '1');
  if ($pagesRaw === '' || !ctype_digit($pagesRaw) || (int)$pagesRaw < 1 || (int)$pagesRaw > 50) {
    $errors[] = "Le nombre de pages doit être un entier entre 1 et 50.";
  } else {
    $pages = (int)$pagesRaw;
  }

  $rawItems = $_POST['items'] ?? [];
  if (!is_array($rawItems) || empty($rawItems)) {
    $errors[] = "Ajoute au moins une sous-catégorie à imprimer.";
  } else {
    $index = [];
    foreach ($cats as $c) $index[(int)$c['id']] = $c;

    foreach ($rawItems as $it) {
      $cid = (int)($it['category_id'] ?? 0);
      if ($cid <= 0 || !isset($index[$cid])) continue;

      $qtyRaw = trim((string)($it['qty'] ?? '1'));
      $fr = normalizeLabel((string)($it['label_fr'] ?? ''));
      $ua = normalizeLabel((string)($it['label_ua'] ?? ''));

      if ($fr === '') $fr = normalizeLabel((string)$index[$cid]['label']);
      if ($ua === '') $ua = normalizeLabel((string)$index[$cid]['label_ua']);

      if ($fr === '' || $ua === '') {
        $errors[] = "Libellé FR/UA manquant pour : " . $index[$cid]['label'];
        continue;
      }

      if ($qtyRaw === '' || !ctype_digit($qtyRaw) || (int)$qtyRaw < 1 || (int)$qtyRaw > 200) {
        $errors[] = "Quantité invalide (1 à 200) pour : " . $index[$cid]['label'];
        continue;
      }

      $items[] = [
        'category_id' => $cid,
        'label_fr' => $fr,
        'label_ua' => $ua,
        'qty' => (int)$qtyRaw,
      ];
    }

    if (empty($items) && empty($errors)) {
      $errors[] = "Aucune ligne valide à imprimer.";
    }
  }

  if (empty($errors) && isset($_POST['do_print'])) {
    $isPrint = true;
  }
}

// Construit la liste d’étiquettes (répétées)
$labelsToPrint = [];
foreach ($items as $it) {
  for ($i = 0; $i < $it['qty']; $i++) {
    $labelsToPrint[] = [
      'fr' => $it['label_fr'],
      'ua' => $it['label_ua'],
    ];
  }
}

$labelsPerPage = 4;
if ($isPrint) {
  $neededPages = (int)ceil(max(1, count($labelsToPrint)) / $labelsPerPage);
  if ($neededPages > $pages) $pages = $neededPages;
}
?>

<?php if (!$isPrint): ?>
<?php require_once __DIR__ . '/../header.php'; ?>

<div class="container" style="max-width: 1100px;">
  <div class="mb-3">
    <h1 class="h4 mb-1">Planche d’étiquettes</h1>
    <div class="text-muted small">Recherche une sous-catégorie → ajoute-la → quantité → génère le PDF.</div>
  </div>

  <?php if (!empty($errors)): ?>
    <div class="alert alert-danger">
      <ul class="mb-0">
        <?php foreach ($errors as $e): ?><li><?= h($e) ?></li><?php endforeach; ?>
      </ul>
    </div>
  <?php endif; ?>

  <form method="post" class="card shadow-sm p-3 mb-3" id="labelsForm">
    <div class="row g-3 align-items-end">
      <div class="col-12 col-md-7">
        <label class="form-label">Rechercher une sous-catégorie</label>
        <input type="text" class="form-control" id="searchInput" placeholder="Ex: Conserves, Pansements, Couvertures…">
        <div class="form-text">On ne propose que les sous-catégories actives.</div>
      </div>
      <div class="col-6 col-md-2">
        <label class="form-label">Pages mini</label>
        <input type="number" min="1" max="50" class="form-control" name="pages" value="<?= (int)$pages ?>">
      </div>
      <div class="col-6 col-md-3 text-end">
        <button class="btn btn-primary" name="do_print" value="1">Générer PDF</button>
      </div>
    </div>

    <hr class="my-3">

    <div class="table-responsive">
      <table class="table table-sm align-middle" id="itemsTable">
        <thead>
          <tr>
            <th>Catégorie</th>
            <th style="width:160px;">Quantité</th>
            <th class="text-end" style="width:120px;">Actions</th>
          </tr>
        </thead>
        <tbody id="itemsBody"></tbody>
      </table>
    </div>

    <div class="text-muted small">
      4 étiquettes par page. Si tu demandes 1 page mais qu’il en faut 3, l’outil ajuste automatiquement.
    </div>
  </form>
</div>

<script>
const CATS = <?= json_encode(array_map(function($c){
  return ['id'=>(int)$c['id'], 'label'=>$c['label'], 'label_ua'=>$c['label_ua']];
}, $cats), JSON_UNESCAPED_UNICODE); ?>;

const items = []; // {category_id,label,label_ua,qty}

const searchInput = document.getElementById('searchInput');
const itemsBody = document.getElementById('itemsBody');
const form = document.getElementById('labelsForm');

function escapeHtml(str){
  return String(str).replace(/[&<>"']/g, s => ({
    '&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'
  }[s]));
}

function renderItems(){
  itemsBody.innerHTML = '';
  items.forEach((it, idx) => {
    const tr = document.createElement('tr');

    const tdLabel = document.createElement('td');
    tdLabel.innerHTML = `<div><strong>${escapeHtml(it.label)}</strong></div>
      <div class="text-muted small">${escapeHtml(it.label_ua || 'UA manquant')}</div>`;
    tr.appendChild(tdLabel);

    const tdQty = document.createElement('td');
    tdQty.innerHTML = `<input type="number" min="1" max="200" class="form-control form-control-sm"
      value="${it.qty}" data-idx="${idx}">`;
    tr.appendChild(tdQty);

    const tdAct = document.createElement('td');
    tdAct.className = 'text-end';
    tdAct.innerHTML = `<button type="button" class="btn btn-sm btn-outline-danger" data-del="${idx}">Supprimer</button>`;
    tr.appendChild(tdAct);

    itemsBody.appendChild(tr);
  });

  itemsBody.querySelectorAll('input[type="number"]').forEach(inp => {
    inp.addEventListener('input', (e) => {
      const i = parseInt(e.target.getAttribute('data-idx'),10);
      let v = parseInt(e.target.value,10);
      if (!Number.isFinite(v) || v < 1) v = 1;
      if (v > 200) v = 200;
      items[i].qty = v;
      e.target.value = v;
    });
  });

  itemsBody.querySelectorAll('button[data-del]').forEach(btn => {
    btn.addEventListener('click', () => {
      const i = parseInt(btn.getAttribute('data-del'),10);
      items.splice(i,1);
      renderItems();
    });
  });
}

let dropdown = null;

function closeDropdown(){
  if (dropdown) dropdown.remove();
  dropdown = null;
}

function openDropdown(matches){
  closeDropdown();
  dropdown = document.createElement('div');
  dropdown.style.position = 'absolute';
  dropdown.style.zIndex = '1000';
  dropdown.style.background = '#fff';
  dropdown.style.border = '1px solid #ddd';
  dropdown.style.borderRadius = '8px';
  dropdown.style.boxShadow = '0 8px 24px rgba(0,0,0,.08)';
  dropdown.style.maxHeight = '280px';
  dropdown.style.overflow = 'auto';
  dropdown.style.width = searchInput.offsetWidth + 'px';

  const rect = searchInput.getBoundingClientRect();
  dropdown.style.left = rect.left + window.scrollX + 'px';
  dropdown.style.top = (rect.bottom + window.scrollY + 6) + 'px';

  matches.slice(0,12).forEach(c => {
    const item = document.createElement('div');
    item.style.padding = '10px 12px';
    item.style.cursor = 'pointer';
    item.innerHTML = `<div><strong>${escapeHtml(c.label)}</strong></div>
      <div class="text-muted small">${escapeHtml(c.label_ua || '')}</div>`;
    item.addEventListener('mouseenter', ()=> item.style.background='#f6f6f6');
    item.addEventListener('mouseleave', ()=> item.style.background='#fff');
    item.addEventListener('click', ()=> {
      addCategory(c);
      searchInput.value = '';
      closeDropdown();
      searchInput.focus();
    });
    dropdown.appendChild(item);
  });

  document.body.appendChild(dropdown);
}

function addCategory(c){
  const existing = items.find(x => x.category_id === c.id);
  if (existing) {
    existing.qty = Math.min(200, existing.qty + 1);
  } else {
    items.push({
      category_id: c.id,
      label: c.label,
      label_ua: c.label_ua,
      qty: 4
    });
  }
  renderItems();
}

searchInput.addEventListener('input', () => {
  const q = searchInput.value.trim().toLowerCase();
  if (q.length < 2) { closeDropdown(); return; }
  const matches = CATS.filter(c => c.label.toLowerCase().includes(q));
  if (!matches.length) { closeDropdown(); return; }
  openDropdown(matches);
});

document.addEventListener('click', (e) => {
  if (e.target !== searchInput && dropdown && !dropdown.contains(e.target)) closeDropdown();
});

form.addEventListener('submit', () => {
  document.querySelectorAll('input[name^="items["]').forEach(n => n.remove());
  items.forEach((it, idx) => {
    const addHidden = (name, value) => {
      const inp = document.createElement('input');
      inp.type = 'hidden';
      inp.name = name;
      inp.value = value;
      form.appendChild(inp);
    };
    addHidden(`items[${idx}][category_id]`, it.category_id);
    addHidden(`items[${idx}][qty]`, it.qty);
    addHidden(`items[${idx}][label_fr]`, it.label);
    addHidden(`items[${idx}][label_ua]`, it.label_ua || '');
  });
});

renderItems();
</script>

<?php require_once __DIR__ . '/../footer.php'; ?>
<?php exit; ?>
<?php endif; ?>

<!doctype html>
<html lang="fr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Étiquettes – PDF</title>

<style>
/* ===== ÉCRAN (bandeau + aperçu) ===== */
:root{
  --page-w: 297mm;
  --page-h: 210mm;
  --pad: 10mm;
  --gap: 10mm;
}
html,body{ margin:0; padding:0; background:#eef0f3; }
.topbar{
  position:sticky; top:0; z-index:50;
  background:#fff;
  border-bottom:1px solid #e5e7eb;
}
.topbar .wrap{
  max-width: 1200px;
  margin:0 auto;
  padding:12px 16px;
  display:flex;
  align-items:center;
  justify-content:space-between;
  gap:12px;
  font-family: Arial, Helvetica, sans-serif;
}
.topbar .title{ font-weight:800; }
.topbar .meta{ color:#555; font-size:12px; line-height:1.2; }
.topbar button{
  background:#1d4ed8;
  color:#fff;
  border:0;
  padding:10px 14px;
  border-radius:10px;
  font-weight:800;
  cursor:pointer;
}
.preview{
  max-width: 1200px;
  margin: 16px auto 32px;
  padding: 0 16px;
}
.sheet{
  width: var(--page-w);
  height: var(--page-h);
  margin: 0 auto 18px;
  background:#fff;
  box-shadow: 0 18px 50px rgba(0,0,0,.10);
  overflow:hidden;
}

/* ===== PRINT (zéro surprise) ===== */
@media print {
  @page { size: A4 landscape; margin: 0; }
  html, body { margin:0; padding:0; background:#fff; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
  .topbar, .preview { display:none !important; }

  .print-page{
    width:297mm;
    height:210mm;
    page-break-after:always;
    overflow:hidden;
    background:#fff;
  }
}

/* Canvas page (commun écran + print) */
.print-page, .sheet{
  position:relative;
}
.canvas{
  position:absolute;
  inset:0;
  padding: var(--pad);
  box-sizing:border-box;
}
.grid{
  width:100%;
  height:100%;
  display:grid;
  grid-template-columns: 1fr 1fr;
  grid-template-rows: 1fr 1fr;
  gap: var(--gap);
}

/* Étiquette */
.label{
  position:relative;
  overflow:hidden;
  background:#fff;
}

/* Filigrane logo */
.watermark{
  position:absolute;
  left:50%;
  top:46%;
  transform: translate(-50%,-50%);
  width: 150mm;
  max-width: 95%;
  opacity: .12;
  pointer-events:none;
}
.watermark img{
  width:100%;
  height:auto;
  display:block;
}

/* TITRES (catégorie) — séparé davantage du bloc convoi */
.label-titles{
  position:absolute;
  top: 18mm;
  left:8mm;
  right:8mm;
  text-align:center;
  font-family: Arial, Helvetica, sans-serif;
  font-weight:900;
  text-transform:uppercase;
  line-height:1.12;
  /* séparation forte avec le bloc convoi */
  margin-bottom: 9mm;
}

/* éviter les césures moches */
.label-titles .fr,
.label-titles .ua{
  hyphens: none;
  word-break: keep-all;
  overflow-wrap: normal;
}

.label-titles .fr{ font-size: 19pt; margin-bottom: 3mm; white-space: pre-line; }
.label-titles .ua{ font-size: 19pt; white-space: pre-line; }

/* BLOC CONVOI — légèrement réduit + plus compact */
.label-bottom{
  position:absolute;
  left:8mm;
  right:8mm;
  bottom: 12mm;
  text-align:center;
  font-family: Arial, Helvetica, sans-serif;
  line-height:1.05;
}

.label-bottom .line-fr{
  font-size: 12.5pt;
  font-weight: 400;
  text-transform: uppercase;
  margin-bottom: 1.8mm;
  letter-spacing: .2px;
}

.label-bottom .line-ua{
  font-size: 12.5pt;
  font-weight: 900;
  text-transform: uppercase;
  margin-bottom: 1.4mm;
  letter-spacing: .2px;
}

.label-bottom .line-city{
  font-size: 12pt;
  font-weight: 900;
  text-transform: uppercase;
  letter-spacing: .2px;
}
</style>
</head>

<body>

<div class="topbar">
  <div class="wrap">
    <div>
      <div class="title">PDF prêt — clique sur “Imprimer / Enregistrer en PDF”.</div>
      <div class="meta">
        Réglages : <b>Paysage</b> • <b>Échelle 100%</b> • désactiver en-têtes/pieds.
        (<?= (int)$pages ?> page(s), <?= (int)count($labelsToPrint) ?> étiquette(s))
      </div>
    </div>
    <button type="button" onclick="window.print()">Imprimer / Enregistrer en PDF</button>
  </div>
</div>

<div class="preview">
  <?php
    $totalLabels = count($labelsToPrint);
    $cursor = 0;

    for ($p=0; $p<$pages; $p++):
  ?>
    <div class="sheet">
      <div class="canvas">
        <div class="grid">
          <?php for ($i=0; $i<4; $i++): ?>
            <?php
              $lab = ($cursor < $totalLabels) ? $labelsToPrint[$cursor] : null;
              $cursor++;
            ?>
            <div class="label">
              <?php if ($logoUrl): ?>
                <div class="watermark">
                  <img src="<?= h($logoUrl) ?>" alt="Logo">
                </div>
              <?php endif; ?>

              <?php if ($lab): ?>
                <div class="label-titles">
                  <div class="fr"><?= h(upperMultiline($lab['fr'])) ?></div>
                  <div class="ua"><?= h(upperMultiline($lab['ua'])) ?></div>
                </div>

                <div class="label-bottom">
                  <div class="line-fr"><?= h($ASSO_LINE_FR) ?></div>
                  <div class="line-ua"><?= h($ASSO_LINE_UA) ?></div>
                  <div class="line-city"><?= h($CITY_LINE_UA) ?></div>
                </div>
              <?php endif; ?>
            </div>
          <?php endfor; ?>
        </div>
      </div>
    </div>
  <?php endfor; ?>
</div>

<?php
// Re-rendu PRINT (mêmes éléments, sans ombre/preview)
$totalLabels = count($labelsToPrint);
$cursor = 0;
for ($p=0; $p<$pages; $p++):
?>
  <div class="print-page">
    <div class="canvas">
      <div class="grid">
        <?php for ($i=0; $i<4; $i++): ?>
          <?php
            $lab = ($cursor < $totalLabels) ? $labelsToPrint[$cursor] : null;
            $cursor++;
          ?>
          <div class="label">
            <?php if ($logoUrl): ?>
              <div class="watermark">
                <img src="<?= h($logoUrl) ?>" alt="Logo">
              </div>
            <?php endif; ?>

            <?php if ($lab): ?>
              <div class="label-titles">
                <div class="fr"><?= h(upperMultiline($lab['fr'])) ?></div>
                <div class="ua"><?= h(upperMultiline($lab['ua'])) ?></div>
              </div>

              <div class="label-bottom">
                <div class="line-fr"><?= h($ASSO_LINE_FR) ?></div>
                <div class="line-ua"><?= h($ASSO_LINE_UA) ?></div>
                <div class="line-city"><?= h($CITY_LINE_UA) ?></div>
              </div>
            <?php endif; ?>
          </div>
        <?php endfor; ?>
      </div>
    </div>
  </div>
<?php endfor; ?>

</body>
</html>