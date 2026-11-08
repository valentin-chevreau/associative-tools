<?php
declare(strict_types=1);

// mailing/unsubscribe.php — Désinscription d'une personne, sans connexion (lien personnel de chaque newsletter).
//
// GET : page de confirmation (un simple clic d'un antivirus ou d'un aperçu de messagerie ne désinscrit personne).
// POST : désinscription effective. Les messageries qui gèrent « List-Unsubscribe-Post » envoient aussi un POST direct.

require_once dirname(__DIR__) . '/shared/bootstrap.php';
require_once __DIR__ . '/includes/mailing.php';

$pdo = mailing_pdo();
$token = (string)($_GET['t'] ?? '');
$h = 'mailing_h';

header('Referrer-Policy: no-referrer');
$state = 'confirm';
if (!mailing_tables_ready($pdo)) {
    $state = 'unknown';
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $state = mailing_unsubscribe($pdo, $token) !== null ? 'done' : 'unknown';
} else {
    $st = $pdo->prepare("SELECT 1 FROM mailing_recipients WHERE token = ?");
    $st->execute([preg_match('/^[a-f0-9]{32}$/', $token) ? $token : '']);
    if (!$st->fetchColumn()) $state = 'unknown';
}
?><!doctype html>
<html lang="fr">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="robots" content="noindex,nofollow">
  <title>Désinscription — Touraine-Ukraine</title>
  <style>
    body { margin: 0; background: #f5f0e8; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Helvetica, Arial, sans-serif; color: #1a1510; }
    main { max-width: 460px; margin: 12vh auto; padding: 0 16px; }
    .card { background: #fff; border: 1px solid #e8ddd0; border-top: 4px solid #d4732a; border-radius: 16px; padding: 28px; }
    h1 { margin: 0 0 10px; font-size: 20px; }
    p { margin: 0 0 18px; font-size: 14px; line-height: 1.55; color: #6b5c47; }
    button { background: #d4732a; color: #fff; border: 0; border-radius: 10px; padding: 12px 22px; font-size: 14px; font-weight: 700; cursor: pointer; }
  </style>
</head>
<body>
<main><div class="card">
<?php if ($state === 'confirm'): ?>
  <h1>Se désinscrire ?</h1>
  <p>Vous ne recevrez plus les e-mails d'information de l'association Touraine-Ukraine (newsletters et rappels). Les messages indispensables liés à votre activité, comme un reçu fiscal que vous avez demandé, ne sont pas concernés.</p>
  <form method="post" action="?t=<?= $h($token) ?>"><button type="submit">Confirmer la désinscription</button></form>
<?php elseif ($state === 'done'): ?>
  <h1>C'est fait</h1>
  <p>Vous ne recevrez plus nos e-mails d'information. Si c'était une erreur, écrivez-nous et nous vous réinscrirons.</p>
<?php else: ?>
  <h1>Lien non reconnu</h1>
  <p>Ce lien de désinscription n'est pas valide ou n'est pas actif (c'est normal dans un message de test). Si vous voulez ne plus recevoir nos e-mails, répondez simplement à l'un d'eux.</p>
<?php endif; ?>
</div></main>
</body>
</html>
