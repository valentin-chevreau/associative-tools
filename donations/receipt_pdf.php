<?php
declare(strict_types=1);

// donations/receipt_pdf.php — téléchargement / affichage du reçu fiscal en PDF (signé si un trésorier est configuré).

require_once dirname(__DIR__) . '/shared/bootstrap.php';
require_admin(); // l'accès au module est contrôlé par shared/bootstrap.php (droits par module)
require_once __DIR__ . '/includes/receipt.php';
require_once __DIR__ . '/includes/ReceiptPdf.php';

$pdo = _bootstrap_get_pdo();
if (!($pdo instanceof PDO)) {
    http_response_code(500);
    echo 'Connexion base de données indisponible.';
    exit;
}

$d = receipt_build($pdo, (int)($_GET['id'] ?? 0), require __DIR__ . '/config.php');
if (isset($d['error'])) {
    http_response_code((int)$d['code']);
    echo $d['error'];
    exit;
}

$pdf = ReceiptPdf::render($d);
$fname = 'recu_fiscal_' . preg_replace('/[^A-Za-z0-9\-]/', '_', $d['number']) . '.pdf';
header('Content-Type: application/pdf');
header('Content-Disposition: inline; filename="' . $fname . '"');
header('Content-Length: ' . strlen($pdf));
header('Cache-Control: private, no-store');
echo $pdf;
