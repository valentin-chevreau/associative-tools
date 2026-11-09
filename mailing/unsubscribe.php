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
    if (mailing_token_owner($pdo, $token) === null) $state = 'unknown';
}
$hasLogo = mailing_logo_url('') !== '';
$siteHome = suite_base() . '/planning/events.php';
$icons = [
    'confirm' => ['#fdf0e3', '#b85e1e', '<rect x="3" y="5" width="18" height="14" rx="2"/><path d="M3 7l9 6 9-6"/>'],
    'done'    => ['#e4f0ea', '#2a7d4a', '<circle cx="12" cy="12" r="9"/><path d="M8 12.5l2.8 2.8L16 9.8"/>'],
    'unknown' => ['#f5ead8', '#6b5c47', '<circle cx="12" cy="12" r="9"/><path d="M12 11v5M12 7.9v.1"/>'],
];
[$icBg, $icFg, $icPath] = $icons[$state];
?><!doctype html>
<html lang="fr">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="robots" content="noindex,nofollow">
  <title>Désinscription — Touraine-Ukraine</title>
  <style>
    * { box-sizing: border-box; }
    body { margin: 0; min-height: 100vh; display: flex; flex-direction: column; align-items: center; justify-content: center; padding: 24px 16px;
           background: #f5f0e8; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Helvetica, Arial, sans-serif; color: #1a1510; }
    .brand { display: flex; align-items: center; gap: 14px; margin-bottom: 22px; }
    .brand img { height: 52px; width: auto; max-width: 150px; display: block; }
    .brand .mark { width: 52px; height: 52px; border-radius: 13px; display: block; }
    .brand .mark { background: #d4732a; color: #fff; font-weight: 800; font-size: 18px; line-height: 52px; text-align: center; }
    .brand b { display: block; font-size: 19px; font-weight: 800; }
    .brand span { font-size: 12.5px; color: #a89880; }
    .card { width: 100%; max-width: 460px; background: #fff; border: 1px solid #e8ddd0; border-top: 5px solid #d4732a; border-radius: 18px; padding: 30px 28px 28px;
            box-shadow: 0 10px 30px rgba(26,21,16,.07); }
    .ico { width: 52px; height: 52px; border-radius: 50%; display: flex; align-items: center; justify-content: center; margin-bottom: 16px; background: <?= $icBg ?>; color: <?= $icFg ?>; }
    h1 { margin: 0 0 10px; font-size: 21px; line-height: 1.25; }
    p { margin: 0 0 20px; font-size: 14.5px; line-height: 1.6; color: #6b5c47; }
    p:last-child { margin-bottom: 0; }
    button, .btn { display: inline-block; background: #d4732a; color: #fff; border: 0; border-radius: 11px; padding: 13px 24px; font-family: inherit; font-weight: 700; font-size: 14.5px; line-height: 1; cursor: pointer; text-decoration: none; }
    button:hover, .btn:hover { background: #b85e1e; }
    button:focus-visible, .btn:focus-visible { outline: 3px solid #f0b060; outline-offset: 2px; }
    .btn-s { background: #fff; color: #3d3428; border: 1.5px solid #e8ddd0; }
    .btn-s:hover { background: #f5f0e8; }
    .foot { margin-top: 22px; font-size: 12px; color: #a89880; text-align: center; }
    .foot a { color: #6b5c47; }
  </style>
</head>
<body>
<div class="brand">
  <?php if ($hasLogo): ?><img src="logo.php" alt="Touraine-Ukraine"><?php else: ?><div class="mark">TU</div><?php endif; ?>
  <div><b>Touraine-Ukraine</b><span>Association</span></div>
</div>
<main class="card">
  <div class="ico" aria-hidden="true"><svg viewBox="0 0 24 24" width="26" height="26" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><?= $icPath ?></svg></div>
<?php if ($state === 'confirm'): ?>
  <h1>Se désinscrire ?</h1>
  <p>Vous ne recevrez plus les e-mails d'information de l'association Touraine-Ukraine (newsletters et rappels). Les messages indispensables liés à votre activité, comme un reçu fiscal que vous avez demandé, ne sont pas concernés.</p>
  <form method="post" action="?t=<?= $h($token) ?>"><button type="submit">Confirmer la désinscription</button></form>
<?php elseif ($state === 'done'): ?>
  <h1>C'est fait</h1>
  <p>Vous ne recevrez plus nos e-mails d'information. Si c'était une erreur, écrivez-nous et nous vous réinscrirons.</p>
  <a class="btn btn-s" href="<?= $h($siteHome) ?>">Retour au site</a>
<?php else: ?>
  <h1>Lien non reconnu</h1>
  <p>Ce lien de désinscription n'est pas valide ou n'est pas actif (c'est normal dans un message de test). Si vous voulez ne plus recevoir nos e-mails, répondez simplement à l'un d'eux.</p>
  <a class="btn btn-s" href="<?= $h($siteHome) ?>">Retour au site</a>
<?php endif; ?>
</main>
<div class="foot">Association Touraine-Ukraine</div>
</body>
</html>
