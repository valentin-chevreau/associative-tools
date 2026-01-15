<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require 'config.php';
$page = 'evenements';

$adminError = '';
$adminSuccess = '';

// -------------------------
// Création d'un nouvel évènement (admin seulement)
// -------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['create_event']) && is_admin()) {
    $nom       = trim($_POST['nom'] ?? '');
    $fond      = trim($_POST['fond_caisse'] ?? '');
    $fondFloat = $fond !== '' ? (float)$fond : 0.0;

    if ($nom === '') {
        $adminError = "Le nom de l’évènement est obligatoire.";
    } else {
        try {
            $pdo->exec("UPDATE evenements SET actif = 0 WHERE actif = 1");
            $stmt = $pdo->prepare("
                INSERT INTO evenements (nom, date_debut, date_fin, fond_caisse, actif)
                VALUES (?, NOW(), NULL, ?, 1)
            ");
            $stmt->execute([$nom, $fondFloat]);

            $adminSuccess = "Nouvel évènement créé.";
            header("Location: evenements.php?ok=1");
            exit;
        } catch (Exception $e) {
            $adminError = "Erreur lors de la création de l’évènement.";
        }
    }
}

// -------------------------
// Clôture d'un évènement (admin uniquement)
// -------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['close_event_id']) && is_admin()) {
    $eventId = (int)$_POST['close_event_id'];
    try {
        $stmt = $pdo->prepare("UPDATE evenements SET actif = 0, date_fin = NOW() WHERE id = ?");
        $stmt->execute([$eventId]);
        $adminSuccess = "Évènement clôturé.";
        header("Location: evenements.php?ok=1");
        exit;
    } catch (Exception $e) {
        $adminError = "Erreur lors de la clôture de l’évènement.";
    }
}

// -------------------------
// Suppression d'un évènement + ventes associées (admin uniquement)
// -------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_event_id']) && is_admin()) {
    $eventId = (int)$_POST['delete_event_id'];

    try {
        // 🔒 Interdire la suppression si l'évènement est déjà clôturé
        $stmt = $pdo->prepare("SELECT date_fin FROM evenements WHERE id = ? LIMIT 1");
        $stmt->execute([$eventId]);
        $dateFin = $stmt->fetchColumn();

        if ($dateFin !== null && $dateFin !== false) {
            $adminError = "Impossible de supprimer un évènement déjà clôturé.";
        } else {
            $stmt = $pdo->prepare("SELECT id FROM ventes WHERE evenement_id = ?");
            $stmt->execute([$eventId]);
            $venteIds = $stmt->fetchAll(PDO::FETCH_COLUMN);

            if (!empty($venteIds)) {
                $in = implode(',', array_map('intval', $venteIds));
                $pdo->exec("DELETE FROM vente_paiements WHERE vente_id IN ($in)");
                $pdo->exec("DELETE FROM vente_details   WHERE vente_id IN ($in)");
                $pdo->exec("DELETE FROM ventes          WHERE id IN ($in)");
            }

            $stmt = $pdo->prepare("DELETE FROM evenements WHERE id = ?");
            $stmt->execute([$eventId]);

            $adminSuccess = "Évènement et ventes associées supprimés.";
            header("Location: evenements.php?ok=1");
            exit;
        }
    } catch (Exception $e) {
        $adminError = "Erreur lors de la suppression de l’évènement.";
    }
}

// -------------------------
// Suppression d'une vente (admin uniquement)
// -------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_sale_id']) && is_admin()) {
    $saleId = (int)$_POST['delete_sale_id'];

    try {
        // 🔒 Interdire suppression si clôturé
        $stmt = $pdo->prepare("
            SELECT e.date_fin
            FROM ventes v
            JOIN evenements e ON v.evenement_id = e.id
            WHERE v.id = ?
            LIMIT 1
        ");
        $stmt->execute([$saleId]);
        $dateFin = $stmt->fetchColumn();

        if ($dateFin !== null) {
            $adminError = "Impossible de supprimer une vente d’un évènement clôturé.";
        } else {
            $pdo->prepare("DELETE FROM vente_paiements WHERE vente_id = ?")->execute([$saleId]);
            $pdo->prepare("DELETE FROM vente_details   WHERE vente_id = ?")->execute([$saleId]);
            $pdo->prepare("DELETE FROM ventes          WHERE id = ?")->execute([$saleId]);

            $adminSuccess = "Vente supprimée.";

            // retour propre sur la page courante + filtres
            $query = $_GET;
            unset($query['ok']);
            $qs = $query ? '?'.http_build_query($query) : '';
            $sep = ($qs === '') ? '?' : '&';
            header("Location: evenements.php".$qs.$sep."ok=1");
            exit;
        }
    } catch (Exception $e) {
        $adminError = "Erreur lors de la suppression de la vente.";
    }
}

// -------------------------
// Suppression d'une ligne de vente (admin uniquement)
// -------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_line_id']) && is_admin()) {
    $lineId = (int)$_POST['delete_line_id'];

    try {
        // 1) retrouver vente_id + total ligne + date_fin évènement
        $stmt = $pdo->prepare("
            SELECT d.vente_id,
                   (d.quantite * d.prix) AS line_total,
                   e.date_fin
            FROM vente_details d
            JOIN ventes v     ON d.vente_id = v.id
            JOIN evenements e ON v.evenement_id = e.id
            WHERE d.id = ?
            LIMIT 1
        ");
        $stmt->execute([$lineId]);
        $line = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$line) {
            throw new Exception("Ligne introuvable");
        }

        if ($line['date_fin'] !== null) {
            $adminError = "Impossible de modifier une vente d’un évènement clôturé.";
        } else {
            $venteId = (int)$line['vente_id'];

            // 2) supprimer la ligne
            $pdo->prepare("DELETE FROM vente_details WHERE id = ?")->execute([$lineId]);

            // 3) recalcul total de la vente depuis les détails restants
            $stmt = $pdo->prepare("SELECT COALESCE(SUM(quantite * prix), 0) AS new_total FROM vente_details WHERE vente_id = ?");
            $stmt->execute([$venteId]);
            $newTotal = (float)$stmt->fetchColumn();

            if ($newTotal <= 0.00001) {
                $pdo->prepare("DELETE FROM vente_paiements WHERE vente_id = ?")->execute([$venteId]);
                $pdo->prepare("DELETE FROM ventes WHERE id = ?")->execute([$venteId]);
                $adminSuccess = "Ligne supprimée. La vente n'ayant plus de produits, elle a été supprimée.";
            } else {
                $pdo->prepare("UPDATE ventes SET total = ? WHERE id = ?")->execute([$newTotal, $venteId]);
                $adminSuccess = "Ligne supprimée.";
            }

            // retour propre + filtres
            $query = $_GET;
            unset($query['ok']);
            $qs = $query ? '?'.http_build_query($query) : '';
            $sep = ($qs === '') ? '?' : '&';
            header("Location: evenements.php".$qs.$sep."ok=1");
            exit;
        }
    } catch (Exception $e) {
        $adminError = "Erreur lors de la suppression de la ligne.";
    }
}

if (isset($_GET['ok']) && !$adminSuccess) {
    $adminSuccess = "Action effectuée.";
}

// -------------------------
// Récap des évènements (totaux par mode de paiement)
// -------------------------
$sqlEvents = "
    SELECT 
        e.id,
        e.nom,
        e.date_debut,
        e.date_fin,
        e.fond_caisse,
        e.fond_caisse_cloture,
        e.ecart_caisse,
        e.actif,
        COALESCE(SUM(CASE WHEN vp.methode = 'CB'      THEN vp.montant ELSE 0 END), 0) AS total_cb,
        COALESCE(SUM(CASE WHEN vp.methode = 'Especes' THEN vp.montant ELSE 0 END), 0) AS total_especes,
        COALESCE(SUM(CASE WHEN vp.methode = 'Cheque'  THEN vp.montant ELSE 0 END), 0) AS total_cheques
    FROM evenements e
    LEFT JOIN ventes v ON v.evenement_id = e.id
    LEFT JOIN vente_paiements vp ON vp.vente_id = v.id
    GROUP BY 
        e.id, e.nom, e.date_debut, e.date_fin,
        e.fond_caisse, e.fond_caisse_cloture, e.ecart_caisse, e.actif
    ORDER BY e.date_debut DESC
";
$events = $pdo->query($sqlEvents)->fetchAll(PDO::FETCH_ASSOC);

// -------------------------
// Filtres historique détaillé
// -------------------------
$eventFilter = $_GET['event'] ?? 'all';
$benFilter   = $_GET['ben']   ?? 'all';
$payFilter   = $_GET['pay']   ?? 'all';
$from        = $_GET['from']  ?? '';
$to          = $_GET['to']    ?? '';

// -------------------------
// Historique détaillé : lignes produit
// -------------------------
$sql = "SELECT 
          v.id AS vente_id,
          v.date_vente,
          v.total,
          v.total_brut,
          v.remise_total,
          e.nom AS event_nom,
          b.nom AS ben_nom,
          d.id AS detail_id,
          d.quantite,
          d.prix,
          d.prix_origine,
          d.prix_brut,
          d.prix_final,
          d.remise,
          d.remise_panier_part,
          d.remise_ligne_type,
          d.remise_ligne_valeur,
          p.nom AS produit_nom
        FROM ventes v
        JOIN evenements e ON v.evenement_id = e.id
        LEFT JOIN benevoles b ON v.benevole_id = b.id
        JOIN vente_details d ON d.vente_id = v.id
        JOIN produits p ON p.id = d.produit_id
        WHERE 1=1";

$params = [];

if ($eventFilter !== 'all') {
    $sql .= " AND v.evenement_id = ?";
    $params[] = (int)$eventFilter;
}
if ($benFilter !== 'all') {
    $sql .= " AND v.benevole_id = ?";
    $params[] = (int)$benFilter;
}
if ($payFilter !== 'all') {
    $sql .= " AND EXISTS (
                SELECT 1
                FROM vente_paiements vp2
                WHERE vp2.vente_id = v.id
                  AND vp2.methode = ?
              )";
    $params[] = $payFilter;
}
if (!empty($from)) {
    $sql .= " AND v.date_vente >= ?";
    $params[] = $from . " 00:00:00";
}
if (!empty($to)) {
    $sql .= " AND v.date_vente <= ?";
    $params[] = $to . " 23:59:59";
}

$sql .= " ORDER BY v.date_vente DESC, v.id DESC, d.id ASC";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Paiements par vente
$paymentsBySale = [];

if ($rows) {
    $ids = array_unique(array_column($rows, 'vente_id'));
    if ($ids) {
        $idsInt = array_map('intval', $ids);
        $in     = implode(',', $idsInt);

        $sqlPay = "
            SELECT vente_id, methode, SUM(montant) AS s
            FROM vente_paiements
            WHERE vente_id IN ($in)
            GROUP BY vente_id, methode
        ";
        $payRows = $pdo->query($sqlPay)->fetchAll(PDO::FETCH_ASSOC);

        foreach ($payRows as $pr) {
            $vid = (int)$pr['vente_id'];
            $m   = (string)$pr['methode'];
            $s   = (float)$pr['s'];
            if (!isset($paymentsBySale[$vid])) $paymentsBySale[$vid] = [];
            if (!isset($paymentsBySale[$vid][$m])) $paymentsBySale[$vid][$m] = 0.0;
            $paymentsBySale[$vid][$m] += $s;
        }
    }
}

function formatPaymentsLabelHtml($paymentsBySale, $venteId) {
    if (!isset($paymentsBySale[$venteId]) || empty($paymentsBySale[$venteId])) {
        return '—';
    }
    $parts = [];
    foreach ($paymentsBySale[$venteId] as $method => $amount) {
        $methodSafe = htmlspecialchars($method, ENT_QUOTES, 'UTF-8');
        $parts[] = $methodSafe . ' ' . number_format((float)$amount, 2) . ' €';
    }
    return implode('<br>', $parts);
}

function formatPaymentsLabelText($paymentsBySale, $venteId) {
    if (!isset($paymentsBySale[$venteId]) || empty($paymentsBySale[$venteId])) {
        return '—';
    }
    $parts = [];
    foreach ($paymentsBySale[$venteId] as $method => $amount) {
        $parts[] = $method.' '.number_format((float)$amount, 2).' €';
    }
    return implode(' + ', $parts);
}

$eventsForFilter = $pdo->query("SELECT id, nom FROM evenements ORDER BY date_debut DESC")->fetchAll(PDO::FETCH_ASSOC);
$benevoles       = $pdo->query("SELECT id, nom FROM benevoles ORDER BY nom")->fetchAll(PDO::FETCH_ASSOC);

// Export CSV
if (isset($_GET['export']) && $_GET['export'] == '1') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="ventes.csv"');
    $out = fopen('php://output','w');
    fputcsv($out, ['vente_id','date','evenement','benevole','paiements','produit','quantite','prix_unitaire','total_vente']);

    foreach ($rows as $r) {
        $vid      = (int)$r['vente_id'];
        $payLabel = formatPaymentsLabelText($paymentsBySale, $vid);
        fputcsv($out, [
            $vid,
            $r['date_vente'],
            $r['event_nom'],
            $r['ben_nom'] ?: 'Global',
            $payLabel,
            $r['produit_nom'],
            (int)$r['quantite'],
            number_format((float)$r['prix'],2,'.',''),
            number_format((float)$r['total'],2,'.','')
        ]);
    }
    exit;
}

// Récap global paiements (selon filtres)
$sqlTotals = "
  SELECT vp.methode, SUM(vp.montant) AS total
  FROM ventes v
  JOIN vente_paiements vp ON vp.vente_id = v.id
  WHERE 1=1
";

$totParams = [];
if ($eventFilter !== 'all') { $sqlTotals .= " AND v.evenement_id = ?"; $totParams[] = (int)$eventFilter; }
if ($benFilter !== 'all')   { $sqlTotals .= " AND v.benevole_id = ?"; $totParams[] = (int)$benFilter; }
if (!empty($from))          { $sqlTotals .= " AND v.date_vente >= ?"; $totParams[] = $from . " 00:00:00"; }
if (!empty($to))            { $sqlTotals .= " AND v.date_vente <= ?"; $totParams[] = $to . " 23:59:59"; }
if ($payFilter !== 'all')   { $sqlTotals .= " AND vp.methode = ?";    $totParams[] = $payFilter; }

$sqlTotals .= " GROUP BY vp.methode";

$stmt = $pdo->prepare($sqlTotals);
$stmt->execute($totParams);
$totRows = $stmt->fetchAll(PDO::FETCH_ASSOC);

$totals = ['CB'=>0.0,'Especes'=>0.0,'Cheque'=>0.0];
foreach ($totRows as $tr) {
  $m = (string)$tr['methode'];
  $totals[$m] = (float)$tr['total'];
}
$grandTotal = ($totals['CB'] ?? 0) + ($totals['Especes'] ?? 0) + ($totals['Cheque'] ?? 0);

// Grouping des lignes par vente
$rowsBySale = [];
foreach ($rows as $r) {
    $vid = (int)$r['vente_id'];
    if (!isset($rowsBySale[$vid])) {
        $rowsBySale[$vid] = [
            'vente_id' => $vid,
            'date_vente' => $r['date_vente'],
            'event_nom' => $r['event_nom'],
            'ben_nom' => $r['ben_nom'] ?: 'Global',
            'total' => (float)$r['total'],
            'total_brut' => (float)($r['total_brut'] ?? 0),
            'remise_total' => (float)($r['remise_total'] ?? 0),
            'lines' => []
        ];
    }

    $qte = (int)$r['quantite'];
    $puFinal = $r['prix_final'] !== null ? (float)$r['prix_final'] : (float)$r['prix'];
    $puOrig  = $r['prix_origine'] !== null ? (float)$r['prix_origine'] : (float)$r['prix'];

    $rowsBySale[$vid]['lines'][] = [
        'detail_id' => (int)$r['detail_id'],
        'produit_nom' => $r['produit_nom'],
        'quantite' => $qte,
        'pu_orig' => $puOrig,
        'pu_final' => $puFinal,
        'remise_ligne_type' => (string)($r['remise_ligne_type'] ?? 'none'),
        'remise_ligne_valeur' => (float)($r['remise_ligne_valeur'] ?? 0),
        'remise_panier_part' => (float)($r['remise_panier_part'] ?? 0),
        'remise_total_ligne' => (float)($r['remise'] ?? 0),
        'line_total_orig' => $puOrig * $qte,
        'line_total_final' => $puFinal * $qte,
    ];
}

$isAdmin = is_admin();
?>
<!doctype html>
<html lang="fr">
<head>
<meta charset="utf-8"/>
<meta name="viewport" content="width=device-width,initial-scale=1"/>
<title>Évènements & Historique – Mini Caisse</title>

<link rel="stylesheet" href="assets/css/evenements.css?v=4">
<script src="assets/js/evenements.js?v=4" defer></script>
</head>
<body>

<?php require_once __DIR__ . '/../shared/suite_nav.php'; ?>

<?php include 'nav.php'; ?>
<?php include 'admin_modal.php'; ?>

<div class="app">

  <div class="card">
    <h2>📅 Évènements & Historique des ventes</h2>
    <p class="small">
      <a href="index.php" class="back-link">← Retour à la caisse</a>
    </p>

    <?php if($adminError): ?>
      <div class="alert error"><?= htmlspecialchars($adminError) ?></div>
    <?php endif; ?>
    <?php if($adminSuccess): ?>
      <div class="alert success"><?= htmlspecialchars($adminSuccess) ?></div>
    <?php endif; ?>

    <h3>Récap des évènements</h3>
    <p class="small">
      La <strong>caisse attendue</strong> = fond de caisse + total des espèces.<br>
      Le <strong>montant gagné</strong> = total des ventes (CB + espèces + chèques).
    </p>

    <div class="table-wrapper">
      <table>
        <thead>
                    <tr>
            <th>Nom</th>
            <th>Début</th>
            <th>Fin</th>
            <th>Fond</th>
            <th>CB</th>
            <th>Espèces</th>
            <th>Chèques</th>
            <th>Montant gagné</th>
            <th>Caisse attendue</th>
            <th>Fond caisse réel</th>
            <th>Écart caisse</th>
            <th>Statut</th>
            <?php if($isAdmin): ?>
              <th>Actions</th>
            <?php endif; ?>
          </tr>
        </thead>
        <tbody>
        <?php foreach($events as $ev):
            $fond      = (float)$ev['fond_caisse'];
            $totalCB   = (float)$ev['total_cb'];
            $totalEsp  = (float)$ev['total_especes'];
            $totalChq  = (float)$ev['total_cheques'];
            $gagne     = $totalCB + $totalEsp + $totalChq;
            $caisseAtt = $fond + $totalEsp;
            $isActive  = (int)$ev['actif'] === 1;
        ?>
          <tr>
            <td><?= htmlspecialchars($ev['nom']) ?></td>
            <td><?= htmlspecialchars($ev['date_debut']) ?></td>
            <td><?= htmlspecialchars($ev['date_fin'] ?: '—') ?></td>
            <td><?= number_format($fond,2) ?> €</td>
            <td><?= number_format($totalCB,2) ?> €</td>
            <td><?= number_format($totalEsp,2) ?> €</td>
            <td><?= number_format($totalChq,2) ?> €</td>
            <td><?= number_format($gagne,2) ?> €</td>
            <td><?= number_format($caisseAtt,2) ?> €</td>

            <?php
              $fondReel = ($ev['fond_caisse_cloture'] !== null) ? (float)$ev['fond_caisse_cloture'] : null;
              $ecart    = ($ev['ecart_caisse'] !== null) ? (float)$ev['ecart_caisse'] : null;

              // Classe couleur écart
              $ecartClass = 'ecart-na';
              if ($ecart !== null) {
                $abs = abs($ecart);
                if ($abs < 0.01)      $ecartClass = 'ecart-ok';
                elseif ($abs <= 1.00) $ecartClass = 'ecart-warn';
                else                  $ecartClass = 'ecart-bad';
              }

              $ecartLabel = '—';
              if ($ecart !== null) {
                $sign = ($ecart > 0.00001) ? '+' : '';
                $ecartLabel = $sign . number_format($ecart, 2) . ' €';
              }
            ?>

            <td><?= $fondReel === null ? '—' : (number_format($fondReel,2).' €') ?></td>
            <td><span class="ecart-pill <?= $ecartClass ?>"><?= htmlspecialchars($ecartLabel) ?></span></td>

            <td>
              <?php if($isActive): ?>
                <span class="badge">Actif</span>
              <?php else: ?>
                <span class="badge ended">Terminé</span>
              <?php endif; ?>
            </td>
            <?php if($isAdmin): ?>
              <td class="action-cell">
                <?php if($isActive): ?>
                  <?php
                    $fond      = (float)$ev['fond_caisse'];
                    $totalEsp  = (float)$ev['total_especes'];
                    $caisseAtt = $fond + $totalEsp; // ✅ fond attendu
                  ?>
                  <button
                    type="button"
                    class="btn secondary"
                    onclick="openCloseCaisseModal(<?= (int)$ev['id'] ?>, <?= number_format($caisseAtt, 2, '.', '') ?>)"
                  >
                    Clôturer la caisse
                  </button>
                <?php endif; ?>

                <form method="post" onsubmit="return confirm('Supprimer cet évènement et toutes ses ventes associées ?');">
                  <input type="hidden" name="delete_event_id" value="<?= (int)$ev['id'] ?>">
                  <button type="submit" class="btn danger btn-sm">Supprimer</button>
                </form>
                <a
                  href="cloture_recap.php?event_id=<?= (int)$ev['id'] ?>"
                  target="_blank"
                  class="btn-link"
                >Récapitualtif
              </a>
              </td>
            <?php endif; ?>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>

    <?php if ($isAdmin): ?>
      <form method="post" class="new-event-form">
        <input type="hidden" name="create_event" value="1">
        <span class="small form-title">Créer un nouvel évènement</span>
        <input type="text" name="nom" placeholder="Nom de l’évènement" required>
        <input type="number" name="fond_caisse" step="0.01" min="0" placeholder="Fond de caisse (€)">
        <button type="submit" class="btn">Créer l’évènement</button>
      </form>
    <?php else: ?>
      <p class="small" style="margin-top:8px;">
        Pour créer un nouvel évènement ou gérer la clôture/suppression, active le <strong>mode administrateur</strong> via la barre en haut de la page.
      </p>
    <?php endif; ?>

  </div>

  <div class="card">
    <h3>📊 Historique détaillé des ventes</h3>

    <form method="get" class="filters" id="history-filters-form">
      <select name="event" onchange="autoSubmitFilters()">
        <option value="all">Tous évènements</option>
        <?php foreach($eventsForFilter as $e): ?>
          <option value="<?= (int)$e['id'] ?>" <?= ($eventFilter==$e['id'])?'selected':'' ?>>
            <?= htmlspecialchars($e['nom']) ?>
          </option>
        <?php endforeach; ?>
      </select>

      <select name="ben" onchange="autoSubmitFilters()">
        <option value="all">Tous bénévoles</option>
        <?php foreach($benevoles as $b): ?>
          <option value="<?= (int)$b['id'] ?>" <?= ($benFilter==$b['id'])?'selected':'' ?>>
            <?= htmlspecialchars($b['nom']) ?>
          </option>
        <?php endforeach; ?>
      </select>

      <select name="pay" onchange="autoSubmitFilters()">
        <option value="all">Tous paiements</option>
        <option value="CB"      <?= ($payFilter==='CB')?'selected':'' ?>>CB</option>
        <option value="Especes" <?= ($payFilter==='Especes')?'selected':'' ?>>Espèces</option>
        <option value="Cheque"  <?= ($payFilter==='Cheque')?'selected':'' ?>>Chèque</option>
      </select>

      <input type="date" name="from" value="<?= htmlspecialchars($from) ?>" onchange="autoSubmitFilters()">
      <input type="date" name="to"   value="<?= htmlspecialchars($to)   ?>" onchange="autoSubmitFilters()">

      <button type="submit" name="export" value="1" class="btn secondary">Export CSV</button>
    </form>

    <p class="small">
      Pour supprimer une vente ou une ligne de vente, active le <strong>mode administrateur</strong> via la barre en haut de la page.
    </p>

    <div class="pay-summary">
      <div class="pay-summary-title">Récap paiements (selon filtres)</div>
      <div class="pay-summary-grid">
        <div class="pay-sum-card"><span>CB</span><strong><?= number_format((float)($totals['CB'] ?? 0),2) ?> €</strong></div>
        <div class="pay-sum-card"><span>Espèces</span><strong><?= number_format((float)($totals['Especes'] ?? 0),2) ?> €</strong></div>
        <div class="pay-sum-card"><span>Chèque</span><strong><?= number_format((float)($totals['Cheque'] ?? 0),2) ?> €</strong></div>
        <div class="pay-sum-card total"><span>Total</span><strong><?= number_format((float)$grandTotal,2) ?> €</strong></div>
      </div>
    </div>

    <div class="table-wrapper">
      <table class="history-table">
        <thead>
          <tr>
            <th class="col-vente">Vente</th>
            <th class="col-pay">Paiements</th>
            <th>Produit</th>
            <th class="col-qte">Qté</th>
            <th class="col-pu">Prix unit.</th>
            <th class="col-total">Total ligne</th>
            <?php if($isAdmin): ?>
              <th class="col-actions">Actions</th>
            <?php endif; ?>
          </tr>
        </thead>
        <tbody>

        <?php foreach ($rowsBySale as $vid => $sale): ?>
          <?php
            $payLabelHtml = formatPaymentsLabelHtml($paymentsBySale, $vid);
            $saleLabel = "Vente #".$vid." — ".$sale['date_vente'];
          ?>

          <tr class="sale-head">
            <td class="sale-meta">
              <div class="sale-title"><?= htmlspecialchars($saleLabel) ?></div>
              <div class="sale-sub">
                <?= htmlspecialchars($sale['event_nom']) ?> • Bénévole : <?= htmlspecialchars($sale['ben_nom']) ?>
              </div>
            </td>

            <td class="pay-cell"><?= $payLabelHtml ?></td>

            <td class="muted">Détail des produits ci-dessous</td>
            <td class="muted">—</td>
            <td class="muted">—</td>
            <td class="sale-total">
              <?php
                $tFinal = (float)($sale['total'] ?? 0);
                $tBrut  = (float)($sale['total_brut'] ?? 0);
                $tRem   = (float)($sale['remise_total'] ?? 0);
              ?>
              Total vente :
              <?php if ($tRem > 0.0001 && $tBrut > $tFinal + 0.0001): ?>
                <span class="price-old"><?= number_format($tBrut,2) ?> €</span>
                <span class="price-new"><?= number_format($tFinal,2) ?> €</span>
                <span class="discount-note">(remise −<?= number_format($tRem,2) ?> €)</span>
              <?php else: ?>
                <?= number_format($tFinal,2) ?> €
              <?php endif; ?>
            </td>

            <?php if($isAdmin): ?>
              <td class="actions-cell">
                <form method="post" onsubmit="return confirm('Supprimer cette vente (toutes ses lignes + paiements) ?');">
                  <input type="hidden" name="delete_sale_id" value="<?= (int)$vid ?>">
                  <button type="submit" class="btn secondary btn-sm">Supprimer</button>
                </form>

                <a class="btn-link" href="recu.php?id=<?= (int)$vid ?>" target="_blank" rel="noopener">
                  Reçu / PDF
                </a>
              </td>
            <?php endif; ?>
          </tr>

          <?php foreach ($sale['lines'] as $line): ?>
            <tr class="sale-line">
              <td></td>
              <td></td>
              <td><?= htmlspecialchars($line['produit_nom']) ?></td>
              <td><?= (int)$line['quantite'] ?></td>
              <td>
                <?php
                  $puO = (float)($line['pu_orig'] ?? 0);
                  $puF = (float)($line['pu_final'] ?? 0);
                ?>
                <?php if ($puO > 0 && abs($puO - $puF) > 0.0001): ?>
                  <span class="price-old"><?= number_format($puO,2) ?> €</span>
                  <span class="price-new"><?= number_format($puF,2) ?> €</span>
                <?php else: ?>
                  <?= number_format($puF ?: $puO,2) ?> €
                <?php endif; ?>
              </td>
              <td>
                <?php
                  $ltO = (float)($line['line_total_orig'] ?? 0);
                  $ltF = (float)($line['line_total_final'] ?? 0);
                  $remPanier = (float)($line['remise_panier_part'] ?? 0);
                  $remTotLigne = (float)($line['remise_total_ligne'] ?? 0);
                ?>
                <?php if ($ltO > 0 && abs($ltO - $ltF) > 0.0001): ?>
                  <div>
                    <span class="price-old"><?= number_format($ltO,2) ?> €</span>
                    <span class="price-new"><?= number_format($ltF,2) ?> €</span>
                  </div>
                  <div class="discount-note">
                    <?php if ($remTotLigne > 0.0001): ?>
                      remise −<?= number_format($remTotLigne,2) ?> €
                    <?php endif; ?>
                    <?php if ($remPanier > 0.0001): ?>
                      <span class="muted">(dont panier −<?= number_format($remPanier,2) ?> €)</span>
                    <?php endif; ?>
                  </div>
                <?php else: ?>
                  <?= number_format($ltF ?: $ltO,2) ?> €
                <?php endif; ?>
              </td>

              <?php if($isAdmin): ?>
                <td class="actions-cell">
                  <form method="post" onsubmit="return confirm('Supprimer uniquement cette ligne ?');">
                    <input type="hidden" name="delete_line_id" value="<?= (int)$line['detail_id'] ?>">
                    <button type="submit" class="btn secondary btn-sm">Suppr. ligne</button>
                  </form>
                </td>
              <?php endif; ?>
            </tr>
          <?php endforeach; ?>

        <?php endforeach; ?>

        </tbody>
      </table>
    </div>

    <p class="small muted-note">
      Chaque vente apparaît d'abord sur une ligne <strong>récap</strong> (évènement, bénévole, paiements, total),
      puis sur une ou plusieurs lignes de détail produits avec les totaux par ligne.
    </p>
  </div>

</div>

<!-- ✅ MODAL CLOTURE -->
<div id="close-caisse-modal" class="modal hidden" onclick="closeModal()" style="display:none;">
  <div class="modal-card" onclick="event.stopPropagation()">
    <div class="modal-header">
      <h3>🔒 Clôture de caisse</h3>
    </div>

    <div class="modal-body">
      <p class="modal-info">Compte le contenu réel de la caisse et indique le montant ci-dessous.</p>

      <div class="caisse-recap">
        <div class="recap-line">
          <span>Fond de caisse théorique</span>
          <strong id="fond-theorique-label">— €</strong>
        </div>
      </div>

      <label class="field-label" for="fond-reel-input">Fond de caisse réel compté (€)</label>
      <input
        type="number"
        id="fond-reel-input"
        class="field-input"
        step="0.01"
        min="0"
        inputmode="decimal"
        placeholder="Ex : 153,50"
        oninput="updateEcartPreview()"
      />

      <p class="field-help">
        Tu peux utiliser une virgule ou un point (ex : 153,50 ou 153.50).
      </p>

      
      <label class="field-label" for="retrait-especes-input">Retrait d'espèces (mise en banque) (€)</label>
      <input
        type="number"
        id="retrait-especes-input"
        class="field-input"
        placeholder="Ex : 100,00"
        step="0.01"
        min="0"
        inputmode="decimal"
      />
      <p class="field-help">Optionnel — montant retiré après clôture (0 si aucun).</p>

      <label class="field-label" for="retrait-note-input">Note de retrait (optionnel)</label>
      <input
        type="text"
        id="retrait-note-input"
        class="field-input"
        placeholder="Ex : remis à la trésorière"
        maxlength="140"
      />

<div id="ecart-preview" class="ecart-preview hidden"></div>
    </div>

    <div class="modal-actions">
      <button type="button" class="btn secondary" onclick="closeModal()">Annuler</button>
      <button type="button" class="btn primary" onclick="confirmCloseCaisse()">Confirmer la clôture</button>
    </div>
  </div>
</div>
</body>
</html>