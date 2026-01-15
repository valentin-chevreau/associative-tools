<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$config = require __DIR__ . '/../config/config.php';
require_once __DIR__ . '/app.php';

$currentVolunteer = getCurrentVolunteer();
// On ne s'appuie plus sur isAdminAuthenticated ici
$adminLogged = !empty($_SESSION['is_admin']) || !empty($_SESSION['admin_authenticated']);
?>
<!DOCTYPE html>
<html lang="fr">
<head>
  <meta charset="UTF-8">
  <title><?= htmlspecialchars($title ?? 'Planning') ?></title>
  <meta name="viewport" content="width=device-width, initial-scale=1">

  <style>
    :root {
      --primary: #2563eb;
      --primary-soft: rgba(37, 99, 235, 0.08);
      --bg: #f3f4f6;
      --card-bg: #ffffff;
      --text-main: #111827;
      --text-muted: #6b7280;
      --border-subtle: #e5e7eb;
      --danger: #dc2626;
      --danger-soft: rgba(220, 38, 38, 0.08);
    }

    * { box-sizing: border-box; }

    body {
      font-family: system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
      margin: 0;
      padding: 0;
      background: var(--bg);
      color: var(--text-main);
    }

    header {
      position: sticky;
      top: 0;
      z-index: 50;
      backdrop-filter: blur(10px);
      background: #ffffffcc;
      border-bottom: 1px solid #e5e7eb;
    }

    .nav-inner {
      max-width: 960px;
      margin: 0 auto;
      padding: 10px 14px;
      display: flex;
      align-items: center;
      justify-content: space-between;
      gap: 10px;
    }

    .nav-left {
      display: flex;
      align-items: center;
      gap: 10px;
    }

    .nav-logo {
      width: 32px;
      height: 32px;
      border-radius: 999px;
      background: linear-gradient(135deg, #2563eb, #3b82f6);
      display: flex;
      align-items: center;
      justify-content: center;
      font-weight: 700;
      font-size: 16px;
      color: white;
      box-shadow: 0 6px 14px rgba(37, 99, 235, 0.4);
    }

    .nav-title {
      display: flex;
      flex-direction: column;
      line-height: 1.2;
    }

    .nav-title-main {
      font-size: 15px;
      font-weight: 600;
      letter-spacing: 0.02em;
      color: #111827;
    }

    .nav-title-sub {
      font-size: 11px;
      color: var(--text-muted);
    }

    .nav-links {
      display: flex;
      align-items: center;
      gap: 6px;
      flex-wrap: wrap;
      justify-content: flex-end;
    }

    .nav-pill {
      display: inline-flex;
      align-items: center;
      gap: 6px;
      padding: 5px 10px;
      border-radius: 999px;
      border: 1px solid #e5e7eb;
      background: #f9fafb;
      color: var(--text-muted);
      font-size: 11px;
      text-decoration: none;
      white-space: nowrap;
      transition: all 0.15s ease;
    }

    .nav-pill:hover {
      border-color: var(--primary);
      color: #111827;
      background: #eef2ff;
    }

    .nav-pill-primary {
      border-color: transparent;
      background: linear-gradient(135deg, #2563eb, #3b82f6);
      color: white;
      font-weight: 500;
    }

    .nav-pill-primary:hover {
      box-shadow: 0 8px 18px rgba(37, 99, 235, 0.4);
    }

    .nav-user {
      font-size: 11px;
      color: var(--text-muted);
    }

    .container {
      max-width: 960px;
      margin: 16px auto 24px;
      padding: 0 14px 20px;
    }

    .card {
      background: var(--card-bg);
      border-radius: 16px;
      padding: 14px;
      margin-bottom: 12px;
      border: 1px solid var(--border-subtle);
      box-shadow: 0 4px 10px rgba(15, 23, 42, 0.04);
    }

    h1, h2, h3 {
      font-weight: 600;
      color: #111827;
      margin: 0 0 8px;
    }

    h2 { font-size: 18px; }
    h3 { font-size: 15px; }

    p {
      font-size: 13px;
      margin: 4px 0;
      color: var(--text-muted);
    }

    button {
      padding: 6px 10px;
      border-radius: 999px;
      border: 1px solid #d1d5db;
      background: #f9fafb;
      cursor: pointer;
      font-size: 12px;
      color: #111827;
      display: inline-flex;
      align-items: center;
      gap: 6px;
      transition: all 0.15s ease;
    }

    button.primary {
      border-color: transparent;
      background: linear-gradient(135deg, #22c55e, #16a34a);
      color: white;
      font-weight: 500;
    }

    button.danger {
      border-color: transparent;
      background: linear-gradient(135deg, #ef4444, #b91c1c);
      color: white;
      font-weight: 500;
    }

    button:hover {
      filter: brightness(0.98);
      box-shadow: 0 4px 10px rgba(15, 23, 42, 0.12);
    }

    input[type="text"],
    input[type="email"],
    input[type="datetime-local"],
    input[type="number"],
    input[type="date"],
    textarea,
    select {
      width: 100%;
      padding: 7px 9px;
      border-radius: 10px;
      border: 1px solid #d1d5db;
      background: #ffffff;
      color: #111827;
      font-size: 13px;
      transition: border-color 0.15s ease, box-shadow 0.15s ease, background-color 0.15s ease;
    }

    input::placeholder,
    textarea::placeholder {
      color: #9ca3af;
    }

    input:focus,
    textarea:focus,
    select:focus {
      outline: none;
      border-color: #2563eb;
      box-shadow: 0 0 0 1px rgba(37, 99, 235, 0.25);
      background: #f9fafb;
    }

    textarea {
      resize: vertical;
      min-height: 70px;
    }

    .muted {
      color: var(--text-muted);
      font-size: 12px;
    }

    .attendees-row {
      display: flex;
      flex-wrap: wrap;
      gap: 6px;
      margin-top: 6px;
    }

    .attendee-pill {
      display: inline-flex;
      align-items: center;
      gap: 6px;
      padding: 3px 8px;
      border-radius: 999px;
      background: #f3f4f6;
      border: 1px solid #e5e7eb;
      font-size: 11px;
      color: #111827;
    }

    .attendee-avatar {
      width: 18px;
      height: 18px;
      border-radius: 999px;
      background: linear-gradient(135deg, #2563eb, #3b82f6);
      display: inline-flex;
      align-items: center;
      justify-content: center;
      font-size: 10px;
      font-weight: 600;
      color: white;
    }

    .badge {
      display: inline-flex;
      align-items: center;
      gap: 4px;
      padding: 2px 7px;
      border-radius: 999px;
      border: 1px solid #e5e7eb;
      background: #f9fafb;
      font-size: 11px;
      color: var(--text-muted);
    }

    .form {
      display: flex;
      flex-direction: column;
      gap: 14px;
      max-width: 760px;
    }

    .form-section {
      margin-top: 6px;
      padding-top: 8px;
      border-top: 1px dashed var(--border-subtle);
    }

    .form-section-title {
      font-size: 11px;
      text-transform: uppercase;
      letter-spacing: 0.12em;
      color: var(--text-muted);
      margin-bottom: 8px;
    }

    .form-grid {
      display: grid;
      grid-template-columns: minmax(0, 1fr);
      gap: 10px 16px;
    }

    @media (min-width: 640px) {
      .form-grid--2 {
        grid-template-columns: repeat(2, minmax(0, 1fr));
      }
    }

    .field {
      display: flex;
      flex-direction: column;
      gap: 3px;
    }

    .field-label {
      font-size: 12px;
      font-weight: 500;
      color: #374151;
    }

    .field-hint {
      font-size: 11px;
      color: var(--text-muted);
    }

    .field-inline {
      display: flex;
      align-items: center;
      gap: 6px;
      font-size: 12px;
    }

    @media (max-width: 640px) {
      .nav-inner {
        flex-direction: column;
        align-items: flex-start;
      }
      .nav-links {
        justify-content: flex-start;
      }
    }
  </style>
</head>
<body>

<?php
// Barre de navigation transversale (Suite : Planning / Caisse / Logistique)
require_once __DIR__ . "/../../shared/suite_nav.php";
?>

<header>
  <div class="nav-inner">
    <div class="nav-left">
      <div class="nav-logo">TU</div>
      <div class="nav-title">
        <span class="nav-title-main">Planning bénévoles</span>
        <span class="nav-title-sub">Touraine–Ukraine</span>
      </div>
    </div>

    <div class="nav-links">
      <a class="nav-pill" href="<?= $config['base_url'] ?>/index.php">Planning</a>

      <?php if ($adminLogged): ?>
        <a class="nav-pill" href="<?= $config['base_url'] ?>/admin/donations.php">Dons</a>
        <a class="nav-pill" href="<?= $config['base_url'] ?>/admin/events_list.php">Admin</a>
        <a class="nav-pill" href="<?= $config['base_url'] ?>/admin/logout.php">Déconnexion admin</a>
      <?php else: ?>
        <a class="nav-pill nav-pill-primary" href="<?= $config['base_url'] ?>/admin/login.php">Saisir code admin</a>
      <?php endif; ?>

      <?php if ($currentVolunteer): ?>
        <span class="nav-user">
          Connecté comme <?= htmlspecialchars($currentVolunteer['first_name'] . ' ' . $currentVolunteer['last_name']) ?>
        </span>
      <?php endif; ?>
    </div>
  </div>
</header>

<div class="container">
  <?= $content ?>
</div>

<footer>
  <div class="nav-inner" style="justify-content:flex-start; padding:6px;">
    <span class="nav-user">© <?= date('Y') ?> Touraine–Ukraine – Outil de planning bénévoles</span>
  </div>
</footer>

</body>
</html>