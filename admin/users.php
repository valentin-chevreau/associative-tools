<?php
// admin/users.php — Gestion des utilisateurs (bénévoles, rôles, fonctions, statuts)

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

function h(string $s): string { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }

function genAccessCode(): string {
    return str_pad((string)random_int(0, 99999999), 8, '0', STR_PAD_LEFT);
}

const MEMBER_FUNCTIONS = [
    'president'     => 'Président',
    'tresorier'     => 'Trésorier',
    'secretaire'    => 'Secrétaire',
    'membre_ca'     => 'Membre du CA',
    'membre_bureau' => 'Membre du bureau',
    'membre_actif'  => 'Membre actif',
];

function labelFunction(?string $f): string {
    if ($f === null || $f === '') return '—';
    return MEMBER_FUNCTIONS[$f] ?? $f; // texte libre si pas dans la liste
}

function formatPhone(?string $phone): string {
    if ($phone === null || $phone === '') return '';
    $digits = preg_replace('/\D/', '', $phone);
    if ($digits === '') return $phone;
    return trim(implode(' ', str_split($digits, 2)));
}

$errors = [];
$success = null;
$revealedCode = null;
$revealedFor  = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    // ── Création d'un nouveau bénévole ──────────────────────────────────────
    if ($action === 'create_volunteer') {
        $firstName = trim($_POST['first_name'] ?? '');
        $lastName  = trim($_POST['last_name'] ?? '');
        $email     = trim($_POST['email'] ?? '');
        $phone     = trim($_POST['phone'] ?? '');
        $memberFunctionRaw = trim($_POST['member_function'] ?? '');
        $memberFunctionCustom = trim($_POST['member_function_custom'] ?? '');
        $presenceStatus = $_POST['presence_status'] ?? 'permanent';

        $memberFunction = $memberFunctionRaw === '__custom' ? $memberFunctionCustom : $memberFunctionRaw;
        if (!in_array($presenceStatus, ['permanent', 'temporaire'], true)) $presenceStatus = 'permanent';

        if ($firstName === '' || $lastName === '') {
            $errors[] = "Le prénom et le nom sont obligatoires.";
        } else {
            $ins = $pdo->prepare("
                INSERT INTO planning_volunteers (first_name, last_name, email, phone, member_function, presence_status, is_active)
                VALUES (?, ?, ?, ?, ?, ?, 1)
            ");
            $ins->execute([
                $firstName, $lastName,
                $email !== '' ? $email : null,
                $phone !== '' ? $phone : null,
                $memberFunction !== '' ? $memberFunction : null,
                $presenceStatus,
            ]);
            $newId = (int)$pdo->lastInsertId();
            $fullName = trim("$firstName $lastName");
            audit_log('admin', 'create', 'volunteer', $newId, $fullName, ['member_function' => $memberFunction, 'presence_status' => $presenceStatus]);
            $success = "Bénévole « $fullName » créé.";
        }
    }

    // ── Actions sur un bénévole existant ────────────────────────────────────
    $volunteerId = (int)($_POST['volunteer_id'] ?? 0);
    if ($volunteerId > 0 && $action !== 'create_volunteer') {
        $chk = $pdo->prepare("SELECT id, first_name, last_name FROM planning_volunteers WHERE id = ?");
        $chk->execute([$volunteerId]);
        $vol = $chk->fetch(PDO::FETCH_ASSOC);

        if ($vol) {
            $fullName = trim($vol['first_name'] . ' ' . $vol['last_name']);

            if ($action === 'update_profile') {
                $memberFunctionRaw = trim($_POST['member_function'] ?? '');
                $memberFunctionCustom = trim($_POST['member_function_custom'] ?? '');
                $memberFunction = $memberFunctionRaw === '__custom' ? $memberFunctionCustom : $memberFunctionRaw;
                $presenceStatus = $_POST['presence_status'] ?? 'permanent';
                if (!in_array($presenceStatus, ['permanent', 'temporaire'], true)) $presenceStatus = 'permanent';

                $pdo->prepare("UPDATE planning_volunteers SET member_function = ?, presence_status = ? WHERE id = ?")
                    ->execute([$memberFunction !== '' ? $memberFunction : null, $presenceStatus, $volunteerId]);
                audit_log('admin', 'update', 'volunteer', $volunteerId, $fullName, ['member_function' => $memberFunction, 'presence_status' => $presenceStatus]);
                $success = "Profil mis à jour pour $fullName.";
            }

            if ($action === 'toggle_active') {
                $newStatus = (int)($_POST['new_status'] ?? 1);
                $pdo->prepare("UPDATE planning_volunteers SET is_active = ? WHERE id = ?")->execute([$newStatus, $volunteerId]);
                audit_log('admin', 'update', 'volunteer', $volunteerId, $fullName, ['action' => $newStatus ? 'activate' : 'deactivate']);
                $success = $newStatus ? "$fullName réactivé." : "$fullName désactivé.";
            }

            if ($action === 'grant_access') {
                $role = $_POST['role'] ?? '';
                if (!in_array($role, ['admin', 'admin_plus'], true)) {
                    $errors[] = "Rôle invalide.";
                } else {
                    $newCode = genAccessCode();
                    $tries = 0;
                    while ($tries < 5) {
                        $dup = $pdo->prepare("SELECT id FROM planning_volunteers WHERE access_code = ?");
                        $dup->execute([$newCode]);
                        if (!$dup->fetch()) break;
                        $newCode = genAccessCode();
                        $tries++;
                    }
                    $pdo->prepare("UPDATE planning_volunteers SET access_code = ?, role = ?, code_created_at = NOW() WHERE id = ?")
                        ->execute([$newCode, $role, $volunteerId]);
                    audit_log('admin', 'update', 'volunteer', $volunteerId, $fullName, ['action' => 'grant_access', 'role' => $role]);
                    $revealedCode = $newCode;
                    $revealedFor  = $fullName;
                    $success = "Accès attribué à $fullName.";
                }
            }

            if ($action === 'revoke_access') {
                $pdo->prepare("UPDATE planning_volunteers SET access_code = NULL, role = NULL WHERE id = ?")->execute([$volunteerId]);
                audit_log('admin', 'update', 'volunteer', $volunteerId, $fullName, ['action' => 'revoke_access']);
                $success = "Accès révoqué pour $fullName.";
            }

            if ($action === 'change_role') {
                $role = $_POST['role'] ?? '';
                if (!in_array($role, ['admin', 'admin_plus'], true)) {
                    $errors[] = "Rôle invalide.";
                } else {
                    $pdo->prepare("UPDATE planning_volunteers SET role = ? WHERE id = ?")->execute([$role, $volunteerId]);
                    audit_log('admin', 'update', 'volunteer', $volunteerId, $fullName, ['action' => 'change_role', 'role' => $role]);
                    $success = "Rôle mis à jour pour $fullName.";
                }
            }

            if ($action === 'regenerate_code') {
                $newCode = genAccessCode();
                $tries = 0;
                while ($tries < 5) {
                    $dup = $pdo->prepare("SELECT id FROM planning_volunteers WHERE access_code = ?");
                    $dup->execute([$newCode]);
                    if (!$dup->fetch()) break;
                    $newCode = genAccessCode();
                    $tries++;
                }
                $pdo->prepare("UPDATE planning_volunteers SET access_code = ?, code_created_at = NOW() WHERE id = ?")
                    ->execute([$newCode, $volunteerId]);
                audit_log('admin', 'update', 'volunteer', $volunteerId, $fullName, ['action' => 'regenerate_code']);
                $revealedCode = $newCode;
                $revealedFor  = $fullName;
                $success = "Code régénéré pour $fullName.";
            }
        } else {
            $errors[] = "Bénévole introuvable.";
        }
    }
}

// ── Filtres liste ────────────────────────────────────────────────────────────
$search    = trim($_GET['q'] ?? '');
$fStatus   = trim($_GET['status'] ?? ''); // '' | 'permanent' | 'temporaire'
$fActive   = trim($_GET['active'] ?? ''); // '' | '1' | '0'

$sql = "SELECT id, first_name, last_name, email, phone, access_code, role, member_function, presence_status,
               code_created_at, last_login_at, is_active
        FROM planning_volunteers WHERE 1=1";
$params = [];
if ($search !== '') {
    $sql .= " AND (first_name LIKE ? OR last_name LIKE ? OR email LIKE ?)";
    $like = '%' . $search . '%';
    $params[] = $like; $params[] = $like; $params[] = $like;
}
if ($fStatus !== '') { $sql .= " AND presence_status = ?"; $params[] = $fStatus; }
if ($fActive !== '') { $sql .= " AND is_active = ?"; $params[] = (int)$fActive; }
$sql .= " ORDER BY is_active DESC, (role IS NOT NULL) DESC, last_name, first_name";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$volunteers = $stmt->fetchAll(PDO::FETCH_ASSOC);

$totalWithAccess = count(array_filter($volunteers, fn($v) => $v['role'] !== null));
$totalActive     = count(array_filter($volunteers, fn($v) => (int)$v['is_active'] === 1));
$totalTemp       = count(array_filter($volunteers, fn($v) => $v['presence_status'] === 'temporaire'));

$pageTitle = 'Utilisateurs — Touraine-Ukraine';
?>
<!doctype html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <title><?= h($pageTitle) ?></title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link rel="stylesheet" href="<?= h(suite_base()) ?>/assets/css/suite_nav.css">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet"
          integrity="sha384-QWTKZyjpPEjISv5WaRU9OFeRpok6YctnYmDr5pNlyT2bRjXh0JMhjY6hW+ALEwIH" crossorigin="anonymous">
    <style>
      body.tu-v2 { display: block; }
      body.tu-v2 .tu-main { margin-left: var(--tu-sw); padding: 24px; }
      @media (max-width: 900px) {
        body.tu-v2 .tu-main { margin-left: 0; padding: 16px; padding-top: 70px; }
      }
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
    <span class="tu-bc-cur">Utilisateurs</span>
  </div>
  <div class="tu-topbar-acts">
    <button class="tu-btn tu-btn-p tu-btn-sm" onclick="openCreateModal()">+ Nouveau bénévole</button>
  </div>
</div>

<div class="tu-pg">

  <?php if ($success): ?>
    <div style="background:var(--tu-green-soft);border:1.5px solid rgba(42,125,74,.25);border-radius:12px;padding:12px 16px;margin-bottom:16px;color:var(--tu-green-main);font-size:13px;"><?= h($success) ?></div>
  <?php endif; ?>
  <?php if (!empty($errors)): ?>
    <div style="background:var(--tu-red-soft);border-radius:12px;padding:12px 16px;margin-bottom:16px;color:var(--tu-red-main);font-size:13px;"><ul style="margin:0;padding-left:16px;"><?php foreach ($errors as $e): ?><li><?= h($e) ?></li><?php endforeach; ?></ul></div>
  <?php endif; ?>

  <?php if ($revealedCode): ?>
    <div style="background:#fff7ed;border:1.5px solid var(--tu-amber-300);border-radius:14px;padding:18px 20px;margin-bottom:20px;">
      <div style="font-weight:800;font-size:14px;color:var(--tu-amber-700);margin-bottom:6px;">🔑 Code d'accès pour <?= h($revealedFor) ?></div>
      <div style="font-family:monospace;font-size:28px;font-weight:800;letter-spacing:6px;color:var(--tu-ink-900);margin-bottom:8px;"><?= h($revealedCode) ?></div>
      <div style="font-size:12.5px;color:var(--tu-ink-400);">Communiquez ce code à la personne maintenant — il ne sera plus affiché en clair par la suite.</div>
    </div>
  <?php endif; ?>

  <div class="tu-ph">
    <div>
      <div class="tu-ph-title">Utilisateurs</div>
      <div class="tu-ph-sub">Basé sur l'annuaire des bénévoles</div>
    </div>
  </div>

  <div class="tu-kpi-grid" style="grid-template-columns:repeat(3,1fr);margin-bottom:20px;">
    <div class="tu-kpi"><div class="tu-kpi-val"><?= $totalActive ?></div><div class="tu-kpi-lbl">Comptes actifs</div></div>
    <div class="tu-kpi amber"><div class="tu-kpi-val"><?= $totalWithAccess ?></div><div class="tu-kpi-lbl">Accès admin</div></div>
    <div class="tu-kpi"><div class="tu-kpi-val"><?= $totalTemp ?></div><div class="tu-kpi-lbl">Membres temporaires</div></div>
  </div>

  <!-- Filtres -->
  <div class="tu-card" style="padding:16px;margin-bottom:16px;">
    <form method="get" style="display:flex;gap:10px;flex-wrap:wrap;align-items:flex-end;">
      <div class="tu-form-field" style="flex:1;min-width:220px;">
        <span class="tu-lbl">Rechercher</span>
        <input type="text" name="q" class="tu-input" placeholder="Nom, prénom, email…" value="<?= h($search) ?>">
      </div>
      <div class="tu-form-field" style="min-width:140px;">
        <span class="tu-lbl">Compte</span>
        <select name="active" class="tu-input">
          <option value="">Tous</option>
          <option value="1" <?= $fActive==='1'?'selected':'' ?>>Actifs</option>
          <option value="0" <?= $fActive==='0'?'selected':'' ?>>Désactivés</option>
        </select>
      </div>
      <button type="submit" class="tu-btn tu-btn-p">Filtrer</button>
    </form>
  </div>

  <!-- Onglets -->
  <div class="tu-tabs" style="display:flex;gap:4px;margin-bottom:16px;border-bottom:1.5px solid var(--tu-ink-100);">
    <button type="button" class="tu-tab-btn active" data-tab="permanent" onclick="switchUserTab('permanent')">Permanents</button>
    <button type="button" class="tu-tab-btn" data-tab="temporaire" onclick="switchUserTab('temporaire')">Temporaires</button>
  </div>
  <style>
    .tu-tab-btn {
      background:none;border:none;padding:10px 16px;font-size:13.5px;font-weight:700;
      color:var(--tu-ink-300);cursor:pointer;border-bottom:2.5px solid transparent;margin-bottom:-1.5px;
      transition:color .15s,border-color .15s;
    }
    .tu-tab-btn:hover { color:var(--tu-ink-700); }
    .tu-tab-btn.active { color:var(--tu-amber-600);border-bottom-color:var(--tu-amber-500); }
    .tu-tab-panel { display:none; }
    .tu-tab-panel.active { display:block; }
  </style>

  <?php
    $permanentVolunteers  = array_values(array_filter($volunteers, fn($v) => $v['presence_status'] !== 'temporaire'));
    $temporaryVolunteers  = array_values(array_filter($volunteers, fn($v) => $v['presence_status'] === 'temporaire'));

    function renderVolunteerTable(array $list): void {
      if (empty($list)) {
        echo '<div style="text-align:center;color:var(--tu-ink-300);padding:24px;font-size:13px;">Aucun bénévole dans cette catégorie.</div>';
        return;
      }
      ?>
      <table class="tu-tbl">
        <thead>
          <tr>
            <th>Bénévole</th>
            <th>Fonction</th>
            <th>Rôle admin</th>
            <th>Dernière connexion</th>
            <th style="text-align:right;">Actions</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($list as $v):
            $fullName = trim($v['first_name'] . ' ' . $v['last_name']);
            $hasAccess = $v['role'] !== null;
            $isActive  = (int)$v['is_active'] === 1;
            $roleLabel = match($v['role']) {
                'admin_plus' => 'Admin+',
                'admin'      => 'Admin',
                default      => null,
            };
            $roleBadgeClass = $v['role'] === 'admin_plus' ? 'tu-bdg-amber' : 'tu-bdg-blue';
          ?>
            <tr style="<?= !$isActive ? 'opacity:.5;' : '' ?>">
              <td>
                <div style="font-weight:700;font-size:13.5px;"><?= h($fullName) ?></div>
                <div style="font-size:11.5px;color:var(--tu-ink-300);">
                  <?= $v['email'] ? h($v['email']) : '' ?>
                  <?php if ($v['email'] && $v['phone']): ?> · <?php endif; ?>
                  <?= $v['phone'] ? h(formatPhone($v['phone'])) : '' ?>
                </div>
              </td>
              <td style="font-size:12.5px;">
                <?= h(labelFunction($v['member_function'])) ?>
                <button type="button" onclick='openEditProfileModal(<?= (int)$v['id'] ?>, <?= json_encode($v['member_function']) ?>, <?= json_encode($v['presence_status']) ?>, <?= json_encode($fullName) ?>)'
                        style="background:none;border:none;color:var(--tu-ink-300);cursor:pointer;font-size:11px;text-decoration:underline;padding:0;margin-left:4px;">éditer</button>
              </td>
              <td>
                <?php if ($hasAccess): ?>
                  <span class="tu-bdg <?= $roleBadgeClass ?>"><?= h($roleLabel) ?></span>
                <?php else: ?>
                  <span style="font-size:12px;color:var(--tu-ink-200);">Aucun</span>
                <?php endif; ?>
              </td>
              <td style="font-size:12px;color:var(--tu-ink-300);">
                <?= $v['last_login_at'] ? h(date('d/m/Y H:i', strtotime($v['last_login_at']))) : '—' ?>
              </td>
              <td style="text-align:right;">
                <div class="tu-tbl-acts" style="justify-content:flex-end;flex-wrap:wrap;">
                  <?php if (!$hasAccess && $isActive): ?>
                    <button type="button" class="tu-btn tu-btn-p tu-btn-xs" onclick="openGrantModal(<?= (int)$v['id'] ?>, <?= json_encode($fullName) ?>)">Donner accès</button>
                  <?php elseif ($hasAccess): ?>
                    <form method="post" style="display:inline;">
                      <input type="hidden" name="action" value="change_role">
                      <input type="hidden" name="volunteer_id" value="<?= (int)$v['id'] ?>">
                      <select name="role" class="tu-fsel" style="font-size:11px;" onchange="this.form.submit()">
                        <option value="admin" <?= $v['role']==='admin'?'selected':'' ?>>Admin</option>
                        <option value="admin_plus" <?= $v['role']==='admin_plus'?'selected':'' ?>>Admin+</option>
                      </select>
                    </form>
                    <form method="post" style="display:inline;" onsubmit="return confirm('Régénérer le code de <?= h(addslashes($fullName)) ?> ?');">
                      <input type="hidden" name="action" value="regenerate_code">
                      <input type="hidden" name="volunteer_id" value="<?= (int)$v['id'] ?>">
                      <button type="submit" class="tu-btn tu-btn-s tu-btn-xs">Nouveau code</button>
                    </form>
                    <form method="post" style="display:inline;" onsubmit="return confirm('Révoquer l\'accès admin de <?= h(addslashes($fullName)) ?> ?');">
                      <input type="hidden" name="action" value="revoke_access">
                      <input type="hidden" name="volunteer_id" value="<?= (int)$v['id'] ?>">
                      <button type="submit" class="tu-btn tu-btn-d tu-btn-xs">Révoquer</button>
                    </form>
                  <?php endif; ?>
                  <form method="post" style="display:inline;" onsubmit="return confirm('<?= $isActive ? 'Désactiver' : 'Réactiver' ?> le compte de <?= h(addslashes($fullName)) ?> ?');">
                    <input type="hidden" name="action" value="toggle_active">
                    <input type="hidden" name="volunteer_id" value="<?= (int)$v['id'] ?>">
                    <input type="hidden" name="new_status" value="<?= $isActive ? 0 : 1 ?>">
                    <button type="submit" class="tu-btn <?= $isActive ? 'tu-btn-d' : 'tu-btn-s' ?> tu-btn-xs"><?= $isActive ? 'Désactiver' : 'Réactiver' ?></button>
                  </form>
                </div>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
      <?php
    }
  ?>

  <div class="tu-tab-panel active" id="tabPanel-permanent">
    <div class="tu-card" style="overflow:hidden;">
      <?php renderVolunteerTable($permanentVolunteers); ?>
    </div>
  </div>

  <div class="tu-tab-panel" id="tabPanel-temporaire">
    <div class="tu-card" style="overflow:hidden;">
      <?php renderVolunteerTable($temporaryVolunteers); ?>
    </div>
  </div>

</div>

<script>
function switchUserTab(tab) {
  document.querySelectorAll('.tu-tab-btn').forEach(b => b.classList.toggle('active', b.dataset.tab === tab));
  document.querySelectorAll('.tu-tab-panel').forEach(p => p.classList.remove('active'));
  document.getElementById('tabPanel-' + tab).classList.add('active');
}
</script>


<!-- Modal : création d'un bénévole -->
<div id="createModalOverlay" style="display:none;position:fixed;inset:0;background:rgba(26,21,16,.55);z-index:2000;align-items:center;justify-content:center;padding:20px;" onclick="if(event.target===this) closeCreateModal()">
  <div class="tu-card" style="max-width:460px;width:100%;padding:0;overflow:hidden;max-height:88vh;display:flex;flex-direction:column;">
    <div style="padding:18px 20px;border-bottom:1px solid var(--tu-ink-100);">
      <div style="font-weight:800;font-size:15px;">Nouveau bénévole</div>
    </div>
    <form method="post" style="overflow-y:auto;flex:1;">
      <input type="hidden" name="action" value="create_volunteer">
      <div style="padding:20px;">
        <div class="tu-form-grid" style="gap:12px;">
          <div class="tu-form-field">
            <span class="tu-lbl">Prénom *</span>
            <input type="text" name="first_name" class="tu-input" required>
          </div>
          <div class="tu-form-field">
            <span class="tu-lbl">Nom *</span>
            <input type="text" name="last_name" class="tu-input" required>
          </div>
        </div>
        <div class="tu-form-field" style="margin-top:12px;">
          <span class="tu-lbl">Email</span>
          <input type="email" name="email" class="tu-input">
        </div>
        <div class="tu-form-field" style="margin-top:12px;">
          <span class="tu-lbl">Téléphone</span>
          <input type="text" name="phone" class="tu-input">
        </div>
        <div class="tu-form-field" style="margin-top:12px;">
          <span class="tu-lbl">Fonction</span>
          <select name="member_function" class="tu-input" id="createFunctionSelect" onchange="document.getElementById('createFunctionCustom').style.display=this.value==='__custom'?'block':'none'">
            <option value="">— Aucune —</option>
            <?php foreach (MEMBER_FUNCTIONS as $key => $label): ?>
              <option value="<?= h($key) ?>"><?= h($label) ?></option>
            <?php endforeach; ?>
            <option value="__custom">Autre…</option>
          </select>
          <input type="text" name="member_function_custom" id="createFunctionCustom" class="tu-input" style="display:none;margin-top:8px;" placeholder="Préciser la fonction…">
        </div>
        <div class="tu-form-field" style="margin-top:12px;">
          <span class="tu-lbl">Statut de présence</span>
          <select name="presence_status" class="tu-input">
            <option value="permanent">Permanent</option>
            <option value="temporaire">Temporaire (collecte ponctuelle, chargement…)</option>
          </select>
        </div>
      </div>
      <div style="padding:14px 20px;border-top:1px solid var(--tu-ink-100);display:flex;justify-content:flex-end;gap:8px;">
        <button type="button" class="tu-btn tu-btn-s" onclick="closeCreateModal()">Annuler</button>
        <button type="submit" class="tu-btn tu-btn-p">Créer</button>
      </div>
    </form>
  </div>
</div>

<!-- Modal : édition profil (fonction + statut) -->
<div id="editProfileModalOverlay" style="display:none;position:fixed;inset:0;background:rgba(26,21,16,.55);z-index:2000;align-items:center;justify-content:center;padding:20px;" onclick="if(event.target===this) closeEditProfileModal()">
  <div class="tu-card" style="max-width:400px;width:100%;padding:0;overflow:hidden;">
    <div style="padding:18px 20px;border-bottom:1px solid var(--tu-ink-100);">
      <div style="font-weight:800;font-size:15px;">Modifier le profil</div>
      <div id="editProfileName" style="font-size:12.5px;color:var(--tu-ink-300);margin-top:2px;"></div>
    </div>
    <form method="post">
      <input type="hidden" name="action" value="update_profile">
      <input type="hidden" name="volunteer_id" id="editProfileVolunteerId">
      <div style="padding:20px;">
        <div class="tu-form-field">
          <span class="tu-lbl">Fonction</span>
          <select name="member_function" class="tu-input" id="editFunctionSelect" onchange="document.getElementById('editFunctionCustom').style.display=this.value==='__custom'?'block':'none'">
            <option value="">— Aucune —</option>
            <?php foreach (MEMBER_FUNCTIONS as $key => $label): ?>
              <option value="<?= h($key) ?>"><?= h($label) ?></option>
            <?php endforeach; ?>
            <option value="__custom">Autre…</option>
          </select>
          <input type="text" name="member_function_custom" id="editFunctionCustom" class="tu-input" style="display:none;margin-top:8px;" placeholder="Préciser la fonction…">
        </div>
        <div class="tu-form-field" style="margin-top:12px;">
          <span class="tu-lbl">Statut de présence</span>
          <select name="presence_status" class="tu-input" id="editPresenceSelect">
            <option value="permanent">Permanent</option>
            <option value="temporaire">Temporaire (collecte ponctuelle, chargement…)</option>
          </select>
        </div>
      </div>
      <div style="padding:14px 20px;border-top:1px solid var(--tu-ink-100);display:flex;justify-content:flex-end;gap:8px;">
        <button type="button" class="tu-btn tu-btn-s" onclick="closeEditProfileModal()">Annuler</button>
        <button type="submit" class="tu-btn tu-btn-p">Enregistrer</button>
      </div>
    </form>
  </div>
</div>

<!-- Modal : attribution d'un accès -->
<div id="grantModalOverlay" style="display:none;position:fixed;inset:0;background:rgba(26,21,16,.55);z-index:2000;align-items:center;justify-content:center;padding:20px;" onclick="if(event.target===this) closeGrantModal()">
  <div class="tu-card" style="max-width:400px;width:100%;padding:0;overflow:hidden;">
    <div style="padding:18px 20px;border-bottom:1px solid var(--tu-ink-100);">
      <div style="font-weight:800;font-size:15px;">Donner un accès admin</div>
      <div id="grantModalName" style="font-size:12.5px;color:var(--tu-ink-300);margin-top:2px;"></div>
    </div>
    <form method="post">
      <input type="hidden" name="action" value="grant_access">
      <input type="hidden" name="volunteer_id" id="grantVolunteerId">
      <div style="padding:20px;">
        <div class="tu-form-field">
          <span class="tu-lbl">Rôle</span>
          <select name="role" class="tu-input">
            <option value="admin">Admin</option>
            <option value="admin_plus">Admin+ (accès gestion utilisateurs)</option>
          </select>
        </div>
        <div style="font-size:12px;color:var(--tu-ink-300);margin-top:10px;">
          Un code à 8 chiffres sera généré automatiquement et affiché une seule fois.
        </div>
      </div>
      <div style="padding:14px 20px;border-top:1px solid var(--tu-ink-100);display:flex;justify-content:flex-end;gap:8px;">
        <button type="button" class="tu-btn tu-btn-s" onclick="closeGrantModal()">Annuler</button>
        <button type="submit" class="tu-btn tu-btn-p">Attribuer</button>
      </div>
    </form>
  </div>
</div>

<script>
function openCreateModal() { document.getElementById('createModalOverlay').style.display = 'flex'; }
function closeCreateModal() { document.getElementById('createModalOverlay').style.display = 'none'; }

function openGrantModal(id, name) {
  document.getElementById('grantVolunteerId').value = id;
  document.getElementById('grantModalName').textContent = name;
  document.getElementById('grantModalOverlay').style.display = 'flex';
}
function closeGrantModal() { document.getElementById('grantModalOverlay').style.display = 'none'; }

function openEditProfileModal(id, currentFunction, currentStatus, name) {
  document.getElementById('editProfileVolunteerId').value = id;
  document.getElementById('editProfileName').textContent = name;

  const fnSelect = document.getElementById('editFunctionSelect');
  const fnCustom = document.getElementById('editFunctionCustom');
  const known = Array.from(fnSelect.options).some(o => o.value === currentFunction);
  if (currentFunction && !known) {
    fnSelect.value = '__custom';
    fnCustom.style.display = 'block';
    fnCustom.value = currentFunction;
  } else {
    fnSelect.value = currentFunction || '';
    fnCustom.style.display = 'none';
    fnCustom.value = '';
  }

  document.getElementById('editPresenceSelect').value = currentStatus || 'permanent';
  document.getElementById('editProfileModalOverlay').style.display = 'flex';
}
function closeEditProfileModal() { document.getElementById('editProfileModalOverlay').style.display = 'none'; }
</script>

</div><!-- /tu-main -->
</body>
</html>
