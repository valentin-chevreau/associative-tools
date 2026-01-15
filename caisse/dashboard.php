<?php
require 'config.php';
$page = 'dashboard';

function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function money($v){ return number_format((float)$v, 2, ',', ' '); }

$eventParam = $_GET['event'] ?? 'all';
$eventId = ($eventParam === 'all') ? null : (int)$eventParam;
if ($eventId !== null && $eventId <= 0) $eventId = null;

// Liste des évènements (pour filtre)
$events = $pdo->query("SELECT id, nom, date_debut, date_fin, actif, fond_caisse, fond_caisse_cloture, ecart_caisse
                       FROM evenements
                       ORDER BY date_debut DESC")->fetchAll(PDO::FETCH_ASSOC);

// Evènement sélectionné (si filtre)
$selectedEvent = null;
if ($eventId !== null) {
  foreach ($events as $ev) {
    if ((int)$ev['id'] === $eventId) { $selectedEvent = $ev; break; }
  }
  if (!$selectedEvent) $eventId = null; // ID inconnu => fallback all
}

// Helpers filtres SQL
$where = "";
$params = [];
if ($eventId !== null) {
  $where = "WHERE v.evenement_id = ?";
  $params[] = $eventId;
}

// KPI - ventes
$sqlKpi = "
  SELECT
    COUNT(*) AS nb_sales,
    COALESCE(SUM(v.total),0) AS total_sales,
    COALESCE(MAX(v.total),0) AS max_sale
  FROM ventes v
  " . ($where ? $where : "");
$stmt = $pdo->prepare($sqlKpi);
$stmt->execute($params);
$kpi = $stmt->fetch(PDO::FETCH_ASSOC) ?: ['nb_sales'=>0,'total_sales'=>0,'max_sale'=>0];

$nbSales    = (int)$kpi['nb_sales'];
$totalSales = (float)$kpi['total_sales'];
$maxSale    = (float)$kpi['max_sale'];
$avgBasket  = ($nbSales > 0) ? ($totalSales / $nbSales) : 0.0;

// KPI - paiements (total encaissé + par méthode)
$sqlPay = "
  SELECT vp.methode, COALESCE(SUM(vp.montant),0) AS s
  FROM vente_paiements vp
  JOIN ventes v ON v.id = vp.vente_id
  " . ($where ? $where : "") . "
  GROUP BY vp.methode
";
$stmt = $pdo->prepare($sqlPay);
$stmt->execute($params);
$payRows = $stmt->fetchAll(PDO::FETCH_ASSOC);

$payTotals = ['CB'=>0.0,'Especes'=>0.0,'Cheque'=>0.0];
foreach ($payRows as $r) {
  $m = (string)$r['methode'];
  if (!isset($payTotals[$m])) $payTotals[$m] = 0.0;
  $payTotals[$m] = (float)$r['s'];
}
$totalPaid = ($payTotals['CB'] ?? 0) + ($payTotals['Especes'] ?? 0) + ($payTotals['Cheque'] ?? 0);

// Dons (produit "Don libre")
$sqlDon = "
  SELECT COALESCE(SUM(d.quantite * d.prix),0) AS dons_total
  FROM vente_details d
  JOIN produits p ON p.id = d.produit_id
  JOIN ventes v ON v.id = d.vente_id
  " . ($where ? $where : "") . "
  AND p.nom = 'Don libre'
";
if (!$where) {
  // Pas de WHERE initial => on doit mettre WHERE avant AND
  $sqlDon = "
    SELECT COALESCE(SUM(d.quantite * d.prix),0) AS dons_total
    FROM vente_details d
    JOIN produits p ON p.id = d.produit_id
    JOIN ventes v ON v.id = d.vente_id
    WHERE p.nom = 'Don libre'
  ";
}
$stmt = $pdo->prepare($sqlDon);
$stmt->execute($params);
$donTotal = (float)$stmt->fetchColumn();

// Top produits (hors Don libre)
$sqlTop = "
  SELECT
    p.id AS produit_id,
    p.nom AS produit_nom,
    COALESCE(SUM(d.quantite),0) AS qte,
    COALESCE(SUM(d.quantite * d.prix),0) AS ca
  FROM vente_details d
  JOIN produits p ON p.id = d.produit_id
  JOIN ventes v ON v.id = d.vente_id
";
if ($where) {
  $sqlTop .= " $where AND p.nom <> 'Don libre' ";
} else {
  $sqlTop .= " WHERE p.nom <> 'Don libre' ";
}
$sqlTop .= "
  GROUP BY p.id, p.nom
  HAVING (qte > 0 OR ca > 0)
";
$stmt = $pdo->prepare($sqlTop);
$stmt->execute($params);
$topProducts = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Prépare deux tris
$topByQty = $topProducts;
usort($topByQty, fn($a,$b)=> (int)$b['qte'] <=> (int)$a['qte']);

$topByCa = $topProducts;
usort($topByCa, fn($a,$b)=> (float)$b['ca'] <=> (float)$a['ca']);

// Export CSV
$export = $_GET['export'] ?? null;
if ($export === 'top_qte' || $export === 'top_ca') {
  $rows = ($export === 'top_qte') ? $topByQty : $topByCa;

  $evSlug = 'tous-evenements';
  if ($selectedEvent) {
    $evSlug = preg_replace('~[^a-z0-9]+~','-', strtolower(trim((string)$selectedEvent['nom'])));
    $evSlug = trim($evSlug,'-') ?: ('event-'.$eventId);
  }
  $fname = ($export === 'top_qte' ? 'top_produits_qte_' : 'top_produits_ca_') . $evSlug . '.csv';

  header('Content-Type: text/csv; charset=utf-8');
  header('Content-Disposition: attachment; filename="'.$fname.'"');
  $out = fopen('php://output', 'w');
  fputcsv($out, ['produit','quantite','ca','prix_moyen']);
  foreach ($rows as $r) {
    $q = (int)$r['qte'];
    $ca = (float)$r['ca'];
    $pm = ($q > 0) ? ($ca / $q) : 0.0;
    fputcsv($out, [(string)$r['produit_nom'], $q, number_format($ca,2,'.',''), number_format($pm,2,'.','')]);
  }
  fclose($out);
  exit;
}

// Quand ça vend
// - event sélectionné => tranches 30 min
// - sinon => 1h
if ($eventId !== null) {
  $slotExpr = "CONCAT(DATE_FORMAT(v.date_vente,'%Y-%m-%d %H:'), LPAD(FLOOR(MINUTE(v.date_vente)/30)*30,2,'0'), ':00')";
} else {
  $slotExpr = "DATE_FORMAT(v.date_vente,'%Y-%m-%d %H:00:00')";
}

$sqlWhen = "
  SELECT $slotExpr AS slot, COUNT(*) AS nb
  FROM ventes v
  " . ($where ? $where : "") . "
  GROUP BY slot
  ORDER BY nb DESC
  LIMIT 10
";
$stmt = $pdo->prepare($sqlWhen);
$stmt->execute($params);
$whenRows = $stmt->fetchAll(PDO::FETCH_ASSOC);

$maxWhen = null; $minWhen = null;
if ($whenRows) {
  $maxWhen = (int)$whenRows[0]['nb'];
  $minWhen = (int)$whenRows[count($whenRows)-1]['nb'];
}

// Infos évènement (période / statut)
$eventMeta = [
  'label' => 'Tous évènements',
  'period'=> '',
  'status'=> '',
  'is_active' => false,
];
if ($selectedEvent) {
  $eventMeta['label'] = (string)$selectedEvent['nom'];
  $d1 = $selectedEvent['date_debut'] ? date('d/m/Y H:i', strtotime($selectedEvent['date_debut'])) : '';
  $d2 = $selectedEvent['date_fin']   ? date('d/m/Y H:i', strtotime($selectedEvent['date_fin']))   : '—';
  $eventMeta['period'] = $d1 . " → " . $d2;
  $isActive = ((int)$selectedEvent['actif'] === 1);
  $eventMeta['is_active'] = $isActive;
  $eventMeta['status'] = $isActive ? 'Actif' : 'Clôturé';
}

// Clôture (si event sélectionné)
$close = null;
if ($selectedEvent) {
  $close = [
    'date_fin' => $selectedEvent['date_fin'] ?: null,
    'fond_reel'=> $selectedEvent['fond_caisse_cloture'] ?? null,
    'ecart'    => $selectedEvent['ecart_caisse'] ?? null,
  ];
}
?>
<!doctype html>
<html lang="fr">
<head>
  <meta charset="utf-8"/>
  <meta name="viewport" content="width=device-width,initial-scale=1"/>
  <title>Dashboard – Mini Caisse</title>
  <link rel="stylesheet" href="assets/css/dashboard.css?v=1">
</head>
<body class="page-dashboard">

<?php require_once __DIR__ . '/../shared/suite_nav.php'; ?>
<div class="dashboard-container">
<?php include 'nav.php'; ?>
<?php include 'admin_modal.php'; ?>

<div class="app">

  <div class="filters-wrap">
    <div class="card">
      <form class="filters" method="get" action="dashboard.php">
        <div class="field">
          <div class="filters-label">Évènement</div>
          <select name="event" class="select-control" onchange="this.form.submit()">
            <option value="all" <?= ($eventId===null ? 'selected' : '') ?>>Tous évènements</option>
            <?php foreach($events as $ev): ?>
              <option value="<?= (int)$ev['id'] ?>" <?= ($eventId!==null && (int)$ev['id']===$eventId ? 'selected' : '') ?>>
                <?= h($ev['nom']) ?>
              </option>
            <?php endforeach; ?>
          </select>

          <div class="meta-line">
            <?php if($selectedEvent): ?>
              <span class="badge <?= $eventMeta['is_active'] ? 'ok' : 'ended' ?>">
                <?= $eventMeta['is_active'] ? '🟢 Actif' : '⚪️ Clôturé' ?>
              </span>
              <span class="badge neutral">📅 <?= h($eventMeta['period']) ?></span>
            <?php else: ?>
              <span class="badge neutral">📌 Vue globale</span>
            <?php endif; ?>
          </div>
        </div>

        <div class="actions">
          <?php
            $baseQs = ['event' => ($eventId===null ? 'all' : (string)$eventId)];
          ?>
          <a class="btn small ghost" href="dashboard.php?event=all">Réinitialiser</a>

          <a class="btn small" href="dashboard.php?<?= http_build_query($baseQs + ['export'=>'top_qte']) ?>">⬇️ Export Qté (CSV)</a>
          <a class="btn small" href="dashboard.php?<?= http_build_query($baseQs + ['export'=>'top_ca']) ?>">⬇️ Export CA (CSV)</a>
        </div>
      </form>
    </div>
  </div>

  <div class="kpis">
    <div class="card kpi">
      <div class="label">Total encaissé (paiements)</div>
      <div class="value"><?= money($totalPaid) ?> €</div>
      <div class="sub">CB + Espèces + Chèques</div>
    </div>
    <div class="card kpi">
      <div class="label">Nombre de ventes</div>
      <div class="value"><?= (int)$nbSales ?></div>
      <div class="sub">sur la sélection</div>
    </div>
    <div class="card kpi">
      <div class="label">Panier moyen</div>
      <div class="value"><?= money($avgBasket) ?> €</div>
      <div class="sub">total ventes / nb ventes</div>
    </div>
    <div class="card kpi">
      <div class="label">Total ventes (ventes.total)</div>
      <div class="value"><?= money($totalSales) ?> €</div>
      <div class="sub">référence “produits”, utile si écart</div>
    </div>
  </div>

  <div class="grid2">
    <div class="card pad">
      <div class="top-head">
        <div class="top-title">🥇 Produits les plus vendus</div>

        <div class="top-controls">
          <button type="button" class="btn small switch active" id="switch-qty" onclick="switchTopMode('qty')">Qté</button>
          <button type="button" class="btn small switch" id="switch-ca" onclick="switchTopMode('ca')">CA</button>
          <button type="button" class="btn small ghost" id="toggle-limit" onclick="toggleLimit()">Afficher tout</button>
        </div>
      </div>

      <div class="don-box">
        <div>
          <strong>🎁 Dons (Don libre)</strong>
          <div class="muted" style="font-size:12px;">Affiché séparément (ne fausse pas le top produits)</div>
        </div>
        <div class="don-amount"><?= money($donTotal) ?> €</div>
      </div>

      <div class="muted" style="font-size:12px; margin-bottom:10px;">
        Prix moyen = CA / quantité. (Basé sur les prix enregistrés en vente.)
      </div>

      <div style="overflow:auto;">
        <table id="top-table-qty">
          <thead>
            <tr>
              <th>Produit</th>
              <th class="num">Quantité</th>
              <th class="num">CA</th>
              <th class="num">Prix moyen</th>
            </tr>
          </thead>
          <tbody>
          <?php if(!$topByQty): ?>
            <tr><td colspan="4" class="muted">Aucune donnée.</td></tr>
          <?php else: ?>
            <?php foreach($topByQty as $i => $r):
              $q = (int)$r['qte'];
              $ca = (float)$r['ca'];
              $pm = ($q>0) ? $ca/$q : 0.0;
              $hide = ($i >= 10) ? 'row-hidden' : '';
            ?>
              <tr class="<?= $hide ?>">
                <td><?= h($r['produit_nom']) ?></td>
                <td class="num"><?= $q ?></td>
                <td class="num"><?= money($ca) ?> €</td>
                <td class="num"><?= money($pm) ?> €</td>
              </tr>
            <?php endforeach; ?>
          <?php endif; ?>
          </tbody>
        </table>

        <table id="top-table-ca" style="display:none;">
          <thead>
            <tr>
              <th>Produit</th>
              <th class="num">CA</th>
              <th class="num">Quantité</th>
              <th class="num">Prix moyen</th>
            </tr>
          </thead>
          <tbody>
          <?php if(!$topByCa): ?>
            <tr><td colspan="4" class="muted">Aucune donnée.</td></tr>
          <?php else: ?>
            <?php foreach($topByCa as $i => $r):
              $q = (int)$r['qte'];
              $ca = (float)$r['ca'];
              $pm = ($q>0) ? $ca/$q : 0.0;
              $hide = ($i >= 10) ? 'row-hidden' : '';
            ?>
              <tr class="<?= $hide ?>">
                <td><?= h($r['produit_nom']) ?></td>
                <td class="num"><?= money($ca) ?> €</td>
                <td class="num"><?= $q ?></td>
                <td class="num"><?= money($pm) ?> €</td>
              </tr>
            <?php endforeach; ?>
          <?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>

    <div class="card pad">
      <div class="top-title" style="margin-bottom:10px;">⏱️ Quand ça vend</div>
      <div class="muted" style="font-size:12px; margin-bottom:10px;">
        <?= $eventId !== null ? 'Tranches de 30 minutes (évènement sélectionné).' : 'Tranches d’une heure (vue globale).' ?>
      </div>

      <?php if(!$whenRows): ?>
        <div class="muted">Aucune vente.</div>
      <?php else: ?>
        <?php foreach($whenRows as $wr):
          $slot = (string)$wr['slot'];
          $nb = (int)$wr['nb'];
          $label = date('d/m H\hi', strtotime($slot));
          if ($eventId === null) $label = date('d/m H\h', strtotime($slot));
          $tag = '';
          if ($maxWhen !== null && $nb === $maxWhen) $tag = '🔥';
          if ($minWhen !== null && $nb === $minWhen) $tag = '💤';
        ?>
          <div class="when-row">
            <div><?= h($label) ?></div>
            <div><span class="pill"><?= $nb ?> vente<?= $nb>1?'s':'' ?></span> <?= $tag ?></div>
          </div>
        <?php endforeach; ?>
      <?php endif; ?>
    </div>
  </div>

  <?php if($selectedEvent): ?>
    <div class="card pad" style="margin-top:12px;">
      <div class="top-title" style="margin-bottom:10px;">📄 Clôture & récapitulatif</div>

      <div class="close-box">
        <div class="line">
          <div class="muted">Statut</div>
          <div><span class="badge <?= $eventMeta['is_active'] ? 'ok' : 'ended' ?>"><?= $eventMeta['is_active'] ? 'Actif' : 'Clôturé' ?></span></div>
        </div>
        <div class="line">
          <div class="muted">Date de clôture</div>
          <div><?= $selectedEvent['date_fin'] ? h(date('d/m/Y H:i', strtotime($selectedEvent['date_fin']))) : '—' ?></div>
        </div>
        <div class="line">
          <div class="muted">Fond réel compté</div>
          <div><?= ($selectedEvent['fond_caisse_cloture'] !== null ? money((float)$selectedEvent['fond_caisse_cloture']).' €' : '—') ?></div>
        </div>
        <div class="line">
          <div class="muted">Écart caisse</div>
          <?php
            $ec = $selectedEvent['ecart_caisse'];
            $ecv = ($ec === null ? null : (float)$ec);
            $cls = 'warn';
            if ($ecv === null) $cls = '';
            elseif (abs($ecv) < 0.005) $cls = 'ok';
            elseif (abs($ecv) >= 10) $cls = 'bad';
          ?>
          <div class="<?= $cls ? 'delta '.$cls : '' ?>">
            <?= ($ecv === null ? '—' : (money($ecv).' €')) ?>
          </div>
        </div>
        <div class="line">
          <div class="muted">PDF récap</div>
          <div>
            <a class="btn small primary" target="_blank" rel="noopener"
               href="cloture_recap.php?event_id=<?= (int)$selectedEvent['id'] ?>">Ouvrir le PDF</a>
          </div>
        </div>
      </div>

      <div class="muted" style="font-size:12px; margin-top:10px;">
        Astuce : si tu vois un écart, compare “Total ventes” et “Total encaissé”.
      </div>
    </div>
  <?php endif; ?>

</div>

<button id="backToTop" class="btn small">⬆︎ Haut</button>
</div>
<script>
  let topMode = 'qty';
  let showAll = false;

  function switchTopMode(mode){
    topMode = mode;
    const tQty = document.getElementById('top-table-qty');
    const tCa  = document.getElementById('top-table-ca');
    const bQty = document.getElementById('switch-qty');
    const bCa  = document.getElementById('switch-ca');

    if(mode === 'ca'){
      tQty.style.display = 'none';
      tCa.style.display = '';
      bQty.classList.remove('active');
      bCa.classList.add('active');
    }else{
      tCa.style.display = 'none';
      tQty.style.display = '';
      bCa.classList.remove('active');
      bQty.classList.add('active');
    }
    applyLimit();
  }

  function toggleLimit(){
    showAll = !showAll;
    document.getElementById('toggle-limit').textContent = showAll ? 'Top 10' : 'Afficher tout';
    applyLimit();
  }

  function applyLimit(){
    const table = (topMode === 'ca') ? document.getElementById('top-table-ca') : document.getElementById('top-table-qty');
    const rows = table.querySelectorAll('tbody tr');
    let idx = 0;
    rows.forEach(r => {
      // ignore row "Aucune donnée"
      if (r.children.length <= 1) return;
      if (!showAll && idx >= 10) r.classList.add('row-hidden');
      else r.classList.remove('row-hidden');
      idx++;
    });
  }

  // Back to top
  const btt = document.getElementById('backToTop');
  window.addEventListener('scroll', () => {
    if(window.scrollY > 400) btt.style.display = 'inline-flex';
    else btt.style.display = 'none';
  });
  btt.addEventListener('click', () => window.scrollTo({top:0, behavior:'smooth'}));
</script>

</body>
</html>
