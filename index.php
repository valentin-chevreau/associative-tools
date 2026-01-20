<?php
declare(strict_types=1);

/**
 * preprod-tools/index.php (ou tools/index.php)
 * - affiche la nav unifiée (shared/suite_nav.php)
 * - affiche les modules sous forme de cards (à partir de nav_visible_items())
 */

require_once __DIR__ . '/shared/bootstrap.php';
require_once __DIR__ . '/shared/nav.config.php';
require_once __DIR__ . '/shared/suite_nav.php';

function h(string $s): string { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }

/* Rôles (suite) */
function current_role_safe(): string {
    return function_exists('current_role') ? (string)current_role() : 'public';
}
function role_level(string $role): int {
    return match ($role) {
        'admin_plus' => 3,
        'admin'      => 2,
        default      => 1, // public
    };
}

/* SVG pour les cards (mapping icône -> svg) */
function card_icon_svg(string $name): string {
    return match ($name) {
        'calendar' => '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M7 3v2M17 3v2M4 8h16M6 5h12a2 2 0 0 1 2 2v13a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V7a2 2 0 0 1 2-2Z" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"/></svg>',
        'truck'    => '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M3 7h11v10H3V7Zm11 3h4l3 3v4h-7V10Z" fill="none" stroke="currentColor" stroke-width="2" stroke-linejoin="round"/><path d="M7 17a2 2 0 1 0 0 4 2 2 0 0 0 0-4Zm12 0a2 2 0 1 0 0 4 2 2 0 0 0 0-4Z" fill="none" stroke="currentColor" stroke-width="2"/></svg>',
        'box'      => '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M21 8 12 3 3 8v10l9 5 9-5V8Z" fill="none" stroke="currentColor" stroke-width="2" stroke-linejoin="round"/><path d="M3 8l9 5 9-5M12 13v10" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"/></svg>',
        'tag'      => '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M20 13 11 22 2 13V2h11l9 11Z" fill="none" stroke="currentColor" stroke-width="2" stroke-linejoin="round"/><path d="M7 7h.01" fill="none" stroke="currentColor" stroke-width="4" stroke-linecap="round"/></svg>',
        'cash'     => '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M3 7h18v10H3V7Z" fill="none" stroke="currentColor" stroke-width="2"/><path d="M12 10a2 2 0 1 0 0 4 2 2 0 0 0 0-4Z" fill="none" stroke="currentColor" stroke-width="2"/></svg>',
        'users'    => '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M16 11a4 4 0 1 0-8 0" fill="none" stroke="currentColor" stroke-width="2"/><path d="M2 21a7 7 0 0 1 20 0" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"/></svg>',
        default    => '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 12h16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"/></svg>',
    };
}

$items = function_exists('nav_visible_items') ? nav_visible_items() : [];
if (!is_array($items)) $items = [];

function portal_item_href(array $it): string {
    if (!empty($it['href'])) return (string)$it['href'];
    if (!empty($it['children']) && is_array($it['children']) && !empty($it['children'][0]['href'])) {
        return (string)$it['children'][0]['href'];
    }
    return function_exists('suite_base') ? (suite_base() . '/') : '/';
}

function portal_item_desc(array $it): string {
    if (!empty($it['children']) && is_array($it['children'])) {
        $labels = array_map(fn($c) => (string)($c['label'] ?? ''), $it['children']);
        $labels = array_values(array_filter($labels, fn($s) => $s !== ''));
        $labels = array_slice($labels, 0, 4);
        return $labels ? implode(' · ', $labels) : 'Ouvrir le module';
    }
    return 'Ouvrir le module';
}

$priority = [
    'Planning'           => 10,
    'Convois'            => 20,
    'Stock local'        => 30,
    'Étiquettes'         => 40,
    'Caisse'             => 50,
    'Annuaire'           => 55,
    'Dons'               => 60,
    'Rapport d’activité' => 70,
];

usort($items, function($a, $b) use ($priority) {
    $la = (string)($a['label'] ?? '');
    $lb = (string)($b['label'] ?? '');
    $pa = $priority[$la] ?? 999;
    $pb = $priority[$lb] ?? 999;
    if ($pa === $pb) return strcmp($la, $lb);
    return $pa <=> $pb;
});

function is_public_item(array $it): bool {
    // IMPORTANT: si min_role absent => on considère public (sinon tu perds tout)
    $min = (string)($it['min_role'] ?? 'public');
    return $min === 'public';
}

$benevole   = array_values(array_filter($items, fn($it) => is_public_item($it)));
$adminItems = array_values(array_filter($items, fn($it) => !is_public_item($it)));

$role      = current_role_safe();
$roleLevel = role_level($role);
?>
<style>
  .suite-home-wrap{
    font-family:system-ui,-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif;
    background:#f5f6f8;
    min-height:calc(100vh - 54px);
    padding:22px 18px;
  }
  .suite-home-inner{ max-width:1100px;margin:0 auto; }
  .suite-home-title{ font-size:34px;font-weight:900;line-height:1.1;margin:10px 0 6px; }
  .suite-home-sub{ opacity:.75; margin-bottom:18px; }
  .suite-home-h2{ margin:18px 0 10px;font-weight:800;font-size:16px; }
  .suite-grid{ display:grid;gap:14px;grid-template-columns:repeat(auto-fit,minmax(260px,1fr)); }
  .suite-card{
    background:#fff;border:1px solid #e5e7eb;border-radius:16px;
    padding:16px;text-decoration:none;color:#111827;
    box-shadow:0 6px 20px rgba(0,0,0,.04);
    display:block;
    transition:transform .12s ease, box-shadow .12s ease;
  }
  .suite-card:hover{ box-shadow:0 10px 30px rgba(0,0,0,.07); transform:translateY(-1px); }
  .suite-row{ display:flex;align-items:center;gap:10px;margin-bottom:6px; }
  .suite-ico{
    width:40px;height:40px;border-radius:12px;background:#f1f5f9;
    display:flex;align-items:center;justify-content:center;
    color:#0f172a;
  }
  .suite-ico svg{ width:20px;height:20px; }
  .suite-label{ font-weight:800;font-size:18px; }
  .suite-desc{ opacity:.75;font-size:13px;margin-bottom:12px; }
  .suite-btn{
    display:inline-block;background:#111827;color:#fff;padding:8px 12px;border-radius:999px;
    font-weight:700;font-size:13px;
  }
  .suite-admin-card{ border-color:#fde68a; }
  .suite-admin-ico{ background:#fef3c7; }
  .suite-role-pill{
    font-size:12px;padding:2px 8px;border-radius:999px;background:#111827;color:#fff;opacity:.9;
  }
</style>

<div class="suite-home-wrap">
  <div class="suite-home-inner">

    <div class="suite-home-title">Suite Touraine-Ukraine</div>
    <div class="suite-home-sub">Accès rapide aux modules</div>

    <div class="suite-home-h2">Modules</div>

    <?php if (empty($benevole)): ?>
      <div style="background:#fff;border:1px solid #e5e7eb;border-radius:14px;padding:14px;opacity:.85;">
        Aucun module visible pour ce rôle.
      </div>
    <?php else: ?>
      <div class="suite-grid">
        <?php foreach ($benevole as $it): ?>
          <?php $iconName = (string)($it['icon'] ?? ''); ?>
          <a class="suite-card" href="<?= h(portal_item_href($it)) ?>">
            <div class="suite-row">
              <div class="suite-ico"><?= card_icon_svg($iconName) ?></div>
              <div class="suite-label"><?= h((string)($it['label'] ?? '')) ?></div>
            </div>
            <div class="suite-desc"><?= h(portal_item_desc($it)) ?></div>
            <div class="suite-btn">Ouvrir</div>
          </a>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>

    <?php if ($roleLevel >= 2 && !empty($adminItems)): ?>
      <div class="suite-home-h2" style="margin-top:26px;">Administration</div>

      <div class="suite-grid">
        <?php foreach ($adminItems as $it): ?>
          <?php $iconName = (string)($it['icon'] ?? ''); ?>
          <a class="suite-card suite-admin-card" href="<?= h(portal_item_href($it)) ?>">
            <div class="suite-row">
              <div class="suite-ico suite-admin-ico"><?= card_icon_svg($iconName) ?></div>
              <div class="suite-label" style="flex:1;"><?= h((string)($it['label'] ?? '')) ?></div>
              <span class="suite-role-pill"><?= h((string)($it['min_role'] ?? 'admin')) ?></span>
            </div>
            <div class="suite-desc"><?= h(portal_item_desc($it)) ?></div>
            <div class="suite-btn">Ouvrir</div>
          </a>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>

  </div>
</div>