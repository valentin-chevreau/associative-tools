<?php
// admin/audit_log.php — Journal d'audit (qui a fait quoi, sur tous les modules)

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

function labelModule(string $m): string {
    return match($m) {
        'logistique' => 'Logistique',
        'planning'   => 'Planning',
        'caisse'     => 'Caisse',
        'admin'      => 'Administration',
        'auth'       => 'Connexion',
        default      => ucfirst($m),
    };
}
function badgeModule(string $m): string {
    return match($m) {
        'logistique' => 'tu-bdg-amber',
        'planning'   => 'tu-bdg-blue',
        'caisse'     => 'tu-bdg-teal',
        'admin'      => 'tu-bdg-ink',
        'auth'       => 'tu-bdg-ink',
        default      => 'tu-bdg-ink',
    };
}
function labelAction(string $a): string {
    return match($a) {
        'create' => 'Création',
        'update' => 'Modification',
        'delete' => 'Suppression',
        'login'  => 'Connexion',
        'logout' => 'Déconnexion',
        default  => ucfirst($a),
    };
}
function iconAction(string $a): string {
    return match($a) {
        'create' => '<svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M12 5v14M5 12h14"/></svg>',
        'update' => '<svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>',
        'delete' => '<svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"/></svg>',
        'login'  => '<svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M15 3h4a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2h-4M10 17l5-5-5-5M15 12H3"/></svg>',
        'logout' => '<svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4M16 17l5-5-5-5M21 12H9"/></svg>',
        default  => '',
    };
}

// ── Filtres ───────────────────────────────────────────────────────────────
$fModule = trim($_GET['module'] ?? '');
$fAction = trim($_GET['action'] ?? '');
$fSearch = trim($_GET['q'] ?? '');
$fFrom   = trim($_GET['from'] ?? '');
$fTo     = trim($_GET['to'] ?? '');
$page    = max(1, (int)($_GET['page'] ?? 1));
$perPage = 50;

$where  = [];
$params = [];

if ($fModule !== '') { $where[] = "module = ?"; $params[] = $fModule; }
if ($fAction !== '') { $where[] = "action = ?"; $params[] = $fAction; }
if ($fSearch !== '') {
    $where[] = "(actor_name LIKE ? OR entity_label LIKE ?)";
    $like = '%' . $fSearch . '%';
    $params[] = $like; $params[] = $like;
}
if ($fFrom !== '') { $where[] = "created_at >= ?"; $params[] = $fFrom . ' 00:00:00'; }
if ($fTo !== '')   { $where[] = "created_at <= ?"; $params[] = $fTo   . ' 23:59:59'; }

$whereSql = !empty($where) ? 'WHERE ' . implode(' AND ', $where) : '';

$countStmt = $pdo->prepare("SELECT COUNT(*) FROM suite_audit_log $whereSql");
$countStmt->execute($params);
$totalRows = (int)$countStmt->fetchColumn();
$totalPages = max(1, (int)ceil($totalRows / $perPage));
$page = min($page, $totalPages);
$offset = ($page - 1) * $perPage;

$sql = "SELECT * FROM suite_audit_log $whereSql ORDER BY created_at DESC, id DESC LIMIT $perPage OFFSET $offset";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$logs = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Listes pour les selects de filtre
$modules = $pdo->query("SELECT DISTINCT module FROM suite_audit_log ORDER BY module")->fetchAll(PDO::FETCH_COLUMN);
$actions = $pdo->query("SELECT DISTINCT action FROM suite_audit_log ORDER BY action")->fetchAll(PDO::FETCH_COLUMN);

// KPIs rapides (sur l'ensemble, pas juste la page filtrée)
$todayCount = (int)$pdo->query("SELECT COUNT(*) FROM suite_audit_log WHERE DATE(created_at) = CURDATE()")->fetchColumn();
$weekCount  = (int)$pdo->query("SELECT COUNT(*) FROM suite_audit_log WHERE created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)")->fetchColumn();

function buildFilterUrl(array $overrides = []): string {
    $params = array_merge($_GET, $overrides);
    $params = array_filter($params, fn($v) => $v !== '' && $v !== null);
    return '?' . http_build_query($params);
}

$pageTitle = 'Journal d\'audit — Touraine-Ukraine';
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
      .audit-row { border-bottom: 1px solid var(--tu-ink-50); padding: 12px 0; display: flex; gap: 12px; align-items: flex-start; }
      .audit-row:last-child { border-bottom: none; }
      .audit-icon { width: 30px; height: 30px; border-radius: 9px; display: flex; align-items: center; justify-content: center; flex-shrink: 0; margin-top: 1px; }
      .audit-icon.create { background: rgba(42,157,143,.12); color: #2a9d8f; }
      .audit-icon.update { background: rgba(59,110,165,.12); color: #3b6ea5; }
      .audit-icon.delete { background: rgba(192,67,42,.12); color: #c0432a; }
      .audit-icon.login,.audit-icon.logout { background: rgba(156,145,132,.15); color: #9c9184; }
    </style>
</head>
<body class="tu-v2">

<?php
require_once dirname(__DIR__) . '/shared/suite_nav.php';
suite_nav_render('audit', '');
?>
<div class="tu-main">

<div class="tu-topbar">
  <div class="tu-bc">
    <a href="<?= h(suite_base()) ?>/index.php" style="color:inherit;text-decoration:none;">Accueil</a>
    <span class="tu-bc-sep">›</span>
    <span class="tu-bc-cur">Journal d'audit</span>
  </div>
</div>

<div class="tu-pg">

  <div class="tu-ph">
    <div>
      <div class="tu-ph-title">Journal d'audit</div>
      <div class="tu-ph-sub">Historique de toutes les actions réalisées dans la suite</div>
    </div>
  </div>

  <!-- KPIs -->
  <div class="tu-kpi-grid" style="grid-template-columns:repeat(3,1fr);margin-bottom:20px;">
    <div class="tu-kpi"><div class="tu-kpi-val"><?= number_format($totalRows, 0, ',', ' ') ?></div><div class="tu-kpi-lbl">Entrées totales</div></div>
    <div class="tu-kpi amber"><div class="tu-kpi-val"><?= $todayCount ?></div><div class="tu-kpi-lbl">Aujourd'hui</div></div>
    <div class="tu-kpi"><div class="tu-kpi-val"><?= $weekCount ?></div><div class="tu-kpi-lbl">7 derniers jours</div></div>
  </div>

  <!-- Filtres -->
  <div class="tu-card" style="padding:18px;margin-bottom:16px;">
    <form method="get" style="display:flex;gap:10px;flex-wrap:wrap;align-items:flex-end;">
      <div class="tu-form-field" style="min-width:160px;">
        <span class="tu-lbl">Module</span>
        <select name="module" class="tu-input">
          <option value="">Tous</option>
          <?php foreach ($modules as $m): ?>
            <option value="<?= h($m) ?>" <?= $fModule===$m?'selected':'' ?>><?= h(labelModule($m)) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="tu-form-field" style="min-width:160px;">
        <span class="tu-lbl">Action</span>
        <select name="action" class="tu-input">
          <option value="">Toutes</option>
          <?php foreach ($actions as $a): ?>
            <option value="<?= h($a) ?>" <?= $fAction===$a?'selected':'' ?>><?= h(labelAction($a)) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="tu-form-field" style="min-width:140px;">
        <span class="tu-lbl">Du</span>
        <input type="date" name="from" class="tu-input" value="<?= h($fFrom) ?>">
      </div>
      <div class="tu-form-field" style="min-width:140px;">
        <span class="tu-lbl">Au</span>
        <input type="date" name="to" class="tu-input" value="<?= h($fTo) ?>">
      </div>
      <div class="tu-form-field" style="flex:1;min-width:200px;">
        <span class="tu-lbl">Recherche (nom, élément)</span>
        <input type="text" name="q" class="tu-input" placeholder="Ex: Valentin, Été 2026…" value="<?= h($fSearch) ?>">
      </div>
      <button type="submit" class="tu-btn tu-btn-p">Filtrer</button>
      <?php if ($fModule || $fAction || $fSearch || $fFrom || $fTo): ?>
        <a href="audit_log.php" class="tu-btn tu-btn-s">Réinitialiser</a>
      <?php endif; ?>
    </form>
  </div>

  <!-- Liste -->
  <div class="tu-card" style="padding:18px;">
    <?php if (empty($logs)): ?>
      <div style="text-align:center;color:var(--tu-ink-300);padding:24px;font-size:13px;">Aucune entrée ne correspond à ces filtres.</div>
    <?php else: ?>
      <?php foreach ($logs as $log):
        $details = $log['details_json'] ? json_decode($log['details_json'], true) : null;
      ?>
        <div class="audit-row">
          <div class="audit-icon <?= h($log['action']) ?>"><?= iconAction($log['action']) ?></div>
          <div style="flex:1;min-width:0;">
            <div style="display:flex;align-items:baseline;gap:8px;flex-wrap:wrap;">
              <span style="font-weight:700;font-size:13.5px;"><?= h($log['actor_name']) ?></span>
              <span class="tu-bdg <?= badgeModule($log['module']) ?>" style="font-size:10px;"><?= h(labelModule($log['module'])) ?></span>
              <span style="font-size:12.5px;color:var(--tu-ink-400);"><?= h(labelAction($log['action'])) ?></span>
              <?php if ($log['entity_type']): ?>
                <span style="font-size:12px;color:var(--tu-ink-300);">· <?= h($log['entity_type']) ?><?= $log['entity_id'] ? ' #' . (int)$log['entity_id'] : '' ?></span>
              <?php endif; ?>
            </div>
            <?php if ($log['entity_label']): ?>
              <div style="font-size:13px;color:var(--tu-ink-600);margin-top:2px;"><?= h($log['entity_label']) ?></div>
            <?php endif; ?>
            <?php if ($details && is_array($details)): ?>
              <div style="font-size:11.5px;color:var(--tu-ink-300);margin-top:4px;font-family:monospace;">
                <?php foreach ($details as $dk => $dv): ?>
                  <span style="margin-right:10px;"><?= h((string)$dk) ?>: <strong><?= h(is_array($dv) ? json_encode($dv) : (string)$dv) ?></strong></span>
                <?php endforeach; ?>
              </div>
            <?php endif; ?>
          </div>
          <div style="font-size:11.5px;color:var(--tu-ink-300);white-space:nowrap;flex-shrink:0;text-align:right;">
            <?= h(date('d/m/Y', strtotime($log['created_at']))) ?><br>
            <?= h(date('H:i', strtotime($log['created_at']))) ?>
          </div>
        </div>
      <?php endforeach; ?>
    <?php endif; ?>
  </div>

  <!-- Pagination -->
  <?php if ($totalPages > 1): ?>
    <div style="display:flex;justify-content:center;gap:6px;margin-top:18px;flex-wrap:wrap;">
      <?php if ($page > 1): ?>
        <a href="<?= h(buildFilterUrl(['page' => $page - 1])) ?>" class="tu-btn tu-btn-s tu-btn-sm">← Précédent</a>
      <?php endif; ?>
      <span style="display:flex;align-items:center;padding:0 12px;font-size:12.5px;color:var(--tu-ink-400);">Page <?= $page ?> / <?= $totalPages ?></span>
      <?php if ($page < $totalPages): ?>
        <a href="<?= h(buildFilterUrl(['page' => $page + 1])) ?>" class="tu-btn tu-btn-s tu-btn-sm">Suivant →</a>
      <?php endif; ?>
    </div>
  <?php endif; ?>

</div>

</div><!-- /tu-main -->
</body>
</html>
