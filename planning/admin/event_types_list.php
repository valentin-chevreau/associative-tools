<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$title = "Admin – Types d’événements";
ob_start();

require_once __DIR__ . '/../includes/app.php';
$config = require __DIR__ . '/../config/config.php';

if (empty($_SESSION['is_admin']) && empty($_SESSION['admin_authenticated'])) {
    header('Location: ' . $config['base_url'] . '/admin/login.php');
    exit;
}

global $pdo;

function h($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

$flash = null;
if (!empty($_GET['msg'])) {
    $flash = (string)$_GET['msg'];
}

$hasCategory = true;
try {
    $pdo->query("SELECT category_label, category_sort FROM event_types LIMIT 1");
} catch (Throwable $e) {
    $hasCategory = false;
}

/**
 * Actions rapides : activer/désactiver
 */
if (isset($_GET['action'], $_GET['id']) && $_GET['action'] === 'toggle') {
    $id = (int)$_GET['id'];
    if ($id > 0) {
        $stmt = $pdo->prepare("UPDATE event_types SET is_active = CASE WHEN is_active=1 THEN 0 ELSE 1 END WHERE id = :id");
        $stmt->execute(['id' => $id]);
    }
    header('Location: ' . $config['base_url'] . '/admin/event_types_list.php?msg=' . urlencode('Statut mis à jour.'));
    exit;
}

/**
 * Liste
 */
if ($hasCategory) {
    $stmt = $pdo->query("
        SELECT id, code, label, is_active, sort_order, category_label, category_sort
        FROM event_types
        ORDER BY category_sort ASC, category_label ASC, sort_order ASC, label ASC
    ");
} else {
    $stmt = $pdo->query("
        SELECT id, code, label, is_active, sort_order
        FROM event_types
        ORDER BY sort_order ASC, label ASC
    ");
}

$types = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Construire des groupes par catégorie (si dispo)
$groups = [];
if ($hasCategory) {
    foreach ($types as $t) {
        $cat = (string)($t['category_label'] ?? 'Autres');
        $sort = (int)($t['category_sort'] ?? 100);
        if (!isset($groups[$cat])) {
            $groups[$cat] = ['sort' => $sort, 'items' => []];
        } else {
            // garder le plus petit sort si incohérences
            $groups[$cat]['sort'] = min($groups[$cat]['sort'], $sort);
        }
        $groups[$cat]['items'][] = $t;
    }
    uasort($groups, function($a, $b) {
        if ($a['sort'] === $b['sort']) return 0;
        return $a['sort'] <=> $b['sort'];
    });
}
?>

<style>
/* Petits styles admin “modernes” */
.admin-toolbar {
  display:flex; flex-wrap:wrap; gap:8px; align-items:center; justify-content:space-between;
}
.pill {
  display:inline-flex; align-items:center; gap:6px;
  padding:6px 10px; border-radius:999px; border:1px solid #e5e7eb;
  background:#fff; font-size:12px; color:#111827; text-decoration:none;
}
.pill:hover { background:#f9fafb; }
.pill.primary { border-color:transparent; background:linear-gradient(135deg,#2563eb,#3b82f6); color:#fff; font-weight:700; }
.pill.danger { border-color:#fecaca; background:#fee2e2; color:#991b1b; font-weight:700; }

.table { width:100%; border-collapse:collapse; }
.table th { text-align:left; font-size:12px; color:#6b7280; padding:10px 8px; border-bottom:1px solid #e5e7eb; }
.table td { padding:10px 8px; border-bottom:1px solid #f3f4f6; font-size:13px; vertical-align:middle; }
.kbd { font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, monospace; font-size:12px; background:#f3f4f6; border:1px solid #e5e7eb; padding:2px 6px; border-radius:8px; }
.tag { display:inline-flex; align-items:center; padding:2px 8px; border-radius:999px; font-size:12px; border:1px solid #e5e7eb; background:#f9fafb; color:#111827; }
.tag.off { background:#fee2e2; border-color:#fecaca; color:#991b1b; }
.cat-title { display:flex; align-items:flex-end; justify-content:space-between; gap:10px; flex-wrap:wrap; margin:0 0 8px; }
.cat-title h3 { margin:0; }
</style>

<div class="card">
  <div class="admin-toolbar">
    <div>
      <h2 style="margin:0 0 4px;">Types d’événements</h2>
      <p class="muted" style="margin:0;">
        Gère la liste des types disponibles (activation, ordre, catégorie). Le CRA se base dessus.
      </p>
    </div>
    <div style="display:flex; gap:8px; flex-wrap:wrap;">
      <a class="pill" href="<?= h($config['base_url']) ?>/admin/events_list.php">← Retour événements</a>
      <a class="pill primary" href="<?= h($config['base_url']) ?>/admin/event_type_edit.php">+ Ajouter un type</a>
    </div>
  </div>

  <?php if ($flash): ?>
    <div class="alert" style="margin-top:12px;"><?= h($flash) ?></div>
  <?php endif; ?>
</div>

<?php if (empty($types)): ?>
  <div class="card">
    <p class="muted">Aucun type pour le moment.</p>
  </div>
<?php else: ?>

  <?php if ($hasCategory): ?>
    <?php foreach ($groups as $cat => $g): ?>
      <div class="card">
        <div class="cat-title">
          <h3><?= h($cat) ?></h3>
          <span class="muted">Ordre catégorie : <span class="kbd"><?= (int)$g['sort'] ?></span></span>
        </div>

        <table class="table">
          <thead>
            <tr>
              <th style="width:110px;">Code</th>
              <th>Libellé</th>
              <th style="width:120px;">Actif</th>
              <th style="width:120px;">Ordre type</th>
              <th style="width:240px;">Actions</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($g['items'] as $t): ?>
              <tr>
                <td><span class="kbd"><?= h($t['code']) ?></span></td>
                <td><strong><?= h($t['label']) ?></strong></td>
                <td>
                  <?php if ((int)$t['is_active'] === 1): ?>
                    <span class="tag">Actif</span>
                  <?php else: ?>
                    <span class="tag off">Inactif</span>
                  <?php endif; ?>
                </td>
                <td><span class="kbd"><?= (int)$t['sort_order'] ?></span></td>
                <td>
                  <a class="pill" href="<?= h($config['base_url']) ?>/admin/event_type_edit.php?id=<?= (int)$t['id'] ?>">Modifier</a>
                  <a class="pill danger" href="<?= h($config['base_url']) ?>/admin/event_types_list.php?action=toggle&id=<?= (int)$t['id'] ?>">
                    <?= ((int)$t['is_active'] === 1) ? 'Désactiver' : 'Activer' ?>
                  </a>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>

        <p class="muted" style="margin-top:10px;">
          Note : un type <strong>inactif</strong> peut rester utile pour l’historique (anciens événements).
        </p>
      </div>
    <?php endforeach; ?>

  <?php else: ?>
    <div class="card">
      <p class="muted" style="margin-top:0;">
        Les colonnes de catégories ne sont pas détectées dans <span class="kbd">event_types</span>.
        (Tu peux appliquer la migration <span class="kbd">category_label</span> / <span class="kbd">category_sort</span>.)
      </p>

      <table class="table">
        <thead>
          <tr>
            <th style="width:110px;">Code</th>
            <th>Libellé</th>
            <th style="width:120px;">Actif</th>
            <th style="width:120px;">Ordre type</th>
            <th style="width:240px;">Actions</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($types as $t): ?>
            <tr>
              <td><span class="kbd"><?= h($t['code']) ?></span></td>
              <td><strong><?= h($t['label']) ?></strong></td>
              <td>
                <?php if ((int)$t['is_active'] === 1): ?>
                  <span class="tag">Actif</span>
                <?php else: ?>
                  <span class="tag off">Inactif</span>
                <?php endif; ?>
              </td>
              <td><span class="kbd"><?= (int)$t['sort_order'] ?></span></td>
              <td>
                <a class="pill" href="<?= h($config['base_url']) ?>/admin/event_type_edit.php?id=<?= (int)$t['id'] ?>">Modifier</a>
                <a class="pill danger" href="<?= h($config['base_url']) ?>/admin/event_types_list.php?action=toggle&id=<?= (int)$t['id'] ?>">
                  <?= ((int)$t['is_active'] === 1) ? 'Désactiver' : 'Activer' ?>
                </a>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>

<?php endif; ?>

<?php
$content = ob_get_clean();
include __DIR__ . '/../includes/layout.php';