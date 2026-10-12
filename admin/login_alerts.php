<?php
// admin/login_alerts.php — Alertes e-mail de connexion (super admin uniquement)
//
// Chaque connexion prévient les super admins par e-mail, sauf celles des utilisateurs exclus ici.
// Seuls les super admins reçoivent ces alertes, et jamais celle de leur propre connexion.
// Fonctionnement : shared/login_alerts.php ; envoi : shared/mailer.php (config MAIL_* du .env).

declare(strict_types=1);

require_once __DIR__ . '/../shared/bootstrap.php';
require_once __DIR__ . '/../shared/login_alerts.php';

if (!defined('APP_BASE')) {
    define('APP_BASE', suite_base() . '/admin');
}

if (!is_super_admin()) {
    suite_forbidden("Les alertes de connexion sont réservées aux super administrateurs.", "Accès refusé", "login-alerts", "Super admin");
}

$pdo = _bootstrap_get_pdo();
if (!($pdo instanceof PDO)) {
    http_response_code(500);
    echo "Connexion base de données indisponible.";
    exit;
}

if (!function_exists('h')) {
    function h(string $s): string { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }
}

$tablesOk = true;
try {
    $pdo->query("SELECT 1 FROM suite_login_alert_exclusions LIMIT 1");
} catch (Throwable) {
    $tablesOk = false;
}

$me = (int)(current_volunteer_id() ?? 0);
$errors = [];
$success = null;

// Utilisateurs pouvant se connecter à l'espace admin (seuls ceux-là ont un code de connexion actif).
$users = $pdo->query("
    SELECT id, first_name, last_name, role, email, is_active
    FROM users
    WHERE role IN ('admin', 'admin_plus', 'super_admin') AND deleted_at IS NULL
    ORDER BY (role = 'super_admin') DESC, last_name, first_name
")->fetchAll(PDO::FETCH_ASSOC);
$byId = [];
foreach ($users as $u) $byId[(int)$u['id']] = $u;
$roleLabels = ['super_admin' => 'Super admin', 'admin_plus' => 'Admin+', 'admin' => 'Admin'];
$fullName = static fn(array $u): string => trim($u['first_name'] . ' ' . $u['last_name']);

if ($tablesOk && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string)($_POST['action'] ?? '');

    if ($action === 'save') {
        $before = suite_login_alert_excluded_ids($pdo);
        $after = [];
        foreach ((array)($_POST['exclude'] ?? []) as $v) {
            $id = (int)$v;
            if (isset($byId[$id])) $after[$id] = $id;
        }
        $after = array_values($after);

        $pdo->beginTransaction();
        try {
            $pdo->exec("DELETE FROM suite_login_alert_exclusions");
            $ins = $pdo->prepare("INSERT INTO suite_login_alert_exclusions (user_id, created_by) VALUES (?, ?)");
            foreach ($after as $id) $ins->execute([$id, $me ?: null]);
            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            $errors[] = "Enregistrement impossible : " . $e->getMessage();
        }

        if (!$errors) {
            $names = static fn(array $ids): array => array_values(array_map(fn($i) => isset($byId[$i]) ? $fullName($byId[$i]) : "#$i", $ids));
            if (array_diff($after, $before) || array_diff($before, $after)) {
                audit_log('admin', 'update', 'login_alerts', null, 'Alertes de connexion', ['changes' => [
                    'Utilisateurs exclus des alertes' => ['from' => $names($before), 'to' => $names($after)],
                ]]);
            }
            $success = count($after) === 0
                ? "Toutes les connexions sont surveillées."
                : "Alertes enregistrées : " . count($after) . " utilisateur" . (count($after) > 1 ? 's' : '') . " exclu" . (count($after) > 1 ? 's' : '') . ".";
        }
    }

    if ($action === 'test') {
        $meUser = $byId[$me] ?? null;
        if (!suite_mail_ready()) {
            $errors[] = "L'envoi d'e-mails n'est pas configuré (MAIL_FROM manquant dans le .env de la suite).";
        } elseif (!$meUser || trim((string)$meUser['email']) === '') {
            $errors[] = "Votre fiche utilisateur n'a pas d'adresse e-mail : ajoutez-la dans Utilisateurs.";
        } else {
            $mailCfg0 = suite_mail_config();
            $err = suite_mail_send((string)$meUser['email'], 'Test des alertes de connexion — Touraine-Ukraine',
                "Ceci est un e-mail de test.\n\nSi vous le lisez, les alertes de connexion pourront vous parvenir à cette adresse.\n",
                suite_mail_html('Les alertes fonctionnent', 'Ceci est un e-mail de test. Si vous le lisez, les alertes de connexion pourront vous parvenir à cette adresse.',
                    [['Destinataire', (string)$meUser['email']], ['Envoyé par', $mailCfg0['from']]], null,
                    'Envoyé depuis Administration › Alertes de connexion.', 'E-mail de test'));
            if ($err === null) $success = "E-mail de test envoyé à " . $meUser['email'] . ".";
            else $errors[] = "Échec de l'envoi : " . $err;
        }
    }
}

$excluded = $tablesOk ? suite_login_alert_excluded_ids($pdo) : [];
$mailCfg = suite_mail_config();
$mailReady = suite_mail_ready();
$transport = $mailCfg['host'] !== '' ? 'SMTP ' . $mailCfg['host'] . ':' . $mailCfg['port'] : 'fonction mail() du serveur';

// Destinataires : super admins actifs avec adresse e-mail.
$recipients = array_values(array_filter($users, static fn($u) => $u['role'] === 'super_admin' && (int)$u['is_active'] === 1));

$pageTitle = 'Alertes de connexion — Touraine-Ukraine';
?>
<!doctype html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <title><?= h($pageTitle) ?></title>
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <link rel="stylesheet" href="<?= h(suite_base()) ?>/assets/css/suite_nav.css<?= function_exists('suite_css_v') ? suite_css_v() : '' ?>"><?= function_exists('suite_pwa_head') ? suite_pwa_head() : '' ?>
    <style>
      body.tu-v2 { display: block; }
      body.tu-v2 .tu-main { margin-left: var(--tu-sw); padding: 24px; }
      @media (max-width: 900px) {
        body.tu-v2 .tu-main { margin-left: 0; padding: 16px; padding-top: 70px; }
      }
      .la-head { padding: 14px 18px; border-bottom: 1px solid var(--tu-ink-100); }
      .la-head b { font-family: var(--tu-font-d); font-size: 14px; }
      .la-head p { margin: 2px 0 0; font-size: 12px; color: var(--tu-ink-300); }
      .la-body { padding: 16px 18px; }
      .la-foot { padding: 14px 18px; border-top: 1px solid var(--tu-ink-100); display: flex; justify-content: space-between; align-items: center; gap: 10px; flex-wrap: wrap; }
      .la-foot span { font-size: 12px; color: var(--tu-ink-300); }
      .la-row { display: flex; justify-content: space-between; gap: 12px; padding: 9px 0; border-top: 1px solid var(--tu-ink-100); font-size: 13px; flex-wrap: wrap; }
      .la-row:first-child { border-top: 0; }
      .la-row small { color: var(--tu-ink-300); }
    </style>
</head>
<body class="tu-v2">

<?php
require_once dirname(__DIR__) . '/shared/suite_nav.php';
suite_nav_render('login-alerts', '');
?>
<div class="tu-main">

<div class="tu-topbar">
  <div class="tu-bc">
    <a href="<?= h(suite_base()) ?>/index.php" style="color:inherit;text-decoration:none;">Accueil</a>
    <span class="tu-bc-sep">›</span>
    <span class="tu-bc-cur">Alertes de connexion</span>
  </div>
  <div class="tu-topbar-acts">
    <a href="audit_log.php" class="tu-btn tu-btn-s tu-btn-sm">Journal d'audit</a>
  </div>
</div>

<div class="tu-pg">

  <?php if ($success): ?>
    <div style="background:var(--tu-green-soft);border:1.5px solid rgba(42,125,74,.25);border-radius:12px;padding:12px 16px;margin-bottom:16px;color:var(--tu-green-main);font-size:13px;"><?= h($success) ?></div>
  <?php endif; ?>
  <?php if ($errors): ?>
    <div style="background:var(--tu-red-soft);border-radius:12px;padding:12px 16px;margin-bottom:16px;color:var(--tu-red-main);font-size:13px;"><ul style="margin:0;padding-left:16px;"><?php foreach ($errors as $e): ?><li><?= h($e) ?></li><?php endforeach; ?></ul></div>
  <?php endif; ?>

  <div class="tu-ph">
    <div>
      <div class="tu-ph-title">Alertes de connexion</div>
      <div class="tu-ph-sub">Être prévenu par e-mail quand un utilisateur se connecte</div>
    </div>
  </div>

  <?php if (!$tablesOk): ?>
    <div class="tu-card" style="padding:20px;">
      <div style="font-weight:800;margin-bottom:6px;">Migration SQL à exécuter</div>
      <div style="font-size:13px;color:var(--tu-ink-400);">
        La table des alertes n'existe pas encore. Exécutez la migration
        <code>admin / 003_suite_login_alerts_exclusions.sql</code> depuis
        <a href="migrations.php" style="color:var(--tu-amber-600);font-weight:700;">Migrations SQL</a>.
      </div>
    </div>
  <?php else: ?>

  <div class="tu-card" style="overflow:visible;margin-bottom:22px;">
    <div class="la-head">
      <b>Connexions surveillées</b>
      <p>Toutes les connexions envoient un e-mail aux super admins (jamais pour votre propre connexion). Ajoutez ici les exceptions.</p>
    </div>
    <form method="post">
      <input type="hidden" name="action" value="save">
      <div class="la-body">
        <label style="display:block;font-size:12px;font-weight:700;color:var(--tu-ink-500);margin-bottom:6px;">Utilisateurs exclus des alertes</label>
        <select multiple name="exclude[]" class="tu-input" data-bulk data-placeholder="Aucune exception — tout le monde est surveillé">
          <?php foreach ($users as $u): $uid = (int)$u['id']; ?>
            <option value="<?= $uid ?>" <?= in_array($uid, $excluded, true) ? 'selected' : '' ?>>
              <?= h($fullName($u)) ?> (<?= h($roleLabels[$u['role']] ?? $u['role']) ?>)<?= (int)$u['is_active'] ? '' : ' — désactivé' ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="la-foot">
        <span><?= count($excluded) === 0 ? 'Tout le monde est surveillé' : count($excluded) . ' exception' . (count($excluded) > 1 ? 's' : '') ?></span>
        <button type="submit" class="tu-btn tu-btn-p">Enregistrer</button>
      </div>
    </form>
  </div>

  <div class="tu-card" style="margin-bottom:22px;">
    <div class="la-head">
      <b>Qui reçoit les alertes ?</b>
      <p>Uniquement les super admins actifs ayant une adresse e-mail (réglable dans Utilisateurs).</p>
    </div>
    <div class="la-body">
      <?php foreach ($recipients as $u): $hasMail = trim((string)$u['email']) !== ''; ?>
        <div class="la-row">
          <span><b><?= h($fullName($u)) ?></b><?= (int)$u['id'] === $me ? ' <small>(vous)</small>' : '' ?></span>
          <?php if ($hasMail): ?>
            <span><?= h((string)$u['email']) ?></span>
          <?php else: ?>
            <span style="color:var(--tu-red-main);">Aucune adresse e-mail : ne recevra rien</span>
          <?php endif; ?>
        </div>
      <?php endforeach; ?>
    </div>
  </div>

  <div class="tu-card" style="margin-bottom:22px;">
    <div class="la-head">
      <b>Envoi des e-mails</b>
      <p>Paramétrage dans le .env de la suite : MAIL_FROM, et MAIL_HOST / MAIL_PORT / MAIL_USER / MAIL_PASS / MAIL_ENCRYPTION pour un serveur SMTP.</p>
    </div>
    <div class="la-body">
      <div class="la-row">
        <span>État</span>
        <span style="font-weight:700;color:<?= $mailReady ? 'var(--tu-green-main)' : 'var(--tu-red-main)' ?>;"><?= $mailReady ? 'Configuré' : 'Non configuré (MAIL_FROM manquant)' ?></span>
      </div>
      <?php if ($mailReady): ?>
        <div class="la-row"><span>Expéditeur</span><span><?= h($mailCfg['from_name']) ?> &lt;<?= h($mailCfg['from']) ?>&gt;</span></div>
        <div class="la-row"><span>Transport</span><span><?= h($transport) ?></span></div>
      <?php endif; ?>
    </div>
    <form method="post" class="la-foot">
      <input type="hidden" name="action" value="test">
      <span>Envoie un message de test à votre propre adresse.</span>
      <button type="submit" class="tu-btn tu-btn-s" <?= $mailReady ? '' : 'disabled' ?>>Envoyer un e-mail de test</button>
    </form>
  </div>

  <?php endif; ?>

</div>
</div><!-- /tu-main -->
</body>
</html>
