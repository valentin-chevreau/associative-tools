<?php
declare(strict_types=1);

// shared/suite_nav.php
// Nav unifiée (incluable). PAS de <html>/<head>/<body> ici.

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/nav.config.php';

if (!function_exists('suite_h')) {
    function suite_h(string $s): string {
        return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
    }
}

/**
 * SVG inline (stroke only) — rendu propre dans des pills
 */
function nav_svg(string $name): string {
    $common = 'class="suite-ico" viewBox="0 0 24 24" aria-hidden="true" focusable="false"';
    return match ($name) {
        'calendar' => '<svg '.$common.'><path d="M7 3v2M17 3v2M4 8h16M6 5h12a2 2 0 0 1 2 2v13a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V7a2 2 0 0 1 2-2z"/></svg>',
        'truck'    => '<svg '.$common.'><path d="M3 7h11v10H3z"/><path d="M14 10h4l2 3v4h-6z"/><path d="M7 17a2 2 0 1 0 0 .01M17 17a2 2 0 1 0 0 .01"/></svg>',
        'box'      => '<svg '.$common.'><path d="M12 3 3 7.5 12 12l9-4.5L12 3z"/><path d="M3 7.5V17l9 4 9-4V7.5"/><path d="M12 12v9"/></svg>',
        'tag'      => '<svg '.$common.'><path d="M20 12l-8 8-10-10V4h6l12 8z"/><path d="M7.5 7.5h.01"/></svg>',
        'cash'     => '<svg '.$common.'><path d="M4 7h16v10H4z"/><path d="M8 12h.01M16 12h.01"/><path d="M10 12a2 2 0 0 0 4 0 2 2 0 0 0-4 0z"/></svg>',
        'users'    => '<svg '.$common.'><path d="M16 11a3 3 0 1 0-6 0 3 3 0 0 0 6 0z"/><path d="M2 20a6 6 0 0 1 12 0"/><path d="M17 20a5 5 0 0 1 5 0"/></svg>',
        default    => '',
    };
}

$items = function_exists('nav_visible_items') ? nav_visible_items() : [];
$base  = function_exists('suite_base') ? suite_base() : '';
$path  = (string)($_SERVER['REQUEST_URI'] ?? ($_SERVER['PHP_SELF'] ?? ''));

$role = function_exists('current_role') ? (string)current_role() : 'public';
$roleLabel = match ($role) {
    'admin_plus' => 'Admin+',
    'admin'      => 'Admin',
    default      => 'Bénévole',
};

$loginHref  = function_exists('suite_login_url')  ? suite_login_url()  : ($base . '/admin/login.php');
$logoutHref = function_exists('suite_logout_url') ? suite_logout_url() : ($base . '/admin/logout.php');

function suite_href_path(string $href): string {
    $hrefNoQuery = explode('?', $href, 2)[0];
    $p = parse_url($hrefNoQuery, PHP_URL_PATH);
    return is_string($p) ? $p : $hrefNoQuery;
}

function suite_is_active_href(string $href, string $currentPath): bool {
    if ($href === '') return false;
    $hp = suite_href_path($href);
    if ($hp === '') return false;
    // match "contains" mais sécurisé: compare sur chemin (pas query)
    $cp = suite_href_path($currentPath);
    return ($cp !== '' && strpos($cp, $hp) !== false);
}

function suite_item_is_active(array $it, string $currentPath): bool {
    if (!empty($it['href']) && is_string($it['href']) && suite_is_active_href($it['href'], $currentPath)) {
        return true;
    }
    if (!empty($it['children']) && is_array($it['children'])) {
        foreach ($it['children'] as $ch) {
            if (!empty($ch['href']) && is_string($ch['href']) && suite_is_active_href($ch['href'], $currentPath)) {
                return true;
            }
        }
    }
    return false;
}
?>
<style>
/* IMPORTANT: tout est préfixé .suite-nav-wrap => aucune fuite CSS vers les pages */
.suite-nav-wrap{
  position: sticky;
  top: 0;
  z-index: 999;
  background: linear-gradient(180deg,#ffffff,#f6f7fb);
  border-bottom: 1px solid rgba(15,23,42,.08);
}

.suite-nav-wrap .suite-nav{
  max-width: 1200px;
  margin: 0 auto;
  padding: 10px 16px;
  display:flex;
  align-items:center;
  justify-content:space-between;
  gap: 14px;
  font-family: system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
}

.suite-nav-wrap .suite-left{
  display:flex;
  align-items:center;
  gap: 10px;
  flex-wrap: wrap;
  min-width: 0;
}

.suite-nav-wrap .suite-brand{
  display:flex;
  align-items:center;
  gap: 10px;
  text-decoration:none;
  color:#111827;
  font-weight: 900;
  white-space: nowrap;
}

.suite-nav-wrap .suite-logo{
  width: 34px;
  height: 34px;
  border-radius: 12px;
  background: linear-gradient(135deg,#2563eb,#22c55e);
  color:#fff;
  display:flex;
  align-items:center;
  justify-content:center;
  font-weight: 900;
  letter-spacing: .02em;
}

.suite-nav-wrap .suite-items{
  display:flex;
  align-items:center;
  gap: 10px;
  flex-wrap: wrap;
}

.suite-nav-wrap .suite-chip{
  display:inline-flex;
  align-items:center;
  gap: 8px;
  padding: 8px 12px;          /* <-- plus compact */
  border-radius: 999px;
  background:#fff;
  border: 1px solid rgba(15,23,42,.10);
  box-shadow: 0 6px 18px rgba(15,23,42,.06);
  color:#111827;
  text-decoration:none;
  font-size: 13px;            /* <-- plus compact */
  font-weight: 800;
  line-height: 1;
  cursor: pointer;
  user-select: none;
  -webkit-tap-highlight-color: transparent;
}

.suite-nav-wrap .suite-chip:hover{
  transform: translateY(-1px);
}

.suite-nav-wrap .suite-chip:focus-visible{
  outline: 2px solid rgba(37,99,235,.40);
  outline-offset: 2px;
}

.suite-nav-wrap .suite-chip.active{
  border-color: rgba(37,99,235,.32);
  box-shadow: 0 10px 22px rgba(37,99,235,.10);
}

.suite-nav-wrap .suite-ico{
  width: 18px;
  height: 18px;
  stroke: currentColor;
  fill: none;
  stroke-width: 2;
  stroke-linecap: round;
  stroke-linejoin: round;
  flex: 0 0 auto;
}

.suite-nav-wrap .suite-caret{
  opacity: .7;
  font-weight: 900;
  margin-left: 2px;
}

.suite-nav-wrap .suite-menu{
  position: relative;
}

.suite-nav-wrap .suite-menu > summary{
  list-style: none;
}

.suite-nav-wrap .suite-menu > summary::-webkit-details-marker{
  display:none;
}

.suite-nav-wrap .suite-dd{
  position: absolute;
  top: 44px;
  left: 0;
  z-index: 9999;
  min-width: 260px;
  background:#fff;
  color:#111827;
  border-radius: 16px;
  padding: 8px;
  border: 1px solid rgba(15,23,42,.10);
  box-shadow: 0 16px 40px rgba(15,23,42,.18);
}

.suite-nav-wrap .suite-dd a{
  display:flex;
  gap: 10px;
  align-items:center;
  padding: 10px 12px;
  border-radius: 12px;
  text-decoration:none;
  color:#111827;
  font-size: 13px;
  font-weight: 700;
}

.suite-nav-wrap .suite-dd a:hover{
  background:#f3f4f6;
}

.suite-nav-wrap .suite-dd .suite-dd-group{
  margin: 6px 8px 4px;
  font-size: 11px;
  font-weight: 900;
  letter-spacing: .08em;
  text-transform: uppercase;
  color: rgba(17,24,39,.55);
}

.suite-nav-wrap .suite-dd .suite-dd-sep{
  height: 1px;
  background: rgba(15,23,42,.08);
  margin: 6px 6px;
  border-radius: 999px;
}

.suite-nav-wrap .suite-right{
  display:flex;
  align-items:center;
  gap: 10px;
  flex-wrap: wrap;
  justify-content:flex-end;
  min-width: 0;
}

.suite-nav-wrap .suite-badge{
  display:inline-flex;
  align-items:center;
  gap: 8px;
  padding: 8px 12px;     /* compact */
  border-radius: 999px;
  background:#fff;
  border: 1px solid rgba(15,23,42,.10);
  font-size: 12px;
  font-weight: 900;
  color:#111827;
  white-space: nowrap;
}

.suite-nav-wrap .suite-dot{
  width: 10px;
  height: 10px;
  border-radius: 50%;
}

.suite-nav-wrap .suite-btn{
  padding: 8px 12px;     /* compact */
  border-radius: 999px;
  border: 1px solid rgba(15,23,42,.12);
  background:#fff;
  color:#111827;
  font-size: 12px;
  font-weight: 900;
  text-decoration:none;
  white-space: nowrap;
}

.suite-nav-wrap .suite-btn.primary{
  border-color: transparent;
  background: linear-gradient(135deg,#2563eb,#3b82f6);
  color:#fff;
}

.suite-nav-wrap .suite-btn.danger{
  border-color: rgba(239,68,68,.30);
  color:#b91c1c;
  background:#fff;
}

@media (max-width: 720px){
  .suite-nav-wrap .suite-nav{
    align-items: flex-start;
  }
  .suite-nav-wrap .suite-dd{
    left: auto;
    right: 0;
    min-width: 240px;
  }
}
</style>

<div class="suite-nav-wrap no-print">
  <nav class="suite-nav no-print" aria-label="Navigation Suite">
    <div class="suite-left">
      <a class="suite-brand" href="<?= suite_h($base . '/index.php') ?>">
        <span class="suite-logo">TU</span>
        <span>Touraine-Ukraine</span>
      </a>

      <div class="suite-items">
        <?php foreach ($items as $it): ?>
          <?php
            if (!is_array($it)) continue;
            $label    = (string)($it['label'] ?? '');
            $iconName = (string)($it['icon'] ?? '');
            $href     = (string)($it['href'] ?? '');
            $children = $it['children'] ?? null;
            $active   = suite_item_is_active($it, $path);
          ?>

          <?php if (is_array($children) && count($children) > 0): ?>
            <?php
              // Group "Admin" séparé visuellement si présent via $ch['group'] === 'Admin'
              $adminGroup = [];
              $mainGroup  = [];
              foreach ($children as $ch) {
                if (!is_array($ch)) continue;
                $grp = (string)($ch['group'] ?? '');
                if ($grp === 'Admin') $adminGroup[] = $ch;
                else $mainGroup[] = $ch;
              }
            ?>
            <details class="suite-menu" <?= $active ? 'open' : '' ?>>
              <summary class="suite-chip <?= $active ? 'active' : '' ?>">
                <?= nav_svg($iconName) ?>
                <?= suite_h($label) ?>
                <span class="suite-caret">▾</span>
              </summary>

              <div class="suite-dd" role="menu">
                <?php foreach ($mainGroup as $ch): ?>
                  <?php
                    $cl    = (string)($ch['label'] ?? '');
                    $chref = (string)($ch['href'] ?? '');
                    if ($chref === '') continue;
                  ?>
                  <a href="<?= suite_h($chref) ?>"><?= suite_h($cl) ?></a>
                <?php endforeach; ?>

                <?php if (!empty($adminGroup)): ?>
                  <div class="suite-dd-sep"></div>
                  <div class="suite-dd-group">Admin</div>
                  <?php foreach ($adminGroup as $ch): ?>
                    <?php
                      $cl    = (string)($ch['label'] ?? '');
                      $chref = (string)($ch['href'] ?? '');
                      if ($chref === '') continue;
                    ?>
                    <a href="<?= suite_h($chref) ?>"><?= suite_h($cl) ?></a>
                  <?php endforeach; ?>
                <?php endif; ?>
              </div>
            </details>
          <?php else: ?>
            <a class="suite-chip <?= $active ? 'active' : '' ?>" href="<?= suite_h($href) ?>">
              <?= nav_svg($iconName) ?>
              <?= suite_h($label) ?>
            </a>
          <?php endif; ?>
        <?php endforeach; ?>
      </div>
    </div>

    <div class="suite-right">
      <span class="suite-badge">
        <span class="suite-dot" style="background:<?= suite_h($role === 'public' ? '#9ca3af' : ($role === 'admin' ? '#f59e0b' : '#22c55e')) ?>;"></span>
        <?= suite_h($roleLabel) ?>
      </span>

      <?php if ($role === 'public'): ?>
        <a class="suite-btn primary" href="<?= suite_h($loginHref) ?>">Saisir code admin</a>
      <?php else: ?>
        <a class="suite-btn danger" href="<?= suite_h($logoutHref) ?>">Déconnexion</a>
      <?php endif; ?>
    </div>
  </nav>
</div>

<script>
(function(){
  // Fermer les autres dropdowns quand on en ouvre un
  document.querySelectorAll('.suite-nav-wrap .suite-menu').forEach(function(d){
    d.addEventListener('toggle', function(){
      if (!d.open) return;
      document.querySelectorAll('.suite-nav-wrap .suite-menu').forEach(function(other){
        if (other !== d) other.open = false;
      });
    });
  });

  // Fermer si clic en dehors
  document.addEventListener('click', function(e){
    var inside = e.target.closest && e.target.closest('.suite-nav-wrap .suite-menu');
    if (inside) return;
    document.querySelectorAll('.suite-nav-wrap .suite-menu').forEach(function(d){ d.open = false; });
  });
})();
</script>