<?php
// admin/groups.php — Gestion des groupes d'utilisateurs (ex : Conseil d'Administration, Bureau…)
// Sert notamment à préremplir rapidement une liste de présence dans le module Documents.
//
// Règles automatiques (voir shared/volunteer_group_rules.php) :
//  - une fonction (ex: Trésorier) peut ajouter automatiquement à un groupe (ex: Bureau)
//  - un groupe peut "impliquer" un autre groupe (ex: Bureau -> implique -> Conseil d'Administration)
// Ces règles n'ajoutent jamais que des adhésions, elles n'en retirent jamais automatiquement.

declare(strict_types=1);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../shared/bootstrap.php';

if (!defined('APP_BASE')) {
    define('APP_BASE', suite_base() . '/admin');
}

if (!is_admin_plus()) {
    http_response_code(403);
    echo "Accès réservé aux administrateurs principaux.";
    exit;
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
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <link rel="stylesheet" href="<?= h(suite_base()) ?>/assets/css/suite_nav.css">
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

    $volChk = $pdo->prepare("SELECT id FROM planning_volunteers WHERE id = ? AND is_active = 1");
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

    if (function_exists('audit_log')) {
        audit_log('admin', 'update', 'volunteer_group', $groupId, $grp['name'], ['volunteer_id' => $volunteerId, 'member' => $member]);
    }

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
            if (function_exists('audit_log')) audit_log('admin', 'create', 'volunteer_group', $newId, $name);
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
                    if (function_exists('audit_log')) audit_log('admin', 'update', 'volunteer_group', $groupId, $name);
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

                if (function_exists('audit_log')) {
                    audit_log('admin', 'update', 'volunteer_group', $groupId, $grp['name'], ['rules' => true]);
                }
                $success = "Règles automatiques mises à jour pour « {$grp['name']} ».";
            }

            if ($action === 'delete_group') {
                $pdo->prepare("DELETE FROM volunteer_groups WHERE id = ?")->execute([$groupId]);
                if (function_exists('audit_log')) audit_log('admin', 'delete', 'volunteer_group', $groupId, $grp['name']);
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
    FROM planning_volunteers
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
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link rel="stylesheet" href="<?= h(suite_base()) ?>/assets/css/suite_nav.css">
    <style>
      body.tu-v2 { display: block; }
      body.tu-v2 .tu-main { margin-left: var(--tu-sw); padding: 24px; }
      @media (max-width: 900px) {
        body.tu-v2 .tu-main { margin-left: 0; padding: 16px; padding-top: 70px; }
      }
      .grp-member {
        display: flex; align-items: center; gap: 10px; padding: 8px 8px;
        font-size: 12.5px; color: var(--tu-ink-900); cursor: pointer;
        border-radius: 8px; transition: background-color .2s;
      }
      .grp-member + .grp-member { margin-top: 1px; }
      .grp-member:hover { background: var(--tu-sand-50); }
      .grp-member.saved { background: var(--tu-green-soft); }
      .grp-member.error { background: var(--tu-red-soft); }
      .grp-member.saving { opacity: .6; }
      .grp-member .grp-member-name { flex: 1; }
      .grp-member .grp-save-hint { font-size: 10px; color: var(--tu-ink-300); visibility: hidden; font-weight: 700; }
      .grp-member.saved .grp-save-hint { visibility: visible; color: var(--tu-green-main); }
      .grp-member.error .grp-save-hint { visibility: visible; color: var(--tu-red-main); }
      .grp-member input[type=checkbox] {
        appearance: none; -webkit-appearance: none; flex-shrink: 0; margin: 0;
        width: 18px; height: 18px; border: 1.5px solid var(--tu-ink-200); border-radius: 5px;
        background: #fff; cursor: pointer; position: relative;
        transition: background-color .15s, border-color .15s;
      }
      .grp-member input[type=checkbox]:hover { border-color: var(--tu-amber-400); }
      .grp-member input[type=checkbox]:checked {
        background: var(--tu-amber-500); border-color: var(--tu-amber-500);
      }
      .grp-member input[type=checkbox]:checked::after {
        content: ''; position: absolute; left: 5px; top: 1px; width: 5px; height: 9px;
        border: solid #fff; border-width: 0 2px 2px 0; transform: rotate(45deg);
      }
      .grp-member input[type=checkbox]:focus-visible { outline: 2px solid var(--tu-amber-300); outline-offset: 2px; }
      .grp-members-list { max-height: 300px; overflow-y: auto; border: 1px solid var(--tu-ink-100); border-radius: 10px; padding: 6px; margin: 10px 0; }
      .grp-rules summary { cursor: pointer; font-size: 11px; color: var(--tu-ink-300); font-weight: 700; text-transform: uppercase; letter-spacing: .04em; padding: 4px 0; }
      .grp-rules summary:hover { color: var(--tu-ink-700); }
      .grp-rules-body { margin-top: 8px; display: flex; flex-direction: column; gap: 10px; padding-top: 8px; border-top: 1px dashed var(--tu-ink-100); }
      .grp-fn-check { display: flex; align-items: center; gap: 6px; font-size: 12px; padding: 2px 0; }
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
      <div class="tu-ph-sub">Regroupe des utilisateurs sous un nom réutilisable (ex : Conseil d'Administration, Bureau…) — utilisé pour préremplir une liste de présence dans le module Documents. Cocher/décocher un membre enregistre aussitôt, pas besoin de bouton.</div>
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
            <div style="display:flex;gap:4px;">
              <button type="button" class="tu-btn-link" style="font-size:11px;" onclick='openRenameGroupModal(<?= $gid ?>, <?= json_encode($g['name'], JSON_HEX_APOS | JSON_HEX_QUOT) ?>)'>renommer</button>
              <form method="post" style="display:inline;" onsubmit="return confirm('Supprimer le groupe « <?= h(addslashes($g['name'])) ?> » ? Les utilisateurs eux-mêmes ne sont pas supprimés.');">
                <input type="hidden" name="action" value="delete_group">
                <input type="hidden" name="group_id" value="<?= $gid ?>">
                <button type="submit" class="tu-btn-link" style="font-size:11px;color:var(--tu-red-main);">supprimer</button>
              </form>
            </div>
          </div>
          <div class="grp-count" data-group="<?= $gid ?>" style="font-size:11.5px;color:var(--tu-ink-300);margin-bottom:6px;"><?= count($members) ?> membre<?= count($members) > 1 ? 's' : '' ?></div>

          <div class="grp-members-list">
            <?php if (empty($volunteers)): ?>
              <div style="padding:10px 0;font-size:12px;color:var(--tu-ink-300);font-style:italic;">Aucun utilisateur actif.</div>
            <?php endif; ?>
            <?php foreach ($volunteers as $v):
              $vid = (int)$v['id'];
              $checked = in_array($vid, $members, true);
              $fullName = trim($v['first_name'] . ' ' . $v['last_name']);
            ?>
              <label class="grp-member">
                <input type="checkbox"
                       data-group="<?= $gid ?>" data-volunteer="<?= $vid ?>"
                       <?= $checked ? 'checked' : '' ?>
                       onchange="toggleGroupMember(this)">
                <span class="grp-member-name"><?= h($fullName) ?></span>
                <span class="grp-save-hint">✓</span>
              </label>
            <?php endforeach; ?>
          </div>

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
                  <div style="margin-top:4px;">
                    <?php foreach (MEMBER_FUNCTIONS as $key => $label): ?>
                      <label class="grp-fn-check">
                        <input type="checkbox" name="auto_functions[]" value="<?= h($key) ?>" <?= in_array($key, $curFns, true) ? 'checked' : '' ?>>
                        <?= h($label) ?>
                      </label>
                    <?php endforeach; ?>
                  </div>
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

/* ── Autosave des cases à cocher membres : plus de bouton "Enregistrer" ───────
   Chaque changement part immédiatement en AJAX. Si le serveur signale qu'une
   cascade a ajouté l'utilisateur à d'autres groupes (ex: Bureau -> CA), on
   recharge la page pour refléter ces changements sans que l'utilisateur ait
   à naviguer manuellement. */
function toggleGroupMember(checkbox) {
  const row = checkbox.closest('.grp-member');
  const groupId = checkbox.dataset.group;
  const volunteerId = checkbox.dataset.volunteer;
  const wasChecked = checkbox.checked;

  row.classList.remove('saved', 'error');
  row.classList.add('saving');

  const body = new URLSearchParams({
    action: 'toggle_member',
    group_id: groupId,
    volunteer_id: volunteerId,
    member: wasChecked ? '1' : '0'
  });

  fetch(window.location.pathname + window.location.search, {
    method: 'POST',
    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
    body: body.toString()
  })
    .then(function (r) { return r.json(); })
    .then(function (data) {
      row.classList.remove('saving');
      if (!data || !data.ok) {
        checkbox.checked = !wasChecked;
        row.classList.add('error');
        setTimeout(function () { row.classList.remove('error'); }, 1800);
        return;
      }
      row.classList.add('saved');
      setTimeout(function () { row.classList.remove('saved'); }, 900);

      const countEl = document.querySelector('.grp-count[data-group="' + groupId + '"]');
      if (countEl && typeof data.count === 'number') {
        countEl.textContent = data.count + ' membre' + (data.count > 1 ? 's' : '');
      }

      if (data.cascaded && data.cascaded.length) {
        // Un ou plusieurs autres groupes ont aussi été mis à jour par la cascade —
        // on recharge pour que ces cases se cochent sans action manuelle.
        location.reload();
      }
    })
    .catch(function () {
      checkbox.checked = !wasChecked;
      row.classList.remove('saving');
      row.classList.add('error');
      setTimeout(function () { row.classList.remove('error'); }, 1800);
    });
}
</script>

</div><!-- /tu-main -->
</body>
</html>
