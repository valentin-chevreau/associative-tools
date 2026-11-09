<?php
declare(strict_types=1);

// mailing/automations.php — Messages automatiques : liste des règles, activer / désactiver / dupliquer / supprimer.

require_once dirname(__DIR__) . '/shared/bootstrap.php';
require_admin();
require_once __DIR__ . '/includes/automations.php';

$pdo = mailing_pdo();
$ready = mailing_tables_ready($pdo) && mailing_auto_tables_ready($pdo);
$h = 'mailing_h';

if ($ready && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!mailing_csrf_ok()) { http_response_code(400); exit('Session expirée : rechargez la page.'); }
    $id = (int)($_POST['id'] ?? 0);
    $rule = $id > 0 ? mailing_rule_get($pdo, $id) : null;
    $action = (string)($_POST['action'] ?? '');
    if ($rule) {
        $def = mailing_trigger_defs()[$rule['trigger']];
        if ($action === 'enable' || $action === 'disable') {
            if ($action === 'enable' && !suite_mail_ready($def['channel'])) {
                mailing_flash("Adresse d'expédition indéterminée : définissez MAIL_FROM dans le .env de la suite avant d'activer ce message.", 'err');
            } else {
                mailing_rule_set_enabled($pdo, $id, $action === 'enable', suite_mail_abs_url(''));
                audit_log('mailing', 'update', 'automation', $id, $rule['name'], ['enabled' => $action === 'enable']);
                mailing_flash($action === 'enable'
                    ? "« {$rule['name']} » est activé." . ($rule['trigger'] === 'new_volunteer' ? ' Les bénévoles déjà présents ne le recevront pas : seuls les prochains.' : '')
                    : "« {$rule['name']} » est désactivé.");
            }
        } elseif ($action === 'duplicate') {
            $new = mailing_rule_duplicate($pdo, $id);
            if ($new) {
                audit_log('mailing', 'create', 'automation', $new, $rule['name'] . ' (copie)');
                mailing_flash('Copie créée (désactivée). Vous pouvez la modifier.');
                mailing_redirect('automation.php?id=' . $new);
            }
        } elseif ($action === 'delete') {
            mailing_rule_delete($pdo, $id);
            audit_log('mailing', 'delete', 'automation', $id, $rule['name']);
            mailing_flash("« {$rule['name']} » est supprimé. Les messages déjà envoyés restent dans le journal.");
        }
    }
    mailing_redirect('automations.php');
}

$rules = $ready ? mailing_rules_list($pdo) : [];
$typeLabels = $ready ? mailing_event_type_labels($pdo) : [];
$groupLabels = $ready ? mailing_group_labels($pdo) : [];
$defs = mailing_trigger_defs();

mailing_page_start('Messages automatiques', 'mailing-auto', 'Automatiques',
    $ready ? '<a href="automation.php?new=1" class="tu-btn tu-btn-p tu-btn-sm">Nouvelle règle</a>' : '');
?>
<div class="tu-ph"><div>
  <div class="tu-ph-title">Messages automatiques</div>
  <div class="tu-ph-sub">Des messages qui partent tout seuls selon vos règles. Créez-les, modifiez-les ou coupez-les à tout moment.</div>
</div></div>

<?php if (!$ready): ?>
  <div class="tu-card" style="padding:20px;">
    <div style="font-weight:800;margin-bottom:6px;">Migration SQL à exécuter</div>
    <div style="font-size:13px;color:var(--tu-ink-400);">Les messages automatiques ont besoin des migrations <code>mailing / 003</code> et <code>mailing / 005</code>. Exécutez-les depuis
      <a href="<?= $h(suite_base()) ?>/admin/migrations.php" style="color:var(--tu-amber-600);font-weight:700;">Migrations SQL</a>.</div>
  </div>
<?php else: $csrf = mailing_csrf(); ?>
  <?php if (!$rules): ?>
    <div class="tu-card" style="padding:28px;text-align:center;margin-bottom:22px;">
      <div style="font-weight:800;font-size:15px;margin-bottom:6px;">Aucune règle pour l'instant</div>
      <p class="ml-muted" style="margin:0 0 14px;">Créez par exemple un rappel avant chaque créneau, ou un message de remerciement le lendemain.</p>
      <a href="automation.php?new=1" class="tu-btn tu-btn-p">Créer une règle</a>
    </div>
  <?php endif; ?>
  <div class="ml-grid" style="grid-template-columns:repeat(auto-fit,minmax(340px,1fr));margin-bottom:22px;">
  <?php foreach ($rules as $r):
      $stats = mailing_rule_stats($pdo, $r); ?>
    <section class="tu-card" style="padding:18px 20px;display:flex;flex-direction:column;gap:12px;">
      <div style="display:flex;justify-content:space-between;align-items:center;gap:10px;">
        <b style="font-family:var(--tu-font-d);font-size:15px;"><?= $h($r['name']) ?></b>
        <span class="ml-pill <?= $r['enabled'] ? 'ml-pill-green' : '' ?>"><?= $r['enabled'] ? 'Activé' : 'Désactivé' ?></span>
      </div>
      <div style="font-size:12px;"><span class="ml-pill ml-pill-blue"><?= $h($defs[$r['trigger']]['label']) ?></span></div>
      <div style="font-size:13px;color:var(--tu-ink-400);line-height:1.55;"><?= $h(mailing_rule_summary($r, $typeLabels, $groupLabels)) ?></div>
      <div style="background:var(--tu-sand-50, #fdf8f0);border:1px solid var(--tu-ink-100);border-radius:12px;padding:12px 14px;font-size:13px;line-height:1.5;">
        <b>Objet :</b> <?= $h($r['subject']) ?><br>
        <span class="ml-muted"><?= $r['customized'] ? 'Texte personnalisé' : 'Texte par défaut' ?> ·
          <?= $r['enabled'] ? $stats['pending'] . ' en attente · ' : '' ?><?= $stats['sent_month'] ?> envoyé<?= $stats['sent_month'] > 1 ? 's' : '' ?> ce mois-ci</span>
      </div>
      <div style="display:flex;gap:8px;flex-wrap:nowrap;align-items:center;">
        <form method="post" style="display:contents;">
          <input type="hidden" name="csrf" value="<?= $h($csrf) ?>"><input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
          <?php if ($r['enabled']): ?>
            <button name="action" value="disable" class="tu-btn tu-btn-s tu-btn-sm">Désactiver</button>
          <?php else: ?>
            <button name="action" value="enable" class="tu-btn tu-btn-p tu-btn-sm">Activer</button>
          <?php endif; ?>
          <a href="automation.php?id=<?= (int)$r['id'] ?>" class="tu-btn tu-btn-s tu-btn-sm">Modifier</a>
          <button name="action" value="duplicate" class="tu-btn tu-btn-s tu-btn-sm">Dupliquer</button>
          <button name="action" value="delete" class="tu-btn tu-btn-s tu-btn-sm" style="margin-left:auto;color:var(--tu-red-main);padding-left:10px;padding-right:10px;" title="Supprimer la règle" aria-label="Supprimer la règle"
                  data-confirm="Supprimer la règle « <?= $h($r['name']) ?> » ? Les messages déjà envoyés restent dans le journal."
                  data-confirm-title="Supprimer la règle" data-confirm-label="Supprimer"><svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" style="display:block;"><path d="M4 7h16M10 11v6M14 11v6M6 7l1 13h10l1-13M9 7V4h6v3"/></svg></button>
        </form>
      </div>
    </section>
  <?php endforeach; ?>
  </div>
  <p class="ml-muted">Un message automatique n'est jamais envoyé deux fois pour le même fait (même inscription, même nouveau bénévole), et les personnes désinscrites n'en reçoivent plus. Les heures sont celles de Paris.</p>
<?php endif; ?>
<?php mailing_page_end();
