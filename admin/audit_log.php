<?php
// admin/audit_log.php — Journal d'audit (qui a fait quoi, sur tous les modules)
// Réservé au super admin : le journal contient des données de sécurité (échecs de connexion,
// droits, accès refusés) et des informations sur les bénévoles.

declare(strict_types=1);

require_once __DIR__ . '/../shared/bootstrap.php';

if (!defined('APP_BASE')) {
    define('APP_BASE', suite_base() . '/admin');
}

if (!is_super_admin()) {
    suite_forbidden("Le journal d'audit est réservé aux super administrateurs.", "Accès refusé", "audit", "Super admin");
}

$pdo = _bootstrap_get_pdo();
if (!($pdo instanceof PDO)) {
    http_response_code(500);
    echo "Connexion base de données indisponible.";
    exit;
}

function h(?string $s): string { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

/** Classe de badge selon le module. */
function auditBadgeModule(string $m): string {
    return match ($m) {
        'logistique' => 'tu-bdg-amber',
        'planning'   => 'tu-bdg-blue',
        'caisse', 'donations' => 'tu-bdg-teal',
        default      => 'tu-bdg-ink',
    };
}

function auditIcon(string $kind): string {
    $a = 'width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"';
    return match ($kind) {
        'create' => "<svg $a><path d=\"M12 5v14M5 12h14\"/></svg>",
        'delete' => "<svg $a><polyline points=\"3 6 5 6 21 6\"/><path d=\"M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6\"/></svg>",
        'alert'  => "<svg $a><path d=\"M10.3 3.9L1.8 18a2 2 0 0 0 1.7 3h17a2 2 0 0 0 1.7-3L13.7 3.9a2 2 0 0 0-3.4 0z\"/><line x1=\"12\" y1=\"9\" x2=\"12\" y2=\"13\"/><line x1=\"12\" y1=\"17\" x2=\"12.01\" y2=\"17\"/></svg>",
        'info'   => "<svg $a><circle cx=\"12\" cy=\"12\" r=\"9\"/><line x1=\"12\" y1=\"8\" x2=\"12.01\" y2=\"8\"/><line x1=\"11\" y1=\"12\" x2=\"12\" y2=\"12\"/><line x1=\"12\" y1=\"12\" x2=\"12\" y2=\"16\"/></svg>",
        default  => "<svg $a><path d=\"M12 20h9\"/><path d=\"M16.5 3.5a2.1 2.1 0 0 1 3 3L7 19l-4 1 1-4 12.5-12.5z\"/></svg>",
    };
}

function auditRoleLabel(?string $r): string {
    return match ($r) {
        'super_admin' => 'Super admin',
        'admin_plus'  => 'Admin+',
        'admin'       => 'Admin',
        'system'      => 'Système',
        'public'      => 'Non connecté',
        default       => (string)$r,
    };
}

/** Affichage d'une valeur d'un diff : libellés de rôles/états, sinon valeur brute. */
function auditShowVal(mixed $v): string {
    if ($v === null || $v === '') return '—';
    return match ((string)$v) {
        'allow' => 'autorisé', 'deny' => 'refusé', 'inherit' => 'hérité',
        'super_admin' => 'Super admin', 'admin_plus' => 'Admin+', 'admin' => 'Admin',
        'permanent' => 'Permanent', 'temporaire' => 'Occasionnel',
        default => audit_format_value($v),
    };
}

// Actions « sensibles » : suppressions, droits, accès, migrations, échecs.
const AUDIT_SENSITIVE_ACTIONS = [
    'delete', 'cancel', 'stock_remove', 'unregister', 'access_grant', 'access_revoke', 'role_change',
    'code_regenerate', 'migration_apply', 'migration_mark', 'migration_fail', 'login_failed', 'access_denied',
    'suppression_fiche', 'desactivation_fiche',
];
const AUDIT_ALERT_ACTIONS = ['login_failed', 'access_denied', 'migration_fail'];

// ── Filtres ───────────────────────────────────────────────────────────────
$fPeriod = (string)($_GET['period'] ?? '30');
$fFrom   = trim((string)($_GET['from'] ?? ''));
$fTo     = trim((string)($_GET['to'] ?? ''));
$fModule = trim((string)($_GET['module'] ?? ''));
$fAction = trim((string)($_GET['action'] ?? ''));
$fActor  = trim((string)($_GET['actor'] ?? ''));
$fEntity = trim((string)($_GET['entity_type'] ?? ''));
$fEntId  = (int)($_GET['entity_id'] ?? 0);
$fOnly   = trim((string)($_GET['only'] ?? ''));
$fSearch = trim((string)($_GET['q'] ?? ''));
$page    = max(1, (int)($_GET['page'] ?? 1));
$perPage = 50;

$where  = [];
$params = [];
$whereParts = []; // [clause, params] — permet de recalculer les compteurs sans le filtre module
$addWhere = function (string $clause, array $p = []) use (&$where, &$params, &$whereParts): void {
    $where[] = $clause;
    array_push($params, ...$p);
    $whereParts[] = [$clause, $p];
};

// Période : préréglage, sauf si des dates personnalisées sont saisies
if ($fFrom !== '' || $fTo !== '') {
    if ($fFrom !== '') $addWhere("created_at >= ?", [$fFrom . ' 00:00:00']);
    if ($fTo   !== '') $addWhere("created_at <= ?", [$fTo . ' 23:59:59']);
    $fPeriod = 'custom';
} elseif ($fPeriod === 'today') {
    $addWhere("created_at >= CURDATE()");
} elseif (in_array($fPeriod, ['7', '30', '90'], true)) {
    $addWhere("created_at >= DATE_SUB(NOW(), INTERVAL " . (int)$fPeriod . " DAY)");
} else {
    $fPeriod = 'all';
}

if ($fModule !== '') $addWhere("module = ?", [$fModule]);
if ($fAction !== '') $addWhere("action = ?", [$fAction]);
if ($fActor  !== '') $addWhere("actor_name = ?", [$fActor]);
if ($fEntity !== '') $addWhere("entity_type = ?", [$fEntity]);
if ($fEntId  >  0)   $addWhere("entity_id = ?", [$fEntId]);

if ($fOnly === 'alerts') {
    $addWhere("action IN (" . implode(',', array_fill(0, count(AUDIT_ALERT_ACTIONS), '?')) . ")", AUDIT_ALERT_ACTIONS);
} elseif ($fOnly === 'sensitive') {
    $addWhere("(action IN (" . implode(',', array_fill(0, count(AUDIT_SENSITIVE_ACTIONS), '?')) . ") OR entity_type = 'permissions')", AUDIT_SENSITIVE_ACTIONS);
} else {
    $fOnly = '';
}

if ($fSearch !== '') {
    $like = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $fSearch) . '%';
    $addWhere("(actor_name LIKE ? OR entity_label LIKE ? OR entity_type LIKE ? OR details_json LIKE ?)", [$like, $like, $like, $like]);
}

$whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

// ── Export CSV (mêmes filtres, plafonné) ─────────────────────────────────
if (($_GET['export'] ?? '') === 'csv') {
    $ex = $pdo->prepare("SELECT * FROM suite_audit_log $whereSql ORDER BY created_at DESC, id DESC LIMIT 20000");
    $ex->execute($params);
    audit_log('admin', 'export', 'audit', null, 'Journal d\'audit (CSV)', ['filtres' => array_filter([
        'periode' => $fPeriod, 'module' => $fModule, 'action' => $fAction, 'acteur' => $fActor,
        'entite' => $fEntity, 'recherche' => $fSearch, 'seulement' => $fOnly,
    ])]);
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="journal_audit_' . date('Ymd_His') . '.csv"');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF");
    fputcsv($out, ['Date', 'Acteur', 'Rôle', 'Module', 'Action', 'Type', 'N°', 'Élément', 'Modifications', 'Détails', 'IP'], ';');
    while ($r = $ex->fetch(PDO::FETCH_ASSOC)) {
        $d = $r['details_json'] ? (json_decode((string)$r['details_json'], true) ?: []) : [];
        $changes = [];
        foreach (($d['changes'] ?? []) as $k => $c) {
            $changes[] = audit_field_label((string)$k) . ' : ' . (!empty($c['redacted']) ? '(valeur masquée)' : auditShowVal($c['from'] ?? null) . ' → ' . auditShowVal($c['to'] ?? null));
        }
        unset($d['changes'], $d['_page']);
        $ctx = [];
        foreach ($d as $k => $v) $ctx[] = audit_field_label((string)$k) . ' : ' . audit_format_value($v);
        fputcsv($out, [
            $r['created_at'], $r['actor_name'], auditRoleLabel($r['actor_role']), audit_module_label((string)$r['module']),
            audit_action_label((string)$r['action']), audit_entity_label((string)$r['entity_type']), $r['entity_id'],
            $r['entity_label'], implode(' | ', $changes), implode(' | ', $ctx), $r['ip_address'],
        ], ';');
    }
    fclose($out);
    exit;
}

// ── Données ───────────────────────────────────────────────────────────────
$countStmt = $pdo->prepare("SELECT COUNT(*) FROM suite_audit_log $whereSql");
$countStmt->execute($params);
$totalRows  = (int)$countStmt->fetchColumn();
$totalPages = max(1, (int)ceil($totalRows / $perPage));
$page       = min($page, $totalPages);
$offset     = ($page - 1) * $perPage;

$stmt = $pdo->prepare("SELECT * FROM suite_audit_log $whereSql ORDER BY created_at DESC, id DESC LIMIT $perPage OFFSET $offset");
$stmt->execute($params);
$logs = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Listes des filtres (sur toute la table)
$modules  = $pdo->query("SELECT DISTINCT module FROM suite_audit_log ORDER BY module")->fetchAll(PDO::FETCH_COLUMN);
$actions  = $pdo->query("SELECT DISTINCT action FROM suite_audit_log ORDER BY action")->fetchAll(PDO::FETCH_COLUMN);
$actors   = $pdo->query("SELECT actor_name, COUNT(*) AS n FROM suite_audit_log GROUP BY actor_name ORDER BY n DESC, actor_name LIMIT 200")->fetchAll(PDO::FETCH_ASSOC);
$entities = $pdo->query("SELECT DISTINCT entity_type FROM suite_audit_log WHERE entity_type IS NOT NULL AND entity_type <> '' ORDER BY entity_type")->fetchAll(PDO::FETCH_COLUMN);

// Répartition par module sur la sélection courante (hors filtre module, pour pouvoir en changer)
$wm = []; $pm = [];
foreach ($whereParts as [$clause, $clauseParams]) {
    if ($clause === 'module = ?') continue;
    $wm[] = $clause;
    array_push($pm, ...$clauseParams);
}
$byModuleStmt = $pdo->prepare("SELECT module, COUNT(*) AS n FROM suite_audit_log " . ($wm ? 'WHERE ' . implode(' AND ', $wm) : '') . " GROUP BY module ORDER BY n DESC");
$byModuleStmt->execute($pm);
$byModule = $byModuleStmt->fetchAll(PDO::FETCH_ASSOC);

// KPIs globaux
$todayCount = (int)$pdo->query("SELECT COUNT(*) FROM suite_audit_log WHERE created_at >= CURDATE()")->fetchColumn();
$weekCount  = (int)$pdo->query("SELECT COUNT(*) FROM suite_audit_log WHERE created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)")->fetchColumn();
$alertWeek  = $pdo->prepare("SELECT COUNT(*) FROM suite_audit_log WHERE created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY) AND action IN (" . implode(',', array_fill(0, count(AUDIT_ALERT_ACTIONS), '?')) . ")");
$alertWeek->execute(AUDIT_ALERT_ACTIONS);
$alertWeekCount = (int)$alertWeek->fetchColumn();

function buildFilterUrl(array $overrides = [], bool $keepPage = false): string {
    $p = array_merge($_GET, $overrides);
    if (!$keepPage && !array_key_exists('page', $overrides)) unset($p['page']);
    $p = array_filter($p, fn($v) => $v !== '' && $v !== null);
    $q = http_build_query($p);
    return suite_base() . '/admin/audit_log.php' . ($q !== '' ? '?' . $q : '');
}

/** Page de l'élément concerné (si elle existe encore), sinon null. */
function auditEntityUrl(string $type, int $id, string $action): ?string {
    if ($id <= 0 || in_array($action, ['delete', 'cancel'], true)) return null;
    $path = match ($type) {
        'convoy'       => 'logistique/convoys/view.php',
        'category'     => 'logistique/categories/view.php',
        'subvention'   => 'subventions/subvention_detail.php',
        'event'        => 'planning/admin/event_edit.php',
        'adhesion'     => 'adhesions/adhesion_edit.php',
        default        => null,
    };
    return $path ? suite_base() . '/' . $path . '?id=' . $id : null;
}

$entityName = '';
if ($fEntId > 0) {
    $nm = $pdo->prepare("SELECT entity_label FROM suite_audit_log WHERE entity_id = ?" . ($fEntity !== '' ? " AND entity_type = ?" : '') . " AND entity_label IS NOT NULL AND entity_label <> '' ORDER BY id DESC LIMIT 1");
    $nm->execute($fEntity !== '' ? [$fEntId, $fEntity] : [$fEntId]);
    $entityName = (string)($nm->fetchColumn() ?: '');
}

$hasFilter = ($fModule || $fAction || $fActor || $fEntity || $fEntId || $fSearch || $fOnly || $fFrom || $fTo || $fPeriod !== '30');

$pageTitle = 'Journal d\'audit — Touraine-Ukraine';
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
      .au-row { border-bottom: 1px solid var(--tu-ink-50); padding: 14px 0; display: flex; gap: 12px; align-items: flex-start; }
      .au-row:last-child { border-bottom: none; }
      .au-ico { width: 30px; height: 30px; border-radius: 9px; display: flex; align-items: center; justify-content: center; flex-shrink: 0; margin-top: 1px; }
      .au-ico.create { background: rgba(42,157,143,.12); color: #2a9d8f; }
      .au-ico.update { background: rgba(59,110,165,.12); color: #3b6ea5; }
      .au-ico.delete { background: rgba(192,67,42,.12);  color: #c0432a; }
      .au-ico.alert  { background: rgba(212,119,44,.16); color: #b25e1e; }
      .au-ico.info   { background: rgba(156,145,132,.15); color: #9c9184; }
      .au-head { display: flex; align-items: baseline; gap: 8px; flex-wrap: wrap; }
      .au-actor { font-weight: 700; font-size: 13.5px; }
      .au-role  { font-size: 11px; color: var(--tu-ink-300); }
      .au-label { font-size: 13px; color: var(--tu-ink-600); margin-top: 3px; }
      .au-label a { color: inherit; text-decoration: none; border-bottom: 1px dotted var(--tu-ink-200); }
      .au-label a:hover { border-bottom-style: solid; }
      .au-diff { margin-top: 8px; border: 1px solid var(--tu-ink-100); border-radius: 10px; overflow: hidden; font-size: 12.5px; }
      .au-diff-row { display: grid; grid-template-columns: 170px 1fr; gap: 10px; padding: 6px 10px; border-bottom: 1px solid var(--tu-ink-50); align-items: baseline; }
      .au-diff-row:last-child { border-bottom: none; }
      .au-diff-k { color: var(--tu-ink-400); font-weight: 600; }
      .au-from { background: rgba(192,67,42,.10); color: #a3361f; border-radius: 5px; padding: 1px 6px; text-decoration: line-through; text-decoration-color: rgba(163,54,31,.4); word-break: break-word; }
      .au-to   { background: rgba(42,125,74,.12); color: #1f6a3d; border-radius: 5px; padding: 1px 6px; font-weight: 600; word-break: break-word; }
      .au-arrow { color: var(--tu-ink-300); margin: 0 6px; }
      .au-masked { color: var(--tu-ink-300); font-style: italic; }
      .au-ctx { display: flex; flex-wrap: wrap; gap: 6px; margin-top: 8px; }
      .au-chip { font-size: 11.5px; background: var(--tu-sand-50); border: 1px solid var(--tu-ink-100); border-radius: 999px; padding: 2px 9px; color: var(--tu-ink-500); }
      .au-chip b { color: var(--tu-ink-700); font-weight: 700; }
      .au-more { margin-top: 6px; font-size: 11.5px; color: var(--tu-ink-300); }
      .au-more summary { cursor: pointer; display: inline-block; }
      .au-time { font-size: 11.5px; color: var(--tu-ink-300); white-space: nowrap; flex-shrink: 0; text-align: right; }
      .au-mods { display: flex; flex-wrap: wrap; gap: 6px; margin-bottom: 14px; }
      .au-mod { font-size: 12px; font-weight: 600; text-decoration: none; padding: 5px 11px; border-radius: 999px; border: 1.5px solid var(--tu-ink-100); color: var(--tu-ink-500); background: #fff; }
      .au-mod.on { background: var(--tu-ink-900, #1a1510); color: #fff; border-color: transparent; }
      .au-seg { display: inline-flex; border: 1.5px solid var(--tu-ink-100); border-radius: 11px; overflow: hidden; background: #fff; }
      .au-seg a { font-size: 12.5px; font-weight: 600; padding: 8px 12px; text-decoration: none; color: var(--tu-ink-500); border-right: 1px solid var(--tu-ink-100); }
      .au-seg a:last-child { border-right: none; }
      .au-seg a.on { background: var(--tu-amber-100, #fdf3e6); color: var(--tu-amber-700, #b25e1e); }
      @media (max-width: 700px) {
        .au-row { flex-wrap: wrap; }
        .au-time { width: 100%; text-align: left; padding-left: 42px; }
        .au-diff-row { grid-template-columns: 1fr; gap: 2px; }
      }
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
  <div class="tu-topbar-acts">
    <a href="<?= h(buildFilterUrl(['export' => 'csv'])) ?>" class="tu-btn tu-btn-s tu-btn-sm">Exporter en CSV</a>
  </div>
</div>

<div class="tu-pg">

  <div class="tu-ph">
    <div>
      <div class="tu-ph-title">Journal d'audit</div>
      <div class="tu-ph-sub">Qui a fait quoi, quand, avec l'avant/après des modifications — réservé aux super administrateurs</div>
    </div>
  </div>

  <!-- KPIs -->
  <div class="tu-kpi-grid" style="grid-template-columns:repeat(4,1fr);margin-bottom:20px;">
    <div class="tu-kpi"><div class="tu-kpi-val"><?= number_format($totalRows, 0, ',', ' ') ?></div><div class="tu-kpi-lbl"><?= $hasFilter ? 'Résultats' : 'Entrées (période)' ?></div></div>
    <div class="tu-kpi amber"><div class="tu-kpi-val"><?= $todayCount ?></div><div class="tu-kpi-lbl">Aujourd'hui</div></div>
    <div class="tu-kpi"><div class="tu-kpi-val"><?= $weekCount ?></div><div class="tu-kpi-lbl">7 derniers jours</div></div>
    <a href="<?= h(buildFilterUrl(['only' => 'alerts', 'period' => '7'])) ?>" style="text-decoration:none;color:inherit;">
      <div class="tu-kpi <?= $alertWeekCount ? 'red' : '' ?>"><div class="tu-kpi-val"><?= $alertWeekCount ?></div><div class="tu-kpi-lbl">Alertes (7 j) — échecs de connexion, accès refusés</div></div>
    </a>
  </div>

  <!-- Filtres -->
  <div class="tu-card" style="padding:18px;margin-bottom:16px;">
    <div style="display:flex;gap:14px;flex-wrap:wrap;align-items:center;margin-bottom:14px;">
      <div class="au-seg" role="group" aria-label="Période">
        <?php foreach (['today' => "Aujourd'hui", '7' => '7 jours', '30' => '30 jours', '90' => '90 jours', 'all' => 'Tout'] as $k => $lbl): ?>
          <a href="<?= h(buildFilterUrl(['period' => $k, 'from' => '', 'to' => ''])) ?>" class="<?= $fPeriod === (string)$k ? 'on' : '' ?>"><?= h($lbl) ?></a>
        <?php endforeach; ?>
      </div>
      <div class="au-seg" role="group" aria-label="Type d'actions">
        <a href="<?= h(buildFilterUrl(['only' => ''])) ?>" class="<?= $fOnly === '' ? 'on' : '' ?>">Tout</a>
        <a href="<?= h(buildFilterUrl(['only' => 'sensitive'])) ?>" class="<?= $fOnly === 'sensitive' ? 'on' : '' ?>">Actions sensibles</a>
        <a href="<?= h(buildFilterUrl(['only' => 'alerts'])) ?>" class="<?= $fOnly === 'alerts' ? 'on' : '' ?>">Alertes</a>
      </div>
    </div>

    <form method="get" style="display:flex;gap:10px;flex-wrap:wrap;align-items:flex-end;">
      <input type="hidden" name="period" value="<?= h($fPeriod === 'custom' ? '' : $fPeriod) ?>">
      <input type="hidden" name="only" value="<?= h($fOnly) ?>">
      <div class="tu-form-field" style="min-width:150px;">
        <span class="tu-lbl">Personne</span>
        <select name="actor" class="tu-input">
          <option value="">Toutes</option>
          <?php foreach ($actors as $a): ?>
            <option value="<?= h($a['actor_name']) ?>" <?= $fActor === $a['actor_name'] ? 'selected' : '' ?>><?= h($a['actor_name']) ?> (<?= (int)$a['n'] ?>)</option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="tu-form-field" style="min-width:150px;">
        <span class="tu-lbl">Action</span>
        <select name="action" class="tu-input">
          <option value="">Toutes</option>
          <?php foreach ($actions as $a): ?>
            <option value="<?= h($a) ?>" <?= $fAction === $a ? 'selected' : '' ?>><?= h(audit_action_label($a)) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="tu-form-field" style="min-width:150px;">
        <span class="tu-lbl">Sur quoi</span>
        <select name="entity_type" class="tu-input">
          <option value="">Tout</option>
          <?php foreach ($entities as $e): ?>
            <option value="<?= h($e) ?>" <?= $fEntity === $e ? 'selected' : '' ?>><?= h(audit_entity_label($e)) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="tu-form-field" style="min-width:130px;">
        <span class="tu-lbl">Du</span>
        <input type="date" name="from" class="tu-input" value="<?= h($fFrom) ?>">
      </div>
      <div class="tu-form-field" style="min-width:130px;">
        <span class="tu-lbl">Au</span>
        <input type="date" name="to" class="tu-input" value="<?= h($fTo) ?>">
      </div>
      <div class="tu-form-field" style="flex:1;min-width:200px;">
        <span class="tu-lbl">Recherche</span>
        <input type="text" name="q" class="tu-input" placeholder="Nom, élément, valeur modifiée…" value="<?= h($fSearch) ?>">
      </div>
      <?php if ($fModule !== ''): ?><input type="hidden" name="module" value="<?= h($fModule) ?>"><?php endif; ?>
      <?php if ($fEntId > 0): ?><input type="hidden" name="entity_id" value="<?= (int)$fEntId ?>"><?php endif; ?>
      <button type="submit" class="tu-btn tu-btn-p">Filtrer</button>
      <?php if ($hasFilter): ?>
        <a href="<?= h(suite_base()) ?>/admin/audit_log.php" class="tu-btn tu-btn-s">Réinitialiser</a>
      <?php endif; ?>
    </form>

    <?php if ($fEntId > 0): ?>
      <div style="margin-top:12px;font-size:12.5px;color:var(--tu-ink-400);">
        Historique de <b><?= h($entityName !== '' ? $entityName : audit_entity_label($fEntity ?: 'élément') . ' n° ' . (int)$fEntId) ?></b><?= $entityName !== '' ? ' (' . h(audit_entity_label($fEntity)) . ' n° ' . (int)$fEntId . ')' : '' ?> —
        <a href="<?= h(buildFilterUrl(['entity_id' => '', 'entity_type' => ''])) ?>">retirer ce filtre</a>
      </div>
    <?php endif; ?>
  </div>

  <!-- Répartition par module -->
  <?php if ($byModule): ?>
    <div class="au-mods">
      <a class="au-mod <?= $fModule === '' ? 'on' : '' ?>" href="<?= h(buildFilterUrl(['module' => ''])) ?>">Tous les modules</a>
      <?php foreach ($byModule as $bm): ?>
        <a class="au-mod <?= $fModule === $bm['module'] ? 'on' : '' ?>" href="<?= h(buildFilterUrl(['module' => $bm['module']])) ?>">
          <?= h(audit_module_label((string)$bm['module'])) ?> · <?= (int)$bm['n'] ?>
        </a>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>

  <!-- Liste -->
  <div class="tu-card" style="padding:6px 18px;">
    <?php if (empty($logs)): ?>
      <div style="text-align:center;color:var(--tu-ink-300);padding:28px;font-size:13px;">Aucune entrée ne correspond à ces filtres.</div>
    <?php else: ?>
      <?php foreach ($logs as $log):
        $kind    = audit_action_kind((string)$log['action']);
        $details = $log['details_json'] ? json_decode((string)$log['details_json'], true) : null;
        $details = is_array($details) ? $details : [];
        $changes = (isset($details['changes']) && is_array($details['changes'])) ? $details['changes'] : [];
        $page_   = $details['_page'] ?? null;
        unset($details['changes'], $details['_page']);
        $ts = strtotime((string)$log['created_at']);
        $entType = (string)($log['entity_type'] ?? '');
        $entId   = (int)($log['entity_id'] ?? 0);
      ?>
        <div class="au-row">
          <div class="au-ico <?= h($kind) ?>"><?= auditIcon($kind) ?></div>
          <div style="flex:1;min-width:0;">
            <div class="au-head">
              <a class="au-actor" style="color:inherit;text-decoration:none;" href="<?= h(buildFilterUrl(['actor' => $log['actor_name']])) ?>"><?= h($log['actor_name']) ?></a>
              <span class="au-role"><?= h(auditRoleLabel($log['actor_role'])) ?></span>
              <span class="tu-bdg <?= auditBadgeModule((string)$log['module']) ?>" style="font-size:10px;"><?= h(audit_module_label((string)$log['module'])) ?></span>
              <span style="font-size:12.5px;font-weight:600;color:<?= $kind === 'delete' || $kind === 'alert' ? 'var(--tu-red-main)' : 'var(--tu-ink-500)' ?>;"><?= h(audit_action_label((string)$log['action'])) ?></span>
              <?php if ($entType !== ''): ?>
                <span style="font-size:12px;color:var(--tu-ink-300);">· <?= h(audit_entity_label($entType)) ?><?= $entId > 0 ? ' #' . $entId : '' ?></span>
              <?php endif; ?>
            </div>

            <?php if ($log['entity_label']): ?>
              <div class="au-label">
                <?php if ($entType !== '' && $entId > 0): ?>
                  <?php $open = auditEntityUrl($entType, $entId, (string)$log['action']); ?>
                  <?php if ($open): ?><a href="<?= h($open) ?>" title="Ouvrir la fiche"><?= h($log['entity_label']) ?></a><?php else: ?><?= h($log['entity_label']) ?><?php endif; ?>
                  <a href="<?= h(buildFilterUrl(['entity_type' => $entType, 'entity_id' => $entId, 'period' => 'all'])) ?>" style="font-size:11.5px;color:var(--tu-ink-300);margin-left:6px;" title="Voir tout l'historique de cet élément">historique</a>
                <?php else: ?>
                  <?= h($log['entity_label']) ?>
                <?php endif; ?>
              </div>
            <?php endif; ?>

            <?php if ($changes): ?>
              <div class="au-diff">
                <?php foreach ($changes as $field => $c): ?>
                  <div class="au-diff-row">
                    <div class="au-diff-k"><?= h(audit_field_label((string)$field)) ?></div>
                    <div>
                      <?php if (!empty($c['redacted'])): ?>
                        <span class="au-masked">modifié (valeur non conservée — donnée personnelle)</span>
                      <?php else: ?>
                        <span class="au-from"><?= h(auditShowVal($c['from'] ?? null)) ?></span><span class="au-arrow">→</span><span class="au-to"><?= h(auditShowVal($c['to'] ?? null)) ?></span>
                      <?php endif; ?>
                    </div>
                  </div>
                <?php endforeach; ?>
              </div>
            <?php endif; ?>

            <?php if ($details): ?>
              <div class="au-ctx">
                <?php foreach ($details as $dk => $dv): if ($dv === null || $dv === '') continue; ?>
                  <span class="au-chip"><?= h(audit_field_label((string)$dk)) ?> : <b><?= h(audit_format_value($dv)) ?></b></span>
                <?php endforeach; ?>
              </div>
            <?php endif; ?>

            <?php if ($log['ip_address'] || $page_): ?>
              <details class="au-more">
                <summary>Contexte technique</summary>
                <?= $log['ip_address'] ? 'IP ' . h($log['ip_address']) : '' ?>
                <?= $page_ ? ' · page ' . h((string)$page_) : '' ?>
                · entrée #<?= (int)$log['id'] ?>
              </details>
            <?php endif; ?>
          </div>
          <div class="au-time">
            <?= h(date('d/m/Y', $ts)) ?><br><?= h(date('H:i:s', $ts)) ?>
          </div>
        </div>
      <?php endforeach; ?>
    <?php endif; ?>
  </div>

  <!-- Pagination -->
  <?php if ($totalPages > 1): ?>
    <div style="display:flex;justify-content:center;gap:6px;margin-top:18px;flex-wrap:wrap;">
      <?php if ($page > 1): ?>
        <a href="<?= h(buildFilterUrl(['page' => $page - 1], true)) ?>" class="tu-btn tu-btn-s tu-btn-sm">← Précédent</a>
      <?php endif; ?>
      <span style="display:flex;align-items:center;padding:0 12px;font-size:12.5px;color:var(--tu-ink-400);">Page <?= $page ?> / <?= $totalPages ?></span>
      <?php if ($page < $totalPages): ?>
        <a href="<?= h(buildFilterUrl(['page' => $page + 1], true)) ?>" class="tu-btn tu-btn-s tu-btn-sm">Suivant →</a>
      <?php endif; ?>
    </div>
  <?php endif; ?>

</div>

</div><!-- /tu-main -->
</body>
</html>
