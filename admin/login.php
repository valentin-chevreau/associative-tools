<?php
declare(strict_types=1);
require_once __DIR__ . '/../shared/bootstrap.php';

// IMPORTANT: aucun echo / HTML avant la logique de POST+redirect
$err = '';
$attempts = $_SESSION['login_attempts'] ?? 0;
$lastAttempt = $_SESSION['last_login_attempt'] ?? 0;
$now = time();

// Rate limiting : max 5 tentatives par 15 minutes
if ($attempts >= 5 && ($now - $lastAttempt) < 900) {
    $waitTime = 900 - ($now - $lastAttempt);
    $err = 'Trop de tentatives. Réessayez dans ' . ceil($waitTime / 60) . ' minutes.';
} elseif (($now - $lastAttempt) > 900) {
    // Reset après 15 minutes
    $_SESSION['login_attempts'] = 0;
    $attempts = 0;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && empty($err)) {
    $code = (string)($_POST['code'] ?? '');
    
    // DEBUG TEMPORAIRE
    error_log("=== LOGIN DEBUG ===");
    error_log("Code POST: '" . $code . "' (longueur: " . strlen($code) . ")");
    error_log("Code trimé: '" . trim($code) . "'");
    error_log("SUITE_MODE: " . (defined('SUITE_MODE') && SUITE_MODE ? 'OUI' : 'NON'));
    
    if (admin_login_with_code($code)) {
        error_log("✅ LOGIN OK");
        // Succès : reset compteur et redirection
        unset($_SESSION['login_attempts'], $_SESSION['last_login_attempt']);
        $next = (string)($_POST['next'] ?? '');
        if ($next === '') $next = suite_base() . '/index.php';
        header('Location: ' . $next);
        exit;
    }
    
    error_log("❌ LOGIN FAIL - Code rejeté par admin_login_with_code()");
    // Échec : incrémenter compteur
    $_SESSION['login_attempts'] = $attempts + 1;
    $_SESSION['last_login_attempt'] = $now;
    
    // POST-Redirect-GET : rediriger pour éviter le resubmit au refresh
    $nextUrl = $_SERVER['REQUEST_URI'] ?? 'login.php';
    header('Location: ' . $nextUrl . (strpos($nextUrl, '?') === false ? '?' : '&') . 'error=1');
    exit;
}

// Récupérer l'erreur depuis l'URL (après redirection)
if (isset($_GET['error']) && $_GET['error'] === '1') {
    $err = 'Code invalide.';
}

$next = (string)($_GET['next'] ?? '');
if ($next === '') $next = suite_base() . '/index.php';

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
      background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
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
      max-width: 440px;
      background: #fff;
      border-radius: 24px;
      box-shadow: 0 20px 60px rgba(0, 0, 0, 0.3);
      padding: 48px 40px;
      animation: slideUp 0.3s ease;
    }
    
    @keyframes slideUp {
      from {
        opacity: 0;
        transform: translateY(20px);
      }
      to {
        opacity: 1;
        transform: translateY(0);
      }
    }
    
    .logo {
      width: 72px;
      height: 72px;
      margin: 0 auto 24px;
      background: linear-gradient(135deg, #2563eb, #22c55e);
      border-radius: 18px;
      display: flex;
      align-items: center;
      justify-content: center;
      color: #fff;
      font-size: 32px;
      font-weight: 900;
      letter-spacing: 1px;
    }
    
    .header {
      text-align: center;
      margin-bottom: 32px;
    }
    
    .title {
      font-size: 26px;
      font-weight: 900;
      color: #111827;
      margin: 0 0 8px;
    }
    
    .sub {
      color: #6b7280;
      margin: 0;
      font-size: 14px;
      line-height: 1.5;
    }
    
    .form-group {
      margin-bottom: 20px;
    }
    
    .form-label {
      display: block;
      font-weight: 700;
      color: #374151;
      margin-bottom: 8px;
      font-size: 14px;
    }
    
    input[type="password"] {
      width: 100%;
      padding: 14px 16px;
      border-radius: 12px;
      border: 2px solid #e5e7eb;
      font-size: 16px;
      outline: none;
      transition: all 0.2s;
      font-family: monospace;
      letter-spacing: 3px;
      text-align: center;
    }
    
    input[type="password"]:focus {
      border-color: #2563eb;
      box-shadow: 0 0 0 4px rgba(37, 99, 235, 0.1);
    }
    
    button {
      width: 100%;
      border: 0;
      border-radius: 12px;
      padding: 16px;
      font-weight: 900;
      font-size: 16px;
      cursor: pointer;
      background: linear-gradient(135deg, #2563eb, #3b82f6);
      color: #fff;
      transition: all 0.2s;
    }
    
    button:hover:not(:disabled) {
      transform: translateY(-2px);
      box-shadow: 0 12px 24px rgba(37, 99, 235, 0.3);
    }
    
    button:disabled {
      opacity: 0.5;
      cursor: not-allowed;
    }
    
    .err {
      margin: 20px 0 0;
      padding: 12px 16px;
      border-radius: 12px;
      background: #fee2e2;
      color: #991b1b;
      border: 1px solid #fecaca;
      font-weight: 700;
      font-size: 14px;
      text-align: center;
    }
    
    .attempts {
      margin-top: 16px;
      text-align: center;
      color: #6b7280;
      font-size: 13px;
    }
    
    .attempts.warning {
      color: #ea580c;
      font-weight: 700;
    }
    
    .link {
      display: block;
      margin-top: 24px;
      text-align: center;
      color: #2563eb;
      text-decoration: none;
      font-weight: 700;
      font-size: 14px;
      transition: color 0.2s;
    }
    
    .link:hover {
      color: #1d4ed8;
    }
    
    .footer {
      margin-top: 32px;
      padding-top: 24px;
      border-top: 1px solid #e5e7eb;
      text-align: center;
      color: #9ca3af;
      font-size: 12px;
    }
    
    @media (max-width: 480px) {
      .card {
        padding: 32px 24px;
      }
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
          <div id="loading" style="display:none; margin-top:12px; text-align:center; color:#6b7280; font-size:14px; font-weight:700;">
            ⏳ Vérification...
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
      
      <a class="link" href="<?= h($next) ?>">← Retour à l'accueil</a>
      
      <div class="footer">
        Touraine-Ukraine © <?= date('Y') ?>
      </div>
    </div>
  </div>
  
  <script>
    document.addEventListener('DOMContentLoaded', function() {
      const input = document.getElementById('code');
      const form = document.getElementById('loginForm');
      const loading = document.getElementById('loading');
      
      if (input && !input.disabled) {
        input.focus();
        input.select();
        
        // Empêcher la soumission du formulaire par défaut (Enter)
        form.addEventListener('submit', function(e) {
          e.preventDefault();
          return false;
        });
        
        // Auto-submit UNIQUEMENT quand exactement 8 caractères
        input.addEventListener('input', function(e) {
          const code = this.value;
          
          // Nettoyer : garder seulement les chiffres
          const cleaned = code.replace(/\D/g, '');
          if (cleaned !== code) {
            this.value = cleaned;
          }
          
          // Soumettre UNIQUEMENT si exactement 8 chiffres
          if (cleaned.length === 8) {
            loading.style.display = 'block';
            input.setAttribute('readonly', 'readonly'); // readonly au lieu de disabled
            
            setTimeout(function() {
              form.submit();
            }, 300);
          }
        });
      }
    });
  </script>
</body>
</html>