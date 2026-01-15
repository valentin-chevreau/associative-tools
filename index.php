<?php
declare(strict_types=1);

require_once __DIR__ . '/shared/bootstrap.php';
require_once __DIR__ . '/shared/suite_nav.php';
require_once __DIR__ . '/shared/nav.config.php';

$items = nav_visible_items();

function portal_item_href(array $it): string {
    if (!empty($it['href'])) return (string)$it['href'];
    if (!empty($it['children']) && is_array($it['children']) && !empty($it['children'][0]['href'])) {
        return (string)$it['children'][0]['href'];
    }
    return suite_base() . '/';
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
    'Planning' => 10,
    'Convois' => 20,
    'Stock local' => 30,
    'Étiquettes' => 40,
    'Caisse' => 50,
    'Dons' => 60,
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
    $min = (string)($it['min_role'] ?? 'public');
    return $min === 'public';
}

$benevole = array_values(array_filter($items, fn($it) => is_public_item($it)));
$adminItems = array_values(array_filter($items, fn($it) => !is_public_item($it)));
?>
<div style="font-family:system-ui;background:#f5f6f8;min-height:calc(100vh - 54px);padding:22px 18px;">
  <div style="max-width:1100px;margin:0 auto;">

    <div style="margin:10px 0 18px;">
      <div style="font-size:34px;font-weight:900;line-height:1.1;margin-bottom:6px;">
        Suite Touraine-Ukraine
      </div>
      <div style="opacity:.75">
        Accès rapide aux modules
      </div>
    </div>

    <div style="margin:18px 0 10px;font-weight:800;font-size:16px;">Modules</div>

    <div style="display:grid;gap:14px;grid-template-columns:repeat(auto-fit,minmax(260px,1fr));">
      <?php foreach ($benevole as $it): ?>
        <a href="<?= h(portal_item_href($it)) ?>"
           style="background:#fff;border:1px solid #e5e7eb;border-radius:16px;
                  padding:16px;text-decoration:none;color:#111827;
                  box-shadow:0 6px 20px rgba(0,0,0,.04);">
          <div style="display:flex;align-items:center;gap:10px;margin-bottom:6px;">
            <div style="width:40px;height:40px;border-radius:12px;background:#f1f5f9;display:flex;align-items:center;justify-content:center;font-size:20px;">
              <?= h((string)($it['icon'] ?? '')) ?>
            </div>
            <div style="font-weight:800;font-size:18px;"><?= h((string)$it['label']) ?></div>
          </div>

          <div style="opacity:.75;font-size:13px;margin-bottom:12px;">
            <?= h(portal_item_desc($it)) ?>
          </div>
          <div style="display:inline-block;background:#111827;color:#fff;padding:8px 12px;border-radius:999px;font-weight:700;font-size:13px;">
            Ouvrir
          </div>
        </a>
      <?php endforeach; ?>
    </div>

    <?php if (is_admin() && !empty($adminItems)): ?>
      <div style="margin:26px 0 10px;font-weight:800;font-size:16px;">Administration</div>

      <div style="display:grid;gap:14px;grid-template-columns:repeat(auto-fit,minmax(260px,1fr));">
        <?php foreach ($adminItems as $it): ?>
          <a href="<?= h(portal_item_href($it)) ?>"
             style="background:#fff;border:1px solid #fde68a;border-radius:16px;
                    padding:16px;text-decoration:none;color:#111827;
                    box-shadow:0 6px 20px rgba(0,0,0,.04);">
            <div style="display:flex;align-items:center;gap:10px;margin-bottom:6px;">
              <div style="width:40px;height:40px;border-radius:12px;background:#fef3c7;display:flex;align-items:center;justify-content:center;font-size:20px;">
                <?= h((string)($it['icon'] ?? '')) ?>
              </div>
              <div style="font-weight:800;font-size:18px;flex:1;"><?= h((string)$it['label']) ?></div>
              <span style="font-size:12px;padding:2px 8px;border-radius:999px;background:#111827;color:#fff;opacity:.9;">
                <?= h((string)($it['min_role'] ?? 'admin')) ?>
              </span>
            </div>

            <div style="opacity:.75;font-size:13px;margin-bottom:12px;">
              <?= h(portal_item_desc($it)) ?>
            </div>

            <div style="display:inline-block;background:#111827;color:#fff;padding:8px 12px;border-radius:999px;font-weight:700;font-size:13px;">
              Ouvrir
            </div>
          </a>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>

  </div>
</div>
