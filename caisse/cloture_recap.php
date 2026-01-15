<?php
require 'config.php';

$eventId = (int)($_GET['event_id'] ?? 0);
if ($eventId <= 0) {
  http_response_code(400);
  echo "Évènement invalide.";
  exit;
}

$stmt = $pdo->prepare("
  SELECT
    e.id,
    e.nom,
    e.date_debut,
    e.date_fin,
    e.fond_caisse,
    e.fond_caisse_cloture,
    e.ecart_caisse,
    e.retrait_caisse_especes,
    e.retrait_caisse_note,
    e.retrait_caisse_date,
    COALESCE(SUM(CASE WHEN vp.methode='CB'      THEN vp.montant ELSE 0 END),0) AS total_cb,
    COALESCE(SUM(CASE WHEN vp.methode='Especes' THEN vp.montant ELSE 0 END),0) AS total_especes,
    COALESCE(SUM(CASE WHEN vp.methode='Cheque'  THEN vp.montant ELSE 0 END),0) AS total_cheques
  FROM evenements e
  LEFT JOIN ventes v ON v.evenement_id = e.id
  LEFT JOIN vente_paiements vp ON vp.vente_id = v.id
  WHERE e.id = ?
  GROUP BY e.id, e.nom, e.date_debut, e.date_fin, e.fond_caisse, e.fond_caisse_cloture, e.ecart_caisse, e.retrait_caisse_especes, e.retrait_caisse_note, e.retrait_caisse_date
  LIMIT 1
");
$stmt->execute([$eventId]);
$ev = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$ev) {
  http_response_code(404);
  echo "Évènement introuvable.";
  exit;
}

function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function eur($n){ return number_format((float)$n, 2, ',', ' ') . " €"; }
function fmt_date_no_time($dt){
  if (!$dt) return '—';
  try{
    $d = new DateTime((string)$dt);
    return $d->format('d/m/Y'); // sans heure
  }catch(Exception $e){
    $s = (string)$dt;
    if (strpos($s, ' ') !== false) $s = explode(' ', $s, 2)[0];
    return h($s);
  }
}

/** Vérifie si une table existe dans la DB courante */
function table_exists(PDO $pdo, string $table): bool {
  try{
    $q = $pdo->prepare("SELECT 1 FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ? LIMIT 1");
    $q->execute([$table]);
    return (bool)$q->fetchColumn();
  } catch (Exception $e){
    return false;
  }
}

/** Récupère la liste des colonnes d'une table (en minuscules) */
function table_columns(PDO $pdo, string $table): array {
  try{
    $rows = $pdo->query("DESCRIBE `$table`")->fetchAll(PDO::FETCH_ASSOC);
    $cols = [];
    foreach ($rows as $r) $cols[] = strtolower((string)$r['Field']);
    return $cols;
  }catch(Exception $e){
    return [];
  }
}

$totalCB  = (float)$ev['total_cb'];
$totalEsp = (float)$ev['total_especes'];
$totalChq = (float)$ev['total_cheques'];

$gagne = $totalCB + $totalEsp + $totalChq;
$caisseAttendue = ((float)$ev['fond_caisse']) + $totalEsp;

$fondReel  = ($ev['fond_caisse_cloture'] !== null) ? (float)$ev['fond_caisse_cloture'] : null;
$ecart     = ($ev['ecart_caisse'] !== null) ? (float)$ev['ecart_caisse'] : null;

// ===== Retraits de caisse : table d'historique si dispo =====
$retraits = []; // [{date, montant, note}]
$totalRetraits = null;

if (table_exists($pdo, 'retraits_caisse')) {
  $cols = table_columns($pdo, 'retraits_caisse');

  $colEvent = in_array('evenement_id', $cols, true) ? 'evenement_id' : (in_array('event_id', $cols, true) ? 'event_id' : null);
  $colMontant = in_array('montant', $cols, true) ? 'montant' : (in_array('amount', $cols, true) ? 'amount' : null);
  $colNote = in_array('note', $cols, true) ? 'note' : (in_array('motif', $cols, true) ? 'motif' : null);
  $colDate = null;
  foreach (['date_retrait','created_at','date','created','datetime'] as $c) {
    if (in_array(strtolower($c), $cols, true)) { $colDate = $c; break; }
  }

  if ($colEvent && $colMontant) {
    $dateExpr = $colDate ? "`$colDate`" : "NULL";
    $noteExpr = $colNote ? "`$colNote`" : "NULL";
    $orderBy  = $colDate ? "`$colDate`" : "`$colMontant`";

    $sql = "SELECT $dateExpr AS d, `$colMontant` AS m, $noteExpr AS n
            FROM `retraits_caisse`
            WHERE `$colEvent` = ?
            ORDER BY $orderBy ASC";

    try{
      $q = $pdo->prepare($sql);
      $q->execute([$eventId]);
      $rows = $q->fetchAll(PDO::FETCH_ASSOC);
      foreach ($rows as $r){
        $retraits[] = [
          'date' => $r['d'] ?? null,
          'montant' => (float)($r['m'] ?? 0),
          'note' => $r['n'] ?? null,
        ];
      }
      $sum = 0.0;
      foreach ($retraits as $rr) $sum += (float)$rr['montant'];
      $totalRetraits = $sum;
    }catch(Exception $e){
      $retraits = [];
      $totalRetraits = null;
    }
  }
}

// Fallback (ancien modèle : un seul retrait agrégé sur evenements)
if ($totalRetraits === null) {
  $totalRetraits = ($ev['retrait_caisse_especes'] !== null) ? (float)$ev['retrait_caisse_especes'] : 0.0;

  if ($totalRetraits > 0) {
    $retraits[] = [
      'date' => $ev['retrait_caisse_date'] ?? null,
      'montant' => $totalRetraits,
      'note' => $ev['retrait_caisse_note'] ?? null,
    ];
  }
}

$resteEnCaisse = null;
if ($fondReel !== null) {
  $resteEnCaisse = $fondReel - (float)$totalRetraits;
}

// Option : impression auto si ?print=1
$autoPrint = (isset($_GET['print']) && $_GET['print'] == '1');

// Couleur écart
$ecartClass = '';
if ($ecart !== null) {
  $abs = abs($ecart);
  if ($abs <= 0.01) $ecartClass = 'ok';
  elseif ($abs <= 2.00) $ecartClass = 'warn';
  else $ecartClass = 'bad';
}
?>
<!doctype html>
<html lang="fr">
<head>
  <meta charset="utf-8"/>
  <meta name="viewport" content="width=device-width,initial-scale=1"/>
  <title>Clôture caisse – <?= h($ev['nom']) ?> – Mini Caisse</title>

  <style>
    :root{ --bg:#0b1220; --card:#0f172a; --text:#e5e7eb; --muted:#94a3b8; --line:#243041; }
    body{ margin:0; font-family: system-ui,-apple-system,Segoe UI,Roboto,Arial; background:var(--bg); color:var(--text); }
    a{ color:inherit; text-decoration:none; }
    .topbar{ display:flex; gap:10px; align-items:center; justify-content:space-between; padding:14px 16px; border-bottom:1px solid var(--line); }
    .btn{ display:inline-flex; align-items:center; gap:8px; padding:10px 12px; border-radius:10px; border:1px solid var(--line); background:rgba(255,255,255,.04); }
    .btn:hover{ background:rgba(255,255,255,.07); }
    .wrap{ max-width:760px; margin:18px auto; padding:0 14px; }
    .ticket{ background:var(--card); border:1px solid var(--line); border-radius:16px; padding:16px; }
    .h1{ font-size:18px; font-weight:800; letter-spacing:.5px; margin:0 0 10px; }
    .meta{ color:var(--muted); font-size:13px; line-height:1.4; margin-bottom:12px; display:flex; gap:18px; flex-wrap:wrap; }
    .hr{ height:1px; background:var(--line); margin:14px 0; }
    .row{ display:flex; justify-content:space-between; gap:12px; padding:7px 0; border-bottom:1px dashed rgba(148,163,184,.25); }
    .row:last-child{ border-bottom:0; }
    .label{ color:var(--muted); }
    .value{ font-weight:700; text-align:right; }
    .total{ font-size:16px; }
    .ok{ color:#16a34a; }
    .warn{ color:#f59e0b; }
    .bad{ color:#ef4444; }
    .section-title{ margin:14px 0 8px; font-size:13px; color:var(--muted); font-weight:700; text-transform:uppercase; letter-spacing:.08em; }
    table{ width:100%; border-collapse:collapse; }
    th,td{ text-align:left; padding:8px 0; border-bottom:1px dashed rgba(148,163,184,.25); font-size:13px; }
    th{ color:var(--muted); font-weight:700; }
    td.amount, th.amount{ text-align:right; }
    td.note{ color:var(--text); opacity:.9; }
    .foot{ margin-top:14px; color:var(--muted); font-size:12px; text-align:center; }
    @media print{
      body{ background:#fff; color:#000; }
      .topbar{ display:none; }
      .ticket{ border:0; border-radius:0; }
      .label{ color:#333; }
      .meta,.foot,.section-title,th{ color:#444; }
      td.note{ color:#000; }
    }
  </style>
</head>
<body>

<?php require_once __DIR__ . '/../shared/suite_nav.php'; ?>

  <div class="topbar">
    <a class="btn" href="evenements.php">← Retour</a>
    <a class="btn" href="cloture_recap.php?event_id=<?= (int)$eventId ?>&print=1">Imprimer / PDF</a>
  </div>

  <div class="wrap">
    <div class="ticket">
      <div class="h1">CLÔTURE DE CAISSE</div>

      <div class="meta">
        <div><strong>Évènement :</strong> <?= h($ev['nom']) ?></div>
        <div><strong>Début :</strong> <?= h($ev['date_debut'] ?: '—') ?></div>
        <div><strong>Fin :</strong> <?= h($ev['date_fin'] ?: '—') ?></div>
      </div>

      <div class="hr"></div>

      <div class="row"><div class="label">Fond de caisse initial</div><div class="value"><?= eur($ev['fond_caisse']) ?></div></div>
      <div class="row"><div class="label">Total CB</div><div class="value"><?= eur($totalCB) ?></div></div>
      <div class="row"><div class="label">Total espèces</div><div class="value"><?= eur($totalEsp) ?></div></div>
      <div class="row"><div class="label">Total chèques</div><div class="value"><?= eur($totalChq) ?></div></div>

      <div class="hr"></div>

      <div class="row"><div class="label">Montant gagné</div><div class="value total"><?= eur($gagne) ?></div></div>
      <div class="row"><div class="label">Caisse attendue (fond + espèces)</div><div class="value total"><?= eur($caisseAttendue) ?></div></div>

      <div class="hr"></div>

      <div class="row">
        <div class="label">Fond de caisse réel compté</div>
        <div class="value"><?= ($fondReel === null ? '—' : eur($fondReel)) ?></div>
      </div>

      <div class="row">
        <div class="label">Écart de caisse</div>
        <div class="value <?= h($ecartClass) ?>">
          <?= ($ecart === null ? '—' : eur($ecart)) ?>
        </div>
      </div>

      <div class="hr"></div>

      <div class="row">
        <div class="label">Retraits de caisse (espèces)</div>
        <div class="value"><?= eur($totalRetraits) ?></div>
      </div>

      <?php if (!empty($retraits)): ?>
        <div class="section-title">Détail des retraits</div>
        <table>
          <thead>
            <tr>
              <th>Date</th>
              <th class="amount">Montant</th>
              <th>Note</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($retraits as $r): ?>
              <tr>
                <td><?= h(fmt_date_no_time($r['date'] ?? null)) ?></td>
                <td class="amount"><?= eur($r['montant'] ?? 0) ?></td>
                <td class="note"><?= h(trim((string)($r['note'] ?? '')) ?: '—') ?></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      <?php endif; ?>

      <div class="row" style="margin-top:10px;">
        <div class="label">Reste en caisse (réel - retraits)</div>
        <div class="value total"><?= ($resteEnCaisse === null ? '—' : eur($resteEnCaisse)) ?></div>
      </div>

      <div class="foot">
        Document généré par Mini Caisse — impression compatible PDF.
      </div>
    </div>
  </div>

<?php if ($autoPrint): ?>
<script>
  window.addEventListener('load', () => window.print());
</script>
<?php endif; ?>

</body>
</html>
