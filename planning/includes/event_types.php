<?php
/**
 * Récupération des types d’évènements depuis la base.
 * - code = valeur stockée dans events.event_type
 * - label = affichage
 */

function get_event_types(PDO $pdo, bool $onlyActive = true): array {
    $sql = "SELECT code, label FROM event_types";
    if ($onlyActive) $sql .= " WHERE is_active = 1";
    $sql .= " ORDER BY sort_order ASC, label ASC";

    $stmt = $pdo->prepare($sql);
    $stmt->execute();

    $types = [];
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $types[$row['code']] = $row['label'];
    }

    return $types;
}

/**
 * Retourne un libellé à partir du code (fallback propre).
 */
function event_type_label(array $types, string $code): string {
    return $types[$code] ?? $code;
}