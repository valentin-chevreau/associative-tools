<?php
declare(strict_types=1);

// mailing/image.php — Sert publiquement les images d'une newsletter (les messageries les chargent sans connexion).
// Volontairement indépendant de la session et de la base : il ne lit que des fichiers au nom aléatoire.

$f = (string)($_GET['f'] ?? '');
if (!preg_match('/^[a-f0-9]{32}\.(jpg|png)$/', $f)) { http_response_code(404); exit; }

$path = dirname(__DIR__) . '/uploads/mailing/' . $f;
if (!is_file($path)) { http_response_code(404); exit; }

header('Content-Type: ' . (str_ends_with($f, '.png') ? 'image/png' : 'image/jpeg'));
header('Content-Length: ' . filesize($path));
header('Cache-Control: public, max-age=31536000, immutable');
header('X-Content-Type-Options: nosniff');
readfile($path);
