<?php
// convoys/customs_export.php — V2 export HTML(print) + CSV — PHP 7.4+
if (session_status() === PHP_SESSION_NONE) session_start();

require_once __DIR__ . '/../db.php';

function h(string $s): string { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }

// APP_BASE (pour /preprod-logistique/ vs /logistique/)
if (!defined('APP_BASE')) {
  $script = $_SERVER['SCRIPT_NAME'] ?? '';
  $base = str_replace('\\', '/', dirname(dirname($script))); // /xxx/convoys/customs_export.php -> /xxx
  $base = rtrim($base, '/');
  define('APP_BASE', $base === '' ? '' : $base);
}

$payloadJson = $_POST['payload'] ?? '';
$format = $_POST['format'] ?? ($_GET['format'] ?? 'html');
$format = ($format === 'csv') ? 'csv' : 'html';

if (!is_string($payloadJson) || trim($payloadJson) === '') {
  http_response_code(400);
  echo "Payload manquant.";
  exit;
}

$data = json_decode($payloadJson, true);
if (!is_array($data)) {
  http_response_code(400);
  echo "Payload invalide (JSON).";
  exit;
}

// Validation minimale
$convoyId = (int)($data['convoy']['id'] ?? 0);
if ($convoyId <= 0) {
  http_response_code(400);
  echo "Convoi invalide.";
  exit;
}

// Récupère convoi depuis DB (source de vérité)
$stmt = $pdo->prepare("SELECT * FROM convoys WHERE id = ?");
$stmt->execute([$convoyId]);
$convoy = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$convoy) {
  http_response_code(404);
  echo "Convoi introuvable.";
  exit;
}

$doc = $data['doc'] ?? [];
$docType = (string)($doc['type'] ?? 'packing_list');
$docTypeOther = (string)($doc['type_other'] ?? '');
$docRef = (string)($doc['ref'] ?? '');
$docNote = (string)($doc['note'] ?? '');

$lines = $data['lines'] ?? [];
if (!is_array($lines)) $lines = [];

// Nettoyage + limite
$cleanLines = [];
foreach ($lines as $ln) {
  if (!is_array($ln)) continue;
  if (!empty($ln['include']) && $ln['include'] !== true && $ln['include'] !== 1 && $ln['include'] !== '1') {
    continue;
  }
  $fr = trim((string)($ln['export_fr'] ?? ''));
  $ua = trim((string)($ln['export_ua'] ?? ''));
  $qty = (int)($ln['qty'] ?? 0);
  $unit = trim((string)($ln['unit'] ?? 'cartons'));
  $notes = trim((string)($ln['notes'] ?? ''));

  if ($fr === '') continue;
  if ($qty < 0) $qty = 0;
  if (mb_strlen($fr, 'UTF-8') > 200) $fr = mb_substr($fr, 0, 200, 'UTF-8');
  if (mb_strlen($ua, 'UTF-8') > 200) $ua = mb_substr($ua, 0, 200, 'UTF-8');
  if (mb_strlen($unit, 'UTF-8') > 30) $unit = mb_substr($unit, 0, 30, 'UTF-8');
  if (mb_strlen($notes, 'UTF-8') > 200) $notes = mb_substr($notes, 0, 200, 'UTF-8');

  $cleanLines[] = [
    'fr' => $fr,
    'ua' => $ua,
    'qty' => $qty,
    'unit' => $unit,
    'notes' => $notes,
  ];
}
if (count($cleanLines) > 300) $cleanLines = array_slice($cleanLines, 0, 300);

function docTypeLabel(string $t, string $other): string {
  switch ($t) {
    case 'customs_declaration': return "Déclaration douanière";
    case 'packing_list': return "Liste de colisage";
    case 'other': return $other !== '' ? $other : "Autre";
    default: return "Document";
  }
}

// CSV
if ($format === 'csv') {
  header('Content-Type: text/csv; charset=UTF-8');
  header('Content-Disposition: attachment; filename="douane_convoi_' . $convoyId . '.csv"');

  // BOM UTF-8 pour Excel
  echo "\xEF\xBB\xBF";
  $out = fopen('php://output', 'w');

  fputcsv($out, ['Convoi', (string)($convoy['name'] ?? '')]);
  fputcsv($out, ['Destination', (string)($convoy['destination'] ?? '')]);
  fputcsv($out, ['Date', (string)($convoy['departure_date'] ?? '')]);
  fputcsv($out, ['Type document', docTypeLabel($docType, $docTypeOther)]);
  fputcsv($out, ['Référence', $docRef]);
  fputcsv($out, ['Note', $docNote]);
  fputcsv($out, []); // blank

  fputcsv($out, ['Libellé FR', 'Libellé UA', 'Quantité', 'Unité', 'Note ligne']);
  foreach ($cleanLines as $ln) {
    fputcsv($out, [$ln['fr'], $ln['ua'], $ln['qty'], $ln['unit'], $ln['notes']]);
  }
  fclose($out);
  exit;
}

// HTML print (PDF via navigateur)
$title = docTypeLabel($docType, $docTypeOther);

?>
<!doctype html>
<html lang="fr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title><?= h($title) ?> — Convoi <?= (int)$convoyId ?></title>
<style>
@page { size: A4 portrait; margin: 12mm; }
html,body { margin:0; padding:0; font-family: Arial, Helvetica, sans-serif; color:#111; }
.small{ font-size: 12px; color:#444; }
h1{ font-size: 18px; margin:0 0 8px 0; }
h2{ font-size: 13px; margin:14px 0 6px 0; }
.meta{ display:flex; flex-wrap:wrap; gap:10px 18px; font-size: 12px; }
.meta b{ color:#000; }
hr{ border:0; border-top:1px solid #ddd; margin:10px 0; }

table{ width:100%; border-collapse:collapse; margin-top:8px; }
th,td{ border:1px solid #ddd; padding:8px 7px; vertical-align:top; }
th{ background:#f6f7f9; font-size:12px; text-align:left; }
td{ font-size:12px; }
.col-qty{ width:72px; text-align:right; }
.col-unit{ width:90px; }
.col-notes{ width:22%; }
.footer{ position:fixed; left:12mm; right:12mm; bottom:8mm; display:flex; justify-content:space-between; font-size:10px; color:#666; }
.badge{ display:inline-block; padding:2px 6px; border:1px solid #ddd; border-radius:999px; font-size:11px; color:#444; }
.no-print{ display:block; padding:10px 12px; background:#f5f7ff; border:1px solid #dbe2ff; border-radius:10px; margin-bottom:10px; }
@media print {
  .no-print{ display:none; }
}
</style>
</head>
<body>

<div class="no-print">
  <div style="font-weight:800; margin-bottom:6px;">Aperçu prêt.</div>
  <div class="small">Clique “Imprimer” puis “Enregistrer en PDF”. (A4 portrait, échelle 100%, en-têtes/pieds désactivés)</div>
  <div style="margin-top:8px;">
    <button onclick="window.print()" style="padding:8px 12px; font-weight:800;">Imprimer / Enregistrer en PDF</button>
  </div>
</div>

<h1><?= h($title) ?> <span class="badge">Aide humanitaire</span></h1>
<div class="meta">
  <div><b>Convoi :</b> <?= h((string)($convoy['name'] ?? '')) ?></div>
  <div><b>Destination :</b> <?= h((string)($convoy['destination'] ?? '')) ?></div>
  <div><b>Date :</b> <?= h((string)($convoy['departure_date'] ?? '')) ?></div>
  <?php if ($docRef !== ''): ?><div><b>Référence :</b> <?= h($docRef) ?></div><?php endif; ?>
</div>

<?php if ($docNote !== ''): ?>
  <hr>
  <div class="small"><b>Note :</b> <?= h($docNote) ?></div>
<?php endif; ?>

<h2>Contenu déclaré</h2>

<?php if (empty($cleanLines)): ?>
  <div class="small">Aucune ligne sélectionnée.</div>
<?php else: ?>
  <table>
    <thead>
      <tr>
        <th>Libellé (FR)</th>
        <th>Libellé (UA)</th>
        <th class="col-qty">Qté</th>
        <th class="col-unit">Unité</th>
        <th class="col-notes">Note</th>
      </tr>
    </thead>
    <tbody>
      <?php foreach ($cleanLines as $ln): ?>
        <tr>
          <td><?= h($ln['fr']) ?></td>
          <td><?= h($ln['ua']) ?></td>
          <td class="col-qty"><?= (int)$ln['qty'] ?></td>
          <td class="col-unit"><?= h($ln['unit']) ?></td>
          <td class="col-notes"><?= h($ln['notes']) ?></td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
<?php endif; ?>

<div class="footer">
  <div>Touraine-Ukraine — document généré via outil logistique</div>
  <div><?= date('Y-m-d H:i') ?> — Convoi #<?= (int)$convoyId ?></div>
</div>

</body>
</html>
