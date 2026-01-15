<?php
// convoys/customs.php — Doc douane (éditeur + PDF print) — Option B (customs_json)
// PHP 7.4+

if (session_status() === PHP_SESSION_NONE) session_start();

require_once __DIR__ . '/../db.php';

// Helpers
function h(string $s): string { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }
function isAdmin(): bool { return !empty($_SESSION['is_admin']); }

// APP_BASE (pour /preprod-logistique/ vs /logistique/)
if (!defined('APP_BASE')) {
  $script = $_SERVER['SCRIPT_NAME'] ?? '';
  // /preprod-logistique/convoys/customs.php => APP_BASE=/preprod-logistique
  $base = str_replace('\\', '/', dirname(dirname($script)));
  $base = rtrim($base, '/');
  define('APP_BASE', $base === '' ? '' : $base);
}

// Logo (filigrane) - même logique que tes labels
function findLogoUrl(): ?string {
  $candidates = [
    __DIR__ . '/../assets/logo.png',
    __DIR__ . '/../assets/img/logo.png',
    __DIR__ . '/../assets/logo.jpg',
    __DIR__ . '/../assets/img/logo.jpg',
  ];

  $rootReal = realpath(__DIR__ . '/..'); // racine appli
  foreach ($candidates as $p) {
    if (!file_exists($p)) continue;
    $fileReal = realpath($p);
    if ($rootReal && $fileReal && strpos($fileReal, $rootReal) === 0) {
      $rel = str_replace($rootReal, '', $fileReal);
      $rel = str_replace('\\', '/', $rel);
      return APP_BASE . $rel;
    }
  }
  return null;
}

$convoyId = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($convoyId <= 0) {
  http_response_code(404);
  echo "Convoi introuvable.";
  exit;
}

// Load convoy
$st = $pdo->prepare("SELECT * FROM convoys WHERE id = ?");
$st->execute([$convoyId]);
$convoy = $st->fetch(PDO::FETCH_ASSOC);
if (!$convoy) {
  http_response_code(404);
  echo "Convoi introuvable.";
  exit;
}

$status = (string)($convoy['status'] ?? 'preparation');
$isPrint = !empty($_GET['print']); // ?print=1

// Compute lines from DB (boxes per sub-category)
function computeLinesFromConvoy(PDO $pdo, int $convoyId): array {
  // On part du principe que les cartons sont rattachés à des sous-catégories (parent_id NOT NULL)
  // Si tu as aussi des cartons sur racine (parent_id NULL), on les inclut quand même.
  $sql = "
    SELECT
      c.id AS category_id,
      c.label AS label_fr,
      COALESCE(c.label_ua, '') AS label_ua,
      c.parent_id AS parent_id,
      COALESCE(p.label, '') AS root_label,
      COUNT(b.id) AS qty
    FROM boxes b
    JOIN categories c ON c.id = b.category_id
    LEFT JOIN categories p ON p.id = c.parent_id
    WHERE b.convoy_id = ?
      AND c.is_active = 1
    GROUP BY c.id, c.label, c.label_ua, c.parent_id, p.label
    ORDER BY
      (CASE WHEN p.label IS NULL OR p.label = '' THEN c.label ELSE p.label END),
      c.label
  ";
  $st = $pdo->prepare($sql);
  $st->execute([$convoyId]);
  $rows = $st->fetchAll(PDO::FETCH_ASSOC);

  $lines = [];
  foreach ($rows as $r) {
    $cid = (int)$r['category_id'];
    $qty = (int)$r['qty'];
    $key = 'cat:' . $cid;

    $lines[] = [
      'key' => $key,
      'source' => [
        'type' => 'category',
        'category_id' => $cid,
        'parent_id' => $r['parent_id'] !== null ? (int)$r['parent_id'] : null,
        'root_label' => (string)($r['root_label'] ?? ''),
        'label_fr' => (string)$r['label_fr'],
        'label_ua' => (string)$r['label_ua'],
      ],
      'included' => true,
      'qty' => $qty,
      'unit' => 'cartons',
      'label_fr_export' => (string)$r['label_fr'],
      'label_ua_export' => (string)$r['label_ua'],
      'note' => '',
    ];
  }
  return $lines;
}

function decodeDraft(?string $json): ?array {
  if (!$json) return null;
  $data = json_decode($json, true);
  return is_array($data) ? $data : null;
}

/**
 * Snapshot intelligent:
 * - On conserve les overrides douane (label_*_export modifiés par l'utilisateur).
 * - Si l'utilisateur n'a PAS personnalisé (export vide OU export égal à l'ancien libellé source),
 *   alors on rafraîchit automatiquement depuis la base (computedLines -> source label_*).
 */
function shouldRefreshFromSource(string $oldExport, string $oldSource): bool {
  $oldExport = trim($oldExport);
  $oldSource = trim($oldSource);
  if ($oldExport === '') return true;
  // Si l'export était identique à l'ancien libellé source, on considère "pas custom"
  return $oldSource !== '' && $oldExport === $oldSource;
}

// Merge computed lines with saved draft (keep export labels + included + note; update qty)
function mergeDraft(array $computedLines, ?array $savedDraft): array {
  $out = [
    'meta' => [
      'doc_title' => 'Liste de colisage — Aide humanitaire',
      'destination' => '',
      'date' => '',
      'notes_globales' => '',
    ],
    'lines' => [],
    'ui' => ['group_mode' => 'by_root'],
  ];

  if (is_array($savedDraft)) {
    if (isset($savedDraft['meta']) && is_array($savedDraft['meta'])) {
      $out['meta'] = array_merge($out['meta'], $savedDraft['meta']);
    }
    if (isset($savedDraft['ui']) && is_array($savedDraft['ui'])) {
      $out['ui'] = array_merge($out['ui'], $savedDraft['ui']);
    }
  }

  $savedIndex = [];
  if (is_array($savedDraft) && !empty($savedDraft['lines']) && is_array($savedDraft['lines'])) {
    foreach ($savedDraft['lines'] as $ln) {
      if (!is_array($ln) || empty($ln['key'])) continue;
      $savedIndex[(string)$ln['key']] = $ln;
    }
  }

  foreach ($computedLines as $ln) {
    $key = (string)$ln['key'];
    if (isset($savedIndex[$key]) && is_array($savedIndex[$key])) {
      $old = $savedIndex[$key];

      // Keep user edits (included/unit/note)
      $ln['included'] = isset($old['included']) ? (bool)$old['included'] : $ln['included'];
      $ln['unit'] = isset($old['unit']) && trim((string)$old['unit']) !== '' ? (string)$old['unit'] : $ln['unit'];
      $ln['note'] = isset($old['note']) ? (string)$old['note'] : $ln['note'];

      // --- Snapshot intelligent sur les libellés export ---
      $newSourceFr = (string)($ln['source']['label_fr'] ?? '');
      $newSourceUa = (string)($ln['source']['label_ua'] ?? '');

      $oldSourceFr = (string)($old['source']['label_fr'] ?? ($old['label_fr_export'] ?? ''));
      $oldSourceUa = (string)($old['source']['label_ua'] ?? ($old['label_ua_export'] ?? ''));

      $oldExportFr = (string)($old['label_fr_export'] ?? '');
      $oldExportUa = (string)($old['label_ua_export'] ?? '');

      // FR
      if (shouldRefreshFromSource($oldExportFr, $oldSourceFr)) {
        $ln['label_fr_export'] = $newSourceFr;
      } else {
        $ln['label_fr_export'] = $oldExportFr;
      }

      // UA
      if (shouldRefreshFromSource($oldExportUa, $oldSourceUa)) {
        $ln['label_ua_export'] = $newSourceUa;
      } else {
        $ln['label_ua_export'] = $oldExportUa;
      }

      // qty: base = computed, mais si user a édité qty manuellement, on garde
      if (isset($old['qty']) && is_numeric($old['qty'])) {
        $ln['qty'] = (int)$old['qty'];
      }
    }
    $out['lines'][] = $ln;
  }

  return $out;
}

function saveDraft(PDO $pdo, int $convoyId, array $draft): void {
  $json = json_encode($draft, JSON_UNESCAPED_UNICODE);
  $st = $pdo->prepare("UPDATE convoys SET customs_json = ? WHERE id = ?");
  $st->execute([$json, $convoyId]);
}

// Build initial draft (from DB + merge)
$savedDraft = decodeDraft($convoy['customs_json'] ?? null);
$computed = computeLinesFromConvoy($pdo, $convoyId);
$draft = mergeDraft($computed, $savedDraft);

// POST actions
$errors = [];
$success = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $action = $_POST['action'] ?? '';

  // Pull meta + lines from POST (editor)
  $posted = null;
  if (!empty($_POST['draft_json'])) {
    $tmp = json_decode((string)$_POST['draft_json'], true);
    if (is_array($tmp)) $posted = $tmp;
  }

  if ($action === 'refresh') {
    // recompute + merge with current saved draft (not posted)
    $computed = computeLinesFromConvoy($pdo, $convoyId);
    $savedDraft = decodeDraft($convoy['customs_json'] ?? null);
    $draft = mergeDraft($computed, $savedDraft);
    saveDraft($pdo, $convoyId, $draft);
    $success = "Brouillon recalculé depuis le convoi.";
  }

  if ($action === 'save') {
    if (!$posted) {
      $errors[] = "Brouillon invalide (JSON manquant).";
    } else {
      // On merge avec le convoi pour rafraîchir les libellés source (snapshot intelligent)
      $computed = computeLinesFromConvoy($pdo, $convoyId);
      $draft = mergeDraft($computed, $posted);
      saveDraft($pdo, $convoyId, $draft);
      $success = "Brouillon sauvegardé.";
    }
  }

  if ($action === 'print') {
    // Print : on part du draft posté (si dispo), sinon saved draft,
    // puis on applique aussi le snapshot intelligent avant rendu PDF.
    if ($posted) {
      $computed = computeLinesFromConvoy($pdo, $convoyId);
      $draft = mergeDraft($computed, $posted);
    } else {
      $savedDraft = decodeDraft($convoy['customs_json'] ?? null);
      if ($savedDraft) {
        $computed = computeLinesFromConvoy($pdo, $convoyId);
        $draft = mergeDraft($computed, $savedDraft);
      }
    }
    $isPrint = true;
  }
}

// If print mode: render standalone print page (NO header/nav)
if ($isPrint) {
  $logoUrl = findLogoUrl();

  $meta = $draft['meta'] ?? [];
  $docTitle = trim((string)($meta['doc_title'] ?? 'Liste de colisage — Aide humanitaire'));
  $dest = trim((string)($meta['destination'] ?? ($convoy['destination'] ?? '')));
  $date = trim((string)($meta['date'] ?? ($convoy['departure_date'] ?? '')));
  $notesGlobales = trim((string)($meta['notes_globales'] ?? ''));

  $lines = [];
  if (!empty($draft['lines']) && is_array($draft['lines'])) $lines = $draft['lines'];

  // Filter included lines
  $included = array_values(array_filter($lines, function($ln){
    return is_array($ln) && !empty($ln['included']);
  }));

  // Total qty
  $totalQty = 0;
  foreach ($included as $ln) {
    $totalQty += (int)($ln['qty'] ?? 0);
  }

  $generatedAt = date('Y-m-d H:i');

  ?>
<!doctype html>
<html lang="fr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= h($docTitle) ?></title>
<style>
  /* Écran */
  html,body{ margin:0; padding:0; background:#f3f4f6; }
  .no-print{
    position:sticky; top:0; z-index:50;
    background:#fff; border-bottom:1px solid #e5e7eb;
    font-family: Arial, Helvetica, sans-serif;
  }
  .no-print .wrap{
    max-width:1100px; margin:0 auto; padding:12px 16px;
    display:flex; align-items:center; justify-content:space-between; gap:12px;
  }
  .no-print .meta{ font-size:12px; color:#444; line-height:1.25; }
  .no-print button{
    background:#1d4ed8; color:#fff; border:0;
    padding:10px 14px; border-radius:10px; font-weight:800; cursor:pointer;
  }

  .page{
    max-width:1100px;
    margin:18px auto 30px;
    padding:0 16px;
  }
  .sheet{
    background:#fff;
    box-shadow: 0 16px 44px rgba(0,0,0,.10);
    padding:16mm 14mm;
    position:relative;
    overflow:hidden;
    font-family: Arial, Helvetica, sans-serif;
  }

  /* Filigrane (écran + print, discret) */
  .watermark{
    position:absolute;
    inset:0;
    display:flex;
    align-items:center;
    justify-content:center;
    pointer-events:none;
    z-index:0;
  }
  .watermark img{
    width:140mm;
    height:auto;
    opacity:.06;
  }

  .doc{
    position:relative;
    z-index:1;
  }

  h1{
    margin:0 0 6mm;
    text-align:center;
    font-size:18pt;
    letter-spacing:.3px;
  }
  .sub{
    display:flex;
    flex-wrap:wrap;
    gap:10px 20px;
    font-size:11.5pt;
    margin-bottom:7mm;
  }
  .sub .item b{ font-weight:800; }
  .notes{
    margin-top:4mm;
    font-size:10.5pt;
    color:#333;
    white-space:pre-wrap;
  }

  table{
    width:100%;
    border-collapse:collapse;
    font-size:11pt;
  }
  th, td{
    border:1px solid #d1d5db;
    padding:6px 8px;
    vertical-align:top;
  }
  th{
    background:#f9fafb;
    text-align:left;
    font-weight:800;
  }
  .col-qty{ width:16mm; text-align:right; }
  .col-unit{ width:22mm; }
  .col-note{ width:44mm; }

  .totals{
    margin-top:6mm;
    display:flex;
    justify-content:space-between;
    align-items:flex-end;
    gap:12px;
    font-size:11.5pt;
  }
  .totals .big{ font-size:12.5pt; font-weight:900; }

  .footer{
    margin-top:10mm;
    font-size:10pt;
    color:#444;
    display:flex;
    justify-content:space-between;
    gap:10px;
  }

  /* print-only est caché à l’écran */
  .print-only{ display:none; }

  /* PRINT : A4 portrait, pas de nav, header de table répété */
  @media print {
    @page { size: A4 portrait; margin: 12mm; }
    html, body { background:#fff; }
    .no-print, .page { display:none !important; }

    .print-only { display:block !important; }
    .print-sheet{
      position:relative;
      font-family: Arial, Helvetica, sans-serif;
    }

    .watermark{
      position:fixed;
      top:50%;
      left:50%;
      transform: translate(-50%, -50%);
      width:auto;
      height:auto;
      z-index:0;
    }
    .watermark img{ opacity:.06; width:140mm; }

    .doc{ position:relative; z-index:1; }

    thead { display: table-header-group; }
    tfoot { display: table-footer-group; }
    tr { page-break-inside: avoid; }
  }
</style>
</head>
<body>

<div class="no-print">
  <div class="wrap">
    <div>
      <div style="font-weight:900;">PDF prêt — clique “Imprimer / Enregistrer en PDF”.</div>
      <div class="meta">
        Réglages recommandés : <b>A4</b> • <b>Portrait</b> • <b>Échelle 100%</b> • sans en-têtes/pieds. <br>
        Convoi #<?= (int)$convoyId ?> • <?= h($generatedAt) ?> • Lignes incluses : <?= (int)count($included) ?>
      </div>
    </div>
    <button type="button" onclick="window.print()">Imprimer / Enregistrer en PDF</button>
  </div>
</div>

<div class="page">
  <div class="sheet">
    <?php if ($logoUrl): ?>
      <div class="watermark"><img src="<?= h($logoUrl) ?>" alt="Logo"></div>
    <?php endif; ?>

    <div class="doc">
      <h1><?= h($docTitle) ?></h1>

      <div class="sub">
        <div class="item"><b>Association :</b> Touraine-Ukraine</div>
        <div class="item"><b>Convoi :</b> <?= h((string)($convoy['name'] ?? '')) ?></div>
        <div class="item"><b>Destination :</b> <?= $dest !== '' ? h($dest) : '—' ?></div>
        <div class="item"><b>Date :</b> <?= $date !== '' ? h($date) : '—' ?></div>
      </div>

      <?php if ($notesGlobales !== ''): ?>
        <div class="notes"><b>Notes :</b> <?= h($notesGlobales) ?></div>
      <?php endif; ?>

      <div style="margin-top:7mm;">
        <table>
          <thead>
            <tr>
              <th>Libellé (FR)</th>
              <th>Libellé (UA)</th>
              <th class="col-qty">Qté</th>
              <th class="col-unit">Unité</th>
              <th class="col-note">Note</th>
            </tr>
          </thead>
          <tbody>
            <?php if (empty($included)): ?>
              <tr><td colspan="5" style="color:#555;">Aucune ligne incluse.</td></tr>
            <?php else: ?>
              <?php foreach ($included as $ln): ?>
                <?php
                  $fr = trim((string)($ln['label_fr_export'] ?? ''));
                  $ua = trim((string)($ln['label_ua_export'] ?? ''));
                  $qty = (int)($ln['qty'] ?? 0);
                  $unit = trim((string)($ln['unit'] ?? 'cartons'));
                  $note = trim((string)($ln['note'] ?? ''));
                ?>
                <tr>
                  <td><?= h($fr !== '' ? $fr : '—') ?></td>
                  <td><?= h($ua !== '' ? $ua : '—') ?></td>
                  <td class="col-qty"><?= (int)$qty ?></td>
                  <td class="col-unit"><?= h($unit !== '' ? $unit : '—') ?></td>
                  <td class="col-note"><?= h($note) ?></td>
                </tr>
              <?php endforeach; ?>
            <?php endif; ?>
          </tbody>
        </table>

        <div class="totals">
          <div class="big">Total : <?= (int)$totalQty ?> cartons</div>
          <div style="text-align:right;">
            <div><b>Responsable :</b> _______________________</div>
            <div><b>Date :</b> ____ / ____ / ______</div>
          </div>
        </div>

        <div class="footer">
          <div>Touraine-Ukraine — Convoi #<?= (int)$convoyId ?></div>
          <div>Généré le <?= h($generatedAt) ?></div>
        </div>
      </div>
    </div>
  </div>
</div>

<!-- Version print-only (même contenu, sans ombres / sans layout écran) -->
<div class="print-only">
  <?php if ($logoUrl): ?>
    <div class="watermark"><img src="<?= h($logoUrl) ?>" alt="Logo"></div>
  <?php endif; ?>
  <div class="doc">
    <h1><?= h($docTitle) ?></h1>

    <div class="sub">
      <div class="item"><b>Association :</b> Touraine-Ukraine</div>
      <div class="item"><b>Convoi :</b> <?= h((string)($convoy['name'] ?? '')) ?></div>
      <div class="item"><b>Destination :</b> <?= $dest !== '' ? h($dest) : '—' ?></div>
      <div class="item"><b>Date :</b> <?= $date !== '' ? h($date) : '—' ?></div>
    </div>

    <?php if ($notesGlobales !== ''): ?>
      <div class="notes"><b>Notes :</b> <?= h($notesGlobales) ?></div>
    <?php endif; ?>

    <table>
      <thead>
        <tr>
          <th>Libellé (FR)</th>
          <th>Libellé (UA)</th>
          <th class="col-qty">Qté</th>
          <th class="col-unit">Unité</th>
          <th class="col-note">Note</th>
        </tr>
      </thead>
      <tbody>
        <?php if (empty($included)): ?>
          <tr><td colspan="5" style="color:#555;">Aucune ligne incluse.</td></tr>
        <?php else: ?>
          <?php foreach ($included as $ln): ?>
            <?php
              $fr = trim((string)($ln['label_fr_export'] ?? ''));
              $ua = trim((string)($ln['label_ua_export'] ?? ''));
              $qty = (int)($ln['qty'] ?? 0);
              $unit = trim((string)($ln['unit'] ?? 'cartons'));
              $note = trim((string)($ln['note'] ?? ''));
            ?>
            <tr>
              <td><?= h($fr !== '' ? $fr : '—') ?></td>
              <td><?= h($ua !== '' ? $ua : '—') ?></td>
              <td class="col-qty"><?= (int)$qty ?></td>
              <td class="col-unit"><?= h($unit !== '' ? $unit : '—') ?></td>
              <td class="col-note"><?= h($note) ?></td>
            </tr>
          <?php endforeach; ?>
        <?php endif; ?>
      </tbody>
    </table>

    <div class="totals">
      <div class="big">Total : <?= (int)$totalQty ?> cartons</div>
      <div style="text-align:right;">
        <div><b>Responsable :</b> _______________________</div>
        <div><b>Date :</b> ____ / ____ / ______</div>
      </div>
    </div>

    <div class="footer">
      <div>Touraine-Ukraine — Convoi #<?= (int)$convoyId ?></div>
      <div>Généré le <?= h($generatedAt) ?></div>
    </div>
  </div>
</div>

<script>
  // Lors d'un rendu ?print=1 on ne déclenche pas auto-print (tu peux l'ajouter si tu veux).
</script>
</body>
</html>
<?php
  exit;
}

// ======== MODE ÉDITEUR (avec header/nav normal) ========

require_once __DIR__ . '/../header.php';

// Build flat list and group by root label for display
$lines = $draft['lines'] ?? [];
if (!is_array($lines)) $lines = [];

// Build index of roots
$grouped = [];
foreach ($lines as $ln) {
  $root = '';
  if (isset($ln['source']['root_label'])) $root = trim((string)$ln['source']['root_label']);
  if ($root === '') $root = '— Sans racine';
  if (!isset($grouped[$root])) $grouped[$root] = [];
  $grouped[$root][] = $ln;
}

$meta = $draft['meta'] ?? [];
$docTitle = (string)($meta['doc_title'] ?? 'Liste de colisage — Aide humanitaire');
$dest = (string)($meta['destination'] ?? ($convoy['destination'] ?? ''));
$date = (string)($meta['date'] ?? ($convoy['departure_date'] ?? ''));
$notesGlobales = (string)($meta['notes_globales'] ?? '');

?>
<div class="container" style="max-width: 1200px;">
  <div class="d-flex flex-column flex-md-row justify-content-between align-items-md-end gap-2 mb-3">
    <div>
      <h1 class="h4 mb-1">Document douane</h1>
      <div class="text-muted small">
        Convoi : <b><?= h((string)$convoy['name']) ?></b>
        • Statut : <b><?= h($status) ?></b>
      </div>
    </div>

    <div class="d-flex flex-wrap gap-2 justify-content-md-end">
      <form method="post" class="d-inline">
        <input type="hidden" name="action" value="refresh">
        <button class="btn btn-outline-secondary btn-sm" type="submit">Recalculer depuis le convoi</button>
      </form>

      <button class="btn btn-primary btn-sm" type="button" onclick="submitAction('save')">Enregistrer</button>
      <button class="btn btn-success btn-sm" type="button" onclick="submitAction('print')">Aperçu PDF</button>
    </div>
  </div>

  <?php if (!empty($success)): ?>
    <div class="alert alert-success"><?= h($success) ?></div>
  <?php endif; ?>
  <?php if (!empty($errors)): ?>
    <div class="alert alert-danger"><ul class="mb-0"><?php foreach($errors as $e): ?><li><?= h($e) ?></li><?php endforeach; ?></ul></div>
  <?php endif; ?>

  <form method="post" id="customsForm" action="customs.php?id=<?= (int)$convoyId ?>">
    <input type="hidden" name="action" id="actionInput" value="save">
    <input type="hidden" name="draft_json" id="draftJson" value="">

    <div class="card shadow-sm mb-3">
      <div class="card-body">
        <h2 class="h6 mb-3">En-tête du document</h2>
        <div class="row g-3">
          <div class="col-12 col-lg-5">
            <label class="form-label">Titre</label>
            <input type="text" class="form-control" id="metaTitle" value="<?= h($docTitle) ?>">
          </div>
          <div class="col-12 col-lg-4">
            <label class="form-label">Destination</label>
            <input type="text" class="form-control" id="metaDest" value="<?= h($dest) ?>">
          </div>
          <div class="col-12 col-lg-3">
            <label class="form-label">Date (optionnelle)</label>
            <input type="date" class="form-control" id="metaDate" value="<?= h($date) ?>">
          </div>
          <div class="col-12">
            <label class="form-label">Notes globales</label>
            <textarea class="form-control" id="metaNotes" rows="2"><?= h($notesGlobales) ?></textarea>
          </div>
        </div>
      </div>
    </div>

    <div class="card shadow-sm mb-3">
      <div class="card-body">
        <div class="d-flex flex-column flex-md-row justify-content-between align-items-md-end gap-2 mb-3">
          <div>
            <h2 class="h6 mb-1">Contenu</h2>
            <div class="text-muted small">Tu peux adapter les libellés pour la douane (sans toucher au référentiel).</div>
          </div>
          <div class="d-flex gap-2">
            <input type="text" class="form-control form-control-sm" style="width:260px;" id="searchInput" placeholder="Rechercher…">
            <button type="button" class="btn btn-outline-secondary btn-sm" onclick="toggleAll(true)">Tout inclure</button>
            <button type="button" class="btn btn-outline-secondary btn-sm" onclick="toggleAll(false)">Tout exclure</button>
          </div>
        </div>

        <?php if (empty($grouped)): ?>
          <div class="text-muted">Aucune donnée (pas de cartons enregistrés).</div>
        <?php else: ?>
          <?php foreach ($grouped as $rootLabel => $items): ?>
            <div class="mb-3">
              <div class="fw-bold mb-2"><?= h($rootLabel) ?></div>

              <div class="table-responsive">
                <table class="table table-sm align-middle customs-table">
                  <thead>
                    <tr>
                      <th style="width:54px;">Incl.</th>
                      <th>Libellé FR (douane)</th>
                      <th>Libellé UA (douane)</th>
                      <th style="width:90px;" class="text-end">Qté</th>
                      <th style="width:110px;">Unité</th>
                      <th style="width:220px;">Note</th>
                    </tr>
                  </thead>
                  <tbody>
                    <?php foreach ($items as $ln): ?>
                      <?php
                        $key = (string)$ln['key'];
                        $fr = (string)($ln['label_fr_export'] ?? '');
                        $ua = (string)($ln['label_ua_export'] ?? '');
                        $qty = (int)($ln['qty'] ?? 0);
                        $unit = (string)($ln['unit'] ?? 'cartons');
                        $note = (string)($ln['note'] ?? '');
                        $inc = !empty($ln['included']);
                      ?>
                      <tr data-key="<?= h($key) ?>">
                        <td>
                          <input type="checkbox" class="form-check-input js-inc" <?= $inc ? 'checked' : '' ?>>
                        </td>
                        <td><input type="text" class="form-control form-control-sm js-fr" value="<?= h($fr) ?>"></td>
                        <td><input type="text" class="form-control form-control-sm js-ua" value="<?= h($ua) ?>"></td>
                        <td><input type="number" min="0" class="form-control form-control-sm text-end js-qty" value="<?= (int)$qty ?>"></td>
                        <td><input type="text" class="form-control form-control-sm js-unit" value="<?= h($unit) ?>"></td>
                        <td><input type="text" class="form-control form-control-sm js-note" value="<?= h($note) ?>"></td>
                      </tr>
                    <?php endforeach; ?>
                  </tbody>
                </table>
              </div>

            </div>
          <?php endforeach; ?>
        <?php endif; ?>

      </div>
    </div>

    <div class="d-flex justify-content-end gap-2 mb-4">
      <button class="btn btn-primary" type="button" onclick="submitAction('save')">Enregistrer</button>
      <button class="btn btn-success" type="button" onclick="submitAction('print')">Aperçu PDF</button>
    </div>
  </form>
</div>

<script>
const initialDraft = <?= json_encode($draft, JSON_UNESCAPED_UNICODE) ?>;

function buildDraftFromUI() {
  const meta = {
    doc_title: document.getElementById('metaTitle').value || 'Liste de colisage — Aide humanitaire',
    destination: document.getElementById('metaDest').value || '',
    date: document.getElementById('metaDate').value || '',
    notes_globales: document.getElementById('metaNotes').value || ''
  };

  const lines = [];
  document.querySelectorAll('tr[data-key]').forEach(tr => {
    const key = tr.getAttribute('data-key');
    const base = (initialDraft.lines || []).find(x => x && x.key === key) || null;

    lines.push({
      key,
      source: base ? base.source : {},
      included: tr.querySelector('.js-inc').checked,
      label_fr_export: tr.querySelector('.js-fr').value || '',
      label_ua_export: tr.querySelector('.js-ua').value || '',
      qty: parseInt(tr.querySelector('.js-qty').value || '0', 10) || 0,
      unit: tr.querySelector('.js-unit').value || 'cartons',
      note: tr.querySelector('.js-note').value || ''
    });
  });

  return { meta, lines, ui: initialDraft.ui || { group_mode: 'by_root' } };
}

function submitAction(action) {
  document.getElementById('actionInput').value = action;
  document.getElementById('draftJson').value = JSON.stringify(buildDraftFromUI());
  const form = document.getElementById('customsForm');

  if (action === 'print') {
    // en print on passe par POST + print=1 (même fichier)
    form.action = "customs.php?id=<?= (int)$convoyId ?>&print=1";
    form.target = "_blank";
  } else {
    form.action = "customs.php?id=<?= (int)$convoyId ?>";
    form.target = "_self";
  }

  form.submit();
}

function toggleAll(val) {
  document.querySelectorAll('.js-inc').forEach(cb => cb.checked = val);
}

const searchInput = document.getElementById('searchInput');
if (searchInput) {
  searchInput.addEventListener('input', () => {
    const q = searchInput.value.trim().toLowerCase();
    document.querySelectorAll('.customs-table tbody tr').forEach(tr => {
      const fr = (tr.querySelector('.js-fr')?.value || '').toLowerCase();
      const ua = (tr.querySelector('.js-ua')?.value || '').toLowerCase();
      const show = q === '' || fr.includes(q) || ua.includes(q);
      tr.style.display = show ? '' : 'none';
    });
  });
}
</script>

<?php require_once __DIR__ . '/../footer.php'; ?>
