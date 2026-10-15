<?php
declare(strict_types=1);

// donations/receipt_send.php — envoie le reçu fiscal (PDF signé en pièce jointe) au donateur par e-mail.
// POST uniquement : donation_id ; redirige vers receipt_view.php avec un message.

require_once dirname(__DIR__) . '/shared/bootstrap.php';
require_admin(); // l'accès au module est contrôlé par shared/bootstrap.php (droits par module)
require_once dirname(__DIR__) . '/shared/mailer.php';
require_once dirname(__DIR__) . '/shared/audit.php';
require_once __DIR__ . '/includes/receipt.php';
require_once __DIR__ . '/includes/ReceiptPdf.php';

$base = function_exists('suite_base') ? suite_base() : '';
$id   = (int)($_POST['donation_id'] ?? 0);
$back = ($_POST['return'] ?? '') === 'list'
    ? $base . '/donations/index.php?year=' . (int)($_POST['year'] ?? date('Y'))
    : $base . '/donations/receipt_view.php?id=' . $id;

if ($_SERVER['REQUEST_METHOD'] !== 'POST') { header('Location: ' . $back); exit; }

$flash = static function (bool $ok, string $text) use ($back): void {
    $_SESSION['receipt_flash'] = ['ok' => $ok, 'text' => $text];
    header('Location: ' . $back);
    exit;
};

$pdo = _bootstrap_get_pdo();
if (!($pdo instanceof PDO)) $flash(false, 'Connexion base de données indisponible.');

$d = receipt_build($pdo, $id, require __DIR__ . '/config.php');
if (isset($d['error'])) $flash(false, (string)$d['error']);

$to = (string)$d['donor_email'];
if (!filter_var($to, FILTER_VALIDATE_EMAIL)) $flash(false, "Ce donateur n'a pas d'adresse e-mail valide : renseignez-la dans le don avant d'envoyer le reçu.");

$org     = (string)$d['org_name'];
$number  = (string)$d['number'];
$subject = "Votre reçu fiscal {$number} — {$org}";
$first   = trim(explode(' ', (string)$d['donor_name'])[0] ?? '');

$text = "Bonjour" . ($first !== '' ? " {$first}" : '') . ",\n\n"
      . "Merci pour votre don de {$d['amount_fr']} à {$org}. Vous trouverez en pièce jointe votre reçu fiscal n° {$number} (PDF), "
      . "à conserver pour votre déclaration de revenus.\n\n"
      . "Très cordialement,\n{$org}\n";
$html = suite_mail_html(
    'Votre reçu fiscal',
    "Merci pour votre don à {$org}. Votre reçu fiscal est joint à ce message au format PDF : conservez-le pour votre déclaration de revenus.",
    [['Référence', $number], ['Montant', (string)$d['amount_fr']], ['Date du don', (string)$d['donation_date']]],
    null,
    $org,
    'Reçu fiscal'
);

$pdf = ReceiptPdf::render($d);
$err = suite_mail_send($to, $subject, $text, $html, [[
    'filename' => 'recu_fiscal_' . preg_replace('/[^A-Za-z0-9\-]/', '_', $number) . '.pdf',
    'type'     => 'application/pdf',
    'content'  => $pdf,
]]);

if ($err !== null) $flash(false, "L'e-mail n'a pas pu être envoyé : {$err}");

audit_log('donations', 'receipt_email_sent', 'donation', $id, 'Reçu fiscal n° ' . $number, [
    'destinataire' => $to,
    'signe'        => $d['signatory'] ? true : false,
]);
$flash(true, "Reçu {$number} envoyé à {$to}" . ($d['signatory'] ? ' (signé).' : ' (sans signature : aucun trésorier avec signature configuré).'));
