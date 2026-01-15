<?php
require 'config.php';

$saleId = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($saleId <= 0) {
  http_response_code(400);
  echo "ID reçu invalide.";
  exit;
}

/**
 * 1) Vente + évènement + bénévole
 */
$stmt = $pdo->prepare("
  SELECT
    v.id,
    v.date_vente,
    v.total,
    v.sous_total,
    v.total_brut,
    v.remise_total,
    v.remise_panier_type,
    v.remise_panier_valeur,
    v.remise_panier_montant,
    e.nom AS event_nom,
    e.fond_caisse,
    b.nom AS benevole_nom
  FROM ventes v
  JOIN evenements e ON e.id = v.evenement_id
  LEFT JOIN benevoles b ON b.id = v.benevole_id
  WHERE v.id = ?
  LIMIT 1
");
$stmt->execute([$saleId]);
$sale = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$sale) {
  http_response_code(404);
  echo "Vente introuvable.";
  exit;
}

/**
 * 2) Détails produits
 */
$stmt = $pdo->prepare("
  SELECT
    p.nom AS produit_nom,
    d.quantite,
    d.prix,
    d.prix_origine,
    d.prix_brut,
    d.prix_final,
    d.remise,
    d.remise_panier_part,
    d.remise_ligne_type,
    d.remise_ligne_valeur
  FROM vente_details d
  JOIN produits p ON p.id = d.produit_id
  WHERE d.vente_id = ?
  ORDER BY p.nom ASC
");
$stmt->execute([$saleId]);
$items = $stmt->fetchAll(PDO::FETCH_ASSOC);

/**
 * 3) Paiements (groupés)
 */
$stmt = $pdo->prepare("
  SELECT methode, SUM(montant) AS montant
  FROM vente_paiements
  WHERE vente_id = ?
  GROUP BY methode
  ORDER BY FIELD(methode,'Especes','CB','Cheque'), methode
");
$stmt->execute([$saleId]);
$payments = $stmt->fetchAll(PDO::FETCH_ASSOC);

/**
 * Calculs
 */
$total = (float)$sale['total'];
$totalBrut = isset($sale['total_brut']) && $sale['total_brut'] !== null ? (float)$sale['total_brut'] : null;
$remiseTotal = isset($sale['remise_total']) && $sale['remise_total'] !== null ? (float)$sale['remise_total'] : null;
$remisePanierMontant = isset($sale['remise_panier_montant']) && $sale['remise_panier_montant'] !== null ? (float)$sale['remise_panier_montant'] : 0.0;
$sousTotal = isset($sale['sous_total']) && $sale['sous_total'] !== null ? (float)$sale['sous_total'] : null;

// Fallbacks si la vente est ancienne / champs non remplis
if ($totalBrut === null || $remiseTotal === null || $sousTotal === null) {
  $calcBrut = 0.0;
  foreach ($items as $it) {
    $q = (int)$it['quantite'];
    $puOrig = $it['prix_origine'] !== null ? (float)$it['prix_origine'] : (float)$it['prix'];
    $calcBrut += $q * $puOrig;
  }
  if ($totalBrut === null) $totalBrut = $calcBrut;
  if ($remiseTotal === null) $remiseTotal = max($totalBrut - $total, 0.0);
  if ($sousTotal === null) $sousTotal = max($total - 0.0, 0.0); // on n'a pas mieux sans logique métier
}

$totalPaid = 0.0;
foreach ($payments as $p) {
  $totalPaid += (float)$p['montant'];
}

// Sur-couverture possible (espèces > total)
$overpay = max($totalPaid - $total, 0);

/**
 * Option : impression auto si ?print=1
 */
$autoPrint = (isset($_GET['print']) && $_GET['print'] == '1');

function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function eur($n){ return number_format((float)$n, 2, ',', ' ') . " €"; }

?>
<!doctype html>
<html lang="fr">
<head>
  <meta charset="utf-8"/>
  <meta name="viewport" content="width=device-width,initial-scale=1"/>
  <title>Reçu #<?= (int)$sale['id'] ?> – Mini Caisse</title>
  <link rel="stylesheet" href="assets/css/recu.css?v=2">
</head>
<body class="recu-page">

<?php require_once __DIR__ . '/../shared/suite_nav.php'; ?>

  <div class="recu-toolbar no-print">
    <a class="btn" href="evenements.php">← Retour</a>
    <button class="btn btn-primary" onclick="window.print()">Imprimer / PDF</button>
  </div>

  <main class="ticket" role="main">
    <header class="ticket-header">
      <div class="ticket-title">REÇU</div>
      <div class="ticket-meta">
        <div><strong>Vente</strong> #<?= (int)$sale['id'] ?></div>
        <div><strong>Date</strong> <?= h($sale['date_vente']) ?></div>
        <div><strong>Évènement</strong> <?= h($sale['event_nom']) ?></div>
        <div><strong>Bénévole</strong> <?= h($sale['benevole_nom'] ?: 'Global / non renseigné') ?></div>
      </div>
    </header>

    <section class="ticket-section">
      <div class="section-title">Articles</div>

      <?php if (!$items): ?>
        <div class="muted">Aucun détail produit (vente sans ligne).</div>
      <?php else: ?>
        <div class="lines">
          <?php foreach ($items as $it):
            $q = (int)$it['quantite'];

            // Prix unitaire : on privilégie les champs de traçage si présents
            $puOrigin = isset($it['prix_origine']) && $it['prix_origine'] !== null ? (float)$it['prix_origine'] : (float)$it['prix'];
            $puFinal  = isset($it['prix_final'])   && $it['prix_final']   !== null ? (float)$it['prix_final']   : (float)$it['prix'];

            $lineOrigin = $q * $puOrigin;
            $lineFinal  = $q * $puFinal;

            $remise = isset($it['remise']) && $it['remise'] !== null
              ? (float)$it['remise']
              : max($lineOrigin - $lineFinal, 0.0);

            $remisePanierPart = isset($it['remise_panier_part']) ? (float)$it['remise_panier_part'] : 0.0;
          ?>
            <div class="line">
              <div class="line-left">
                <div class="name"><?= h($it['produit_nom']) ?></div>

                <?php if ($remise > 0.009 && $puOrigin > 0.0 && abs($puOrigin - $puFinal) > 0.004): ?>
                  <div class="sub muted">
                    <?= $q ?> ×
                    <span class="price-old"><?= eur($puOrigin) ?></span>
                    <span class="price-new"><?= eur($puFinal) ?></span>
                  </div>
                  <div class="sub muted discount-note">
                    remise <?= eur(-$remise) ?>
                    <?php if ($remisePanierPart > 0.009): ?>
                      <span class="muted">(dont panier <?= eur(-$remisePanierPart) ?>)</span>
                    <?php endif; ?>
                  </div>
                <?php else: ?>
                  <div class="sub muted"><?= $q ?> × <?= eur($puFinal) ?></div>
                <?php endif; ?>
              </div>

              <div class="line-right">
                <?php if ($remise > 0.009 && $lineOrigin > 0.0 && abs($lineOrigin - $lineFinal) > 0.004): ?>
                  <div class="amounts">
                    <div class="amount-old"><?= eur($lineOrigin) ?></div>
                    <div class="amount-new"><?= eur($lineFinal) ?></div>
                  </div>
                <?php else: ?>
                  <?= eur($lineFinal) ?>
                <?php endif; ?>
              </div>
            </div>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    </section>

    <section class="ticket-section">
      <div class="section-title">Paiements</div>

      <?php if (!$payments): ?>
        <div class="muted">—</div>
      <?php else: ?>
        <div class="pay-list">
          <?php foreach ($payments as $p):
            $m = $p['methode'];
            $amt = (float)$p['montant'];

            // jolis labels
            $label = $m;
            if ($m === 'Especes') $label = 'Espèces';
            if ($m === 'CB')      $label = 'CB';
            if ($m === 'Cheque')  $label = 'Chèque';
          ?>
            <div class="pay-row">
              <div class="pay-method"><?= h($label) ?></div>
              <div class="pay-amount"><?= eur($amt) ?></div>
            </div>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    </section>

    <section class="ticket-section totals">
      <?php
        $totalFinal = (float)$sale['total'];

        // Totaux tracés si dispo
        $totalBrut = isset($sale['total_brut']) && $sale['total_brut'] !== null ? (float)$sale['total_brut'] : null;
        $remiseTotal = isset($sale['remise_total']) && $sale['remise_total'] !== null ? (float)$sale['remise_total'] : null;
        $remisePanierMontant = isset($sale['remise_panier_montant']) ? (float)$sale['remise_panier_montant'] : 0.0;
      ?>

      <div class="total-row">
        <div>Total</div>
        <div class="strong">
          <?php if ($totalBrut !== null && $remiseTotal !== null && $remiseTotal > 0.009 && abs($totalBrut - $totalFinal) > 0.004): ?>
            <span class="price-old"><?= eur($totalBrut) ?></span>
            <span class="price-new"><?= eur($totalFinal) ?></span>
          <?php else: ?>
            <?= eur($totalFinal) ?>
          <?php endif; ?>
        </div>
      </div>

      <?php if ($remisePanierMontant > 0.009): ?>
        <div class="total-row">
          <div class="muted">Remise panier</div>
          <div class="muted"><?= eur(-$remisePanierMontant) ?></div>
        </div>
      <?php endif; ?>

      <?php if ($remiseTotal !== null && $remiseTotal > 0.009): ?>
        <div class="total-row">
          <div class="muted">Remise totale</div>
          <div class="muted"><?= eur(-$remiseTotal) ?></div>
        </div>
      <?php endif; ?>

      <?php if ($overpay > 0.009): ?>
        <div class="total-row">
          <div>Monnaie à rendre (info)</div>
          <div><?= eur($overpay) ?></div>
        </div>
      <?php endif; ?>
    </section>

    <footer class="ticket-footer">
      <div class="thanks">Merci ❤️</div>
      <div class="muted small">
        Reçu généré par Mini Caisse — impression compatible PDF.
      </div>
    </footer>
  </main>

  <?php if ($autoPrint): ?>
    <script>
      window.addEventListener('load', () => {
        setTimeout(() => window.print(), 200);
      });
    </script>
  <?php endif; ?>

</body>
</html>