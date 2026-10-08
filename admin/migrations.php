<?php
// admin/migrations.php — Suivi et exécution des migrations SQL de tous les modules
// Réservé au rôle super_admin (voir shared/bootstrap.php).

declare(strict_types=1);

require_once __DIR__ . '/../shared/bootstrap.php';

if (!defined('APP_BASE')) {
    define('APP_BASE', suite_base() . '/admin');
}

if (!is_super_admin()) {
    suite_forbidden("Les migrations SQL sont réservées aux super administrateurs.", "Accès refusé", "users", "Super admin");
}

$pdo = _bootstrap_get_pdo();
if (!($pdo instanceof PDO)) {
    http_response_code(500);
    echo "Connexion base de données indisponible.";
    exit;
}

function h(string $s): string { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}
if (empty($_SESSION['mig_csrf'])) {
    $_SESSION['mig_csrf'] = bin2hex(random_bytes(24));
}
$csrf = (string)$_SESSION['mig_csrf'];

// ── Table de suivi ───────────────────────────────────────────────────────────
function mig_log_table_exists(PDO $pdo): bool {
    try {
        return (bool)$pdo->query("SHOW TABLES LIKE 'suite_migrations_log'")->fetchColumn();
    } catch (Throwable) {
        return false;
    }
}

/** Ajoute (de façon idempotente) les colonnes de diagnostic à une table de suivi créée par l'ancienne version. */
function mig_upgrade_log_table(PDO $pdo): void {
    $cols = $pdo->query("SHOW COLUMNS FROM suite_migrations_log")->fetchAll(PDO::FETCH_COLUMN);
    if (!in_array('status', $cols, true)) {
        $pdo->exec("ALTER TABLE suite_migrations_log ADD COLUMN status VARCHAR(10) NOT NULL DEFAULT 'success' AFTER checksum");
    }
    if (!in_array('duration_ms', $cols, true)) {
        $pdo->exec("ALTER TABLE suite_migrations_log ADD COLUMN duration_ms INT NULL AFTER status");
    }
    if (!in_array('error', $cols, true)) {
        $pdo->exec("ALTER TABLE suite_migrations_log ADD COLUMN error TEXT NULL AFTER duration_ms");
    }
}

/** Exécute un fichier SQL (plusieurs instructions possibles) et lève l'erreur de n'importe laquelle. */
function mig_run_sql(PDO $pdo, string $sql): void {
    $stmt = $pdo->query($sql);
    if ($stmt instanceof PDOStatement) {
        do {
            // Avancer dans les jeux de résultats : c'est ce qui remonte les erreurs des instructions suivantes.
        } while ($stmt->nextRowset());
        $stmt->closeCursor();
    }
}

$hasLogTable = mig_log_table_exists($pdo);
$flash = null; // ['type' => 'success'|'error'|'warn', 'msg' => string, 'lines' => string[]]

if ($_SERVER['REQUEST_METHOD'] === 'POST'
    && !hash_equals($csrf, (string)($_POST['csrf'] ?? ''))) {
    http_response_code(403);
    $_POST = [];
    $flash = ['type' => 'error', 'msg' => 'Session expirée ou formulaire invalide — rechargez la page et recommencez.'];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'bootstrap_log') {
    try {
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS suite_migrations_log (
                id INT AUTO_INCREMENT PRIMARY KEY,
                module VARCHAR(60) NOT NULL,
                filename VARCHAR(255) NOT NULL,
                checksum VARCHAR(64) NULL,
                status VARCHAR(10) NOT NULL DEFAULT 'success',
                duration_ms INT NULL,
                error TEXT NULL,
                applied_by VARCHAR(150) NULL,
                applied_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                UNIQUE KEY uniq_module_file (module, filename)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");
        $hasLogTable = true;
        $flash = ['type' => 'success', 'msg' => 'Table de suivi des migrations créée.'];
    } catch (Throwable $e) {
        $flash = ['type' => 'error', 'msg' => 'Erreur à la création de la table de suivi : ' . $e->getMessage()];
    }
}

if ($hasLogTable) {
    try {
        mig_upgrade_log_table($pdo);
    } catch (Throwable $e) {
        $flash = ['type' => 'error', 'msg' => 'Impossible de mettre à niveau la table de suivi : ' . $e->getMessage()];
    }
}

// ── Fichiers de migration : tous les <module>/migrations/*.sql ────────────────
$suiteRoot = realpath(__DIR__ . '/..') ?: dirname(__DIR__);
$moduleLabels = [
    'admin'       => 'Administration',
    'adhesions'   => 'Adhésions',
    'caisse'      => 'Caisse',
    'documents'   => 'Documents',
    'donations'   => 'Dons',
    'logistique'  => 'Logistique',
    'planning'    => 'Planning',
    'prospection' => 'Prospection',
    'subventions' => 'Subventions',
];

$byModule = []; // module => [filename => absolute path]
foreach (glob($suiteRoot . '/*/migrations', GLOB_ONLYDIR) ?: [] as $dir) {
    $mod = basename(dirname($dir));
    if (!preg_match('/^[a-z0-9_-]+$/', $mod) || in_array($mod, ['vendor', 'node_modules', 'secrets'], true)) continue;
    $files = glob($dir . '/*.sql') ?: [];
    sort($files, SORT_STRING);
    foreach ($files as $f) $byModule[$mod][basename($f)] = $f;
}
ksort($byModule);

// ── Statut appliqué ────────────────────────────────────────────────────────
function mig_load_log(PDO $pdo, bool $hasLogTable): array {
    $out = [];
    if (!$hasLogTable) return $out;
    try {
        foreach ($pdo->query("SELECT * FROM suite_migrations_log")->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $out[$r['module'] . '/' . $r['filename']] = $r;
        }
    } catch (Throwable) {}
    return $out;
}

/** État d'un fichier : pending | applied | modified | failed */
function mig_state(?array $row, string $path): string {
    if (!$row || ($row['status'] ?? 'success') !== 'success') {
        return $row && ($row['status'] ?? '') === 'failed' ? 'failed' : 'pending';
    }
    $cs = $row['checksum'] ?? null;
    if ($cs !== null && $cs !== '' && $cs !== sha1((string)file_get_contents($path))) return 'modified';
    return 'applied';
}

/**
 * Exécute une migration, journalise le résultat (table de suivi + audit).
 * @return array{ok:bool,ms:int,error:?string}
 */
function mig_execute(PDO $pdo, string $mod, string $file, string $path, string $who): array {
    $sql = (string)file_get_contents($path);
    $checksum = sha1($sql);
    $t0 = microtime(true);
    $error = null;
    try {
        mig_run_sql($pdo, $sql);
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
    $ms = (int)round((microtime(true) - $t0) * 1000);

    if ($error === null) {
        $pdo->prepare("
            INSERT INTO suite_migrations_log (module, filename, checksum, status, duration_ms, error, applied_by, applied_at)
            VALUES (?, ?, ?, 'success', ?, NULL, ?, NOW())
            ON DUPLICATE KEY UPDATE checksum = VALUES(checksum), status = 'success', duration_ms = VALUES(duration_ms),
                error = NULL, applied_by = VALUES(applied_by), applied_at = NOW()
        ")->execute([$mod, $file, $checksum, $ms, $who]);
        audit_log('admin', 'migration_apply', 'migration', null, $mod . '/' . $file, ['duree_ms' => $ms, 'checksum' => $checksum]);
    } else {
        // Un échec ne doit pas écraser le statut d'une migration déjà appliquée avec succès.
        $pdo->prepare("
            INSERT INTO suite_migrations_log (module, filename, checksum, status, duration_ms, error, applied_by, applied_at)
            VALUES (?, ?, ?, 'failed', ?, ?, ?, NOW())
            ON DUPLICATE KEY UPDATE error = VALUES(error), duration_ms = VALUES(duration_ms),
                status = IF(status = 'success', status, 'failed')
        ")->execute([$mod, $file, $checksum, $ms, mb_substr($error, 0, 2000), $who]);
        audit_log('admin', 'migration_fail', 'migration', null, $mod . '/' . $file, ['duree_ms' => $ms, 'erreur' => mb_substr($error, 0, 500)]);
    }
    return ['ok' => $error === null, 'ms' => $ms, 'error' => $error];
}

$applied = mig_load_log($pdo, $hasLogTable);
$who = function_exists('current_volunteer_name') ? (string)current_volunteer_name() : 'Inconnu';

// ── Actions ───────────────────────────────────────────────────────────────────
$action = (string)($_POST['action'] ?? '');
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $hasLogTable && in_array($action, ['apply', 'mark_applied', 'apply_all'], true)) {

    $lockName = 'suite_migrations';
    $gotLock = (bool)$pdo->query("SELECT GET_LOCK('" . $lockName . "', 0)")->fetchColumn();

    if (!$gotLock) {
        $flash = ['type' => 'error', 'msg' => 'Une autre exécution de migrations est déjà en cours. Réessayez dans un instant.'];
    } else {
        try {
            if ($action === 'apply_all') {
                $done = [];
                $stopped = null;
                $skippedAfter = [];
                foreach ($byModule as $mod => $files) {
                    foreach ($files as $file => $path) {
                        $state = mig_state($applied[$mod . '/' . $file] ?? null, $path);
                        if ($state !== 'pending' && $state !== 'failed') continue;
                        if ($stopped !== null) { $skippedAfter[] = $mod . '/' . $file; continue; }
                        $r = mig_execute($pdo, $mod, $file, $path, $who);
                        if ($r['ok']) {
                            $done[] = $mod . '/' . $file . ' (' . $r['ms'] . ' ms)';
                        } else {
                            $stopped = ['key' => $mod . '/' . $file, 'error' => (string)$r['error']];
                        }
                    }
                }
                if ($stopped) {
                    $flash = ['type' => 'error', 'msg' => 'Arrêt sur ' . $stopped['key'] . ' : ' . $stopped['error']
                        . ' — ' . count($done) . ' migration(s) appliquée(s) avant l\'erreur, ' . count($skippedAfter) . ' non exécutée(s).',
                        'lines' => $done];
                } elseif ($done) {
                    $flash = ['type' => 'success', 'msg' => count($done) . ' migration(s) appliquée(s).', 'lines' => $done];
                } else {
                    $flash = ['type' => 'warn', 'msg' => 'Aucune migration en attente.'];
                }
            } else {
                $mod  = trim((string)($_POST['module'] ?? ''));
                $file = basename((string)($_POST['file'] ?? ''));
                $path = $byModule[$mod][$file] ?? null;

                if ($path === null) {
                    $flash = ['type' => 'error', 'msg' => 'Fichier de migration introuvable.'];
                } else {
                    $state = mig_state($applied[$mod . '/' . $file] ?? null, $path);
                    if ($action === 'apply') {
                        if ($state === 'applied') {
                            $flash = ['type' => 'warn', 'msg' => $mod . '/' . $file . ' est déjà appliquée.'];
                        } elseif ($state === 'modified' && ($_POST['confirm_modified'] ?? '') !== '1') {
                            $flash = ['type' => 'error', 'msg' => 'Ce fichier a été modifié depuis son application : confirmation explicite requise.'];
                        } else {
                            $r = mig_execute($pdo, $mod, $file, $path, $who);
                            $flash = $r['ok']
                                ? ['type' => 'success', 'msg' => 'Migration appliquée : ' . $mod . '/' . $file . ' (' . $r['ms'] . ' ms)']
                                : ['type' => 'error', 'msg' => 'Échec sur ' . $mod . '/' . $file . ' : ' . $r['error']];
                        }
                    } else { // mark_applied
                        $checksum = sha1((string)file_get_contents($path));
                        $pdo->prepare("
                            INSERT INTO suite_migrations_log (module, filename, checksum, status, duration_ms, error, applied_by, applied_at)
                            VALUES (?, ?, ?, 'success', NULL, NULL, ?, NOW())
                            ON DUPLICATE KEY UPDATE checksum = VALUES(checksum), status = 'success', duration_ms = NULL,
                                error = NULL, applied_by = VALUES(applied_by), applied_at = NOW()
                        ")->execute([$mod, $file, $checksum, $who]);
                        audit_log('admin', 'migration_mark', 'migration', null, $mod . '/' . $file, ['checksum' => $checksum]);
                        $flash = ['type' => 'success', 'msg' => 'Marquée comme déjà appliquée (sans exécution) : ' . $mod . '/' . $file];
                    }
                }
            }
        } catch (Throwable $e) {
            $flash = ['type' => 'error', 'msg' => 'Erreur inattendue : ' . $e->getMessage()];
        } finally {
            $pdo->query("SELECT RELEASE_LOCK('" . $lockName . "')");
        }
    }
    $applied = mig_load_log($pdo, $hasLogTable);
}

// ── Synthèse ──────────────────────────────────────────────────────────────────
$rowsView = []; // module/file => state
$counts = ['pending' => 0, 'applied' => 0, 'modified' => 0, 'failed' => 0];
$pendingList = [];
foreach ($byModule as $mod => $files) {
    foreach ($files as $file => $path) {
        $key = $mod . '/' . $file;
        $st = mig_state($applied[$key] ?? null, $path);
        $rowsView[$key] = $st;
        $counts[$st]++;
        if ($st === 'pending' || $st === 'failed') $pendingList[] = $key;
    }
}
$missing = []; // appliquées en base mais fichier absent du disque
foreach ($applied as $key => $row) {
    if (($row['status'] ?? 'success') === 'success' && !isset($rowsView[$key])) $missing[] = $row;
}
$totalFiles = count($rowsView);

$fModule = (string)($_GET['module'] ?? '');
$fStatus = (string)($_GET['status'] ?? '');
if (!isset($byModule[$fModule])) $fModule = '';
if (!in_array($fStatus, ['pending', 'applied', 'modified', 'failed'], true)) $fStatus = '';

$stateLabels = [
    'pending'  => ['En attente', 'tu-bdg-amber'],
    'applied'  => ['Appliquée', 'tu-bdg-teal'],
    'modified' => ['Modifiée depuis', 'tu-bdg-amber'],
    'failed'   => ['En échec', 'tu-bdg-red'],
];

$history = [];
if ($hasLogTable) {
    $history = array_values($applied);
    usort($history, fn($a, $b) => strcmp((string)$b['applied_at'], (string)$a['applied_at']));
    $history = array_slice($history, 0, 15);
}

$pageTitle = 'Migrations SQL — Touraine-Ukraine';
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
      .mig-mod-card { margin-bottom: 18px; }
      .mig-row { display:flex; align-items:center; gap:12px; padding:12px 0; border-bottom:1px solid var(--tu-ink-50); flex-wrap:wrap; }
      .mig-row:last-child { border-bottom: none; }
      .mig-file { font-family: ui-monospace, SFMono-Regular, Menlo, monospace; font-size: 12.5px; font-weight: 700; }
      .mig-meta { font-size: 11.5px; color: var(--tu-ink-300); margin-top: 2px; }
      .mig-err { font-size: 12px; color: var(--tu-red-main); background: var(--tu-red-soft); border-radius: 8px; padding: 6px 9px; margin-top: 6px; word-break: break-word; }
      .mig-sql-pre { background: var(--tu-sand-50); border:1px solid var(--tu-ink-100); border-radius:10px; padding:10px 12px; font-size:11.5px; font-family: ui-monospace, SFMono-Regular, Menlo, monospace; white-space:pre-wrap; word-break:break-word; max-height:260px; overflow:auto; margin-top:8px; display:none; }
      .mig-actions { margin-left:auto; display:flex; gap:6px; flex-wrap:wrap; align-items:center; }
      .mig-box { border-radius:14px; padding:12px 16px; margin-bottom:16px; font-size:13px; border:1.5px solid; }
      .mig-box.success { background:var(--tu-green-soft); border-color:rgba(42,125,74,.25); color:var(--tu-green-main); }
      .mig-box.error   { background:var(--tu-red-soft);   border-color:rgba(192,67,42,.25); color:var(--tu-red-main); }
      .mig-box.warn    { background:var(--tu-amber-50, #fff7e6); border-color:rgba(200,140,20,.3); color:var(--tu-amber-600); }
      .mig-box ul { margin:6px 0 0 18px; padding:0; font-family: ui-monospace, monospace; font-size:12px; }
      .mig-filters { display:flex; gap:8px; flex-wrap:wrap; margin-bottom:16px; align-items:center; }
      .mig-filters a { font-size:12.5px; padding:5px 11px; border-radius:999px; border:1px solid var(--tu-ink-100); text-decoration:none; color:var(--tu-ink-500, inherit); }
      .mig-filters a.on { background:var(--tu-ink-900, #222); color:#fff; border-color:transparent; }
      .mig-hist { width:100%; border-collapse:collapse; font-size:12.5px; }
      .mig-hist th { text-align:left; font-size:11px; text-transform:uppercase; letter-spacing:.04em; color:var(--tu-ink-300); padding:6px 8px; }
      .mig-hist td { padding:8px; border-top:1px solid var(--tu-ink-50); vertical-align:top; }
    </style>
</head>
<body class="tu-v2">

<?php
require_once dirname(__DIR__) . '/shared/suite_nav.php';
suite_nav_render('migrations', '');

function mig_url(array $o = []): string {
    $p = array_filter(array_merge($_GET, $o), fn($v) => $v !== '' && $v !== null);
    return '?' . http_build_query($p);
}
?>
<div class="tu-main">

<div class="tu-topbar">
  <div class="tu-bc">
    <a href="<?= h(suite_base()) ?>/index.php" style="color:inherit;text-decoration:none;">Accueil</a>
    <span class="tu-bc-sep">›</span>
    <span class="tu-bc-cur">Migrations SQL</span>
  </div>
</div>

<div class="tu-pg">

  <div class="tu-ph">
    <div>
      <div class="tu-ph-title">Migrations SQL</div>
      <div class="tu-ph-sub">Suivi et exécution des fichiers <code>migrations/*.sql</code> de chaque module — réservé aux super administrateurs</div>
    </div>
  </div>

  <?php if ($flash): ?>
    <div class="mig-box <?= h($flash['type']) ?>">
      <?= h($flash['msg']) ?>
      <?php if (!empty($flash['lines'])): ?>
        <ul><?php foreach ($flash['lines'] as $l): ?><li><?= h($l) ?></li><?php endforeach; ?></ul>
      <?php endif; ?>
    </div>
  <?php endif; ?>

  <?php if (!$hasLogTable): ?>
    <div class="tu-card" style="padding:22px;">
      <div style="font-weight:800;margin-bottom:6px;">Table de suivi absente</div>
      <div style="font-size:13px;color:var(--tu-ink-300);margin-bottom:14px;">
        La table <code>suite_migrations_log</code> n'existe pas encore sur cette base — elle est nécessaire pour savoir quelles migrations ont déjà été appliquées. Cette action ne touche à rien d'autre.
      </div>
      <form method="post">
        <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
        <input type="hidden" name="action" value="bootstrap_log">
        <button type="submit" class="tu-btn tu-btn-p">Créer la table de suivi</button>
      </form>
    </div>
  <?php else: ?>

    <div class="tu-kpi-grid" style="grid-template-columns:repeat(4,1fr);margin-bottom:20px;">
      <div class="tu-kpi"><div class="tu-kpi-val"><?= $totalFiles ?></div><div class="tu-kpi-lbl">Fichiers détectés</div></div>
      <div class="tu-kpi <?= $counts['pending'] ? 'amber' : 'green' ?>"><div class="tu-kpi-val"><?= $counts['pending'] + $counts['failed'] ?></div><div class="tu-kpi-lbl">À appliquer</div></div>
      <div class="tu-kpi <?= $counts['failed'] ? 'red' : '' ?>"><div class="tu-kpi-val"><?= $counts['failed'] ?></div><div class="tu-kpi-lbl">En échec</div></div>
      <div class="tu-kpi <?= ($counts['modified'] || $missing) ? 'amber' : '' ?>"><div class="tu-kpi-val"><?= $counts['modified'] + count($missing) ?></div><div class="tu-kpi-lbl">Anomalies (modifiées / fichier absent)</div></div>
    </div>

    <?php if ($pendingList): ?>
      <div class="tu-card" style="padding:18px;margin-bottom:18px;border:1.5px solid rgba(200,140,20,.35);">
        <div style="font-weight:800;font-size:14px;margin-bottom:6px;"><?= count($pendingList) ?> migration(s) à appliquer, dans cet ordre :</div>
        <ol style="margin:0 0 12px 18px;padding:0;font-family:ui-monospace,monospace;font-size:12.5px;">
          <?php foreach ($pendingList as $k): ?><li><?= h($k) ?><?= ($rowsView[$k] === 'failed') ? ' <span style="color:var(--tu-red-main);">(nouvelle tentative)</span>' : '' ?></li><?php endforeach; ?>
        </ol>
        <div style="font-size:12.5px;color:var(--tu-ink-300);margin-bottom:12px;">
          Les migrations sont exécutées l'une après l'autre ; la première erreur arrête tout le lot. Les fichiers « modifiés depuis » ne sont jamais rejoués dans ce lot.
          <strong>Faites une sauvegarde de la base avant d'appliquer</strong> : une migration modifie réellement la structure ou les données et n'est pas annulable.
        </div>
        <form method="post" onsubmit="return confirm('Appliquer <?= count($pendingList) ?> migration(s) sur cette base ?\n\nAvez-vous une sauvegarde récente ?');">
          <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
          <input type="hidden" name="action" value="apply_all">
          <button type="submit" class="tu-btn tu-btn-p">Appliquer toutes les migrations en attente</button>
        </form>
      </div>
    <?php endif; ?>

    <?php if ($missing): ?>
      <div class="mig-box warn">
        <strong>Fichier absent du disque</strong> alors que la base les marque comme appliquées :
        <ul><?php foreach ($missing as $m): ?><li><?= h($m['module'] . '/' . $m['filename']) ?></li><?php endforeach; ?></ul>
      </div>
    <?php endif; ?>

    <div class="mig-filters">
      <span style="font-size:12px;color:var(--tu-ink-300);">Module :</span>
      <a href="<?= h(mig_url(['module' => ''])) ?>" class="<?= $fModule === '' ? 'on' : '' ?>">Tous</a>
      <?php foreach (array_keys($byModule) as $m): ?>
        <a href="<?= h(mig_url(['module' => $m])) ?>" class="<?= $fModule === $m ? 'on' : '' ?>"><?= h($moduleLabels[$m] ?? ucfirst($m)) ?></a>
      <?php endforeach; ?>
      <span style="font-size:12px;color:var(--tu-ink-300);margin-left:10px;">Statut :</span>
      <a href="<?= h(mig_url(['status' => ''])) ?>" class="<?= $fStatus === '' ? 'on' : '' ?>">Tous</a>
      <?php foreach ($stateLabels as $k => [$lbl]): ?>
        <a href="<?= h(mig_url(['status' => $k])) ?>" class="<?= $fStatus === $k ? 'on' : '' ?>"><?= h($lbl) ?> (<?= $counts[$k] ?>)</a>
      <?php endforeach; ?>
    </div>

    <?php if (empty($byModule)): ?>
      <div class="tu-card" style="padding:24px;text-align:center;color:var(--tu-ink-300);">
        Aucun dossier <code>migrations/</code> trouvé dans les modules.
      </div>
    <?php endif; ?>

    <?php $shown = 0; foreach ($byModule as $mod => $files):
        if ($fModule !== '' && $fModule !== $mod) continue;
        $visible = [];
        foreach ($files as $file => $path) {
            if ($fStatus === '' || $rowsView[$mod . '/' . $file] === $fStatus) $visible[$file] = $path;
        }
        if (!$visible) continue;
    ?>
      <div class="tu-card mig-mod-card" style="padding:18px;">
        <div style="font-weight:800;font-size:14px;margin-bottom:8px;"><?= h($moduleLabels[$mod] ?? ucfirst($mod)) ?></div>

        <?php foreach ($visible as $file => $path):
          $shown++;
          $key   = $mod . '/' . $file;
          $row   = $applied[$key] ?? null;
          $state = $rowsView[$key];
          $domId = 'sql-' . preg_replace('/[^a-zA-Z0-9_]/', '-', $key);
        ?>
          <div class="mig-row">
            <div style="min-width:0;flex:1;">
              <div class="mig-file"><?= h($file) ?> <span class="tu-bdg <?= h($stateLabels[$state][1]) ?>"><?= h($stateLabels[$state][0]) ?></span></div>
              <?php if ($row && ($row['status'] ?? 'success') === 'success'): ?>
                <div class="mig-meta">
                  Appliquée le <?= h(date('d/m/Y H:i', strtotime((string)$row['applied_at']))) ?>
                  <?= !empty($row['applied_by']) ? ' par ' . h((string)$row['applied_by']) : '' ?>
                  <?= isset($row['duration_ms']) && $row['duration_ms'] !== null ? ' · ' . (int)$row['duration_ms'] . ' ms' : ' · marquée sans exécution' ?>
                </div>
              <?php elseif ($row): ?>
                <div class="mig-meta">Dernière tentative le <?= h(date('d/m/Y H:i', strtotime((string)$row['applied_at']))) ?><?= !empty($row['applied_by']) ? ' par ' . h((string)$row['applied_by']) : '' ?></div>
              <?php endif; ?>
              <?php if ($state === 'modified'): ?>
                <div class="mig-meta" style="color:var(--tu-amber-600);">Le contenu du fichier a changé depuis son application : la base peut ne plus correspondre.</div>
              <?php endif; ?>
              <?php if ($row && !empty($row['error'])): ?>
                <div class="mig-err"><?= h((string)$row['error']) ?></div>
              <?php endif; ?>
              <button type="button" class="tu-btn-link" style="margin-top:4px;" onclick="var e=document.getElementById('<?= h($domId) ?>');e.style.display=e.style.display==='block'?'none':'block';">Voir le SQL</button>
              <pre class="mig-sql-pre" id="<?= h($domId) ?>"><?= h((string)file_get_contents($path)) ?></pre>
            </div>

            <div class="mig-actions">
              <?php if ($state !== 'applied'): ?>
                <form method="post" onsubmit="return confirm(<?= h(json_encode(
                    ($state === 'modified'
                        ? "ATTENTION : " . $file . " a été modifié depuis son application. Le rejouer peut échouer ou dupliquer des changements. Continuer ?"
                        : "Exécuter " . $file . " sur cette base ?\nCette action modifie réellement la structure/les données."),
                    JSON_UNESCAPED_UNICODE)) ?>);">
                  <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
                  <input type="hidden" name="action" value="apply">
                  <input type="hidden" name="module" value="<?= h($mod) ?>">
                  <input type="hidden" name="file" value="<?= h($file) ?>">
                  <?php if ($state === 'modified'): ?><input type="hidden" name="confirm_modified" value="1"><?php endif; ?>
                  <button type="submit" class="tu-btn tu-btn-p tu-btn-xs"><?= $state === 'failed' ? 'Réessayer' : ($state === 'modified' ? 'Rejouer' : 'Appliquer') ?></button>
                </form>
                <form method="post" onsubmit="return confirm(<?= h(json_encode("Marquer " . $file . " comme déjà appliquée SANS l'exécuter ?", JSON_UNESCAPED_UNICODE)) ?>);">
                  <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
                  <input type="hidden" name="action" value="mark_applied">
                  <input type="hidden" name="module" value="<?= h($mod) ?>">
                  <input type="hidden" name="file" value="<?= h($file) ?>">
                  <button type="submit" class="tu-btn tu-btn-s tu-btn-xs">Marquer comme appliquée</button>
                </form>
              <?php endif; ?>
            </div>
          </div>
        <?php endforeach; ?>
      </div>
    <?php endforeach; ?>

    <?php if ($byModule && $shown === 0): ?>
      <div class="tu-card" style="padding:24px;text-align:center;color:var(--tu-ink-300);">Aucune migration ne correspond à ces filtres.</div>
    <?php endif; ?>

    <?php if ($history): ?>
      <div class="tu-card" style="padding:18px;">
        <div style="font-weight:800;font-size:14px;margin-bottom:8px;">Historique récent</div>
        <div style="overflow-x:auto;">
          <table class="mig-hist">
            <thead><tr><th>Date</th><th>Migration</th><th>Résultat</th><th>Durée</th><th>Par</th></tr></thead>
            <tbody>
              <?php foreach ($history as $r): $ok = ($r['status'] ?? 'success') === 'success'; ?>
                <tr>
                  <td style="white-space:nowrap;"><?= h(date('d/m/Y H:i', strtotime((string)$r['applied_at']))) ?></td>
                  <td style="font-family:ui-monospace,monospace;"><?= h($r['module'] . '/' . $r['filename']) ?></td>
                  <td><?= $ok ? 'Succès' : '<span style="color:var(--tu-red-main);font-weight:700;">Échec</span>' ?></td>
                  <td><?= isset($r['duration_ms']) && $r['duration_ms'] !== null ? (int)$r['duration_ms'] . ' ms' : '—' ?></td>
                  <td><?= h((string)($r['applied_by'] ?? '—')) ?></td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
        <div style="font-size:12px;color:var(--tu-ink-300);margin-top:10px;">
          Chaque exécution, échec ou marquage est aussi enregistré dans le <a href="<?= h(suite_base()) ?>/admin/audit_log.php?module=admin&amp;entity_type=migration">journal d'audit</a>.
        </div>
      </div>
    <?php endif; ?>

  <?php endif; ?>

</div>

</div><!-- /tu-main -->
</body>
</html>
