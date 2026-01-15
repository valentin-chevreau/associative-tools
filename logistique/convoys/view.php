<?php
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../header.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($id <= 0) {
    echo "<p>Convoi introuvable.</p>";
    require __DIR__ . '/../footer.php';
    exit;
}

$isAdmin    = !empty($_SESSION['is_admin']);
$simpleMode = !empty($_SESSION['simple_mode']);

$stmt = $pdo->prepare("SELECT * FROM convoys WHERE id = ?");
$stmt->execute([$id]);
$convoi = $stmt->fetch();
if (!$convoi) {
    echo "<p>Convoi introuvable.</p>";
    require __DIR__ . '/../footer.php';
    exit;
}

$status  = (string)($convoi['status'] ?? 'preparation');
$canEdit = ($isAdmin || $status === 'preparation');

function labelStatutConvoi(string $status): string {
    switch ($status) {
        case 'preparation': return 'En préparation';
        case 'expedie':     return 'Expédié';
        case 'livre':       return 'Livré';
        case 'archive':     return 'Archivé';
        default:            return $status;
    }
}
function badgeStatutConvoi(string $status): string {
    switch ($status) {
        case 'preparation': return 'warning';
        case 'expedie':     return 'primary';
        case 'livre':       return 'success';
        case 'archive':     return 'secondary';
        default:            return 'light';
    }
}

// Flash
if (!empty($_SESSION['flash_success'])) {
    echo '<div class="alert alert-success">' . htmlspecialchars($_SESSION['flash_success']) . '</div>';
    unset($_SESSION['flash_success']);
}
if (!empty($_SESSION['flash_error'])) {
    echo '<div class="alert alert-danger">' . htmlspecialchars($_SESSION['flash_error']) . '</div>';
    unset($_SESSION['flash_error']);
}

/**
 * Sécurité serveur : toute modification POST = admin only
 */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!$isAdmin) {
        http_response_code(403);
        echo "<div class='alert alert-danger'>Accès interdit.</div>";
        require __DIR__ . '/../footer.php';
        exit;
    }

    $action = $_POST['action'] ?? '';

    if ($action === 'change_status') {
        $newStatus = $_POST['status'] ?? '';
        $allowed   = ['preparation', 'expedie', 'livre', 'archive'];

        if (in_array($newStatus, $allowed, true) && $newStatus !== $convoi['status']) {
            $upd = $pdo->prepare("UPDATE convoys SET status = ? WHERE id = ?");
            $upd->execute([$newStatus, $id]);
        }
        header('Location: ' . APP_BASE . '/convoys/view.php?id=' . $id);
        exit;
    }

    if ($action === 'update_info') {
        $name          = trim($_POST['name'] ?? '');
        $destination   = trim($_POST['destination'] ?? '');
        $departureDate = trim($_POST['departure_date'] ?? '');

        if ($name === '') $name = $convoi['name'];

        $destValue = $destination !== '' ? $destination : null;
        $dateValue = $departureDate !== '' ? $departureDate : null;

        $upd = $pdo->prepare("
            UPDATE convoys
            SET name = ?, destination = ?, departure_date = ?
            WHERE id = ?
        ");
        $upd->execute([$name, $destValue, $dateValue, $id]);

        header('Location: ' . APP_BASE . '/convoys/view.php?id=' . $id);
        exit;
    }
}

/**
 * Racines actives (dropdown ajout + palettes)
 */
$rootsActiveStmt = $pdo->query("
    SELECT id, label
    FROM categories
    WHERE parent_id IS NULL AND is_active = 1
    ORDER BY label
");
$rootsActive = $rootsActiveStmt->fetchAll();

/**
 * Catégories enfants actives (dropdown ajout)
 * Liste "recherchable" avec breadcrumb Racine › Enfant
 */
$childrenStmt = $pdo->query("
    SELECT c.id, c.label, c.root_id, r.label AS root_label
    FROM categories c
    JOIN categories r ON r.id = c.root_id
    WHERE c.parent_id IS NOT NULL
      AND c.is_active = 1
    ORDER BY r.label, c.label
");
$children = $childrenStmt->fetchAll();

/**
 * Total cartons
 */
$totalStmt = $pdo->prepare("SELECT COUNT(*) AS total_boxes FROM boxes WHERE convoy_id = ?");
$totalStmt->execute([$id]);
$totalBoxes = (int)($totalStmt->fetch()['total_boxes'] ?? 0);

/**
 * Nb catégories utilisées (distinct)
 */
$catCountStmt = $pdo->prepare("SELECT COUNT(DISTINCT category_id) AS n FROM boxes WHERE convoy_id = ?");
$catCountStmt->execute([$id]);
$totalCategoriesUsed = (int)($catCountStmt->fetch()['n'] ?? 0);

/**
 * Stats groupées : Racine -> Catégorie
 */
$statsStmt = $pdo->prepare("
    SELECT
        r.id    AS root_id,
        r.label AS root_label,
        c.id    AS category_id,
        c.label AS category_label,
        COUNT(b.id) AS nb_boxes
    FROM boxes b
    JOIN categories c ON c.id = b.category_id
    JOIN categories r ON r.id = c.root_id
    WHERE b.convoy_id = ?
    GROUP BY r.id, r.label, c.id, c.label
    ORDER BY r.label, c.label
");
$statsStmt->execute([$id]);

$groups = [];
while ($row = $statsStmt->fetch()) {
    $rootId = (int)$row['root_id'];
    if (!isset($groups[$rootId])) {
        $groups[$rootId] = [
            'root_label' => $row['root_label'],
            'root_total' => 0,
            'items'      => []
        ];
    }
    $nb = (int)$row['nb_boxes'];
    $groups[$rootId]['root_total'] += $nb;
    $groups[$rootId]['items'][] = [
        'category_id' => (int)$row['category_id'],
        'label'       => $row['category_label'],
        'nb_boxes'    => $nb,
        'root_label'  => $row['root_label'],
    ];
}

/**
 * Palettes déclarées : toutes les racines actives éditables
 */
$palStmt = $pdo->prepare("
    SELECT root_category_id, real_count
    FROM convoy_palettes
    WHERE convoy_id = ?
");
$palStmt->execute([$id]);

$palettesReal = [];
while ($r = $palStmt->fetch()) {
    $palettesReal[(int)$r['root_category_id']] = (int)$r['real_count'];
}

$totalPalettes = 0;
foreach ($rootsActive as $rt) {
    $rid = (int)$rt['id'];
    $totalPalettes += (int)($palettesReal[$rid] ?? 0);
}

/**
 * Helpers URL
 */
function buildQuickAddUrl(int $convoyId, int $categoryId, int $qty): string {
    return APP_BASE . '/boxes/quick_add.php?convoy_id=' . $convoyId . '&category_id=' . $categoryId . '&qty=' . $qty;
}
function buildCreateBoxUrl(int $convoyId, int $rootId, ?int $categoryId = null): string {
    $u = APP_BASE . '/boxes/create.php?convoy_id=' . $convoyId . '&root_id=' . $rootId;
    if ($categoryId) $u .= '&category_id=' . $categoryId;
    return $u;
}
?>

<style>
/* KPIs */
.convoy-kpis{display:flex;gap:.5rem;flex-wrap:wrap;align-items:center}
.convoy-kpi-pill{display:inline-flex;gap:.4rem;align-items:center;border:1px solid rgba(0,0,0,.1);border-radius:999px;padding:.35rem .6rem;background:#f8f9fa}
.convoy-kpi-pill strong{font-weight:600}
.convoy-subtitle{color:#6c757d;font-size:.95rem}

/* Palettes */
.palettes-toggle{display:flex;justify-content:space-between;align-items:center;gap:1rem;border:1px solid rgba(0,0,0,.08);border-radius:14px;padding:.75rem 1rem;background:#f8f9fa}

/* Table: racines vs enfants */
.root-row td{background:rgba(13,110,253,.10)!important;} /* BLEU sur toute la ligne */
.root-row .root-title{font-weight:700}
.child-label{font-weight:600}
.child-root{font-size:.85rem;color:#6c757d}
.badge-count{display:inline-block;min-width:2.2rem;text-align:center;border-radius:.5rem;padding:.25rem .45rem;background:rgba(13,110,253,.15);color:#0d6efd;font-weight:700}
.table-actions{white-space:nowrap}
.btn-round{border-radius:999px;padding:.15rem .55rem}

/* Dropdown search */
.dropdown-menu-wide{min-width:360px;max-width:460px}
.dropdown-search-wrap{padding:.5rem .75rem;border-bottom:1px solid rgba(0,0,0,.08)}
.dropdown-results{max-height:340px;overflow:auto}
.dropdown-hint{padding:.75rem;color:#6c757d;font-size:.9rem}
</style>

<div class="d-flex flex-column flex-md-row justify-content-between align-items-md-start mb-3 gap-2">
  <div>
    <h1 class="h4 mb-1"><?= htmlspecialchars($convoi['name']) ?></h1>
    <p class="text-muted mb-1">
      <?php if (!empty($convoi['destination'])): ?>
        Destination : <strong><?= htmlspecialchars($convoi['destination']) ?></strong>
      <?php else: ?>
        Destination : <em>à définir</em>
      <?php endif; ?>

      <?php if (!empty($convoi['departure_date'])): ?>
        &nbsp;•&nbsp; Date : <strong><?= htmlspecialchars($convoi['departure_date']) ?></strong>
      <?php else: ?>
        &nbsp;•&nbsp; Date : <em>à définir</em>
      <?php endif; ?>
    </p>

    <span class="badge bg-<?= badgeStatutConvoi($status) ?>">
      <?= htmlspecialchars(labelStatutConvoi($status)) ?>
    </span>

    <?php if (!$canEdit): ?>
      <span class="badge bg-light text-dark ms-1">Lecture seule</span>
      <div class="text-muted small mt-2">
        Ce convoi n’est plus en préparation : ajout/suppression de cartons et palettes verrouillés.
      </div>
    <?php endif; ?>
  </div>

  <div class="text-md-end">
    <div class="d-flex flex-wrap justify-content-md-end gap-2 mb-2">
      <a class="btn btn-outline-secondary btn-sm" href="<?= APP_BASE ?>/convoys/customs.php?id=<?= (int)$id ?>">
        Générer document de douane
      </a>

      <?php if ($canEdit): ?>
      <div class="dropdown">
        <button class="btn btn-primary btn-sm dropdown-toggle" data-bs-toggle="dropdown" aria-expanded="false" id="addCatBtn">
          + Ajouter (catégorie)
        </button>
        <div class="dropdown-menu dropdown-menu-end dropdown-menu-wide p-0" aria-labelledby="addCatBtn">
          <div class="dropdown-search-wrap">
            <input type="text" class="form-control form-control-sm" id="catSearchInput" placeholder="Rechercher une catégorie…">
            <div class="small text-muted mt-1">Astuce : tape au moins 2 lettres. La liste ne s’affiche que sur match.</div>
          </div>

          <div class="dropdown-results">
            <div class="dropdown-hint" id="catHint">Tape pour filtrer, puis clique sur une entrée.</div>
            <ul class="list-unstyled mb-0" id="catList" style="display:none;">
              <?php foreach ($rootsActive as $rt): ?>
                <?php
                  $rid = (int)$rt['id'];
                  $label = (string)$rt['label'];
                  $search = mb_strtolower($label);
                ?>
                <li>
                  <a class="dropdown-item"
                     data-search="<?= htmlspecialchars($search) ?>"
                     href="<?= htmlspecialchars(buildCreateBoxUrl((int)$id, $rid)) ?>">
                     <?= htmlspecialchars($label) ?> — <span class="text-muted">toutes</span>
                  </a>
                </li>
              <?php endforeach; ?>

              <li><hr class="dropdown-divider my-1"></li>

              <?php foreach ($children as $ch): ?>
                <?php
                  $cid = (int)$ch['id'];
                  $rid = (int)$ch['root_id'];
                  $rootLabel = (string)$ch['root_label'];
                  $label = (string)$ch['label'];
                  $breadcrumb = $rootLabel . ' › ' . $label;
                  $search = mb_strtolower($breadcrumb . ' ' . $label . ' ' . $rootLabel);
                ?>
                <li>
                  <a class="dropdown-item"
                     data-search="<?= htmlspecialchars($search) ?>"
                     href="<?= htmlspecialchars(buildCreateBoxUrl((int)$id, $rid, $cid)) ?>">
                     <?= htmlspecialchars($breadcrumb) ?>
                  </a>
                </li>
              <?php endforeach; ?>
            </ul>
          </div>
        </div>
      </div>
      <?php endif; ?>
    </div>

    <?php if ($isAdmin): ?>
      <form method="post" class="d-inline-block mb-1">
        <input type="hidden" name="action" value="change_status">
        <select name="status" class="form-select form-select-sm d-inline-block w-auto mb-1" onchange="this.form.submit()">
          <option value="preparation" <?= $status === 'preparation' ? 'selected' : '' ?>>En préparation</option>
          <option value="expedie"     <?= $status === 'expedie' ? 'selected' : '' ?>>Expédié</option>
          <option value="livre"       <?= $status === 'livre' ? 'selected' : '' ?>>Livré</option>
          <option value="archive"     <?= $status === 'archive' ? 'selected' : '' ?>>Archivé</option>
        </select>
      </form>
      <div class="form-text">Changement de statut (admin).</div>
    <?php endif; ?>
  </div>
</div>

<div class="card shadow-sm mb-3">
  <div class="card-body d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-3">
    <div class="convoy-kpis">
      <span class="convoy-kpi-pill"><strong><?= (int)$totalBoxes ?></strong> carton(s)</span>
      <span class="convoy-kpi-pill"><strong><?= (int)$totalPalettes ?></strong> palette(s) déclarée(s)</span>
      <span class="convoy-kpi-pill"><strong><?= (int)$totalCategoriesUsed ?></strong> catégorie(s)</span>
    </div>
    <div class="convoy-subtitle">Vue synthétique • mise à jour automatique.</div>
  </div>
</div>

<?php if ($isAdmin): ?>
<div class="card shadow-sm mb-3">
  <div class="card-body">
    <h2 class="h6 mb-3">Informations du convoi (admin)</h2>
    <form method="post">
      <input type="hidden" name="action" value="update_info">
      <div class="row g-3">
        <div class="col-12 col-md-4">
          <label class="form-label">Nom</label>
          <input type="text" name="name" class="form-control" value="<?= htmlspecialchars($convoi['name']) ?>">
        </div>
        <div class="col-12 col-md-4">
          <label class="form-label">Destination</label>
          <input type="text" name="destination" class="form-control" value="<?= htmlspecialchars($convoi['destination'] ?? '') ?>">
        </div>
        <div class="col-12 col-md-4 col-lg-3">
          <label class="form-label">Date (optionnelle)</label>
          <input type="date" name="departure_date" class="form-control" value="<?= htmlspecialchars($convoi['departure_date'] ?? '') ?>">
        </div>
      </div>
      <div class="row mt-3">
        <div class="col-12 text-end">
          <button class="btn btn-outline-primary px-4">Enregistrer</button>
        </div>
      </div>
    </form>
  </div>
</div>
<?php endif; ?>

<!-- Palettes repliables (fermées par défaut) -->
<div class="palettes-toggle mb-3">
  <div class="d-flex flex-column">
    <strong>Palettes déclarées</strong>
    <span class="text-muted small">Afficher uniquement si besoin.</span>
  </div>

  <div class="d-flex align-items-center gap-2">
    <span class="badge bg-dark">Total : <?= (int)$totalPalettes ?></span>
    <button class="btn btn-outline-secondary btn-sm" type="button" data-bs-toggle="collapse" data-bs-target="#palettesCollapse" aria-expanded="false">
      Afficher / masquer
    </button>
  </div>
</div>

<div class="collapse" id="palettesCollapse">
  <div class="card shadow-sm mb-3">
    <div class="card-body">
      <?php if (empty($rootsActive)): ?>
        <p class="text-muted mb-0">Aucune catégorie racine active.</p>
      <?php else: ?>
        <div class="table-responsive">
          <table class="table table-sm align-middle mb-0">
            <thead>
              <tr>
                <th>Catégorie</th>
                <th class="text-center">Palettes</th>
                <?php if ($canEdit): ?>
                  <th class="text-end">Actions</th>
                <?php endif; ?>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($rootsActive as $rt): ?>
                <?php
                  $rid = (int)$rt['id'];
                  $label = (string)$rt['label'];
                  $palReal = (int)($palettesReal[$rid] ?? 0);
                ?>
                <tr>
                  <td><strong><?= htmlspecialchars($label) ?></strong></td>
                  <td class="text-center"><?= $palReal ?></td>
                  <?php if ($canEdit): ?>
                    <td class="text-end table-actions">
                      <a class="btn btn-outline-secondary btn-sm btn-round"
                         href="<?= APP_BASE ?>/pallets/update.php?convoy_id=<?= (int)$id ?>&root_id=<?= $rid ?>&delta=-1"
                         onclick="return confirm('Retirer une palette ?');">-</a>
                      <a class="btn btn-outline-primary btn-sm btn-round"
                         href="<?= APP_BASE ?>/pallets/update.php?convoy_id=<?= (int)$id ?>&root_id=<?= $rid ?>&delta=1">+</a>
                    </td>
                  <?php endif; ?>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      <?php endif; ?>
    </div>
  </div>
</div>

<div class="d-flex flex-column flex-md-row justify-content-between align-items-md-end gap-2 mt-4 mb-2">
  <div>
    <h2 class="h5 mb-0">Cartons par catégorie</h2>
    <div class="text-muted small">Table unique, regroupée par catégorie (racine)</div>
  </div>
  <div class="text-md-end" style="min-width:280px">
    <input type="text" class="form-control form-control-sm" id="tableSearch" placeholder="Rechercher une catégorie…">
  </div>
</div>

<?php if (empty($groups)): ?>
  <p class="text-muted">Aucun carton enregistré pour ce convoi.</p>
<?php else: ?>
  <div class="card shadow-sm">
    <div class="card-body p-0">
      <div class="table-responsive">
        <table class="table table-sm align-middle mb-0" id="catsTable">
          <thead class="table-light">
            <tr>
              <th>Catégorie</th>
              <th class="text-center">Cartons</th>
              <?php if ($canEdit): ?>
                <th class="text-end">Actions</th>
              <?php endif; ?>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($groups as $rootId => $g): ?>
              <?php $rootLabel = (string)$g['root_label']; ?>
              <tr class="root-row" data-root="<?= (int)$rootId ?>" data-search="<?= htmlspecialchars(mb_strtolower($rootLabel)) ?>">
                <td class="root-title" colspan="<?= $canEdit ? 3 : 2 ?>">
                  <span class="root-title"><?= htmlspecialchars($rootLabel) ?></span>
                  <span class="text-muted"> • <?= (int)$g['root_total'] ?> carton(s)</span>
                </td>
              </tr>

              <?php foreach ($g['items'] as $it): ?>
                <?php
                  $cid = (int)$it['category_id'];
                  $label = (string)$it['label'];
                  $nb = (int)$it['nb_boxes'];
                  $search = mb_strtolower($label . ' ' . $rootLabel);
                ?>
                <tr class="child-row" data-root="<?= (int)$rootId ?>" data-search="<?= htmlspecialchars($search) ?>">
                  <td>
                    <div class="child-label"><?= htmlspecialchars($label) ?></div>
                    <div class="child-root"><?= htmlspecialchars($rootLabel) ?></div>
                  </td>
                  <td class="text-center">
                    <span class="badge-count"><?= $nb ?></span>
                  </td>
                  <?php if ($canEdit): ?>
                    <td class="text-end table-actions">
                      <a href="<?= htmlspecialchars(buildQuickAddUrl((int)$id, $cid, 1)) ?>"
                         class="btn btn-outline-secondary btn-sm btn-round" title="+1">+1</a>
                      <a href="<?= htmlspecialchars(buildCreateBoxUrl((int)$id, (int)$rootId, $cid)) ?>"
                         class="btn btn-success btn-sm btn-round" title="Ajouter…">Ajouter…</a>
                      <a href="<?= htmlspecialchars(buildQuickAddUrl((int)$id, $cid, -1)) ?>"
                         class="btn btn-outline-danger btn-sm btn-round"
                         onclick="return confirm('Retirer 1 carton de cette catégorie ?');"
                         title="-1">-1</a>
                    </td>
                  <?php endif; ?>
                </tr>
              <?php endforeach; ?>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>
<?php endif; ?>

<script>
(function(){
  // Dropdown search: masquer la liste tant qu'on n'a pas de match
  const input = document.getElementById('catSearchInput');
  const list  = document.getElementById('catList');
  const hint  = document.getElementById('catHint');

  if (input && list && hint) {
    const items = Array.from(list.querySelectorAll('a.dropdown-item[data-search]'));
    const divider = Array.from(list.querySelectorAll('hr.dropdown-divider'));

    function applyFilter(qRaw){
      const q = (qRaw || '').trim().toLowerCase();
      if (q.length < 2) {
        list.style.display = 'none';
        hint.style.display = '';
        hint.textContent = 'Tape pour filtrer, puis clique sur une entrée.';
        items.forEach(a => a.parentElement.style.display = '');
        divider.forEach(hr => hr.style.display = '');
        return;
      }

      let any = false;
      items.forEach(a => {
        const hay = (a.getAttribute('data-search') || '');
        const ok = hay.indexOf(q) !== -1;
        a.parentElement.style.display = ok ? '' : 'none';
        if (ok) any = true;
      });

      divider.forEach(hr => hr.style.display = '');

      if (any) {
        hint.style.display = 'none';
        list.style.display = '';
      } else {
        list.style.display = 'none';
        hint.style.display = '';
        hint.textContent = 'Aucun résultat. Essaie un autre terme.';
      }
    }

    input.addEventListener('input', () => applyFilter(input.value));

    document.addEventListener('shown.bs.dropdown', function(e){
      if (e.target && e.target.id === 'addCatBtn') {
        setTimeout(()=>{ input.focus(); input.select(); }, 50);
        applyFilter(input.value);
      }
    });
  }

  // Table search (roots + children)
  const tInput = document.getElementById('tableSearch');
  const table  = document.getElementById('catsTable');
  if (tInput && table) {
    const rootRows  = Array.from(table.querySelectorAll('tr.root-row'));
    const childRows = Array.from(table.querySelectorAll('tr.child-row'));

    function update(){
      const q = (tInput.value || '').trim().toLowerCase();

      childRows.forEach(tr => {
        const hay = tr.getAttribute('data-search') || '';
        tr.style.display = (!q || hay.indexOf(q) !== -1) ? '' : 'none';
      });

      rootRows.forEach(rtr => {
        const rootId = rtr.getAttribute('data-root');
        const hay = rtr.getAttribute('data-search') || '';
        const matchRoot = (!q || hay.indexOf(q) !== -1);
        const hasVisibleChild = childRows.some(ch => ch.getAttribute('data-root') === rootId && ch.style.display !== 'none');
        rtr.style.display = (matchRoot && (!q || hasVisibleChild || matchRoot)) || hasVisibleChild ? '' : 'none';
      });
    }

    tInput.addEventListener('input', update);
  }
})();
</script>

<?php require __DIR__ . '/../footer.php'; ?>