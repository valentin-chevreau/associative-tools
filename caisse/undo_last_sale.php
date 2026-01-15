<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';

header('Content-Type: text/plain; charset=utf-8');

/**
 * undo_last_sale.php
 * - Admin only
 * - Annule (hard delete) la dernière vente de l'évènement actif
 * - Remet le stock uniquement si produits.stock IS NOT NULL
 */

function respond(int $code, string $msg): void {
    http_response_code($code);
    echo $msg;
    exit;
}

// Admin only
if (!is_admin()) {
    respond(403, "Accès refusé (admin).");
}

// Evènement actif
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
    respond(400, "Aucun évènement actif.");
}
$eventId = (int)$event['id'];

try {
    // Dernière vente de l'évènement actif
    $stmt = $pdo->prepare("SELECT id FROM ventes WHERE evenement_id = ? ORDER BY id DESC LIMIT 1");
    $stmt->execute([$eventId]);
    $vente = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$vente) {
        respond(400, "Aucune vente à annuler.");
    }
    $venteId = (int)$vente['id'];

    $pdo->beginTransaction();

    // Lock lignes + produits concernés
    $stmtDet = $pdo->prepare("SELECT produit_id, quantite FROM vente_details WHERE vente_id = ? FOR UPDATE");
    $stmtDet->execute([$venteId]);
    $details = $stmtDet->fetchAll(PDO::FETCH_ASSOC);

    if (!empty($details)) {
        $pids = [];
        foreach ($details as $d) {
            $pids[] = (int)$d['produit_id'];
        }
        $pids = array_values(array_unique(array_filter($pids)));

        if (count($pids) > 0) {
            $in = implode(',', array_fill(0, count($pids), '?'));
            // Lock produits
            $stmtLock = $pdo->prepare("SELECT id, stock FROM produits WHERE id IN ($in) FOR UPDATE");
            $stmtLock->execute($pids);
        }

        // Restock : uniquement si stock non NULL
        $stmtRestock = $pdo->prepare("
            UPDATE produits
            SET stock = stock + :q
            WHERE id = :id
            AND stock IS NOT NULL
        ");

        foreach ($details as $d) {
            $pid = (int)$d['produit_id'];
            $q   = (int)$d['quantite'];
            if ($pid <= 0 || $q <= 0) continue;

            $stmtRestock->execute([
                ':q'  => $q,
                ':id' => $pid,
            ]);
        }
    }

    // Suppression paiements + lignes + vente
    $pdo->prepare("DELETE FROM vente_paiements WHERE vente_id = ?")->execute([$venteId]);
    $pdo->prepare("DELETE FROM vente_details   WHERE vente_id = ?")->execute([$venteId]);
    $pdo->prepare("DELETE FROM ventes          WHERE id = ?")->execute([$venteId]);

    $pdo->commit();
    echo "OK";
} catch (Exception $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    respond(500, "Erreur : " . $e->getMessage());
}
