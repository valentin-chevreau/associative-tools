<?php
declare(strict_types=1);

// mailing/index.php — Historique des newsletters : brouillons, planifiées, en cours, envoyées.

require_once dirname(__DIR__) . '/shared/bootstrap.php';
require_admin();
require_once __DIR__ . '/includes/mailing.php';

$pdo = mailing_pdo();
$ready = mailing_tables_ready($pdo);
$h = 'mailing_h';

$rows = [];
if ($ready) {
    $rows = $pdo->query("SELECT c.*,
            (SELECT COUNT(*) FROM mailing_recipients r WHERE r.campaign_id = c.id AND r.status = 'sent') AS n_sent,
            (SELECT COUNT(*) FROM mailing_recipients r WHERE r.campaign_id = c.id AND r.status = 'failed') AS n_failed
        FROM mailing_campaigns c ORDER BY c.created_at DESC, c.id DESC LIMIT 200")->fetchAll(PDO::FETCH_ASSOC);
}
$audiences = mailing_audiences();

mailing_page_start('Historique des envois', 'mailing-history', 'Historique',
    '<a href="compose.php" class="tu-btn tu-btn-p tu-btn-sm">Nouvelle newsletter</a>');
?>
<div class="tu-ph"><div>
  <div class="tu-ph-title">Historique des envois</div>
  <div class="tu-ph-sub">Les newsletters en brouillon, planifiées, en cours d'envoi ou envoyées</div>
</div></div>

<?php if (!$ready): mailing_migration_notice(); else: ?>
<div class="tu-card" style="margin-bottom:22px;overflow-x:auto;">
<table class="tu-tbl"><thead><tr><th>Objet</th><th>Listes</th><th>Date</th><th>Avancement</th><th>Statut</th></tr></thead><tbody>
<?php foreach ($rows as $r):
    [$label, $tone] = mailing_status_label((string)$r['status']);
    $lists = implode(', ', array_map(static fn(string $k): string => $audiences[$k]['label'], mailing_parse_audiences((string)$r['audiences'])));
    $href = $r['status'] === 'draft' ? 'compose.php?id=' . (int)$r['id'] : 'campaign.php?id=' . (int)$r['id'];
    $total = (int)$r['recipients_total']; $done = (int)$r['n_sent'] + (int)$r['n_failed'];
    if ($r['status'] === 'queued' && $r['scheduled_at']) $date = 'Planifié ' . date('d/m/Y H:i', strtotime((string)$r['scheduled_at']));
    elseif ($r['finished_at']) $date = date('d/m/Y H:i', strtotime((string)$r['finished_at']));
    elseif ($r['started_at']) $date = date('d/m/Y H:i', strtotime((string)$r['started_at']));
    else $date = 'Modifié le ' . date('d/m/Y', strtotime((string)$r['updated_at'])); ?>
  <tr>
    <td><a href="<?= $h($href) ?>" style="font-weight:700;color:inherit;"><?= $h($r['subject']) ?></a></td>
    <td class="ml-muted"><?= $h($lists !== '' ? $lists : '—') ?></td>
    <td class="ml-muted"><?= $h($date) ?></td>
    <td style="min-width:160px;">
      <?php if ($total > 0): ?>
        <div class="ml-bar"><i style="width:<?= (int)round($done / $total * 100) ?>%"></i></div>
        <div class="ml-muted" style="margin-top:4px;"><?= (int)$r['n_sent'] ?> / <?= $total ?> envoyés<?= $r['n_failed'] ? ' · ' . (int)$r['n_failed'] . ' en échec' : '' ?></div>
      <?php else: ?><span class="ml-muted">—</span><?php endif; ?>
    </td>
    <td><span class="ml-pill ml-pill-<?= $h($tone) ?>"><?= $h($label) ?></span></td>
  </tr>
<?php endforeach; if (!$rows): ?>
  <tr><td colspan="5" class="ml-muted" style="padding:22px;">Aucune newsletter pour l'instant. <a href="compose.php" style="color:var(--tu-amber-600);font-weight:700;">Rédiger la première</a></td></tr>
<?php endif; ?>
</tbody></table>
</div>
<p class="ml-muted">Aucun suivi d'ouverture n'est effectué : seuls le nombre de messages remis au serveur de messagerie et les échecs sont conservés.</p>
<?php endif; ?>
<?php mailing_page_end();
