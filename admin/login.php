<?php
// admin/login.php

declare(strict_types=1);
require_once __DIR__ . '/../shared/bootstrap.php';

$err = '';
$attempts    = $_SESSION['login_attempts']     ?? 0;
$lastAttempt = $_SESSION['last_login_attempt'] ?? 0;
$now = time();

if ($attempts >= 5 && ($now - $lastAttempt) < 900) {
    $waitTime = 900 - ($now - $lastAttempt);
    $err = 'Trop de tentatives. Réessayez dans ' . ceil($waitTime / 60) . ' minutes.';
} elseif (($now - $lastAttempt) > 900) {
    $_SESSION['login_attempts'] = 0;
    $attempts = 0;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && empty($err)) {
    $code = (string)($_POST['code'] ?? '');

    error_log("=== LOGIN DEBUG ===");
    error_log("Code POST: '" . $code . "' (longueur: " . strlen($code) . ")");
    error_log("Code trimé: '" . trim($code) . "'");
    error_log("SUITE_MODE: " . (defined('SUITE_MODE') && SUITE_MODE ? 'OUI' : 'NON'));

    if (admin_login_with_code($code)) {
        error_log("LOGIN OK");
        unset($_SESSION['login_attempts'], $_SESSION['last_login_attempt']);
        $next = (string)($_POST['next'] ?? '');
        if ($next === '') $next = suite_base() . '/index.php';
        header('Location: ' . $next);
        exit;
    }

    error_log("LOGIN FAIL - Code rejete par admin_login_with_code()");
    $_SESSION['login_attempts'] = $attempts + 1;
    $_SESSION['last_login_attempt'] = $now;
    $nextUrl = $_SERVER['REQUEST_URI'] ?? 'login.php';
    header('Location: ' . $nextUrl . (strpos($nextUrl, '?') === false ? '?' : '&') . 'error=1');
    exit;
}

if (isset($_GET['error']) && $_GET['error'] === '1') {
    $err = 'Code invalide.';
}

$next = (string)($_GET['next'] ?? '');
if ($next === '') $next = suite_base() . '/index.php';

// Lien "retour" -> planning benevoles (page publique)
$planningUrl = rtrim(suite_base(), '/') . '/planning/index.php';

$attemptsLeft = max(0, 5 - $attempts);
?>
<!doctype html>
<html lang="fr">
<head>
  <meta charset="utf-8">
  <title>Connexion admin — Touraine-Ukraine</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <style>
    * { margin: 0; padding: 0; box-sizing: border-box; }

    body {
      margin: 0;
      /* Dégradé dans les tons du design system TU : ink-900 -> amber */
      background: linear-gradient(135deg, #1a1510 0%, #3d2608 60%, #c47328 100%);
      font-family: system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
      min-height: 100vh;
    }

    .wrap {
      min-height: 100vh;
      display: flex;
      align-items: center;
      justify-content: center;
      padding: 22px;
    }

    .card {
      width: 100%;
      max-width: 400px;
      background: #faf7f2; /* --tu-sand-50 */
      border-radius: 24px;
      box-shadow: 0 24px 64px rgba(0,0,0,.45);
      padding: 44px 36px;
      animation: slideUp 0.3s ease;
    }

    @keyframes slideUp {
      from { opacity: 0; transform: translateY(16px); }
      to   { opacity: 1; transform: translateY(0); }
    }

    .logo {
      width: 68px;
      height: 68px;
      margin: 0 auto 22px;
      background: linear-gradient(135deg, #1a1510, #c47328);
      border-radius: 16px;
      display: flex;
      align-items: center;
      justify-content: center;
      color: #fff;
      font-size: 28px;
      font-weight: 900;
      letter-spacing: 1px;
    }

    .header {
      text-align: center;
      margin-bottom: 28px;
    }

    .title {
      font-size: 22px;
      font-weight: 900;
      color: #1a1510;
      margin: 0 0 6px;
    }

    .sub {
      color: #8a7968;
      margin: 0;
      font-size: 13.5px;
      line-height: 1.5;
    }

    .form-group { margin-bottom: 20px; }

    .form-label {
      display: block;
      font-weight: 700;
      color: #3d2e1e;
      margin-bottom: 8px;
      font-size: 13px;
      text-transform: uppercase;
      letter-spacing: .05em;
    }

    input[type="password"] {
      width: 100%;
      padding: 14px 16px;
      border-radius: 12px;
      border: 2px solid #e8dfd4;
      background: #fff;
      font-size: 18px;
      outline: none;
      transition: all 0.2s;
      font-family: monospace;
      letter-spacing: 4px;
      text-align: center;
      color: #1a1510;
    }

    input[type="password"]:focus {
      border-color: #c47328;
      box-shadow: 0 0 0 4px rgba(196,115,40,.12);
    }

    .err {
      margin: 16px 0 0;
      padding: 11px 14px;
      border-radius: 10px;
      background: #fee2e2;
      color: #991b1b;
      border: 1px solid #fecaca;
      font-weight: 700;
      font-size: 13px;
      text-align: center;
    }

    .attempts {
      margin-top: 14px;
      text-align: center;
      color: #8a7968;
      font-size: 12px;
    }
    .attempts.warning { color: #c47328; font-weight: 700; }

    .link {
      display: block;
      margin-top: 22px;
      text-align: center;
      color: #8a7968;
      text-decoration: none;
      font-size: 13px;
      transition: color 0.2s;
    }
    .link:hover { color: #c47328; }

    .footer {
      margin-top: 28px;
      padding-top: 20px;
      border-top: 1px solid #e8dfd4;
      text-align: center;
      color: #b5a99a;
      font-size: 11px;
    }

    @media (max-width: 480px) {
      .card { padding: 32px 22px; }
    }
  </style>
</head>
<body>
  <div class="wrap">
    <div class="card">
      <div class="logo">TU</div>

      <div class="header">
        <h1 class="title">Espace Administrateur</h1>
        <p class="sub">Saisissez votre code d'accès pour continuer</p>
      </div>

      <form method="post" action="" id="loginForm">
        <input type="hidden" name="next" value="<?= h($next) ?>">
        <div class="form-group">
          <label class="form-label" for="code">Code d'accès</label>
          <input
            id="code"
            name="code"
            type="password"
            inputmode="numeric"
            autocomplete="new-password"
            placeholder="••••••••"
            pattern="\d{8}"
            maxlength="8"
            minlength="8"
            required
            autofocus
            <?= $attemptsLeft === 0 ? 'disabled' : '' ?>
          >
          <div id="loading" style="display:none;margin-top:12px;text-align:center;color:#8a7968;font-size:13px;font-weight:700;">
            Vérification en cours...
          </div>
        </div>
      </form>

      <?php if ($err !== ''): ?>
        <div class="err"><?= h($err) ?></div>
      <?php endif; ?>

      <?php if ($attemptsLeft > 0 && $attemptsLeft < 5): ?>
        <div class="attempts <?= $attemptsLeft <= 2 ? 'warning' : '' ?>">
          <?= $attemptsLeft ?> tentative<?= $attemptsLeft > 1 ? 's' : '' ?> restante<?= $attemptsLeft > 1 ? 's' : '' ?>
        </div>
      <?php endif; ?>

      <a class="link" href="<?= h($planningUrl) ?>">← Retour au planning</a>

      <div class="footer">
        Touraine-Ukraine &copy; <?= date('Y') ?>
      </div>
    </div>
  </div>

  <script>
    document.addEventListener('DOMContentLoaded', function() {
      const input   = document.getElementById('code');
      const form    = document.getElementById('loginForm');
      const loading = document.getElementById('loading');

      if (input && !input.disabled) {
        input.focus();
        input.select();

        form.addEventListener('submit', function(e) { e.preventDefault(); return false; });

        input.addEventListener('input', function() {
          const cleaned = this.value.replace(/\D/g, '');
          if (cleaned !== this.value) this.value = cleaned;
          if (cleaned.length === 8) {
            loading.style.display = 'block';
            input.setAttribute('readonly', 'readonly');
            setTimeout(function() { form.submit(); }, 300);
          }
        });
      }
    });
  </script>
</body>
</html>
