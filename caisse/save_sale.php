<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';

header('Content-Type: text/plain; charset=utf-8');

/**
 * save_sale.php
 * - Récupère l'évènement actif (obligatoire)
 * - Recalcule le total côté serveur (prix DB = source de vérité)
 * - Gère les paiements multiples (table vente_paiements)
 * - Enregistre la vente (ventes) + lignes (vente_details)
 * - Décrémente le stock UNIQUEMENT si produits.stock IS NOT NULL
 * - Bloque les stocks négatifs même en concurrence (FOR UPDATE + UPDATE conditionnel)
 *
 * Convention stock illimité : produits.stock = NULL
 *
 * Don libre :
 * - Si tu as un produit type "Don libre" (prix=0), on autorise un prix unitaire issu du front
 *   (champ item['price']) pour cette ligne uniquement.
 */

function respond(int $code, string $msg): void {
    http_response_code($code);
    echo $msg;
    exit;
}

// -------------- Lecture input (JSON ou POST) ----------------
$raw = file_get_contents('php://input') ?: '';
$data = null;

if (trim($raw) !== '') {
    $json = json_decode($raw, true);
    if (is_array($json)) {
        $data = $json;
    }
}
if (!is_array($data)) {
    $data = $_POST;
}

// On accepte plusieurs noms possibles pour coller à l'existant/front
$cart     = $data['cart']     ?? $data['panier']   ?? null;
$payments = $data['payments'] ?? $data['paiements'] ?? null;
$benevole = $data['benevole'] ?? $data['benevole_id'] ?? null;

// Remise panier (optionnelle) : {type:'amount'|'percent', value:number}
$globalDiscount = $data['globalDiscount']
    ?? $data['global_discount']
    ?? $data['remise_panier']
    ?? null;

if (!is_array($cart) || count($cart) === 0) {
    respond(400, "Panier vide");
}
if (!is_array($payments) || count($payments) === 0) {
    respond(400, "Aucun paiement");
}

$benevoleId = null;
if ($benevole !== null && $benevole !== '') {
    $benevoleId = (int)$benevole;
    if ($benevoleId <= 0) $benevoleId = null;
}

// -------------- Evènement actif obligatoire -----------------
try {
    $event = $pdo->query("
    SELECT id
    FROM evenements
    WHERE actif = 1
    AND date_fin IS NULL
    ORDER BY id DESC
    LIMIT 1
")->fetch(PDO::FETCH_ASSOC);

} catch (Exception $e) {
    respond(500, "Erreur DB (evenement) : " . $e->getMessage());
}
if (!$event) {
    respond(403, "Aucun évènement actif");
}
$eventId = (int)$event['id'];

// -------------- Normalisation paiements --------------------
$normalizedPayments = [];
$totalPaid = 0.0;

foreach ($payments as $p) {
    if (!is_array($p)) continue;

    $method = (string)($p['method'] ?? $p['methode'] ?? '');
    $amount = $p['amount'] ?? $p['montant'] ?? null;

    $method = trim($method);
    if ($method === '' || $amount === null || !is_numeric($amount)) continue;

    $amt = (float)$amount;
    if ($amt <= 0) continue;

    $mUp = strtoupper($method);
    if ($mUp === 'CB') $method = 'CB';
    elseif ($mUp === 'ESPECES' || $mUp === 'ESPÈCES') $method = 'Especes';
    elseif ($mUp === 'CHEQUE' || $mUp === 'CHÈQUE') $method = 'Cheque';

    if (!in_array($method, ['CB', 'Especes', 'Cheque'], true)) continue;

    $normalizedPayments[] = ['method' => $method, 'amount' => $amt];
    $totalPaid += $amt;
}

if (count($normalizedPayments) === 0) {
    respond(400, "Paiements invalides");
}

$mainMethod = $normalizedPayments[0]['method']; // ventes.paiement = enum simple

// -------------- Normalisation panier ------------------------
/**
 * On accepte ces formes par item :
 * - id / produit_id
 * - qty / quantite
 * - price / prix (ignoré sauf don libre)
 * - discount / remise (optionnel) :
 *     - discount: {type:'amount'|'percent', value:number}
 *     - ou remise_type + remise_value
 */
$items = [];
$productIds = [];

foreach ($cart as $it) {
    if (!is_array($it)) continue;

    $pid = (int)($it['id'] ?? $it['produit_id'] ?? 0);
    $qty = (int)($it['qty'] ?? $it['quantite'] ?? 0);

    // price potentiellement utilisé pour don libre
    $frontPrice = $it['price'] ?? $it['prix'] ?? null;

    // remise ligne (optionnelle)
    $disc = $it['discount'] ?? $it['remise'] ?? null;
    if (is_array($disc)) {
        $dtype = (string)($disc['type'] ?? $disc['remise_type'] ?? '');
        $dval  = $disc['value'] ?? $disc['remise_value'] ?? null;
    } else {
        $dtype = (string)($it['remise_type'] ?? $it['discount_type'] ?? '');
        $dval  = $it['remise_value'] ?? $it['discount_value'] ?? null;
    }

    $dtype = trim($dtype);
    if ($dtype !== 'amount' && $dtype !== 'percent') {
        $dtype = '';
    }
    $dval = (is_numeric($dval) ? (float)$dval : null);
    if ($dval !== null && $dval <= 0) {
        $dval = null;
        $dtype = '';
    }

    if ($pid <= 0 || $qty <= 0) continue;

    $items[] = [
        'product_id' => $pid,
        'qty'        => $qty,
        'front_price'=> (is_numeric($frontPrice) ? (float)$frontPrice : null),
        'discount'   => ($dtype && $dval !== null) ? ['type' => $dtype, 'value' => $dval] : null,
    ];
    $productIds[] = $pid;
}

if (count($items) === 0) {
    respond(400, "Panier invalide");
}

$productIds = array_values(array_unique($productIds));

// -------------- Transaction : lock + calcul + insert ----------
try {

// Colonnes existantes (on s'adapte au schéma sans modifier la DB)
$colsVentes = [
    'sous_total',
    'remise_panier_type','remise_panier_valeur','remise_panier_montant',
    'total_brut','remise_total',
];
$colsDetails = [
    'prix_origine',
    'remise_ligne_type','remise_ligne_valeur',
    'remise_panier_part',
    'prix_final',
    'prix_brut','remise',
];

$hasVentes = array_fill_keys($colsVentes, false);
$hasDetails = array_fill_keys($colsDetails, false);

try {
    $dbName = (string)$pdo->query("SELECT DATABASE()")->fetchColumn();
    if ($dbName !== '') {
        $stmtCols = $pdo->prepare("
            SELECT TABLE_NAME, COLUMN_NAME
            FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = :db
              AND TABLE_NAME IN ('ventes','vente_details')
        ");
        $stmtCols->execute([':db' => $dbName]);
        while ($r = $stmtCols->fetch(PDO::FETCH_ASSOC)) {
            $t = (string)$r['TABLE_NAME'];
            $c = (string)$r['COLUMN_NAME'];
            if ($t === 'ventes' && array_key_exists($c, $hasVentes)) $hasVentes[$c] = true;
            if ($t === 'vente_details' && array_key_exists($c, $hasDetails)) $hasDetails[$c] = true;
        }
    }
} catch (Throwable $e) {
    // Si on ne peut pas introspecter, on continue en mode minimal (colonnes historiques uniquement)
}

$pdo->beginTransaction();

    // Lock produits concernés
    $in = implode(',', array_fill(0, count($productIds), '?'));
    $stmt = $pdo->prepare("SELECT id, nom, prix, stock, actif FROM produits WHERE id IN ($in) FOR UPDATE");
    $stmt->execute($productIds);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $products = [];
    foreach ($rows as $r) {
        $products[(int)$r['id']] = $r;
    }

    // Vérif existence / actifs + calcul total serveur (avec remises)
    $subtotal = 0.0;            // total après remises ligne
    $totalBrut = 0.0;          // total avant remises (ligne + panier)
    $eligibleSubtotal = 0.0;    // total éligible à la remise panier (hors dons)
    $lines = [];                // pid => [qty, unit, is_donation, line_net, name]

    // On regroupe si le même produit apparaît plusieurs fois
    $grouped = [];
    foreach ($items as $it) {
        $pid = $it['product_id'];
        if (!isset($grouped[$pid])) {
            $grouped[$pid] = [
                'qty' => 0,
                'front_price' => $it['front_price'],
                'discount' => $it['discount'],
            ];
        }
        $grouped[$pid]['qty'] += $it['qty'];

        // si plusieurs lignes “don libre” arrivent, on garde le dernier front_price non null
        if ($it['front_price'] !== null) {
            $grouped[$pid]['front_price'] = $it['front_price'];
        }

        // si plusieurs remises arrivent pour le même produit, on garde la dernière non nulle
        if ($it['discount'] !== null) {
            $grouped[$pid]['discount'] = $it['discount'];
        }
    }

    foreach ($grouped as $pid => $g) {
        if (!isset($products[$pid])) {
            throw new Exception("Produit introuvable (id=$pid)");
        }
        $p = $products[$pid];

        if ((int)$p['actif'] !== 1) {
            throw new Exception("Produit inactif : " . $p['nom']);
        }

        $qty = (int)$g['qty'];
        if ($qty <= 0) continue;

        // Stock : si non NULL, contrôle strict
        if ($p['stock'] !== null) {
            $stock = (int)$p['stock'];
            if ($qty > $stock) {
                throw new Exception("Stock insuffisant : {$p['nom']} (restant=$stock, demandé=$qty)");
            }
        }

        // Prix serveur : DB par défaut
        $unit = (float)$p['prix'];

        /**
         * Cas don libre :
         * - produit prix=0 ET nom contient "don" (souple, évite d’ajouter une colonne)
         * - on prend alors le prix du front (montant saisi)
         */
        $nameLower = mb_strtolower((string)$p['nom']);
        $isDonationLike = ($unit == 0.0 && str_contains($nameLower, 'don'));

        // Prix unitaire (don libre : prix front autorisé)
        if ($isDonationLike) {
            $fp = $g['front_price'];
            if ($fp === null || $fp < 0) {
                $fp = 0.0;
            }
            // Sécurité anti-montants délirants (ajuste si tu veux)
            if ($fp > 10000) {
                throw new Exception("Montant de don trop élevé");
            }
            $unit = (float)$fp;
        }

        
// Remise ligne (interdite sur les dons)
$lineBase = $unit * (float)$qty;

$lineDiscType = 'none';
$lineDiscVal  = 0.0;

$lineDiscount = 0.0;
if (!empty($g['discount'])) {
    if ($isDonationLike) {
        throw new Exception("Remise interdite sur un don : {$p['nom']}");
    }
    $dtype = (string)($g['discount']['type'] ?? '');
    $dval  = (float)($g['discount']['value'] ?? 0);
    if ($dval > 0) {
        if ($dtype === 'percent') {
            $lineDiscount = $lineBase * ($dval / 100.0);
            $lineDiscType = 'percent';
            $lineDiscVal  = $dval;
        } elseif ($dtype === 'amount') {
            $lineDiscount = $dval;
            $lineDiscType = 'amount';
            $lineDiscVal  = $dval;
        }
    }
}
if ($lineDiscount < 0) $lineDiscount = 0.0;
if ($lineDiscount > $lineBase) $lineDiscount = $lineBase;

$lineNet = $lineBase - $lineDiscount;
        if ($lineNet < 0) $lineNet = 0.0;
        $totalBrut += $lineBase;

        $subtotal += $lineNet;
        if (!$isDonationLike) {
            $eligibleSubtotal += $lineNet;
        }

        
$lines[$pid] = [
    'qty'              => $qty,
    'unit'             => $unit,
    'is_donation'      => $isDonationLike,
    'line_base'        => $lineBase,
    'line_discount'    => $lineDiscount,
    'line_disc_type'   => $lineDiscType,
    'line_disc_value'  => $lineDiscVal,
    'line_net'         => $lineNet,
    'nom'              => (string)$p['nom'],
];
    }

    
// Remise panier (hors dons)
$globalDiscountAmount = 0.0;
$globalDiscountType   = 'none';
$globalDiscountValue  = 0.0;

if ($eligibleSubtotal > 0 && is_array($globalDiscount)) {
    $gType = trim((string)($globalDiscount['type'] ?? ''));
    $gVal  = $globalDiscount['value'] ?? null;
    $gVal  = (is_numeric($gVal) ? (float)$gVal : 0.0);

    if ($gVal > 0 && ($gType === 'percent' || $gType === 'amount')) {
        $globalDiscountType  = $gType;
        $globalDiscountValue = $gVal;

        if ($gType === 'percent') {
            $globalDiscountAmount = $eligibleSubtotal * ($gVal / 100.0);
        } else { // amount
            $globalDiscountAmount = $gVal;
        }
    }
}
if ($globalDiscountAmount < 0) $globalDiscountAmount = 0.0;
if ($globalDiscountAmount > $eligibleSubtotal) $globalDiscountAmount = $eligibleSubtotal;

    // Total final
    $serverTotal = $subtotal - $globalDiscountAmount;
    if ($serverTotal < 0) $serverTotal = 0.0;
    $serverTotal = round($serverTotal, 2);

    $totalBrut = round($totalBrut, 2);
    $remiseTotal = $totalBrut - $serverTotal;
    if ($remiseTotal < 0) $remiseTotal = 0.0;
    $remiseTotal = round($remiseTotal, 2);

    // Vérif paiement suffisant (serveur)
    // Tolérance arrondi : 0.01
    if ($totalPaid + 0.00001 < $serverTotal - 0.01) {
        throw new Exception("Paiement insuffisant (payé=" . number_format($totalPaid, 2, '.', '') . " / total=" . number_format($serverTotal, 2, '.', '') . ")");
    }

    
// Insert vente
$sousTotal = round($subtotal, 2);

$saleCols = ['evenement_id','benevole_id','paiement','total','date_vente'];
$saleVals = [':event_id',':benevole_id',':paiement',':total','NOW()'];

if (!empty($hasVentes['sous_total'])) {
    $saleCols[] = 'sous_total';
    $saleVals[] = ':sous_total';
}
if (!empty($hasVentes['remise_panier_type'])) {
    $saleCols[] = 'remise_panier_type';
    $saleVals[] = ':remise_panier_type';
}
if (!empty($hasVentes['remise_panier_valeur'])) {
    $saleCols[] = 'remise_panier_valeur';
    $saleVals[] = ':remise_panier_valeur';
}
if (!empty($hasVentes['remise_panier_montant'])) {
    $saleCols[] = 'remise_panier_montant';
    $saleVals[] = ':remise_panier_montant';
}
if (!empty($hasVentes['total_brut'])) {
    $saleCols[] = 'total_brut';
    $saleVals[] = ':total_brut';
}
if (!empty($hasVentes['remise_total'])) {
    $saleCols[] = 'remise_total';
    $saleVals[] = ':remise_total';
}

$sqlSale = "INSERT INTO ventes (".implode(',', $saleCols).") VALUES (".implode(',', $saleVals).")";
$stmtSale = $pdo->prepare($sqlSale);

$paramsSale = [
    ':event_id'    => $eventId,
    ':benevole_id' => $benevoleId,
    ':paiement'    => $mainMethod,
    ':total'       => $serverTotal,
];
if (in_array(':sous_total', $saleVals, true))            $paramsSale[':sous_total'] = $sousTotal;
if (in_array(':remise_panier_type', $saleVals, true))    $paramsSale[':remise_panier_type'] = $globalDiscountType;
if (in_array(':remise_panier_valeur', $saleVals, true))  $paramsSale[':remise_panier_valeur'] = round($globalDiscountValue, 2);
if (in_array(':remise_panier_montant', $saleVals, true)) $paramsSale[':remise_panier_montant'] = round($globalDiscountAmount, 2);
if (in_array(':total_brut', $saleVals, true))            $paramsSale[':total_brut'] = $totalBrut;
if (in_array(':remise_total', $saleVals, true))          $paramsSale[':remise_total'] = $remiseTotal;

$stmtSale->execute($paramsSale);

$venteId = (int)$pdo->lastInsertId();

        
// Insert lignes (on s'adapte aux colonnes disponibles)
$lineCols = ['vente_id','produit_id','quantite','prix'];
$lineVals = [':vente_id',':produit_id',':quantite',':prix'];

if (!empty($hasDetails['prix_origine']))        { $lineCols[] = 'prix_origine';        $lineVals[] = ':prix_origine'; }
if (!empty($hasDetails['remise_ligne_type']))   { $lineCols[] = 'remise_ligne_type';   $lineVals[] = ':remise_ligne_type'; }
if (!empty($hasDetails['remise_ligne_valeur'])) { $lineCols[] = 'remise_ligne_valeur'; $lineVals[] = ':remise_ligne_valeur'; }
if (!empty($hasDetails['remise_panier_part']))  { $lineCols[] = 'remise_panier_part';  $lineVals[] = ':remise_panier_part'; }
if (!empty($hasDetails['prix_final']))          { $lineCols[] = 'prix_final';          $lineVals[] = ':prix_final'; }
if (!empty($hasDetails['prix_brut']))           { $lineCols[] = 'prix_brut';           $lineVals[] = ':prix_brut'; }
if (!empty($hasDetails['remise']))              { $lineCols[] = 'remise';              $lineVals[] = ':remise'; }

$stmtLine = $pdo->prepare("INSERT INTO vente_details (".implode(',', $lineCols).") VALUES (".implode(',', $lineVals).")");

// Update stock conditionnel : empêche négatif en concurrence
    $stmtDec = $pdo->prepare("
        UPDATE produits
        SET stock = stock - :qte
        WHERE id = :pid
          AND stock IS NOT NULL
          AND stock >= :qte
    ");

    // Répartition de la remise panier sur les lignes éligibles (hors dons)
    $remainingGlobal = $globalDiscountAmount;
    $eligibleLeft    = $eligibleSubtotal;

    // On conserve l'ordre d'itération (id produit) pour un résultat stable
    foreach ($grouped as $pid => $g) {
        if (!isset($lines[$pid])) continue;

        $qty = (int)($lines[$pid]['qty'] ?? 0);
        if ($qty <= 0) continue;

        $isDonationLike = (bool)($lines[$pid]['is_donation'] ?? false);
        $lineNet = (float)($lines[$pid]['line_net'] ?? 0.0);

        $alloc = 0.0;
        if (!$isDonationLike && $remainingGlobal > 0.00001 && $eligibleLeft > 0.00001) {
            // Allocation proportionnelle, avec "reste" géré sur la fin
            $alloc = round($remainingGlobal * ($lineNet / $eligibleLeft), 2);
            if ($alloc > $remainingGlobal) $alloc = $remainingGlobal;
            if ($alloc > $lineNet) $alloc = $lineNet;
            $remainingGlobal -= $alloc;
            $eligibleLeft    -= $lineNet;
        }

        $finalLineTotal = $lineNet - $alloc;
        if ($finalLineTotal < 0) $finalLineTotal = 0.0;

        // Prix unitaire stocké = prix final (après remises) / quantité
        $unitToStore = $finalLineTotal / (float)$qty;

        // Sur don libre, on garde le prix saisi tel quel (plus clair sur les reçus)
        if ($isDonationLike) {
            $unitToStore = (float)($lines[$pid]['unit'] ?? 0.0);
        }

        
// Arrondi pour coller aux habitudes d'affichage
$unitToStore = round($unitToStore, 2);

$lineBase = (float)($lines[$pid]['line_base'] ?? 0.0);

// Remise totale de la ligne (remise ligne + part remise panier)
$lineDiscountTotal = $lineBase - $finalLineTotal;
if ($lineDiscountTotal < 0) $lineDiscountTotal = 0.0;
$lineDiscountTotal = round($lineDiscountTotal, 2);

$params = [
    ':vente_id'   => $venteId,
    ':produit_id' => $pid,
    ':quantite'   => $qty,
    ':prix'       => $unitToStore,
];

// Champs de traçage (si colonnes présentes)
if (!empty($hasDetails['prix_origine']))        $params[':prix_origine'] = round((float)($lines[$pid]['unit'] ?? 0.0), 2);
if (!empty($hasDetails['remise_ligne_type']))   $params[':remise_ligne_type'] = (string)($lines[$pid]['line_disc_type'] ?? 'none');
if (!empty($hasDetails['remise_ligne_valeur'])) $params[':remise_ligne_valeur'] = round((float)($lines[$pid]['line_disc_value'] ?? 0.0), 2);
if (!empty($hasDetails['remise_panier_part']))  $params[':remise_panier_part'] = round($alloc, 2);
if (!empty($hasDetails['prix_final']))          $params[':prix_final'] = $unitToStore;

// prix_brut = prix unitaire après remise ligne (avant remise panier)
// - pour un don, on conserve le montant saisi (pas de remise)
if (!empty($hasDetails['prix_brut'])) {
    if ($isDonationLike) {
        $params[':prix_brut'] = round((float)($lines[$pid]['unit'] ?? 0.0), 2);
    } else {
        $unitBrut = ($qty > 0) ? (($lineNet) / (float)$qty) : 0.0;
        $params[':prix_brut'] = round($unitBrut, 2);
    }
}

// remise = remise totale sur la ligne (remise ligne + part remise panier), en montant (pas un %)
if (!empty($hasDetails['remise']))              $params[':remise'] = $lineDiscountTotal;

$stmtLine->execute($params);

        // Décrément stock seulement si stock non NULL (illimité => NULL)
        $stmtDec->execute([
            ':qte' => $qty,
            ':pid' => $pid,
        ]);

        // Si stock était limité, on doit avoir décrémenté 1 ligne
        // Si stock était limité, on doit avoir décrémenté 1 ligne
        $p = $products[$pid] ?? null;
        if ($p && $p['stock'] !== null && $stmtDec->rowCount() === 0) {
            throw new Exception("Stock insuffisant (concurrence) : {$p['nom']}");
        }
    }

    // Insert paiements détaillés
    $stmtPay = $pdo->prepare("
        INSERT INTO vente_paiements (vente_id, methode, montant)
        VALUES (:vente_id, :methode, :montant)
    ");
    foreach ($normalizedPayments as $pay) {
        $stmtPay->execute([
            ':vente_id' => $venteId,
            ':methode'  => $pay['method'],
            ':montant'  => $pay['amount'],
        ]);
    }

    $pdo->commit();
    echo "OK";
} catch (Exception $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    respond(500, "Erreur : " . $e->getMessage());
}
