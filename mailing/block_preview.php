<?php
declare(strict_types=1);

// mailing/block_preview.php — Aperçu de chaque bloc pour l'éditeur (réponse JSON).
// Reçoit la liste des blocs, renvoie pour chacun le bloc nettoyé et son HTML tel qu'il sera envoyé.

require_once dirname(__DIR__) . '/shared/bootstrap.php';
require_admin();
require_once __DIR__ . '/includes/mailing.php';

header('Content-Type: application/json; charset=UTF-8');
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !mailing_csrf_ok()) {
    http_response_code(400);
    echo json_encode(['error' => 'Session expirée : rechargez la page.']);
    exit;
}

$pdo = mailing_pdo();
$raw = json_decode((string)($_POST['blocks'] ?? '[]'), true);
$blocks = mailing_blocks_sanitize($pdo, $raw);
$base = suite_base();
echo json_encode([
    'blocks' => $blocks,
    'html'   => array_map(static fn(array $b): string => mailing_block_html($b, $base), $blocks),
], JSON_UNESCAPED_UNICODE);
