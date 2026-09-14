<?php
// admin/migrations.php — Suivi et exécution des migrations SQL de tous les modules
// Réservé au rôle super_admin (voir shared/bootstrap.php).

declare(strict_types=1);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../shared/bootstrap.php';

if (!defined('APP_BASE')) {
    define('APP_BASE', suite_base() . '/admin');
}

if (!is_super_admin()) {
    http_response_code(403);
    echo "Accès réservé aux super administrateurs.";
    exit;
}

$pdo = _bootstrap_get_pdo();
if (!($pdo instanceof PDO)) {
    http_response_code(500);
    echo "Connexion base de données indisponible.";
    exit;
}

function h(string $s): string { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }

// ── Table de suivi ───────────────────────────────────────────────────────────
function mig_log_table_exists(PDO $pdo): bool {
    try {
        return (bool)$pdo->query("SHOW TABLES LIKE 'suite_migrations_log'")->fetchColumn();
    } catch (Throwable) {
        return false;
    }
}

$hasLogTable = mig_log_table_exists($pdo);
$flash = null; // ['type' => 'success'|'error', 'msg' => string]

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'bootstrap_log') {
    try {
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS suite_migrations_log (
                id INT AUTO_INCREMENT PRIMARY KEY,
                module VARCHAR(60) NOT NULL,
                filename VARCHAR(255) NOT NULL,
                checksum VARCHAR(64) NULL,
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

// ── Modules scannés (sous-modules + racine) ───────────────────────────────────
$suiteRoot = realpath(__DIR__ . '/..') ?: dirname(__DIR__);
$modules   = ['caisse', 'planning', 'adhesions', 'subventions', 'logistique', 'prospection'];

$byModule = [];
foreach ($modules as $mod) {
    $dir = $suiteRoot . '/' . $mod . '/migrations';
    if (!is_dir($dir)) continue;
    $files = glob($dir . '/*.sql') ?: [];
    sort($files, SORT_STRING);
    if ($files) $byModule[$mod] = $files;
}

// ── Statut appliqué ────────────────────────────────────────────────────────
$applied = []; // "module/filename" => row
if ($hasLogTable) {
    try {
        $rows = $pdo->query("SELECT * FROM suite_migrations_log")->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as $r) {
            $applied[$r['module'] . '/' . $r['filename']] = $r;
        }
    } catch (Throwable) {}
}

// ── Actions : appliquer / marquer comme appliquée ─────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST'
    && in_array($_POST['action'] ?? '', ['apply', 'mark_applied'], true)
    && $hasLogTable
) {
    $mod  = trim((string)($_POST['module'] ?? ''));
    $file = basename((string)($_POST['file'] ?? ''));
    $path = $suiteRoot . '/' . $mod . '/migrations/' . $file;

    if (!in_array($mod, $modules, true) || !is_file($path) || !str_ends_with($file, '.sql')) {
        $flash = ['type' => 'error', 'msg' => 'Fichier de migration introuvable.'];
    } else {
        $sql      = (string)file_get_contents($path);
        $checksum = sha1($sql);
        $who      = function_exists('current_volunteer_name') ? current_volunteer_name() : 'Inconnu';
        $doApply  = $_POST['action'] === 'apply';

        try {
            if ($doApply) {
                $pdo->exec($sql);
            }
            $pdo->prepare("
                INSERT INTO suite_migrations_log (module, filename, checksum, applied_by)
                VALUES (?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE checksum = VALUES(checksum), applied_by = VALUES(applied_by), applied_at = NOW()
            ")->execute([$mod, $file, $checksum, $who]);

            if (function_exists('audit_log')) {
                audit_log('admin', $doApply ? 'migration_apply' : 'migration_mark', 'migration', null, $mod . '/' . $file);
            }

            $applied[$mod . '/' . $file] = [
                'module' => $mod, 'filename' => $file, 'checksum' => $checksum,
                'applied_by' => $who, 'applied_at' => date('Y-m-d H:i:s'),
            ];
            $flash = ['type' => 'success', 'msg' => ($doApply ? 'Migration appliquée : ' : 'Marquée comme déjà appliquée : ') . $file];
        } catch (Throwable $e) {
            $flash = ['type' => 'error', 'msg' => "Erreur sur $mod/$file : " . $e->getMessage()];
        }
    }
}

// Recompte pending après actions
$totalFiles = 0;
$totalPending = 0;
foreach ($byModule as $mod => $files) {
    foreach ($files as $f) {
        $totalFiles++;
        if (!isset($applied[$mod . '/' . basename($f)])) $totalPending++;
    }
}

$moduleLabels = [
    'caisse'      => 'Caisse',
    'planning'    => 'Planning',
    'adhesions'   => 'Adhésions',
    'subventions' => 'Subventions',
    'logistique'  => 'Logistique',
    'prospection' => 'Prospection',
];

$pageTitle = 'Migrations SQL — Touraine-Ukraine';
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
      .mig-mod-card { margin-bottom: 18px; }
      .mig-row { display:flex; align-items:center; gap:12px; padding:12px 0; border-bottom:1px solid var(--tu-ink-50); flex-wrap:wrap; }
      .mig-row:last-child { border-bottom: none; }
      .mig-file { font-family: ui-monospace, SFMono-Regular, Menlo, monospace; font-size: 12.5px; font-weight: 700; }
      .mig-meta { font-size: 11.5px; color: var(--tu-ink-300); }
      .mig-sql-pre { background: var(--tu-sand-50); border:1px solid var(--tu-ink-100); border-radius:10px; padding:10px 12px; font-size:11.5px; font-family: ui-monospace, SFMono-Regular, Menlo, monospace; white-space:pre-wrap; word-break:break-word; max-height:260px; overflow:auto; margin-top:8px; display:none; }
      .mig-actions { margin-left:auto; display:flex; gap:6px; flex-wrap:wrap; }
    </style>
</head>
<body class="tu-v2">

<?php
require_once dirname(__DIR__) . '/shared/suite_nav.php';
suite_nav_render('migrations', '');
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
    <div style="background:<?= $flash['type']==='success' ? 'var(--tu-green-soft)' : 'var(--tu-red-soft)' ?>;border:1.5px solid <?= $flash['type']==='success' ? 'rgba(42,125,74,.25)' : 'rgba(192,67,42,.25)' ?>;border-radius:14px;padding:12px 16px;margin-bottom:16px;color:<?= $flash['type']==='success' ? 'var(--tu-green-main)' : 'var(--tu-red-main)' ?>;font-size:13px;">
      <?= h($flash['msg']) ?>
    </div>
  <?php endif; ?>

  <?php if (!$hasLogTable): ?>
    <div class="tu-card" style="padding:22px;">
      <div style="font-weight:800;margin-bottom:6px;">Table de suivi absente</div>
      <div style="font-size:13px;color:var(--tu-ink-300);margin-bottom:14px;">
        La table <code>suite_migrations_log</code> n'existe pas encore sur cette base — elle est nécessaire pour savoir quelles migrations ont déjà été appliquées. Cette action ne touche à rien d'autre.
      </div>
      <form method="post">
        <input type="hidden" name="action" value="bootstrap_log">
        <button type="submit" class="tu-btn tu-btn-p">Créer la table de suivi</button>
      </form>
    </div>
  <?php else: ?>

    <div class="tu-kpi-grid" style="grid-template-columns:repeat(2,1fr);margin-bottom:20px;max-width:420px;">
      <div class="tu-kpi"><div class="tu-kpi-val"><?= $totalFiles ?></div><div class="tu-kpi-lbl">Fichiers détectés</div></div>
      <div class="tu-kpi <?= $totalPending ? 'amber' : '' ?>"><div class="tu-kpi-val"><?= $totalPending ?></div><div class="tu-kpi-lbl">En attente</div></div>
    </div>

    <?php if (empty($byModule)): ?>
      <div class="tu-card" style="padding:24px;text-align:center;color:var(--tu-ink-300);">
        Aucun dossier <code>migrations/</code> trouvé dans les modules.
      </div>
    <?php endif; ?>

    <?php foreach ($byModule as $mod => $files): ?>
      <div class="tu-card mig-mod-card" style="padding:18px;">
        <div style="font-weight:800;font-size:14px;margin-bottom:8px;"><?= h($moduleLabels[$mod] ?? ucfirst($mod)) ?></div>

        <?php foreach ($files as $i => $path):
          $file = basename($path);
          $key  = $mod . '/' . $file;
          $row  = $applied[$key] ?? null;
          $currentChecksum = sha1((string)file_get_contents($path));
          $changedSinceApplied = $row && ($row['checksum'] ?? null) !== null && $row['checksum'] !== $currentChecksum;
          $domId = 'sql-' . preg_replace('/[^a-zA-Z0-9_]/', '-', $key);
        ?>
          <div class="mig-row">
            <div style="min-width:0;">
              <div class="mig-file"><?= h($file) ?></div>
              <?php if ($row): ?>
                <div class="mig-meta">
                  ✓ Appliquée le <?= h(date('d/m/Y H:i', strtotime($row['applied_at']))) ?>
                  <?= $row['applied_by'] ? ' par ' . h($row['applied_by']) : '' ?>
                  <?php if ($changedSinceApplied): ?>
                    <span style="color:var(--tu-amber-600);font-weight:700;"> · fichier modifié depuis</span>
                  <?php endif; ?>
                </div>
              <?php else: ?>
                <div class="mig-meta">En attente</div>
              <?php endif; ?>
              <button type="button" class="tu-btn-link" style="margin-top:4px;" onclick="document.getElementById('<?= h($domId) ?>').style.display = document.getElementById('<?= h($domId) ?>').style.display === 'block' ? 'none' : 'block';">Voir le SQL</button>
              <pre class="mig-sql-pre" id="<?= h($domId) ?>"><?= h(file_get_contents($path)) ?></pre>
            </div>

            <div class="mig-actions">
              <?php if (!$row || $changedSinceApplied): ?>
                <form method="post" onsubmit="return confirm('Exécuter <?= h(addslashes($file)) ?> sur cette base ? Cette action modifie la structure/les données réellement.');">
                  <input type="hidden" name="action" value="apply">
                  <input type="hidden" name="module" value="<?= h($mod) ?>">
                  <input type="hidden" name="file" value="<?= h($file) ?>">
                  <button type="submit" class="tu-btn tu-btn-p tu-btn-xs">Appliquer</button>
                </form>
                <form method="post" onsubmit="return confirm('Marquer <?= h(addslashes($file)) ?> comme déjà appliquée SANS l\'exécuter ?');">
                  <input type="hidden" name="action" value="mark_applied">
                  <input type="hidden" name="module" value="<?= h($mod) ?>">
                  <input type="hidden" name="file" value="<?= h($file) ?>">
                  <button type="submit" class="tu-btn tu-btn-s tu-btn-xs">Marquer comme appliquée</button>
                </form>
              <?php else: ?>
                <span class="tu-bdg tu-bdg-teal">OK</span>
              <?php endif; ?>
            </div>
          </div>
        <?php endforeach; ?>
      </div>
    <?php endforeach; ?>

  <?php endif; ?>

</div>

</div><!-- /tu-main -->
</body>
</html>
