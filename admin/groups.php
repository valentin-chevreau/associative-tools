<?php
// admin/groups.php — Gestion des groupes d'utilisateurs (ex : Conseil d'Administration, Bureau…)
// Sert notamment à préremplir rapidement une liste de présence dans le module Documents.
//
// Règles automatiques (voir shared/volunteer_group_rules.php) :
//  - une fonction (ex: Trésorier) peut ajouter automatiquement à un groupe (ex: Bureau)
//  - un groupe peut "impliquer" un autre groupe (ex: Bureau -> implique -> Conseil d'Administration)
// Ces règles n'ajoutent jamais que des adhésions, elles n'en retirent jamais automatiquement.

declare(strict_types=1);

require_once __DIR__ . '/../shared/bootstrap.php';

if (!defined('APP_BASE')) {
    define('APP_BASE', suite_base() . '/admin');
}

if (!is_admin_plus()) {
    suite_forbidden("La gestion des groupes est réservée aux administrateurs principaux (Admin+).", "Accès refusé", "users", "Admin+");
}

$pdo = _bootstrap_get_pdo();
if (!($pdo instanceof PDO)) {
    http_response_code(500);
    echo "Connexion base de données indisponible.";
    exit;
}

require_once __DIR__ . '/../shared/member_functions.php';
require_once __DIR__ . '/../shared/volunteer_group_rules.php';

function h(string $s): string { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }

function groups_table_exists(PDO $pdo, string $table): bool {
    try {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?");
        $stmt->execute([$table]);
        return (bool)$stmt->fetchColumn();
    } catch (Throwable) { return false; }
}

$hasSchema = groups_table_exists($pdo, 'volunteer_groups') && groups_table_exists($pdo, 'volunteer_group_members');

if (!$hasSchema) {
    $pageTitle = 'Groupes — Touraine-Ukraine';
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
        </style>
    </head>
    <body class="tu-v2">
    <?php require_once dirname(__DIR__) . '/shared/suite_nav.php'; suite_nav_render('users', ''); ?>
    <div class="tu-main">
      <div class="tu-pg">
        <div class="tu-card" style="padding:30px;max-width:640px;">
          <h2 style="margin-top:0;">Module Groupes non initialisé</h2>
          <p>La table <code>volunteer_groups</code> n'existe pas encore sur cette base.</p>
          <?php if (function_exists('is_super_admin') && is_super_admin()): ?>
            <p>Applique la migration <code>planning/migrations/001_add_volunteer_groups.sql</code> depuis <a href="<?= h(suite_base()) ?>/admin/migrations.php">Migrations SQL</a>.</p>
          <?php else: ?>
            <p>Demande à un super administrateur d'appliquer la migration du module depuis la page Migrations SQL.</p>
          <?php endif; ?>
          <a href="<?= h(suite_base()) ?>/admin/users.php" class="tu-btn tu-btn-s">← Retour aux utilisateurs</a>
        </div>
      </div>
    </div>
    </body>
    </html>
    <?php
    exit;
}

$rulesReady = volunteer_groups_schema_ready($pdo);

// ── Endpoint AJAX : bascule un utilisateur dans/hors d'un groupe (autosave) ───
// Répond en JSON et s'arrête là — pas de rendu de page pour cette action.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'toggle_member') {
    header('Content-Type: application/json; charset=utf-8');

    $groupId     = (int)($_POST['group_id'] ?? 0);
    $volunteerId = (int)($_POST['volunteer_id'] ?? 0);
    $member      = ($_POST['member'] ?? '') === '1';

    $chk = $pdo->prepare("SELECT id, name FROM volunteer_groups WHERE id = ?");
    $chk->execute([$groupId]);
    $grp = $chk->fetch(PDO::FETCH_ASSOC);

    $volChk = $pdo->prepare("SELECT id FROM users WHERE id = ? AND is_active = 1");
    $volChk->execute([$volunteerId]);

    if (!$grp || !$volChk->fetch()) {
        echo json_encode(['ok' => false, 'error' => 'not_found']);
        exit;
    }

    if ($member) {
        $pdo->prepare("INSERT IGNORE INTO volunteer_group_members (group_id, volunteer_id) VALUES (?, ?)")->execute([$groupId, $volunteerId]);
    } else {
        $pdo->prepare("DELETE FROM volunteer_group_members WHERE group_id = ? AND volunteer_id = ?")->execute([$groupId, $volunteerId]);
    }

    // Applique la cascade "implies_group_id" pour cet utilisateur et détecte ce qui a été
    // ajouté ailleurs (pour prévenir le navigateur qu'un autre groupe a aussi bougé).
    $cascaded = [];
    if ($member && $rulesReady) {
        $before = $pdo->prepare("SELECT group_id FROM volunteer_group_members WHERE volunteer_id = ?");
        $before->execute([$volunteerId]);
        $beforeIds = array_map('intval', $before->fetchAll(PDO::FETCH_COLUMN));

        volunteer_groups_sync_auto_memberships($pdo, $volunteerId);

        $after = $pdo->prepare("SELECT group_id FROM volunteer_group_members WHERE volunteer_id = ?");
        $after->execute([$volunteerId]);
        $afterIds = array_map('intval', $after->fetchAll(PDO::FETCH_COLUMN));
        $cascaded = array_values(array_diff($afterIds, $beforeIds));
    }

    $vq = $pdo->prepare("SELECT first_name, last_name FROM users WHERE id = ?");
    $vq->execute([$volunteerId]);
    $vRow = $vq->fetch(PDO::FETCH_ASSOC);
    audit_log('admin', $member ? 'register' : 'unregister', 'volunteer_group', $groupId, $grp['name'], [
        'benevole'  => $vRow ? trim($vRow['first_name'] . ' ' . $vRow['last_name']) : ('#' . $volunteerId),
        'cascade'   => !empty($cascaded) ? count($cascaded) . ' groupe(s) lié(s) mis à jour' : null,
    ]);

    $cStmt = $pdo->prepare("SELECT COUNT(*) FROM volunteer_group_members WHERE group_id = ?");
    $cStmt->execute([$groupId]);
    $count = (int)$cStmt->fetchColumn();

    echo json_encode(['ok' => true, 'count' => $count, 'cascaded' => $cascaded]);
    exit;
}

$errors = [];
$success = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'create_group') {
        $name = trim($_POST['name'] ?? '');
        if ($name === '') {
            $errors[] = "Le nom du groupe est obligatoire.";
        } else {
            $maxOrder = (int)$pdo->query("SELECT COALESCE(MAX(sort_order), 0) FROM volunteer_groups")->fetchColumn();
            $pdo->prepare("INSERT INTO volunteer_groups (name, sort_order) VALUES (?, ?)")->execute([$name, $maxOrder + 1]);
            $newId = (int)$pdo->lastInsertId();
            audit_log('admin', 'create', 'volunteer_group', $newId, $name);
            $success = "Groupe « $name » créé.";
        }
    }

    $groupId = (int)($_POST['group_id'] ?? 0);
    if ($groupId > 0 && $action !== 'create_group') {
        $chk = $pdo->prepare("SELECT id, name FROM volunteer_groups WHERE id = ?");
        $chk->execute([$groupId]);
        $grp = $chk->fetch(PDO::FETCH_ASSOC);

        if ($grp) {
            if ($action === 'rename_group') {
                $name = trim($_POST['name'] ?? '');
                if ($name === '') {
                    $errors[] = "Le nom du groupe est obligatoire.";
                } else {
                    $pdo->prepare("UPDATE volunteer_groups SET name = ? WHERE id = ?")->execute([$name, $groupId]);
                    audit_update('admin', 'volunteer_group', $groupId, $name, ['name' => $grp['name']], ['name' => $name]);
                    $success = "Groupe renommé en « $name ».";
                }
            }

            if ($action === 'update_rules' && $rulesReady) {
                $impliesId = (int)($_POST['implies_group_id'] ?? 0);
                $impliesId = ($impliesId > 0 && $impliesId !== $groupId) ? $impliesId : null;

                $autoFns = array_values(array_intersect(
                    array_map('trim', $_POST['auto_functions'] ?? []),
                    array_keys(MEMBER_FUNCTIONS)
                ));
                $autoFnsStr = $autoFns ? implode(',', $autoFns) : null;

                $pdo->prepare("UPDATE volunteer_groups SET implies_group_id = ?, auto_functions = ? WHERE id = ?")
                    ->execute([$impliesId, $autoFnsStr, $groupId]);

                // Applique tout de suite : ajoute les utilisateurs dont la fonction matche déjà,
                // puis fait remonter la cascade pour tous les membres actuels du groupe.
                volunteer_groups_apply_group_functions($pdo, $groupId);
                volunteer_groups_sync_cascade_for_group($pdo, $groupId);

                audit_log('admin', 'update', 'volunteer_group', $groupId, $grp['name'], [
                    'regles'            => 'règles automatiques modifiées',
                    'groupe_implique'   => $impliesId,
                    'fonctions_auto'    => $autoFnsStr,
                ]);
                $success = "Règles automatiques mises à jour pour « {$grp['name']} ».";
            }

            if ($action === 'delete_group') {
                $pdo->prepare("DELETE FROM volunteer_groups WHERE id = ?")->execute([$groupId]);
                audit_log('admin', 'delete', 'volunteer_group', $groupId, $grp['name']);
                $success = "Groupe « {$grp['name']} » supprimé.";
            }
        } else {
            $errors[] = "Groupe introuvable.";
        }
    }
}

// ── Amorçage automatique des règles par défaut (détection par NOM, une seule fois,
//    n'écrase jamais une config existante) : "Bureau" -> implique -> groupe dont le
//    nom contient "conseil d'administration" (ou nommé "CA"), + les 6 fonctions de
//    bureau ajoutent automatiquement au groupe "Bureau". ────────────────────────
$seededBureauId = volunteer_groups_autoseed_default_rules($pdo);
if ($seededBureauId !== null) {
    volunteer_groups_apply_group_functions($pdo, $seededBureauId);
    volunteer_groups_sync_cascade_for_group($pdo, $seededBureauId);
}

$volunteers = $pdo->query("
    SELECT id, first_name, last_name, member_function
    FROM users
    WHERE is_active = 1
    ORDER BY last_name ASC, first_name ASC
")->fetchAll(PDO::FETCH_ASSOC);

$groups = $pdo->query($rulesReady
    ? "SELECT id, name, implies_group_id, auto_functions FROM volunteer_groups ORDER BY sort_order ASC, name ASC"
    : "SELECT id, name, NULL AS implies_group_id, NULL AS auto_functions FROM volunteer_groups ORDER BY sort_order ASC, name ASC"
)->fetchAll(PDO::FETCH_ASSOC);

$memberMap = []; // group_id => [volunteer_id, …]
$memberRows = $pdo->query("SELECT group_id, volunteer_id FROM volunteer_group_members")->fetchAll(PDO::FETCH_ASSOC);
foreach ($memberRows as $r) {
    $memberMap[(int)$r['group_id']][] = (int)$r['volunteer_id'];
}

$pageTitle = 'Groupes — Touraine-Ukraine';
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
      .grp-members { margin: 10px 0; }
      .grp-state { min-height: 16px; margin-top: 4px; font-size: 11px; font-weight: 700; color: var(--tu-ink-300); }
      .grp-state.saved { color: var(--tu-green-main); }
      .grp-state.error { color: var(--tu-red-main); }
      .grp-members.saving { opacity: .6; pointer-events: none; }
      .grp-rules summary { cursor: pointer; font-size: 11px; color: var(--tu-ink-300); font-weight: 700; text-transform: uppercase; letter-spacing: .04em; padding: 4px 0; }
      .grp-rules summary:hover { color: var(--tu-ink-700); }
      .grp-rules-body { margin-top: 8px; display: flex; flex-direction: column; gap: 10px; padding-top: 8px; border-top: 1px dashed var(--tu-ink-100); }
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
    <span class="tu-bc-cur">Groupes</span>
  </div>
  <div class="tu-topbar-acts">
    <button class="tu-btn tu-btn-p tu-btn-sm" onclick="openCreateGroupModal()">+ Nouveau groupe</button>
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
      <div class="tu-ph-title">Groupes d'utilisateurs</div>
      <div class="tu-ph-sub">Regroupe des utilisateurs sous un nom réutilisable (ex : Conseil d'Administration, Bureau…) — utilisé pour préremplir une liste de présence dans le module Documents. Ajouter ou retirer un membre enregistre aussitôt, pas besoin de bouton.</div>
    </div>
  </div>

  <?php if (!$rulesReady): ?>
    <div class="tu-card" style="padding:16px 18px;margin-bottom:16px;background:var(--tu-sand-50);">
      <div style="font-size:13px;font-weight:700;margin-bottom:4px;">Règles automatiques non activées</div>
      <div style="font-size:12.5px;color:var(--tu-ink-300);">
        Applique la migration <code>planning/migrations/002_group_auto_rules.sql</code> depuis
        <a href="<?= h(suite_base()) ?>/admin/migrations.php">Migrations SQL</a> pour activer
        l'auto-attribution (ex : une fonction de bureau ajoute automatiquement au groupe « Bureau »,
        qui ajoute lui-même automatiquement au groupe « Conseil d'Administration »).
      </div>
    </div>
  <?php endif; ?>

  <?php if (empty($groups)): ?>
    <div class="tu-card" style="padding:30px;text-align:center;color:var(--tu-ink-300);">
      Aucun groupe pour l'instant. Crée le premier avec « + Nouveau groupe ».
    </div>
  <?php else: ?>
    <div style="display:grid;grid-template-columns:repeat(auto-fill, minmax(280px, 1fr));gap:16px;">
      <?php foreach ($groups as $g):
        $gid = (int)$g['id'];
        $members = $memberMap[$gid] ?? [];
        $curFns = $g['auto_functions'] ? array_filter(explode(',', (string)$g['auto_functions'])) : [];
      ?>
        <div class="tu-card" style="padding:18px;">
          <div style="display:flex;justify-content:space-between;align-items:flex-start;gap:8px;margin-bottom:2px;">
            <div style="font-weight:800;font-size:14.5px;"><?= h($g['name']) ?></div>
            <div style="display:flex;gap:10px;">
              <button type="button" class="tu-btn-link" onclick='openRenameGroupModal(<?= $gid ?>, <?= json_encode($g['name'], JSON_HEX_APOS | JSON_HEX_QUOT) ?>)'>renommer</button>
              <form method="post" style="display:inline;" onsubmit="return confirm('Supprimer le groupe « <?= h(addslashes($g['name'])) ?> » ? Les utilisateurs eux-mêmes ne sont pas supprimés.');">
                <input type="hidden" name="action" value="delete_group">
                <input type="hidden" name="group_id" value="<?= $gid ?>">
                <button type="submit" class="tu-btn-link danger">supprimer</button>
              </form>
            </div>
          </div>
          <div class="grp-count" data-group="<?= $gid ?>" style="font-size:11.5px;color:var(--tu-ink-300);margin-bottom:6px;"><?= count($members) ?> membre<?= count($members) > 1 ? 's' : '' ?></div>

          <?php if (empty($volunteers)): ?>
            <div style="padding:10px 0;font-size:12px;color:var(--tu-ink-300);font-style:italic;">Aucun utilisateur actif.</div>
          <?php else: ?>
            <div class="grp-members" data-group="<?= $gid ?>">
              <select multiple class="tu-input grp-multi" data-bulk data-placeholder="Aucun membre — cliquer pour ajouter" data-group="<?= $gid ?>">
                <?php foreach ($volunteers as $v):
                  $vid = (int)$v['id'];
                  $fullName = trim($v['first_name'] . ' ' . $v['last_name']);
                ?>
                  <option value="<?= $vid ?>" <?= in_array($vid, $members, true) ? 'selected' : '' ?>><?= h($fullName) ?></option>
                <?php endforeach; ?>
              </select>
              <div class="grp-state" aria-live="polite"></div>
            </div>
          <?php endif; ?>

          <?php if ($rulesReady): ?>
            <details class="grp-rules">
              <summary>Règles automatiques</summary>
              <form method="post" class="grp-rules-body">
                <input type="hidden" name="action" value="update_rules">
                <input type="hidden" name="group_id" value="<?= $gid ?>">

                <div class="tu-form-field">
                  <span class="tu-lbl" style="font-size:11px;">Être ici donne aussi accès au groupe</span>
                  <select name="implies_group_id" class="tu-input" style="font-size:12px;">
                    <option value="">— Aucun —</option>
                    <?php foreach ($groups as $og):
                      if ((int)$og['id'] === $gid) continue;
                    ?>
                      <option value="<?= (int)$og['id'] ?>" <?= (int)($g['implies_group_id'] ?? 0) === (int)$og['id'] ? 'selected' : '' ?>><?= h($og['name']) ?></option>
                    <?php endforeach; ?>
                  </select>
                </div>

                <div class="tu-form-field">
                  <span class="tu-lbl" style="font-size:11px;">Fonctions qui ajoutent automatiquement ici</span>
                  <select multiple name="auto_functions[]" class="tu-input" data-bulk data-placeholder="Aucune fonction">
                    <?php foreach (MEMBER_FUNCTIONS as $key => $label): ?>
                      <option value="<?= h($key) ?>" <?= in_array($key, $curFns, true) ? 'selected' : '' ?>><?= h($label) ?></option>
                    <?php endforeach; ?>
                  </select>
                </div>

                <button type="submit" class="tu-btn tu-btn-s tu-btn-sm" style="align-self:flex-start;">Enregistrer les règles</button>
              </form>
            </details>
          <?php endif; ?>
        </div>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>

</div>

<!-- Modal : création d'un groupe -->
<div id="createGroupModalOverlay" style="display:none;position:fixed;inset:0;background:rgba(26,21,16,.55);z-index:2000;align-items:center;justify-content:center;padding:20px;" onclick="if(event.target===this) closeCreateGroupModal()">
  <div class="tu-card" style="max-width:380px;width:100%;padding:0;overflow:hidden;">
    <div style="padding:18px 20px;border-bottom:1px solid var(--tu-ink-100);">
      <div style="font-weight:800;font-size:15px;">Nouveau groupe</div>
    </div>
    <form method="post">
      <input type="hidden" name="action" value="create_group">
      <div style="padding:20px;">
        <div class="tu-form-field">
          <span class="tu-lbl">Nom *</span>
          <input type="text" name="name" class="tu-input" required placeholder="Ex : Conseil d'Administration">
        </div>
      </div>
      <div style="padding:14px 20px;border-top:1px solid var(--tu-ink-100);display:flex;justify-content:flex-end;gap:8px;">
        <button type="button" class="tu-btn tu-btn-s" onclick="closeCreateGroupModal()">Annuler</button>
        <button type="submit" class="tu-btn tu-btn-p">Créer</button>
      </div>
    </form>
  </div>
</div>

<!-- Modal : renommer un groupe -->
<div id="renameGroupModalOverlay" style="display:none;position:fixed;inset:0;background:rgba(26,21,16,.55);z-index:2000;align-items:center;justify-content:center;padding:20px;" onclick="if(event.target===this) closeRenameGroupModal()">
  <div class="tu-card" style="max-width:380px;width:100%;padding:0;overflow:hidden;">
    <div style="padding:18px 20px;border-bottom:1px solid var(--tu-ink-100);">
      <div style="font-weight:800;font-size:15px;">Renommer le groupe</div>
    </div>
    <form method="post">
      <input type="hidden" name="action" value="rename_group">
      <input type="hidden" name="group_id" id="renameGroupId">
      <div style="padding:20px;">
        <div class="tu-form-field">
          <span class="tu-lbl">Nom *</span>
          <input type="text" name="name" id="renameGroupName" class="tu-input" required>
        </div>
      </div>
      <div style="padding:14px 20px;border-top:1px solid var(--tu-ink-100);display:flex;justify-content:flex-end;gap:8px;">
        <button type="button" class="tu-btn tu-btn-s" onclick="closeRenameGroupModal()">Annuler</button>
        <button type="submit" class="tu-btn tu-btn-p">Enregistrer</button>
      </div>
    </form>
  </div>
</div>

<script>
function openCreateGroupModal() { document.getElementById('createGroupModalOverlay').style.display = 'flex'; }
function closeCreateGroupModal() { document.getElementById('createGroupModalOverlay').style.display = 'none'; }
function openRenameGroupModal(id, name) {
  document.getElementById('renameGroupId').value = id;
  document.getElementById('renameGroupName').value = name;
  document.getElementById('renameGroupModalOverlay').style.display = 'flex';
}
function closeRenameGroupModal() { document.getElementById('renameGroupModalOverlay').style.display = 'none'; }

/* ── Autosave des membres : plus de bouton "Enregistrer" ───────────────────────
   Chaque ajout/retrait part immédiatement en AJAX (un appel par membre modifié).
   Si le serveur signale qu'une cascade a ajouté l'utilisateur à d'autres groupes
   (ex: Bureau -> CA), on recharge la page pour refléter ces changements. */
function postToggle(groupId, volunteerId, member) {
  const body = new URLSearchParams({
    action: 'toggle_member', group_id: groupId, volunteer_id: volunteerId, member: member ? '1' : '0'
  });
  return fetch(window.location.pathname + window.location.search, {
    method: 'POST',
    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
    body: body.toString()
  }).then(function (r) { return r.json(); });
}

document.addEventListener('DOMContentLoaded', function () {
  document.querySelectorAll('select.grp-multi').forEach(function (select) {
    const groupId = select.dataset.group;
    const box = select.closest('.grp-members');
    const state = box.querySelector('.grp-state');
    let saved = select._tuMulti.values();
    let busy = false;

    function flash(cls, msg) {
      state.className = 'grp-state ' + cls;
      state.textContent = msg;
      setTimeout(function () { state.className = 'grp-state'; state.textContent = ''; }, 1600);
    }

    select.addEventListener('change', async function () {
      if (busy) return;
      const now = select._tuMulti.values();
      const added = now.filter(function (v) { return saved.indexOf(v) === -1; });
      const removed = saved.filter(function (v) { return now.indexOf(v) === -1; });
      if (!added.length && !removed.length) return;

      busy = true;
      box.classList.add('saving');
      let cascaded = false, count = null, failed = false;
      for (const v of added.concat(removed)) {
        try {
          const data = await postToggle(groupId, v, added.indexOf(v) !== -1);
          if (!data || !data.ok) { failed = true; break; }
          if (typeof data.count === 'number') count = data.count;
          if (data.cascaded && data.cascaded.length) cascaded = true;
        } catch (e) { failed = true; break; }
      }
      box.classList.remove('saving');
      busy = false;

      if (failed) {
        select._tuMulti.set(saved, true); // retour à l'état enregistré
        flash('error', 'Échec de l\u2019enregistrement');
        return;
      }
      saved = now;
      flash('saved', '\u2713 Enregistré');
      const countEl = document.querySelector('.grp-count[data-group="' + groupId + '"]');
      if (countEl && count !== null) countEl.textContent = count + ' membre' + (count > 1 ? 's' : '');
      if (cascaded) location.reload();
    });
  });
});
</script>

</div><!-- /tu-main -->
</body>
</html>
