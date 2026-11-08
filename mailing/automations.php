<?php
declare(strict_types=1);

// mailing/automations.php — Messages automatiques : activer, désactiver, ouvrir pour modifier le texte.

require_once dirname(__DIR__) . '/shared/bootstrap.php';
require_admin();
require_once __DIR__ . '/includes/automations.php';

$pdo = mailing_pdo();
$ready = mailing_tables_ready($pdo) && mailing_auto_tables_ready($pdo);
$defs = mailing_auto_definitions();
$h = 'mailing_h';

if ($ready && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!mailing_csrf_ok()) { http_response_code(400); exit('Session expirée : rechargez la page.'); }
    $type = (string)($_POST['type'] ?? '');
    $on = ($_POST['action'] ?? '') === 'enable';
    if (isset($defs[$type]) && in_array($_POST['action'] ?? '', ['enable', 'disable'], true)) {
        if ($on && !suite_mail_ready($defs[$type]['channel'])) {
            mailing_flash("Adresse d'expédition indéterminée : définissez MAIL_FROM dans le .env de la suite avant d'activer ce message.", 'err');
        } else {
            mailing_auto_set_enabled($pdo, $type, $on, suite_mail_abs_url(''));
            audit_log('mailing', 'update', 'automation', null, $defs[$type]['label'], ['enabled' => $on]);
            mailing_flash($on ? "« {$defs[$type]['label']} » est activé." . ($type === 'welcome' ? " Les bénévoles déjà présents ne le recevront pas : seuls les prochains." : '') : "« {$defs[$type]['label']} » est désactivé.");
        }
    }
    mailing_redirect('automations.php');
}

mailing_page_start('Messages automatiques', 'mailing-auto', 'Automatiques');
?>
<div class="tu-ph"><div>
  <div class="tu-ph-title">Messages automatiques</div>
  <div class="tu-ph-sub">Des messages qui partent tout seuls. Chacun peut être activé, modifié ou coupé.</div>
</div></div>

<?php if (!$ready): ?>
  <div class="tu-card" style="padding:20px;">
    <div style="font-weight:800;margin-bottom:6px;">Migration SQL à exécuter</div>
    <div style="font-size:13px;color:var(--tu-ink-400);">Exécutez la migration <code>mailing / 003_mailing_automations.sql</code> depuis <a href="<?= $h(suite_base()) ?>/admin/migrations.php" style="color:var(--tu-amber-600);font-weight:700;">Migrations SQL</a>.</div>
  </div>
<?php else: $csrf = mailing_csrf(); ?>
  <div class="ml-grid" style="grid-template-columns:repeat(auto-fit,minmax(340px,1fr));margin-bottom:22px;">
  <?php foreach ($defs as $type => $d):
      $cfg = mailing_auto_get($pdo, $type);
      $stats = mailing_auto_stats($pdo, $type, $cfg['site_url']); ?>
    <section class="tu-card" style="padding:18px 20px;display:flex;flex-direction:column;gap:12px;">
      <div style="display:flex;justify-content:space-between;align-items:center;gap:10px;">
        <b style="font-family:var(--tu-font-d);font-size:15px;"><?= $h($d['label']) ?></b>
        <span class="ml-pill <?= $cfg['enabled'] ? 'ml-pill-green' : '' ?>"><?= $cfg['enabled'] ? 'Activé' : 'Désactivé' ?></span>
      </div>
      <div style="font-size:13px;color:var(--tu-ink-400);line-height:1.55;"><?= $h($d['desc']) ?></div>
      <div style="background:var(--tu-sand-50, #fdf8f0);border:1px solid var(--tu-ink-100);border-radius:12px;padding:12px 14px;font-size:13px;line-height:1.5;">
        <b>Objet :</b> <?= $h($cfg['subject']) ?><br>
        <span class="ml-muted"><?= $cfg['customized'] ? 'Texte personnalisé' : 'Texte par défaut' ?> ·
          <?= $type === 'reminder' ? $stats['pending'] . ' rappel' . ($stats['pending'] > 1 ? 's' : '') . ' prévu' . ($stats['pending'] > 1 ? 's' : '') . ' pour demain · ' : '' ?>
          <?= $stats['sent_month'] ?> envoyé<?= $stats['sent_month'] > 1 ? 's' : '' ?> ce mois-ci</span>
      </div>
      <form method="post" style="display:flex;gap:10px;flex-wrap:wrap;">
        <input type="hidden" name="csrf" value="<?= $h($csrf) ?>"><input type="hidden" name="type" value="<?= $h($type) ?>">
        <?php if ($cfg['enabled']): ?>
          <button name="action" value="disable" class="tu-btn tu-btn-s">Désactiver</button>
        <?php else: ?>
          <button name="action" value="enable" class="tu-btn tu-btn-p">Activer</button>
        <?php endif; ?>
        <a href="automation.php?type=<?= $h($type) ?>" class="tu-btn tu-btn-s">Modifier le texte</a>
      </form>
    </section>
  <?php endforeach; ?>
  </div>
  <p class="ml-muted">Un message automatique n'est jamais envoyé deux fois pour le même fait (même inscription, même nouveau bénévole), et les personnes désinscrites n'en reçoivent plus. Les rappels partent à partir de <?= MAILING_REMINDER_HOUR ?>h, la veille.</p>
<?php endif; ?>
<?php mailing_page_end();
