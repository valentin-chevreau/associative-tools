<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';

header('Content-Type: application/json; charset=utf-8');

function respond(int $code, array $payload): void {
    http_response_code($code);
    echo json_encode($payload);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    respond(405, ['ok' => false, 'error' => 'Méthode non autorisée']);
}
if (!function_exists('is_admin') || !is_admin()) {
    respond(403, ['ok' => false, 'error' => 'Accès interdit (admin)']);
}

$eventId = (int)($_POST['event_id'] ?? 0);

$fondReelRaw = str_replace(',', '.', (string)($_POST['fond_reel'] ?? ''));
$fondReel = is_numeric($fondReelRaw) ? (float)$fondReelRaw : -1;

$retraitRaw = str_replace(',', '.', (string)($_POST['retrait_especes'] ?? ''));
$retrait = ($retraitRaw === '' ? null : (is_numeric($retraitRaw) ? (float)$retraitRaw : null));

$retraitNote = trim((string)($_POST['retrait_note'] ?? ''));

if ($eventId <= 0 || $fondReel < 0) {
    respond(400, ['ok' => false, 'error' => 'Données invalides']);
}
if ($retrait !== null && $retrait < 0) {
    respond(400, ['ok' => false, 'error' => 'Retrait invalide']);
}
if ($retraitNote !== '' && mb_strlen($retraitNote) > 255) {
    $retraitNote = mb_substr($retraitNote, 0, 255);
}

try {
    $pdo->beginTransaction();

    $stmtEv = $pdo->prepare("SELECT id, fond_caisse, actif, date_fin FROM evenements WHERE id = ? FOR UPDATE");
    $stmtEv->execute([$eventId]);
    $ev = $stmtEv->fetch(PDO::FETCH_ASSOC);

    if (!$ev) {
        $pdo->rollBack();
        respond(404, ['ok' => false, 'error' => 'Évènement introuvable']);
    }

    $isAlreadyClosed = ((int)($ev['actif'] ?? 0) === 0) || (!empty($ev['date_fin']));
    if ($isAlreadyClosed) {
        $pdo->rollBack();
        respond(400, ['ok' => false, 'error' => 'Évènement déjà clôturé']);
    }

    $fondCaisse = (float)($ev['fond_caisse'] ?? 0);

    // Total espèces via vente_paiements
    $stmtCash = $pdo->prepare("
        SELECT COALESCE(SUM(vp.montant), 0) s
        FROM vente_paiements vp
        JOIN ventes v ON v.id = vp.vente_id
        WHERE v.evenement_id = ?
          AND vp.methode = 'Especes'
    ");
    $stmtCash->execute([$eventId]);
    $cashSales = (float)$stmtCash->fetchColumn();

    $fondTheorique = $fondCaisse + $cashSales;
    $ecart = $fondReel - $fondTheorique;

    // Clôture event
    $stmtUp = $pdo->prepare("
        UPDATE evenements
        SET fond_caisse_cloture = :fond_reel,
            ecart_caisse        = :ecart,
            date_fin            = NOW(),
            actif               = 0
        WHERE id = :id
    ");
    $stmtUp->execute([
        ':fond_reel' => $fondReel,
        ':ecart'     => $ecart,
        ':id'        => $eventId,
    ]);

    // Retrait optionnel au moment de la clôture
    if ($retrait !== null && $retrait > 0) {
        $stmtIns = $pdo->prepare("
            INSERT INTO retraits_caisse (evenement_id, montant, note, created_at)
            VALUES (:eid, :montant, :note, NOW())
        ");
        $stmtIns->execute([
            ':eid'     => $eventId,
            ':montant' => $retrait,
            ':note'    => ($retraitNote !== '' ? $retraitNote : null),
        ]);

        // Total retraits + cache sur evenements
        $stmtSum = $pdo->prepare("SELECT COALESCE(SUM(montant),0) FROM retraits_caisse WHERE evenement_id = ?");
        $stmtSum->execute([$eventId]);
        $totalRetraits = (float)$stmtSum->fetchColumn();

        $stmtEvUp = $pdo->prepare("
            UPDATE evenements
            SET retrait_caisse_especes = :total,
                retrait_caisse_note    = :note,
                retrait_caisse_date    = NOW()
            WHERE id = :id
        ");
        $stmtEvUp->execute([
            ':total' => $totalRetraits,
            ':note'  => ($retraitNote !== '' ? $retraitNote : null),
            ':id'    => $eventId,
        ]);
    }

    $pdo->commit();

    respond(200, [
        'ok'             => true,
        'fond_theorique' => round($fondTheorique, 2),
        'fond_reel'      => round($fondReel, 2),
        'ecart'          => round($ecart, 2),
    ]);
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    respond(500, ['ok' => false, 'error' => 'Erreur : ' . $e->getMessage()]);
}