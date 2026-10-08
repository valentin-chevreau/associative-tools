<?php
// admin/permissions.php — Droits d'accès par module (super admin uniquement)
//
// 1) Matrice RÔLE × MODULE : accès par défaut des rôles admin / admin+.
//    (le super admin a toujours accès à tout, non modifiable)
// 2) Droits PARTICULIERS : surcharge pour un utilisateur donné
//    (autoriser ou retirer l'accès à un module, quel que soit son rôle).
// Résolution : surcharge utilisateur > défaut du rôle. Voir shared/bootstrap.php.

declare(strict_types=1);

require_once __DIR__ . '/../shared/bootstrap.php';

if (!defined('APP_BASE')) {
    define('APP_BASE', suite_base() . '/admin');
}

if (!is_super_admin()) {
    suite_forbidden("La gestion des droits est réservée aux super administrateurs.", "Accès refusé", "users", "Super admin");
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

$modules = suite_modules();
$editableRoles = ['admin' => 'Admin', 'admin_plus' => 'Admin+'];

// Les tables sont créées par planning/migrations/004_permissions_and_user_soft_delete.sql
$tablesOk = true;
try {
    $pdo->query("SELECT 1 FROM suite_role_permissions LIMIT 1");
    $pdo->query("SELECT 1 FROM suite_user_permissions LIMIT 1");
} catch (Throwable $e) {
    $tablesOk = false;
}

$errors = [];
$success = null;

if ($tablesOk && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    // ── Matrice des rôles ───────────────────────────────────────────────────
    if ($action === 'save_roles') {
        $stmt = $pdo->prepare("
            INSERT INTO suite_role_permissions (role, module, allowed) VALUES (?, ?, ?)
            ON DUPLICATE KEY UPDATE allowed = VALUES(allowed)
        ");
        $prev = [];
        foreach ($pdo->query("SELECT role, module, allowed FROM suite_role_permissions")->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $prev[$r['role']][$r['module']] = (int)$r['allowed'];
        }
        $changes = [];
        foreach (array_keys($editableRoles) as $role) {
            foreach (array_keys($modules) as $module) {
                $allowed = !empty($_POST['perm'][$role][$module]) ? 1 : 0;
                $stmt->execute([$role, $module, $allowed]);
                $was = $prev[$role][$module] ?? (suite_module_default_access($role, $module) ? 1 : 0);
                if ($was !== $allowed) {
                    $changes[$role . ' › ' . $module] = ['from' => $was, 'to' => $allowed];
                }
            }
        }
        if ($changes) {
            audit_log('admin', 'update', 'permissions', null, 'Droits par rôle', ['changes' => $changes]);
        }
        suite_permissions_load(true);
        $success = "Droits des rôles enregistrés.";
    }

    // ── Droits particuliers d'un utilisateur ────────────────────────────────
    if ($action === 'save_user') {
        $uid = (int)($_POST['user_id'] ?? 0);
        $chk = $pdo->prepare("SELECT id, first_name, last_name FROM users WHERE id = ? AND role IS NOT NULL");
        $chk->execute([$uid]);
        $target = $chk->fetch(PDO::FETCH_ASSOC);

        if (!$target) {
            $errors[] = "Utilisateur introuvable ou sans accès admin.";
        } else {
            $me = current_volunteer_id();
            $del = $pdo->prepare("DELETE FROM suite_user_permissions WHERE user_id = ? AND module = ?");
            $ups = $pdo->prepare("
                INSERT INTO suite_user_permissions (user_id, module, allowed, updated_by) VALUES (?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE allowed = VALUES(allowed), updated_by = VALUES(updated_by)
            ");
            $prevQ = $pdo->prepare("SELECT module, allowed FROM suite_user_permissions WHERE user_id = ?");
            $prevQ->execute([$uid]);
            $prevOv = [];
            foreach ($prevQ->fetchAll(PDO::FETCH_ASSOC) as $r) $prevOv[$r['module']] = ((int)$r['allowed'] === 1) ? 'allow' : 'deny';
            $changes = [];
            foreach (array_keys($modules) as $module) {
                $v = $_POST['user_perm'][$module] ?? 'inherit';
                if ($v === 'allow') {
                    $ups->execute([$uid, $module, 1, $me]);
                } elseif ($v === 'deny') {
                    $ups->execute([$uid, $module, 0, $me]);
                } else {
                    $v = 'inherit';
                    $del->execute([$uid, $module]);
                }
                $was = $prevOv[$module] ?? 'inherit';
                if ($was !== $v) {
                    $changes[$module] = ['from' => $was, 'to' => $v];
                }
            }
            $name = trim($target['first_name'] . ' ' . $target['last_name']);
            if ($changes) {
                audit_log('admin', 'update', 'permissions', $uid, $name, ['changes' => $changes]);
            }
            suite_permissions_load(true);
            $success = "Droits particuliers enregistrés pour $name.";
        }
    }
}

// ── Données d'affichage ──────────────────────────────────────────────────────
$perm = $tablesOk ? suite_permissions_load(true) : ['roles' => [], 'users' => []];

function roleAllowed(array $perm, string $role, string $module): bool {
    return $perm['roles'][$role][$module] ?? suite_module_default_access($role, $module);
}

$adminUsers = [];
if ($tablesOk) {
    $adminUsers = $pdo->query("
        SELECT id, first_name, last_name, role, is_active
        FROM users
        WHERE role IS NOT NULL AND deleted_at IS NULL
        ORDER BY (role = 'super_admin') DESC, last_name, first_name
    ")->fetchAll(PDO::FETCH_ASSOC);
}

$selectedId = (int)($_GET['user'] ?? $_POST['user_id'] ?? 0);
$selected = null;
foreach ($adminUsers as $u) {
    if ((int)$u['id'] === $selectedId) { $selected = $u; break; }
}

$roleLabels = ['super_admin' => 'Super admin', 'admin_plus' => 'Admin+', 'admin' => 'Admin'];
$pageTitle = 'Droits d\'accès — Touraine-Ukraine';
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
      .perm-head { padding: 14px 18px; border-bottom: 1px solid var(--tu-ink-100); }
      .perm-foot { padding: 14px 18px; border-top: 1px solid var(--tu-ink-100); display: flex; justify-content: space-between; align-items: center; gap: 10px; flex-wrap: wrap; }
      .perm-foot span { font-size: 12px; color: var(--tu-ink-300); }
      .perm-panel[hidden] { display: none; }
    </style>
</head>
<body class="tu-v2">

<?php
require_once dirname(__DIR__) . '/shared/suite_nav.php';
suite_nav_render('users', '');
?>
<div class="tu-main">

<div class="tu-topbar">
  <div class="tu-bc">
    <a href="<?= h(suite_base()) ?>/index.php" style="color:inherit;text-decoration:none;">Accueil</a>
    <span class="tu-bc-sep">›</span>
    <a href="users.php" style="color:inherit;text-decoration:none;">Utilisateurs</a>
    <span class="tu-bc-sep">›</span>
    <span class="tu-bc-cur">Droits</span>
  </div>
  <div class="tu-topbar-acts">
    <a href="users.php" class="tu-btn tu-btn-s tu-btn-sm">Utilisateurs</a>
    <a href="groups.php" class="tu-btn tu-btn-s tu-btn-sm">Groupes</a>
  </div>
</div>

<div class="tu-pg">

  <?php if ($success): ?>
    <div style="background:var(--tu-green-soft);border:1.5px solid rgba(42,125,74,.25);border-radius:12px;padding:12px 16px;margin-bottom:16px;color:var(--tu-green-main);font-size:13px;"><?= h($success) ?></div>
  <?php endif; ?>
  <?php if (!empty($errors)): ?>
    <div style="background:var(--tu-red-soft);border-radius:12px;padding:12px 16px;margin-bottom:16px;color:var(--tu-red-main);font-size:13px;"><ul style="margin:0;padding-left:16px;"><?php foreach ($errors as $e): ?><li><?= h($e) ?></li><?php endforeach; ?></ul></div>
  <?php endif; ?>

  <div class="tu-ph">
    <div>
      <div class="tu-ph-title">Droits d'accès</div>
      <div class="tu-ph-sub">Qui peut ouvrir quel module : par rôle, puis cas par cas</div>
    </div>
  </div>

  <?php if (!$tablesOk): ?>
    <div class="tu-card" style="padding:20px;">
      <div style="font-weight:800;margin-bottom:6px;">Migration SQL à exécuter</div>
      <div style="font-size:13px;color:var(--tu-ink-400);">
        Les tables de droits n'existent pas encore. Exécutez la migration
        <code>planning / 004_permissions_and_user_soft_delete.sql</code> depuis
        <a href="migrations.php" style="color:var(--tu-amber-600);font-weight:700;">Migrations SQL</a>.
        En attendant, les accès restent ceux d'origine.
      </div>
    </div>
  <?php else: ?>

  <!-- 1. Matrice rôle × module -->
  <div class="tu-card" style="overflow:hidden;margin-bottom:22px;">
    <div style="padding:14px 18px;border-bottom:1px solid var(--tu-ink-100);">
      <div style="font-family:var(--tu-font-d);font-size:14px;font-weight:700;">Accès par rôle</div>
      <div style="font-size:12px;color:var(--tu-ink-300);margin-top:2px;">Valeur par défaut pour tous les utilisateurs d'un rôle. Le super admin a toujours accès à tout.</div>
    </div>
    <form method="post" id="roleForm">
      <input type="hidden" name="action" value="save_roles">
      <div class="tu-seg" id="roleSeg" style="margin:12px 18px 8px;">
        <?php $first = true; foreach ($editableRoles as $role => $rl): ?>
          <button type="button" data-role="<?= h($role) ?>" class="<?= $first ? 'on' : '' ?>"><?= h($rl) ?></button>
        <?php $first = false; endforeach; ?>
      </div>
      <?php $first = true; foreach ($editableRoles as $role => $rl): ?>
        <div class="perm-panel" data-role="<?= h($role) ?>" <?= $first ? '' : 'hidden' ?>>
          <?php foreach ($modules as $key => $label): ?>
            <label class="tu-row">
              <input type="checkbox" name="perm[<?= h($role) ?>][<?= h($key) ?>]" value="1" <?= roleAllowed($perm, $role, $key) ? 'checked' : '' ?>>
              <span class="tu-row-ck">✓</span>
              <span class="tu-row-t"><b><?= h($label) ?></b><small><?= h($key) ?></small></span>
            </label>
          <?php endforeach; ?>
        </div>
      <?php $first = false; endforeach; ?>
      <div class="perm-foot">
        <span id="roleCount"></span>
        <button type="submit" class="tu-btn tu-btn-p">Enregistrer les droits des rôles</button>
      </div>
    </form>
  </div>

  <!-- 2. Droits particuliers -->
  <div class="tu-card" style="overflow:hidden;">
    <div style="padding:14px 18px;border-bottom:1px solid var(--tu-ink-100);">
      <div style="font-family:var(--tu-font-d);font-size:14px;font-weight:700;">Droits particuliers</div>
      <div style="font-size:12px;color:var(--tu-ink-300);margin-top:2px;">Autoriser ou retirer un module pour une personne précise, sans changer son rôle.</div>
    </div>

    <form method="get" style="padding:14px 18px;display:flex;gap:10px;align-items:flex-end;flex-wrap:wrap;border-bottom:1px solid var(--tu-ink-100);">
      <div class="tu-form-field" style="min-width:260px;">
        <span class="tu-lbl">Utilisateur</span>
        <select name="user" class="tu-input" onchange="this.form.submit()">
          <option value="0">— Choisir —</option>
          <?php foreach ($adminUsers as $u):
              $hasOv = !empty($perm['users'][(int)$u['id']]); ?>
            <option value="<?= (int)$u['id'] ?>" <?= (int)$u['id'] === $selectedId ? 'selected' : '' ?>>
              <?= h(trim($u['first_name'] . ' ' . $u['last_name'])) ?> (<?= h($roleLabels[$u['role']] ?? $u['role']) ?>)<?= $hasOv ? ' •' : '' ?><?= (int)$u['is_active'] ? '' : ' — désactivé' ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>
      <div style="font-size:12px;color:var(--tu-ink-300);padding-bottom:10px;">• = a des droits particuliers</div>
    </form>

    <?php if ($selected): ?>
      <?php $selRole = (string)$selected['role']; $selOv = $perm['users'][(int)$selected['id']] ?? []; ?>
      <form method="post">
        <input type="hidden" name="action" value="save_user">
        <input type="hidden" name="user_id" value="<?= (int)$selected['id'] ?>">
        <?php if ($selRole === 'super_admin'): ?>
          <div style="padding:18px;font-size:13px;color:var(--tu-ink-400);">Un super admin a toujours accès à tout : aucun droit particulier à définir.</div>
        <?php else: ?>
        <div id="userRows">
          <?php foreach ($modules as $key => $label):
              $roleDefault = roleAllowed($perm, $selRole, $key);
              $cur = isset($selOv[$key]) ? ($selOv[$key] ? 'allow' : 'deny') : 'inherit';
              $eff = $cur === 'inherit' ? $roleDefault : ($cur === 'allow'); ?>
            <label class="tu-row" data-def="<?= $roleDefault ? 1 : 0 ?>">
              <input type="checkbox" <?= $eff ? 'checked' : '' ?>>
              <input type="hidden" name="user_perm[<?= h($key) ?>]" value="<?= h($cur) ?>">
              <span class="tu-row-ck">✓</span>
              <span class="tu-row-t"><b><?= h($label) ?></b><small><?= h($key) ?> · Par défaut pour <?= h($roleLabels[$selRole] ?? $selRole) ?> : <?= $roleDefault ? 'autorisé' : 'refusé' ?></small></span>
              <span class="tu-row-tag" hidden></span>
              <button type="button" class="tu-row-reset" hidden>rétablir</button>
            </label>
          <?php endforeach; ?>
        </div>
        <div class="perm-foot">
          <span id="userCount"></span>
          <button type="submit" class="tu-btn tu-btn-p">Enregistrer pour <?= h(trim($selected['first_name'] . ' ' . $selected['last_name'])) ?></button>
        </div>
        <?php endif; ?>
      </form>
    <?php else: ?>
      <div style="padding:18px;font-size:13px;color:var(--tu-ink-300);">Choisissez un utilisateur pour voir et modifier ses droits particuliers.</div>
    <?php endif; ?>
  </div>

  <?php endif; ?>

</div>
</div><!-- /tu-main -->
<script>
(function () {
  /* Accès par rôle : un panneau par rôle, bascule par le sélecteur. */
  var seg = document.getElementById('roleSeg');
  function countRole() {
    var on = seg && seg.querySelector('button.on');
    if (!on) return;
    var panel = document.querySelector('.perm-panel[data-role="' + on.dataset.role + '"]');
    var n = panel.querySelectorAll('input:checked').length, t = panel.querySelectorAll('input').length;
    document.getElementById('roleCount').textContent = n + ' module' + (n > 1 ? 's' : '') + ' sur ' + t;
  }
  if (seg) {
    seg.addEventListener('click', function (e) {
      var b = e.target.closest('button'); if (!b) return;
      seg.querySelectorAll('button').forEach(function (k) { k.classList.toggle('on', k === b); });
      document.querySelectorAll('.perm-panel').forEach(function (p) { p.hidden = p.dataset.role !== b.dataset.role; });
      countRole();
    });
    document.getElementById('roleForm').addEventListener('change', countRole);
    countRole();
  }

  /* Droits particuliers : le ✓ montre l'accès réel ; l'écart avec le rôle devient allow/deny. */
  var rows = document.getElementById('userRows');
  function updateRow(row) {
    var cb = row.querySelector('input[type=checkbox]'), hid = row.querySelector('input[type=hidden]');
    var def = row.dataset.def === '1', eff = cb.checked;
    hid.value = eff === def ? 'inherit' : (eff ? 'allow' : 'deny');
    var tag = row.querySelector('.tu-row-tag'), rs = row.querySelector('.tu-row-reset');
    tag.hidden = rs.hidden = hid.value === 'inherit';
    tag.className = 'tu-row-tag ' + (eff ? 'add' : 'del');
    tag.textContent = eff ? 'Ajouté' : 'Retiré';
  }
  function countUser() {
    var all = rows.querySelectorAll('.tu-row'), n = 0, d = 0;
    all.forEach(function (r) { if (r.querySelector('input[type=checkbox]').checked) n++; if (r.querySelector('input[type=hidden]').value !== 'inherit') d++; });
    document.getElementById('userCount').textContent = n + ' module' + (n > 1 ? 's' : '') + ' accessible' + (n > 1 ? 's' : '') + ' · ' + d + ' écart' + (d > 1 ? 's' : '') + ' avec le rôle';
  }
  if (rows) {
    rows.querySelectorAll('.tu-row').forEach(updateRow);
    countUser();
    rows.addEventListener('change', function (e) { updateRow(e.target.closest('.tu-row')); countUser(); });
    rows.addEventListener('click', function (e) {
      var rs = e.target.closest('.tu-row-reset'); if (!rs) return;
      e.preventDefault();
      var row = rs.closest('.tu-row');
      row.querySelector('input[type=checkbox]').checked = row.dataset.def === '1';
      updateRow(row); countUser();
    });
  }
})();
</script>
</body>
</html>
