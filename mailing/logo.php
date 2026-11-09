<?php
declare(strict_types=1);

// mailing/logo.php — Sert publiquement le logo de l'association (en-tête des e-mails et de la page de désinscription).
// Indépendant de la session et de la base, comme image.php : il ne lit que le logo téléversé dans Administration > Habillage.

$path = dirname(__DIR__) . '/uploads/branding/logo.png';
if (!is_file($path) || filesize($path) === 0 || filesize($path) > 2 * 1024 * 1024) { http_response_code(404); exit; }

header('Content-Type: image/png');
header('Content-Length: ' . filesize($path));
header('Cache-Control: public, max-age=86400');
header('X-Content-Type-Options: nosniff');
readfile($path);
