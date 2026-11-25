<?php
declare(strict_types=1);

// donations/proof.php — sert le justificatif d'un don en nature (uploads/donations/, hors dépôt et hors accès web direct).
// Réservé aux administrateurs ayant accès au module Dons.

require_once dirname(__DIR__) . '/shared/bootstrap.php';
require_admin(); // l'accès au module est contrôlé par shared/bootstrap.php (droits par module)

$pdo = _bootstrap_get_pdo();
if (!($pdo instanceof PDO)) { http_response_code(500); echo 'Connexion base de données indisponible.'; exit; }

$st = $pdo->prepare('SELECT proof_path FROM donations WHERE id = :id');
$st->execute(['id' => (int)($_GET['id'] ?? 0)]);
$rel = (string)($st->fetchColumn() ?: '');

$root = realpath(dirname(__DIR__) . '/uploads/donations');
$abs  = $rel !== '' && $root !== false ? realpath(dirname(__DIR__) . '/uploads/' . $rel) : false;
if ($abs === false || strncmp($abs, $root . DIRECTORY_SEPARATOR, strlen($root) + 1) !== 0 || !is_file($abs)) {
    http_response_code(404);
    echo 'Justificatif introuvable.';
    exit;
}

$ext  = strtolower(pathinfo($abs, PATHINFO_EXTENSION));
$mime = ['pdf' => 'application/pdf', 'jpg' => 'image/jpeg', 'png' => 'image/png'][$ext] ?? 'application/octet-stream';
header('Content-Type: ' . $mime);
header('Content-Disposition: inline; filename="justificatif_don_' . (int)($_GET['id'] ?? 0) . '.' . $ext . '"');
header('Content-Length: ' . filesize($abs));
header('X-Content-Type-Options: nosniff');
header("Content-Security-Policy: default-src 'none'; img-src 'self' data:; style-src 'unsafe-inline'");
header('Cache-Control: private, no-store');
readfile($abs);
