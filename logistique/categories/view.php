<?php
// categories/view.php — référentiel public + actions admin only + recherche + affichage "chips/cards" + compteur + filtre inactives

if (session_status() === PHP_SESSION_NONE) session_start();
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../header.php';

$isAdmin = !empty($_SESSION['is_admin']);

function h($s): string {
  return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
}

// APP_BASE (pour /preprod-logistique/ vs /logistique/)
if (!defined('APP_BASE')) {
  $script = $_SERVER['SCRIPT_NAME'] ?? '';
  // ex: /preprod-logistique/categories/view.php -> APP_BASE=/preprod-logistique
  $base = str_replace('\\', '/', dirname(dirname($script)));
  $base = rtrim($base, '/');
  define('APP_BASE', $base === '' ? '' : $base);
}

// Toutes les catégories (racines + sous-catégories)
$all = $pdo->query("
  SELECT id, label, COALESCE(label_ua,'') AS label_ua, parent_id, is_active
  FROM categories
  ORDER BY
    CASE WHEN parent_id IS NULL THEN 0 ELSE 1 END,
    label ASC
")->fetchAll(PDO::FETCH_ASSOC);

// Indexation
$roots = [];
$childrenByRoot = [];
foreach ($all as $c) {
  $id = (int)$c['id'];
  $parentId = $c['parent_id'] !== null ? (int)$c['parent_id'] : null;

  if ($parentId === null) {
    $roots[$id] = $c;
    if (!isset($childrenByRoot[$id])) $childrenByRoot[$id] = [];
  } else {
    if (!isset($childrenByRoot[$parentId])) $childrenByRoot[$parentId] = [];
    $childrenByRoot[$parentId][] = $c;
  }
}
?>

<style>
/* Option B UI */
.cat-search-card { border:1px solid rgba(0,0,0,.08); }
.root-card { border:1px solid rgba(0,0,0,.08); }
.root-header { display:flex; align-items:flex-start; justify-content:space-between; gap:12px; }
.root-title { margin:0; }
.ua-line { margin-top:4px; }
.child-grid { display:grid; grid-template-columns: repeat(2, minmax(0,1fr)); gap:10px; }
@media (max-width: 768px){
  .child-grid { grid-template-columns: 1fr; }
}

.child-chip {
  border:1px solid rgba(0,0,0,.10);
  border-radius:12px;
  padding:10px 12px;
  background:#fff;
  display:flex;
  align-items:flex-start;
  justify-content:space-between;
  gap:10px;
}
.child-chip:hover { box-shadow:0 8px 24px rgba(0,0,0,.06); }

.child-main { min-width:0; }
.child-fr { font-weight:700; line-height:1.2; }
.child-ua { font-size:.86rem; color:#6c757d; margin-top:4px; line-height:1.2; }
.child-actions { display:flex; flex-wrap:wrap; justify-content:flex-end; gap:6px; }

.badge-inactive { background:#6c757d; }
.muted-empty { color:#6c757d; font-size:.9rem; }

.controls-row{
  display:flex;
  align-items:center;
  justify-content:space-between;
  gap:12px;
  flex-wrap:wrap;
}
.kpis{
  font-size:12px;
  color:#555;
}
.kpis strong{ color:#111; }
.toggle-wrap{
  display:flex;
  align-items:center;
  gap:10px;
  margin-top:8px;
}
.form-switch .form-check-input{ cursor:pointer; }
</style>

<div class="container" style="max-width:1100px;">

  <div class="d-flex flex-column flex-md-row justify-content-between align-items-md-end gap-2 mb-3">
    <div>
      <h1 class="h4 mb-1">Catégories</h1>
      <div class="text-muted small">
        Référentiel consultable par tous. Les actions de gestion sont réservées à l’admin.
      </div>
    </div>

    <?php if ($isAdmin): ?>
      <div class="d-flex gap-2">
        <a class="btn btn-primary btn-sm" href="create.php?mode=root">+ Catégorie racine</a>
        <a class="btn btn-outline-primary btn-sm" href="create.php?mode=child">+ Sous-catégorie</a>
      </div>
    <?php endif; ?>
  </div>

  <!-- Recherche + options -->
  <div class="card shadow-sm cat-search-card mb-3">
    <div class="card-body">
      <div class="controls-row">
        <div style="flex:1; min-width:260px;">
          <label class="form-label mb-1">Rechercher</label>
          <input type="search" id="catSearch" class="form-control"
                 placeholder="Tape un mot-clé… (FR ou UA)">
          <div class="form-text">Filtre en direct. (Échap = effacer)</div>
        </div>

        <div class="kpis" id="kpis">
          <div>Racines affichées : <strong id="kpiRoots">0</strong></div>
          <div>Sous-catégories affichées : <strong id="kpiChildren">0</strong></div>
        </div>
      </div>

      <div class="toggle-wrap">
        <div class="form-check form-switch m-0">
          <input class="form-check-input" type="checkbox" id="hideInactive">
          <label class="form-check-label" for="hideInactive">Masquer les inactives</label>
        </div>
        <span class="text-muted small">Utile pour simplifier le référentiel.</span>
      </div>
    </div>
  </div>

  <?php if (empty($roots)): ?>
    <div class="alert alert-warning">Aucune catégorie.</div>
  <?php else: ?>

    <?php foreach ($roots as $rootId => $root): ?>
      <?php
        $rootActive = (int)$root['is_active'] === 1;
        $rootFr = (string)$root['label'];
        $rootUa = trim((string)$root['label_ua']);
        $children = $childrenByRoot[$rootId] ?? [];

        $rootSearch = mb_strtolower($rootFr . ' ' . $rootUa, 'UTF-8');
      ?>

      <div class="card shadow-sm mb-3 root-card root-block"
           data-root="1"
           data-active="<?= $rootActive ? '1' : '0' ?>"
           data-search="<?= h($rootSearch) ?>">
        <div class="card-body">

          <div class="root-header">
            <div>
              <div class="d-flex align-items-center gap-2">
                <h2 class="h6 root-title"><?= h($rootFr) ?></h2>
                <?php if (!$rootActive): ?>
                  <span class="badge badge-inactive">Inactive</span>
                <?php endif; ?>
              </div>

              <?php if ($rootUa !== ''): ?>
                <div class="text-muted small ua-line"><?= h($rootUa) ?></div>
              <?php endif; ?>
            </div>

            <?php if ($isAdmin): ?>
              <div class="text-end">
                <a class="btn btn-sm btn-outline-secondary mb-1" href="update.php?id=<?= (int)$rootId ?>">Modifier</a>
                <a class="btn btn-sm btn-outline-danger mb-1" href="delete.php?id=<?= (int)$rootId ?>"
                   onclick="return confirm('Supprimer cette catégorie racine ?');">Supprimer</a>
              </div>
            <?php endif; ?>
          </div>

          <hr class="my-3">

          <?php if (empty($children)): ?>
            <div class="muted-empty">Aucune sous-catégorie.</div>
          <?php else: ?>

            <div class="child-grid">
              <?php foreach ($children as $ch): ?>
                <?php
                  $cid = (int)$ch['id'];
                  $active = (int)$ch['is_active'] === 1;
                  $fr = (string)$ch['label'];
                  $ua = trim((string)$ch['label_ua']);
                  $search = mb_strtolower($fr . ' ' . $ua, 'UTF-8');
                ?>
                <div class="child-chip child-row"
                     data-child="1"
                     data-active="<?= $active ? '1' : '0' ?>"
                     data-search="<?= h($search) ?>">
                  <div class="child-main">
                    <div class="d-flex align-items-center gap-2">
                      <div class="child-fr"><?= h($fr) ?></div>
                      <?php if (!$active): ?>
                        <span class="badge badge-inactive">Inactive</span>
                      <?php endif; ?>
                    </div>

                    <?php if ($ua !== ''): ?>
                      <div class="child-ua"><?= h($ua) ?></div>
                    <?php endif; ?>
                  </div>

                  <div class="child-actions">
                    <?php if ($active && $ua !== ''): ?>
                      <a class="btn btn-sm btn-outline-primary"
                         href="<?= APP_BASE ?>/labels/labels.php?category_id=<?= (int)$cid ?>&qty=4&do_print=1"
                         target="_blank" rel="noopener">
                        Étiquette ×4
                      </a>
                    <?php endif; ?>

                    <?php if ($isAdmin): ?>
                      <a class="btn btn-sm btn-outline-secondary" href="update.php?id=<?= (int)$cid ?>">Modifier</a>
                      <a class="btn btn-sm btn-outline-danger" href="delete.php?id=<?= (int)$cid ?>"
                         onclick="return confirm('Supprimer cette sous-catégorie ?');">Supprimer</a>
                    <?php endif; ?>
                  </div>
                </div>
              <?php endforeach; ?>
            </div>

          <?php endif; ?>

        </div>
      </div>

    <?php endforeach; ?>

  <?php endif; ?>

</div>

<script>
(function(){
  const input = document.getElementById('catSearch');
  const hideInactive = document.getElementById('hideInactive');
  const kpiRoots = document.getElementById('kpiRoots');
  const kpiChildren = document.getElementById('kpiChildren');

  if (!input || !hideInactive) return;

  const rootBlocks = Array.from(document.querySelectorAll('.root-block'));

  function norm(s){
    return (s || '').toString().toLowerCase().trim();
  }

  function isActive(el){
    return (el.getAttribute('data-active') || '1') === '1';
  }

  function updateKpis(){
    let rootsShown = 0;
    let childrenShown = 0;

    rootBlocks.forEach(root => {
      if (root.style.display === 'none') return;
      rootsShown++;

      const children = Array.from(root.querySelectorAll('.child-row'));
      children.forEach(ch => {
        if (ch.style.display === 'none') return;
        childrenShown++;
      });
    });

    kpiRoots.textContent = String(rootsShown);
    kpiChildren.textContent = String(childrenShown);
  }

  function apply(){
    const q = norm(input.value);
    const hideInactives = !!hideInactive.checked;

    // reset display
    rootBlocks.forEach(root => {
      root.style.display = '';
      const children = Array.from(root.querySelectorAll('.child-row'));
      children.forEach(ch => ch.style.display = '');
    });

    // 1) filtre inactives (si activé)
    if (hideInactives) {
      rootBlocks.forEach(root => {
        // si racine inactive => on masque tout le bloc, point.
        if (!isActive(root)) {
          root.style.display = 'none';
          return;
        }
        // sinon on masque uniquement les enfants inactifs
        const children = Array.from(root.querySelectorAll('.child-row'));
        children.forEach(ch => {
          if (!isActive(ch)) ch.style.display = 'none';
        });
      });
    }

    // 2) filtre recherche
    if (q) {
      rootBlocks.forEach(root => {
        if (root.style.display === 'none') return; // déjà masqué (inactif)

        const rootSearch = norm(root.getAttribute('data-search'));
        const children = Array.from(root.querySelectorAll('.child-row'))
          .filter(ch => ch.style.display !== 'none'); // tenir compte "hideInactive"

        const rootMatch = rootSearch.includes(q);

        let anyChildMatch = false;

        children.forEach(ch => {
          const s = norm(ch.getAttribute('data-search'));
          const match = s.includes(q);

          if (!rootMatch) {
            ch.style.display = match ? '' : 'none';
          }
          if (match) anyChildMatch = true;
        });

        if (rootMatch) {
          root.style.display = '';
          children.forEach(ch => ch.style.display = '');
        } else {
          root.style.display = anyChildMatch ? '' : 'none';
        }
      });
    } else {
      // pas de recherche : si hideInactive est ON, on peut se retrouver avec une racine active
      // mais tous les enfants masqués => on laisse la racine visible (référentiel)
    }

    updateKpis();
  }

  input.addEventListener('input', apply);
  hideInactive.addEventListener('change', apply);

  input.addEventListener('keydown', (e) => {
    if (e.key === 'Escape') {
      input.value = '';
      apply();
      input.blur();
    }
  });

  // init
  apply();
})();
</script>

<?php require_once __DIR__ . '/../footer.php'; ?>