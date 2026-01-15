<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';

if (!function_exists('is_admin') || !is_admin()) {
    http_response_code(403);
    echo "Accès interdit (admin).";
    exit;
}

function h(string $s): string { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }

$eventId = (int)($_GET['event_id'] ?? 0);

$events = $pdo->query("SELECT id, nom, date_debut, date_fin, actif FROM evenements ORDER BY id DESC")->fetchAll(PDO::FETCH_ASSOC);
if ($eventId <= 0 && !empty($events)) {
    $eventId = (int)$events[0]['id'];
}

$event = null;
if ($eventId > 0) {
    $stmt = $pdo->prepare("SELECT * FROM evenements WHERE id = ?");
    $stmt->execute([$eventId]);
    $event = $stmt->fetch(PDO::FETCH_ASSOC);
}

$retraits = [];
$totalRetraits = 0.0;
if ($event) {
    $stmt = $pdo->prepare("SELECT id, montant, note, created_at FROM retraits_caisse WHERE evenement_id = ? ORDER BY created_at DESC, id DESC");
    $stmt->execute([$eventId]);
    $retraits = $stmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($retraits as $r) $totalRetraits += (float)$r['montant'];
}

$page = 'dashboard';
?>
<!doctype html>
<html lang="fr">
<head>
  <meta charset="utf-8"/>
  <meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1"/>
  <title>Retraits de caisse</title>
  <link rel="stylesheet" href="assets/css/style.css?v=8">
  <style>
    .wrap{max-width:1100px;margin:10px auto;padding:10px}
    .cardx{background:#fff;border-radius:16px;padding:14px;box-shadow:0 8px 20px rgba(15,23,42,0.06);border:1px solid #e5e7eb}
    .row{display:flex;gap:10px;flex-wrap:wrap;align-items:end}
    .row > *{flex:1 1 240px}
    .btnx{border:0;border-radius:12px;padding:10px 12px;font-weight:700;cursor:pointer}
    .btn-primary{background:#2563eb;color:#fff}
    .btn-secondary{background:#e5e7eb;color:#111827}
    table{width:100%;border-collapse:collapse}
    th,td{padding:10px 8px;border-bottom:1px solid #e5e7eb;text-align:left;font-size:14px}
    th{font-size:12px;color:#6b7280;text-transform:uppercase;letter-spacing:.06em}
    .muted{color:#6b7280;font-size:13px}
    .total{display:flex;justify-content:space-between;align-items:baseline;padding-top:10px;margin-top:10px;border-top:1px dashed #e5e7eb}
    .total strong{font-size:18px}
    input,select{width:100%;padding:10px 12px;border-radius:12px;border:1px solid #d1d5db;font-size:14px}
  </style>
</head>
<body>

<?php require_once __DIR__ . '/../shared/suite_nav.php'; ?>
<?php include 'nav.php'; ?>

<div class="wrap">
  <div class="cardx">
    <h2 style="margin:0 0 8px 0;">Retraits de caisse</h2>
    <div class="muted">Ajoute des retraits d'espèces (mise en banque) après ou pendant la clôture.</div>

    <div class="row" style="margin-top:12px">
      <div>
        <div class="muted" style="margin-bottom:6px">Évènement</div>
        <select onchange="location.href='retraits_caisse.php?event_id='+this.value">
          <?php foreach ($events as $ev): ?>
            <option value="<?= (int)$ev['id'] ?>" <?= ((int)$ev['id']===$eventId?'selected':'') ?>>
              #<?= (int)$ev['id'] ?> — <?= h((string)$ev['nom']) ?><?= ((int)$ev['actif']===1?' (actif)':'') ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>

      <div>
        <div class="muted" style="margin-bottom:6px">Montant retiré (€)</div>
        <input id="retrait-montant" type="number" step="0.01" inputmode="decimal" placeholder="Ex: 150,00">
      </div>

      <div>
        <div class="muted" style="margin-bottom:6px">Note (optionnel)</div>
        <input id="retrait-note" type="text" maxlength="255" placeholder="Ex: Mise en banque lundi">
      </div>

      <div style="flex:0 0 180px">
        <button class="btnx btn-primary" onclick="addRetrait()">Ajouter</button>
      </div>
    </div>

    <div class="total">
      <div class="muted">Total retraits enregistrés</div>
      <strong><?= number_format($totalRetraits, 2, ',', ' ') ?> €</strong>
    </div>

    <div style="margin-top:12px;display:flex;gap:8px;flex-wrap:wrap">
      <?php if ($event): ?>
        <a class="btnx btn-secondary" href="cloture_recap.php?event_id=<?= (int)$eventId ?>" style="text-decoration:none;display:inline-flex;align-items:center;justify-content:center">Voir récap clôture</a>
      <?php endif; ?>
    </div>

    <div style="margin-top:14px">
      <table>
        <thead>
          <tr>
            <th>Date</th>
            <th>Montant</th>
            <th>Note</th>
          </tr>
        </thead>
        <tbody>
          <?php if (empty($retraits)): ?>
            <tr><td colspan="3" class="muted">Aucun retrait pour cet évènement.</td></tr>
          <?php else: ?>
            <?php foreach ($retraits as $r): ?>
              <tr>
                <td><?= h((string)$r['created_at']) ?></td>
                <td><strong><?= number_format((float)$r['montant'], 2, ',', ' ') ?> €</strong></td>
                <td><?= h((string)($r['note'] ?? '')) ?></td>
              </tr>
            <?php endforeach; ?>
          <?php endif; ?>
        </tbody>
      </table>
    </div>

  </div>
</div>

<script>
function addRetrait(){
  const eventId = <?= (int)$eventId ?>;
  const montant = document.getElementById('retrait-montant').value;
  const note = document.getElementById('retrait-note').value;

  const params = new URLSearchParams();
  params.set('event_id', eventId);
  params.set('montant', montant);
  params.set('note', note);

  fetch('add_retrait_caisse.php', {
    method:'POST',
    headers: {'Content-Type':'application/x-www-form-urlencoded'},
    body: params.toString()
  })
  .then(r => r.json())
  .then(res => {
    if(!res.ok){
      alert(res.error || 'Erreur');
      return;
    }
    location.reload();
  })
  .catch(() => alert('Erreur réseau'));
}
</script>
</body>
</html>