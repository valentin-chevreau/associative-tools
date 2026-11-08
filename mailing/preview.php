<?php
declare(strict_types=1);

// mailing/preview.php — Aperçu HTML d'une newsletter enregistrée (affiché dans un cadre de compose.php et campaign.php).

require_once dirname(__DIR__) . '/shared/bootstrap.php';
require_admin();
require_once __DIR__ . '/includes/mailing.php';

$pdo = mailing_pdo();
$st = $pdo->prepare("SELECT * FROM mailing_campaigns WHERE id = ?");
$st->execute([(int)($_GET['id'] ?? 0)]);
$c = $st->fetch(PDO::FETCH_ASSOC);
if (!$c) { http_response_code(404); exit('Newsletter introuvable.'); }

header('Content-Type: text/html; charset=UTF-8');
header("Content-Security-Policy: default-src 'none'; style-src 'unsafe-inline'; img-src https: data:");
echo mailing_render_html($c, '#', suite_base());
