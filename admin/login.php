<?php
declare(strict_types=1);

require_once __DIR__ . '/../shared/bootstrap.php';

// IMPORTANT: aucun echo / HTML avant la logique de POST+redirect
$err = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $code = (string)($_POST['code'] ?? '');
    if (admin_login_with_code($code)) {
        $next = (string)($_POST['next'] ?? '');
        if ($next === '') $next = suite_base() . '/index.php';
        header('Location: ' . $next);
        exit;
    }
    $err = 'Code invalide.';
}

$next = (string)($_GET['next'] ?? '');
if ($next === '') $next = suite_base() . '/index.php';

?>
<!doctype html>
<html lang="fr">
<head>
  <meta charset="utf-8">
  <title>Connexion admin — Touraine-Ukraine</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <style>
    body{margin:0;background:#f5f6f8;font-family:system-ui,-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif;}
    .wrap{min-height:100vh;display:flex;align-items:center;justify-content:center;padding:22px;}
    .card{width:100%;max-width:420px;background:#fff;border:1px solid #e5e7eb;border-radius:18px;box-shadow:0 14px 40px rgba(15,23,42,.10);padding:18px;}
    .title{font-size:20px;font-weight:900;margin:0 0 6px;}
    .sub{opacity:.7;margin:0 0 14px;font-size:13px;}
    .row{display:flex;gap:10px;align-items:center;}
    input{width:100%;padding:12px 12px;border-radius:12px;border:1px solid #e5e7eb;font-size:16px;outline:none;}
    input:focus{border-color:rgba(29,78,216,.45);box-shadow:0 0 0 4px rgba(29,78,216,.10);}
    button{border:0;border-radius:999px;padding:10px 14px;font-weight:900;cursor:pointer;background:linear-gradient(135deg,#1d4ed8,#2563eb);color:#fff;}
    .err{margin:10px 0 0;padding:10px 12px;border-radius:12px;background:#fee2e2;color:#991b1b;border:1px solid #fecaca;font-weight:800;font-size:13px;}
    .link{display:inline-block;margin-top:12px;color:#1d4ed8;text-decoration:none;font-weight:800;font-size:13px;}
  </style>
</head>
<body>
  <div class="wrap">
    <div class="card">
      <h1 class="title">Connexion admin</h1>
      <p class="sub">Saisis ton code pour activer les modules Admin / Admin+.</p>

      <form method="post" action="">
        <input type="hidden" name="next" value="<?= h($next) ?>">
        <div class="row">
          <input name="code" type="password" inputmode="numeric" autocomplete="one-time-code"
                 placeholder="Code admin" required autofocus>
          <button type="submit">Valider</button>
        </div>
      </form>

      <?php if ($err !== ''): ?>
        <div class="err"><?= h($err) ?></div>
      <?php endif; ?>

      <a class="link" href="<?= h($next) ?>">← Retour</a>
    </div>
  </div>
</body>
</html>