<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$title = "Admin – Événements";
ob_start();

require_once __DIR__ . '/../includes/app.php';
$config = require __DIR__ . '/../config/config.php';

if (empty($_SESSION['is_admin']) && empty($_SESSION['admin_authenticated'])) {
    header('Location: ' . $config['base_url'] . '/admin/login.php');
    exit;
}

global $pdo;

function h($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

// =======================
// Filtres
// =======================
$filterShow = isset($_GET['show']) ? (string)$_GET['show'] : 'all'; // upcoming|past|all
if (!in_array($filterShow, ['upcoming', 'past', 'all'], true)) $filterShow = 'upcoming';

$filterGroup = isset($_GET['group']) ? (string)$_GET['group'] : '0'; // 0|1
$filterGroup = ($filterGroup === '1') ? '1' : '0';

$filterCategory = isset($_GET['category']) ? trim((string)$_GET['category']) : ''; // category_label
$filterType = isset($_GET['type']) ? trim((string)$_GET['type']) : '';             // event_type code
$filterQ = isset($_GET['q']) ? trim((string)$_GET['q']) : '';
$filterCancelled = isset($_GET['cancelled']) ? (string)$_GET['cancelled'] : '0';   // 0|1|all
if (!in_array($filterCancelled, ['0', '1', 'all'], true)) $filterCancelled = '0';

$baseUrl = $config['base_url'] . '/admin/events_list.php';

function build_url($base, $params) {
    foreach ($params as $k => $v) {
        if ($v === '' || $v === null) unset($params[$k]);
    }
    return $base . '?' . http_build_query($params);
}

$currentParams = [
    'show' => $filterShow,
    'group' => $filterGroup,
    'category' => $filterCategory,
    'type' => $filterType,
    'q' => $filterQ,
    'cancelled' => $filterCancelled,
];

// =======================
// Référentiels : catégories & types
// =======================
$hasCategoryCols = true;
$categories = [];
$eventTypes = [];
$typeLabelMap = [];

try {
    $pdo->query("SELECT category_label, category_sort FROM event_types LIMIT 1");
} catch (Throwable $e) {
    $hasCategoryCols = false;
}

try {
    if ($hasCategoryCols) {
        $stmtCats = $pdo->query("
            SELECT category_label AS label, MIN(category_sort) AS sort
            FROM event_types
            WHERE category_label IS NOT NULL AND category_label <> ''
            GROUP BY category_label
            ORDER BY sort ASC, label ASC
        ");
        $categories = $stmtCats->fetchAll(PDO::FETCH_ASSOC);

        $stmtTypes = $pdo->query("
            SELECT code, label, is_active, sort_order, category_label, category_sort
            FROM event_types
            ORDER BY category_sort ASC, category_label ASC, sort_order ASC, label ASC
        ");
        $eventTypes = $stmtTypes->fetchAll(PDO::FETCH_ASSOC);
    } else {
        $stmtTypes = $pdo->query("
            SELECT code, label
            FROM event_types
            ORDER BY sort_order ASC, label ASC
        ");
        $eventTypes = $stmtTypes->fetchAll(PDO::FETCH_ASSOC);
    }
} catch (Throwable $e) {
    $hasCategoryCols = false;
    $eventTypes = [
        ['code' => 'permanence', 'label' => 'Permanence'],
        ['code' => 'evenement', 'label' => 'Événement'],
    ];
}

foreach ($eventTypes as $t) {
    $typeLabelMap[(string)$t['code']] = (string)$t['label'];
}

// Types pour le select (si catégorie sélectionnée => restreint)
$typesForSelect = [];
if ($hasCategoryCols && $filterCategory !== '') {
    foreach ($eventTypes as $t) {
        if ((string)($t['category_label'] ?? '') === $filterCategory) {
            $typesForSelect[] = $t;
        }
    }
} else {
    $typesForSelect = $eventTypes;
}

// Si type incohérent avec la catégorie, reset
if ($hasCategoryCols && $filterCategory !== '' && $filterType !== '') {
    $ok = false;
    foreach ($eventTypes as $t) {
        if ((string)$t['code'] === $filterType && (string)($t['category_label'] ?? '') === $filterCategory) {
            $ok = true; break;
        }
    }
    if (!$ok) $filterType = '';
}

// =======================
// SQL
// =======================
$whereParts = [];
$sqlParams = [];
$commonWhereSql = "";

if ($hasCategoryCols && $filterCategory !== '') {
    $whereParts[] = "e.event_type IN (
        SELECT et2.code FROM event_types et2
        WHERE et2.category_label = :catLabel
    )";
    $sqlParams['catLabel'] = $filterCategory;
}

if ($filterType !== '') {
    $whereParts[] = "e.event_type = :etype";
    $sqlParams['etype'] = $filterType;
}

if ($filterCancelled !== 'all') {
    $whereParts[] = "e.is_cancelled = :iscancelled";
    $sqlParams['iscancelled'] = (int)$filterCancelled;
}

if ($filterQ !== '') {
    $whereParts[] = "(e.title LIKE :q OR e.description LIKE :q)";
    $sqlParams['q'] = '%' . $filterQ . '%';
}

if (!empty($whereParts)) {
    $commonWhereSql = ' AND ' . implode(' AND ', $whereParts);
}

$selectSql = "
  SELECT
    e.*,
    (SELECT COUNT(*) FROM event_registrations r WHERE r.event_id = e.id AND r.status = 'present') AS present_count,
    et.label AS type_label,
    et.category_label,
    et.category_sort
  FROM events e
  LEFT JOIN event_types et ON et.code = e.event_type
  WHERE 1=1
";

$upcomingEvents = [];
$pastEvents = [];

if ($filterShow === 'upcoming' || $filterShow === 'all') {
    $sql = $selectSql . "
      AND e.start_datetime >= NOW()
      {$commonWhereSql}
      ORDER BY e.start_datetime ASC
    ";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($sqlParams);
    $upcomingEvents = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

if ($filterShow === 'past' || $filterShow === 'all') {
    $sql = $selectSql . "
      AND e.start_datetime < NOW()
      {$commonWhereSql}
      ORDER BY e.start_datetime DESC
      LIMIT 160
    ";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($sqlParams);
    $pastEvents = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// =======================
// UI helpers
// =======================
function badge_status($event, $presentCount) {
    $min = (int)($event['min_volunteers'] ?? 0);
    if (!empty($event['is_cancelled'])) return ['label' => 'Annulé', 'color' => '#991b1b', 'bg' => '#fee2e2'];
    if ($min > 0 && $presentCount < $min) return ['label' => 'Sous le minimum', 'color' => '#ea580c', 'bg' => '#ffedd5'];
    return ['label' => 'OK', 'color' => '#16a34a', 'bg' => '#dcfce7'];
}

function group_by_category(array $events): array {
    $groups = [];
    foreach ($events as $e) {
        $cat = trim((string)($e['category_label'] ?? ''));
        $cat = $cat !== '' ? $cat : 'Autres';
        $sort = (int)($e['category_sort'] ?? 999);

        if (!isset($groups[$cat])) $groups[$cat] = ['name' => $cat, 'sort' => $sort, 'events' => []];
        else $groups[$cat]['sort'] = min($groups[$cat]['sort'], $sort);

        $groups[$cat]['events'][] = $e;
    }
    uasort($groups, function($a, $b) {
        if ($a['sort'] === $b['sort']) return strcasecmp($a['name'], $b['name']);
        return $a['sort'] <=> $b['sort'];
    });
    return $groups;
}

function render_event_card($event, $config, $typeLabelMap) {
    $start = new DateTime($event['start_datetime']);
    $end   = new DateTime($event['end_datetime']);

    $presentCount = (int)($event['present_count'] ?? 0);
    $min = (int)($event['min_volunteers'] ?? 0);
    $max = $event['max_volunteers'] !== null ? (int)$event['max_volunteers'] : null;

    $typeCode = (string)($event['event_type'] ?? '');
    $typeLabel = trim((string)($event['type_label'] ?? ''));
    if ($typeLabel === '') $typeLabel = $typeLabelMap[$typeCode] ?? ($typeCode ?: 'Type');

    $catLabel = trim((string)($event['category_label'] ?? ''));

    $st = badge_status($event, $presentCount);
    ?>
    <div class="card" style="<?= !empty($event['is_cancelled']) ? 'opacity:0.85;' : '' ?>">
      <div style="display:flex; justify-content:space-between; gap:12px; flex-wrap:wrap;">
        <div>
          <div class="tags">
            <?php if ($catLabel !== ''): ?>
              <span class="tag cat"><?= h($catLabel) ?></span>
            <?php endif; ?>
            <span class="tag"><?= h($typeLabel) ?></span>
            <?php if (!empty($event['is_cancelled'])): ?>
              <span class="tag status" style="background:<?= h($st['bg']) ?>; color:<?= h($st['color']) ?>;">
                <?= h($st['label']) ?>
              </span>
            <?php endif; ?>
          </div>

          <h3 style="margin:0 0 6px;"><?= h($event['title']) ?></h3>
          <div class="muted"><?= h($start->format('d/m/Y H:i')) ?> → <?= h($end->format('d/m/Y H:i')) ?></div>
          <div class="muted" style="margin-top:6px;">
            Inscrits : <strong><?= (int)$presentCount ?></strong>
            <?php if ($min > 0): ?> · min <?= (int)$min ?><?php endif; ?>
            <?php if ($max !== null): ?> · max <?= (int)$max ?><?php endif; ?>
          </div>
        </div>

        <div style="display:flex; gap:8px; align-items:flex-start; justify-content:flex-end; flex-wrap:wrap;">
          <a class="pill" href="<?= h($config['base_url']) ?>/admin/event_registrations.php?id=<?= (int)$event['id'] ?>">Inscrits</a>
          <a class="pill" href="<?= h($config['base_url']) ?>/admin/event_edit.php?id=<?= (int)$event['id'] ?>">Modifier</a>
        </div>
      </div>
    </div>
    <?php
}

$activeFilterBits = [];
if ($hasCategoryCols && $filterCategory !== '') $activeFilterBits[] = "Catégorie : {$filterCategory}";
if ($filterType !== '') $activeFilterBits[] = "Type : " . ($typeLabelMap[$filterType] ?? $filterType);
if ($filterCancelled !== '0') $activeFilterBits[] = "Annulés : " . ($filterCancelled==='1'?'uniquement':'inclus');
if ($filterQ !== '') $activeFilterBits[] = "Recherche : “{$filterQ}”";
$filterSummary = empty($activeFilterBits) ? "Aucun filtre." : implode(' · ', $activeFilterBits);

?>
<style>
.admin-toolbar{display:flex;flex-wrap:wrap;gap:10px;align-items:flex-end;justify-content:space-between;}

.field{display:flex;flex-direction:column;gap:8px;min-width:0;}
.field label{font-size:12px;font-weight:900;color:#374151;}
.small-muted{font-size:12px;color:#6b7280;}

/* 🔒 Anti-débordement global */
.filters, .filters * { box-sizing: border-box; }
.filters{ width:100%; }
.card .form-control{ max-width:100% !important; min-width:0 !important; }

/* ✅ Grille fluide (pas de pixels fixes => ça ne déborde plus) */
.filters{
  display:grid !important;
  grid-template-columns: 1fr !important;
  grid-template-areas:
    "cat"
    "type"
    "search"
    "cancel"
    "actions" !important;
  gap:12px !important;
  margin-top:12px !important;
  align-items:end !important;
}

.filters .f-cat{grid-area:cat;}
.filters .f-type{grid-area:type;}
.filters .f-search{grid-area:search;}
.filters .f-cancel{grid-area:cancel;}
.filters .f-actions{
  grid-area:actions;
  display:flex;
  justify-content:flex-end;
  align-items:end;
}

/* >= 920px : 2 colonnes fluides */
@media (min-width: 920px){
  .filters{
    grid-template-columns: minmax(0,1fr) minmax(0,1fr) !important;
    grid-template-areas:
      "cat type"
      "search cancel"
      ". actions" !important;
  }
}

/* >= 1100px : 4 colonnes fluides (rétrécissables) */
@media (min-width: 1100px){
  .filters{
    grid-template-columns:
      minmax(180px, 1fr)
      minmax(220px, 1.2fr)
      minmax(260px, 1.6fr)
      minmax(200px, 1fr) !important;
    grid-template-areas:
      "cat type search cancel"
      ". . . actions" !important;
  }
}

/* Inputs/selects : mêmes styles (override fort) */
.card .form-control{
  width:100% !important;
  padding:10px 12px !important;
  border:1px solid #d1d5db !important;
  border-radius:12px !important;
  background:#fff !important;
  font-size:14px !important;
  outline:none;
  line-height:1.2;
}
.card .form-control:focus{
  border-color:#93c5fd !important;
  box-shadow:0 0 0 3px rgba(147,197,253,0.35) !important;
}

/* Select : chevron custom */
.card select.form-control{
  appearance:none;
  -webkit-appearance:none;
  -moz-appearance:none;
  padding-right:40px !important;
  background-image:
    linear-gradient(45deg, transparent 50%, #6b7280 50%),
    linear-gradient(135deg, #6b7280 50%, transparent 50%);
  background-position:
    calc(100% - 18px) 50%,
    calc(100% - 12px) 50%;
  background-size:6px 6px, 6px 6px;
  background-repeat:no-repeat;
}

/* Buttons / chips */
.pill{display:inline-flex;align-items:center;gap:6px;padding:8px 12px;border-radius:999px;border:1px solid #e5e7eb;background:#fff;font-size:13px;color:#111827;text-decoration:none;}
.pill:hover{background:#f9fafb;}
.pill.primary{border-color:transparent;background:linear-gradient(135deg,#2563eb,#3b82f6);color:#fff;font-weight:900;}

.chips{display:flex;gap:8px;flex-wrap:wrap;align-items:center;margin-top:12px;}
.chip{display:inline-flex;align-items:center;gap:6px;padding:8px 12px;border-radius:999px;border:1px solid #e5e7eb;background:#fff;font-size:13px;color:#111827;text-decoration:none;}
.chip:hover{background:#f9fafb;}
.chip.on{border-color:transparent;background:linear-gradient(135deg,#10b981,#22c55e);color:#fff;font-weight:900;}
.chip.alt.on{background:linear-gradient(135deg,#6366f1,#3b82f6);}

/* Tags */
.tags{display:flex;gap:6px;flex-wrap:wrap;margin-bottom:6px;}
.tag{display:inline-flex;align-items:center;padding:2px 10px;border-radius:999px;font-size:12px;border:1px solid #e5e7eb;background:#f9fafb;color:#111827;}
.tag.cat{background:#eef2ff;border-color:#c7d2fe;color:#3730a3;font-weight:800;}
.tag.status{border-color:transparent;font-weight:900;}

/* Group header */
.group-head{
  display:flex;align-items:center;justify-content:space-between;gap:10px;flex-wrap:wrap;
  padding:10px 12px;border:1px solid #e5e7eb;border-radius:14px;background:#fff;
  margin-top:12px;
}
.group-title{margin:0;font-weight:900;}
.group-sub{font-size:12px;color:#6b7280;}
</style>

<div class="card">
  <div class="admin-toolbar">
    <div>
      <h2 style="margin:0 0 6px;">Gestion des événements</h2>
      <p class="muted" style="margin:0;">Autosubmit partout. Filtre par catégorie, type, recherche, annulés.</p>
    </div>
    <div style="display:flex; gap:8px; flex-wrap:wrap;">
      <a class="pill primary" href="<?= h($config['base_url']) ?>/admin/event_edit.php">+ Nouvel événement</a>
      <a class="pill" href="<?= h($config['base_url']) ?>/admin/generate_saturdays.php">Générer des samedis</a>
      <a class="pill" href="<?= h($config['base_url']) ?>/admin/volunteers_list.php">Gérer les bénévoles</a>
      <a class="pill" href="<?= h($config['base_url']) ?>/admin/event_types_list.php">Types d’événements</a>
    </div>
  </div>

  <div class="chips">
    <?php $p['show'] = 'all'; ?>
    <a class="chip <?= $filterShow==='all'?'on':'' ?>" href="<?= h(build_url($baseUrl, $p)) ?>">Tout</a>
    <?php $p = $currentParams; $p['show'] = 'upcoming'; ?>
    <a class="chip <?= $filterShow==='upcoming'?'on':'' ?>" href="<?= h(build_url($baseUrl, $p)) ?>">À venir</a>
    <?php $p['show'] = 'past'; ?>
    <a class="chip <?= $filterShow==='past'?'on':'' ?>" href="<?= h(build_url($baseUrl, $p)) ?>">Historique</a>
    

    <span style="width:10px;"></span>

    <?php $p = $currentParams; $p['group'] = '0'; ?>
    <a class="chip alt <?= $filterGroup==='0'?'on':'' ?>" href="<?= h(build_url($baseUrl, $p)) ?>">Liste simple</a>
    <?php $p['group'] = '1'; ?>
    <a class="chip alt <?= $filterGroup==='1'?'on':'' ?>" href="<?= h(build_url($baseUrl, $p)) ?>">Grouper par catégorie</a>
  </div>

  <form id="filtersForm" method="get" class="filters" autocomplete="off">
    <input type="hidden" name="show" value="<?= h($filterShow) ?>">
    <input type="hidden" name="group" value="<?= h($filterGroup) ?>">

    <?php if ($hasCategoryCols): ?>
      <div class="field f-cat">
        <label>Catégorie</label>
        <select class="form-control autosubmit" name="category">
          <option value="">Toutes les catégories</option>
          <?php foreach ($categories as $c): ?>
            <?php $lab = (string)($c['label'] ?? ''); ?>
            <option value="<?= h($lab) ?>" <?= ($filterCategory === $lab) ? 'selected' : '' ?>><?= h($lab) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
    <?php endif; ?>

    <div class="field f-type">
      <label>Type d’événement</label>
      <select class="form-control autosubmit" name="type">
        <option value=""><?= ($hasCategoryCols && $filterCategory !== '') ? "Tous les types de la catégorie" : "Tous les types" ?></option>
        <?php foreach ($typesForSelect as $t): ?>
          <?php $code = (string)$t['code']; $label = (string)$t['label']; ?>
          <option value="<?= h($code) ?>" <?= ($filterType === $code) ? 'selected' : '' ?>><?= h($label) ?></option>
        <?php endforeach; ?>
      </select>
    </div>

    <div class="field f-search">
      <label>Recherche</label>
      <input id="qInput" class="form-control" type="text" name="q" value="<?= h($filterQ) ?>" placeholder="Titre ou description…">
    </div>

    <div class="field f-cancel">
      <label>Annulés</label>
      <select class="form-control autosubmit" name="cancelled">
        <option value="0" <?= $filterCancelled==='0'?'selected':'' ?>>Masquer les annulés</option>
        <option value="all" <?= $filterCancelled==='all'?'selected':'' ?>>Inclure annulés</option>
        <option value="1" <?= $filterCancelled==='1'?'selected':'' ?>>Seulement annulés</option>
      </select>
    </div>

    <div class="f-actions">
      <a class="pill" href="<?= h($baseUrl) ?>">Réinitialiser</a>
    </div>
  </form>

  <div style="display:flex; justify-content:space-between; gap:10px; flex-wrap:wrap; align-items:center; margin-top:10px;">
    <div class="small-muted"><strong>Filtres :</strong> <?= h($filterSummary) ?></div>
    <div class="small-muted">Résultats : <?= (int)count($upcomingEvents) ?> à venir · <?= (int)count($pastEvents) ?> historiques</div>
  </div>
</div>

<script>
(function(){
  const form = document.getElementById('filtersForm');
  if (!form) return;

  form.querySelectorAll('.autosubmit').forEach(el => {
    el.addEventListener('change', () => form.submit());
  });

  const q = document.getElementById('qInput');
  if (q) {
    let t = null;
    q.addEventListener('input', () => {
      clearTimeout(t);
      t = setTimeout(() => form.submit(), 450);
    });
  }
})();
</script>

<?php if (empty($upcomingEvents) && empty($pastEvents)): ?>
  <div class="card"><p class="muted">Aucun événement ne correspond aux filtres.</p></div>
<?php endif; ?>

<?php if (!empty($upcomingEvents)): ?>
  <?php if ($filterGroup === '1'): ?>
    <?php $groups = group_by_category($upcomingEvents); ?>
    <?php foreach ($groups as $g): ?>
      <div class="group-head">
        <h4 class="group-title"><?= h($g['name']) ?></h4>
        <div class="group-sub"><?= (int)count($g['events']) ?> événement(s) · à venir</div>
      </div>
      <?php foreach ($g['events'] as $event): ?>
        <?php render_event_card($event, $config, $typeLabelMap); ?>
      <?php endforeach; ?>
    <?php endforeach; ?>
  <?php else: ?>
    <?php foreach ($upcomingEvents as $event): ?>
      <?php render_event_card($event, $config, $typeLabelMap); ?>
    <?php endforeach; ?>
  <?php endif; ?>
<?php endif; ?>

<?php if (!empty($pastEvents)): ?>
  <?php if ($filterGroup === '1'): ?>
    <?php $groups = group_by_category($pastEvents); ?>
    <?php foreach ($groups as $g): ?>
      <div class="group-head">
        <h4 class="group-title"><?= h($g['name']) ?></h4>
        <div class="group-sub"><?= (int)count($g['events']) ?> événement(s) · historiques</div>
      </div>
      <?php foreach ($g['events'] as $event): ?>
        <?php render_event_card($event, $config, $typeLabelMap); ?>
      <?php endforeach; ?>
    <?php endforeach; ?>
  <?php else: ?>
    <?php foreach ($pastEvents as $event): ?>
      <?php render_event_card($event, $config, $typeLabelMap); ?>
    <?php endforeach; ?>
  <?php endif; ?>
<?php endif; ?>

<?php
$content = ob_get_clean();
include __DIR__ . '/../includes/layout.php';