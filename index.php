<?php
declare(strict_types=1);

require_once __DIR__ . '/shared/bootstrap.php';

if (!is_admin()) {
    $next     = $_SERVER['REQUEST_URI'] ?? '';
    $loginUrl = suite_login_url();
    if ($next && strpos($loginUrl, '?') === false) $loginUrl .= '?next=' . urlencode($next);
    header('Location: ' . $loginUrl);
    exit;
}

require_once __DIR__ . '/shared/nav.config.php';
require_once __DIR__ . '/shared/suite_nav.php';

function h(string $s): string { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }

$base = function_exists('suite_base') ? suite_base() : '';

$items = function_exists('nav_visible_items') ? nav_visible_items() : [];
if (!is_array($items)) $items = [];

function portal_item_href(array $it): string {
    if (!empty($it['href'])) return (string)$it['href'];
    if (!empty($it['children'][0]['href'])) return (string)$it['children'][0]['href'];
    return function_exists('suite_base') ? suite_base() . '/' : '/';
}

function portal_item_desc(array $it): string {
    if (!empty($it['children']) && is_array($it['children'])) {
        $labels = array_slice(array_values(array_filter(array_map(fn($c) => (string)($c['label'] ?? ''), $it['children']))), 0, 4);
        return $labels ? implode(' · ', $labels) : 'Ouvrir le module';
    }
    return 'Ouvrir le module';
}

$priority = [
    'Planning' => 10, 'Convois' => 20, 'Stock local' => 30, 'Étiquettes' => 40,
    'Caisse' => 50, 'Annuaire' => 55, 'Adhésions' => 60, 'Subventions' => 65,
    'Dons' => 70, "Rapport d'activité" => 80,
];

usort($items, function($a, $b) use ($priority) {
    $pa = $priority[(string)($a['label'] ?? '')] ?? 999;
    $pb = $priority[(string)($b['label'] ?? '')] ?? 999;
    return $pa !== $pb ? $pa <=> $pb : strcmp((string)($a['label']??''), (string)($b['label']??''));
});

$publicItems = array_values(array_filter($items, fn($it) => ((string)($it['min_role'] ?? 'public')) === 'public'));
$adminItems  = array_values(array_filter($items, fn($it) => ((string)($it['min_role'] ?? 'public')) !== 'public'));

$role = function_exists('current_role') ? current_role() : 'public';
$isAdminPlus = ($role === 'admin_plus');
?>
<!DOCTYPE html>
<html lang="fr">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Suite Touraine-Ukraine</title>
  <link rel="stylesheet" href="<?= h($base) ?>/assets/css/suite_nav.css">
</head>
<body class="tu-v2">

<?php suite_nav_render('', ''); ?>

<div class="tu-main">

  <div class="tu-topbar">
    <div class="tu-bc">
      <span class="tu-bc-cur">Accueil</span>
    </div>
  </div>

  <div class="tu-pg">

    <div class="tu-ph">
      <div>
        <div class="tu-ph-title">Bonjour 👋</div>
        <div class="tu-ph-sub">Que souhaitez-vous faire aujourd'hui ?</div>
      </div>
    </div>

    <?php if (!empty($publicItems)): ?>
      <div style="font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.1em;color:var(--tu-ink-300);margin-bottom:12px;">Modules</div>
      <div class="tu-portal-grid tu-mb4">
        <?php foreach ($publicItems as $it): ?>
          <a href="<?= h(portal_item_href($it)) ?>" class="tu-portal-card">
            <div class="tu-pc-ico">
              <?= card_icon_emoji((string)($it['icon'] ?? '')) ?>
            </div>
            <div class="tu-pc-name"><?= h((string)($it['label'] ?? '')) ?></div>
            <div class="tu-pc-desc"><?= h(portal_item_desc($it)) ?></div>
            <div class="tu-pc-foot"><span class="tu-pc-link">Ouvrir →</span></div>
          </a>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>

    <?php if (!empty($adminItems)): ?>
      <div style="font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.1em;color:var(--tu-ink-300);margin-bottom:12px;">Administration</div>
      <div class="tu-portal-grid">
        <?php foreach ($adminItems as $it): ?>
          <a href="<?= h(portal_item_href($it)) ?>" class="tu-portal-card tu-portal-card-admin">
            <div class="tu-pc-ico">
              <?= card_icon_emoji((string)($it['icon'] ?? '')) ?>
            </div>
            <div class="tu-pc-name"><?= h((string)($it['label'] ?? '')) ?></div>
            <div class="tu-pc-desc"><?= h(portal_item_desc($it)) ?></div>
            <div class="tu-pc-foot">
              <span class="tu-pc-link">Ouvrir →</span>
              <span class="tu-bdg tu-bdg-amber" style="font-size:10px;"><?= h((string)($it['min_role'] ?? 'admin')) ?></span>
            </div>
          </a>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>

  </div>
</div>

<?php
function card_icon_emoji(string $name): string {
    return match($name) {
        'calendar'  => '📅',
        'truck'     => '🚛',
        'box'       => '📦',
        'tag'       => '🏷',
        'cash'      => '💰',
        'users'     => '👥',
        'id-card'   => '🪪',
        'briefcase' => '🗂',
        default     => '⚙️',
    };
}
?>

<style>
.tu-portal-grid {
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(210px, 1fr));
  gap: 12px;
}
.tu-portal-card {
  background: var(--tu-raised);
  border: 1px solid var(--tu-ink-100);
  border-radius: var(--tu-r-lg);
  padding: 18px;
  cursor: pointer;
  transition: all .2s;
  text-decoration: none;
  display: block;
  box-shadow: var(--tu-shadow);
}
.tu-portal-card:hover {
  box-shadow: var(--tu-shadow-lg);
  transform: translateY(-2px);
  border-color: var(--tu-amber-400);
}
.tu-portal-card-admin {
  border-color: rgba(232,146,74,.22);
  background: linear-gradient(135deg, #fffef9, #fdf5e8);
}
.tu-pc-ico {
  width: 42px; height: 42px;
  border-radius: 12px;
  background: var(--tu-sand-100);
  display: flex; align-items: center; justify-content: center;
  margin-bottom: 12px;
  font-size: 20px;
}
.tu-portal-card-admin .tu-pc-ico { background: rgba(232,146,74,.14); }
.tu-pc-name {
  font-family: var(--tu-font-d);
  font-size: 14.5px; font-weight: 700;
  color: var(--tu-ink-900); margin-bottom: 4px;
}
.tu-pc-desc { font-size: 12px; color: var(--tu-ink-300); line-height: 1.5; }
.tu-pc-foot {
  margin-top: 12px;
  display: flex; align-items: center; justify-content: space-between;
}
.tu-pc-link { font-size: 12px; font-weight: 700; color: var(--tu-amber-500); }
</style>

</body>
</html>
