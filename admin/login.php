<?php
declare(strict_types=1);

require_once __DIR__ . '/../shared/bootstrap.php';
require_once __DIR__ . '/../shared/suite_nav.php';

$err = '';
$next = (string)($_GET['next'] ?? '');
if ($next === '') $next = suite_base() . '/';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $code = (string)($_POST['code'] ?? '');
    $next = (string)($_POST['next'] ?? $next);

    if (admin_login_with_code($code)) {
        header('Location: ' . $next);
        exit;
    }
    $err = "Code invalide.";
}
?>
<div style="max-width:520px;margin:26px auto;padding:18px;font-family:system-ui">
  <h1 style="margin:0 0 10px;">Connexion admin</h1>

  <?php if ($err): ?>
    <div style="margin:10px 0;padding:10px;border-radius:10px;background:#f8d7da;border:1px solid #f5c2c7;color:#842029;">
      <?= h($err) ?>
    </div>
  <?php endif; ?>

  <form method="post" style="display:flex;gap:10px;align-items:center;margin-top:12px;">
    <input type="hidden" name="next" value="<?= h($next) ?>">
    <input name="code" type="password" autocomplete="one-time-code"
           placeholder="Code admin"
           style="flex:1;padding:10px 12px;border:1px solid #e5e7eb;border-radius:12px;font-size:16px;">
    <button type="submit"
            style="padding:10px 14px;border:0;border-radius:12px;background:#111827;color:#fff;font-weight:700;">
      Valider
    </button>
  </form>

  <div style="opacity:.7;margin-top:12px;font-size:13px;">
    Admin+ donne accès aux fonctions sensibles.
  </div>
</div>
