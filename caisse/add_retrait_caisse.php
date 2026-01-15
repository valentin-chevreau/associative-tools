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
$montantRaw = str_replace(',', '.', (string)($_POST['montant'] ?? ''));
$montant = is_numeric($montantRaw) ? (float)$montantRaw : -1;
$note = trim((string)($_POST['note'] ?? ''));

if ($eventId <= 0 || $montant <= 0) {
    respond(400, ['ok' => false, 'error' => 'Données invalides']);
}
if ($note !== '' && mb_strlen($note) > 255) {
    $note = mb_substr($note, 0, 255);
}

try {
    $pdo->beginTransaction();

    $stmtEv = $pdo->prepare("SELECT id FROM evenements WHERE id = ? FOR UPDATE");
    $stmtEv->execute([$eventId]);
    $ev = $stmtEv->fetch(PDO::FETCH_ASSOC);

    if (!$ev) {
        $pdo->rollBack();
        respond(404, ['ok' => false, 'error' => 'Évènement introuvable']);
    }

    $stmtIns = $pdo->prepare("
        INSERT INTO retraits_caisse (evenement_id, montant, note, created_at)
        VALUES (:eid, :montant, :note, NOW())
    ");
    $stmtIns->execute([
        ':eid'     => $eventId,
        ':montant' => $montant,
        ':note'    => ($note !== '' ? $note : null),
    ]);

    $stmtSum = $pdo->prepare("SELECT COALESCE(SUM(montant),0) FROM retraits_caisse WHERE evenement_id = ?");
    $stmtSum->execute([$eventId]);
    $totalRetraits = (float)$stmtSum->fetchColumn();

    $stmtUp = $pdo->prepare("
        UPDATE evenements
        SET retrait_caisse_especes = :total,
            retrait_caisse_note    = :note,
            retrait_caisse_date    = NOW()
        WHERE id = :id
    ");
    $stmtUp->execute([
        ':total' => $totalRetraits,
        ':note'  => ($note !== '' ? $note : null),
        ':id'    => $eventId,
    ]);

    $pdo->commit();

    respond(200, ['ok' => true, 'total_retraits' => round($totalRetraits, 2)]);
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    respond(500, ['ok' => false, 'error' => 'Erreur : ' . $e->getMessage()]);
}