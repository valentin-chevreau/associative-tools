<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$title = "Admin – Code d’accès";
ob_start();

require_once __DIR__ . '/../includes/app.php';
$config = require __DIR__ . '/../config/config.php';

$codeLength = 8;

// Si déjà connecté, redirige vers l'admin
if (!empty($_SESSION['is_admin']) || !empty($_SESSION['admin_authenticated'])) {
    header('Location: ' . $config['base_url'] . '/admin/events_list.php');
    exit;
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $codeInput = $_POST['code'] ?? '';
    $code = preg_replace('/\D+/', '', (string)$codeInput);

    if ($code === '') {
        $error = "Merci de saisir le code.";
    } elseif (strlen($code) !== $codeLength) {
        $error = "Le code doit contenir exactement $codeLength chiffres.";
    } else {
        $storedRaw = isset($config['admin_code']) ? (string)$config['admin_code'] : '';
        $stored = preg_replace('/\D+/', '', $storedRaw);

        if ($stored === '' || strlen($stored) !== $codeLength) {
            $error = "Code incorrect.";
        } elseif (hash_equals($stored, $code)) {
            $_SESSION['admin_authenticated'] = true;
            $_SESSION['is_admin'] = true;
            header('Location: ' . $config['base_url'] . '/admin/events_list.php');
            exit;
        } else {
            $error = "Code incorrect.";
        }
    }
}
?>
<div class="card">
  <h2>Accès administration</h2>
  <p class="muted">
    Saisis ton <strong>code d’accès à <?= $codeLength ?> chiffres</strong> pour entrer dans l’administration.
  </p>

  <?php if (!empty($error)): ?>
    <p style="color:#b91c1c; margin-top:8px; font-weight:600;">
      <?= htmlspecialchars($error) ?>
    </p>
  <?php endif; ?>

  <style>
    .pin-wrapper {
      margin-top: 16px;
      display: flex;
      flex-direction: column;
      gap: 14px;
      max-width: 320px;
      margin-left: auto;
      margin-right: auto;
    }

    .pin-display {
      display: flex;
      justify-content: center;
      gap: 8px;
      margin-bottom: 4px;
    }

    .pin-dot {
      width: 14px;
      height: 14px;
      border-radius: 999px;
      border: 1px solid #9ca3af;
      background: #f3f4f6;
      transition: background 0.15s, border-color 0.15s;
    }

    .pin-dot.filled {
      background: #111827;
      border-color: #111827;
    }

    .pin-input-hidden {
      position: absolute;
      opacity: 0;
      pointer-events: none;
      height: 0;
      width: 0;
    }

    .pad-grid {
      display: grid;
      grid-template-columns: repeat(3, 1fr);
      gap: 10px;
      margin-top: 8px;
    }

    .pad-key {
      width: 100%;
      padding: 14px 0;
      border-radius: 999px;
      border: 1px solid #d1d5db;
      background: #ffffff;
      font-size: 18px;
      font-weight: 600;
      color: #111827;
      cursor: pointer;
      text-align: center;
      user-select: none;
      display: flex;
      justify-content: center;
      align-items: center;
      transition: background 0.1s ease, transform 0.05s ease;
    }

    .pad-key:hover {
      background: #f3f4f6;
    }

    .pad-key:active {
      transform: scale(0.97);
    }

    .pad-key-secondary {
      font-size: 13px;
      font-weight: 500;
      color: #6b7280;
    }

    .pin-actions {
      display: flex;
      justify-content: space-between;
      align-items: center;
      margin-top: 6px;
      flex-wrap: wrap;
      gap: 8px;
    }

    .pin-hint {
      font-size: 11px;
      color: #6b7280;
    }
  </style>

  <form method="post" class="pin-wrapper" id="admin-pin-form" autocomplete="off">
    <input
      type="password"
      name="code"
      id="code-input"
      class="pin-input-hidden"
      inputmode="numeric"
      pattern="[0-9]*"
    >

    <div class="pin-display" id="pin-dots">
      <?php for ($i = 0; $i < $codeLength; $i++): ?>
        <div class="pin-dot" data-index="<?= $i ?>"></div>
      <?php endfor; ?>
    </div>

    <div class="pad-grid">
      <?php foreach ([1,2,3,4,5,6,7,8,9] as $d): ?>
        <button class="pad-key" type="button" data-key="<?= $d ?>"><?= $d ?></button>
      <?php endforeach; ?>
      <button class="pad-key pad-key-secondary" type="button" data-key="clear">Effacer</button>
      <button class="pad-key" type="button" data-key="0">0</button>
      <button class="pad-key pad-key-secondary" type="button" data-key="back">←</button>
    </div>

    <div class="pin-actions">
      <span class="pin-hint">Tu peux aussi taper directement au clavier.</span>
      <button type="submit" class="primary" id="submit-btn">Valider</button>
    </div>
  </form>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
  const form  = document.getElementById('admin-pin-form');
  const input = document.getElementById('code-input');
  const dots  = document.querySelectorAll('#pin-dots .pin-dot');
  const maxLen = <?= $codeLength ?>;

  function syncDots() {
    const len = input.value.length;
    dots.forEach((dot, idx) => {
      dot.classList.toggle('filled', idx < len);
    });

    if (len === maxLen) {
      setTimeout(() => {
        form.submit();
      }, 80);
    }
  }

  document.querySelectorAll('.pad-key[data-key]').forEach(btn => {
    btn.addEventListener('click', function (e) {
      e.preventDefault();
      const key = this.getAttribute('data-key');

      if (key === 'clear') {
        input.value = '';
      } else if (key === 'back') {
        input.value = input.value.slice(0, -1);
      } else {
        if (input.value.length < maxLen) {
          input.value += key;
        }
      }
      syncDots();
    });
  });

  input.addEventListener('input', function () {
    let v = input.value.replace(/\D+/g, '');
    if (v.length > maxLen) v = v.slice(0, maxLen);
    input.value = v;
    syncDots();
  });

  form.addEventListener('submit', function () {
    // Le serveur valide le code
  });

  input.focus();
  syncDots();
});
</script>

<?php
$content = ob_get_clean();
include __DIR__ . '/../includes/layout.php';