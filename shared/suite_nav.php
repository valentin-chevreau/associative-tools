<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/nav.config.php';

$items = nav_visible_items();
?>
<style>
  .suitebar{position:sticky;top:0;z-index:9999;background:#111827;color:#fff;font-family:system-ui;
    display:flex;gap:10px;align-items:center;padding:10px 12px;border-bottom:1px solid rgba(255,255,255,.1)}
  .suitebar a{color:#fff;text-decoration:none;padding:6px 10px;border-radius:8px;display:inline-flex;align-items:center;gap:6px;white-space:nowrap}
  .suitebar a:hover{background:rgba(255,255,255,.08)}
  .badge{font-size:12px;padding:2px 8px;border-radius:999px;background:rgba(255,255,255,.14)}
  .right{margin-left:auto;display:flex;gap:10px;align-items:center}
  .dot{width:8px;height:8px;border-radius:999px;background:#22c55e;display:inline-block}
  .dot.off{background:#ef4444}

  .dd{position:relative;display:inline-block}
  .ddm{display:none;position:absolute;top:38px;left:0;min-width:240px;
    background:#111827;border:1px solid rgba(255,255,255,.12);border-radius:12px;padding:6px;
    box-shadow:0 10px 30px rgba(0,0,0,.25)}
  .ddm a{display:block;padding:8px 10px;border-radius:10px}
  .ddm a:hover{background:rgba(255,255,255,.08)}
  .dd:hover .ddm{display:block}
</style>

<div class="suitebar">
  <a href="<?= h(suite_base()) ?>/">🏠 Portail</a>
  <span class="badge"><?= strtoupper(h(app_env())) ?></span>

  <?php foreach ($items as $it): ?>
    <?php $icon = (string)($it['icon'] ?? ''); ?>
    <?php if (!empty($it['children']) && is_array($it['children'])): ?>
      <div class="dd">
        <a href="<?= h($it['children'][0]['href'] ?? (suite_base().'/')) ?>">
          <?= $icon ? h($icon) : '' ?> <?= h((string)$it['label']) ?> <span style="opacity:.7">▾</span>
        </a>
        <div class="ddm" role="menu">
          <?php foreach ($it['children'] as $c): ?>
            <a role="menuitem" href="<?= h((string)$c['href']) ?>"><?= h((string)$c['label']) ?></a>
          <?php endforeach; ?>
        </div>
      </div>
    <?php else: ?>
      <a href="<?= h((string)$it['href']) ?>"><?= $icon ? h($icon) : '' ?> <?= h((string)$it['label']) ?></a>
    <?php endif; ?>
  <?php endforeach; ?>

  <div class="right">
    <span class="badge">
      <span class="dot <?= is_admin() ? '' : 'off' ?>"></span>
      <?= is_admin() ? (is_admin_plus() ? 'Admin +' : 'Admin') : 'Mode bénévole' ?>
    </span>

    <?php if (!is_admin()): ?>
      <a class="badge" href="<?= h(suite_login_url()) ?>?next=<?= h($_SERVER['REQUEST_URI'] ?? (suite_base().'/')) ?>">Se connecter</a>
    <?php else: ?>
      <a class="badge" href="<?= h(suite_logout_url()) ?>">Se déconnecter</a>
    <?php endif; ?>
  </div>
</div>
