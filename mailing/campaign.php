<?php
declare(strict_types=1);

// mailing/campaign.php — Détail d'une newsletter lancée : avancement, échecs, annulation ; suppression d'un brouillon.

require_once dirname(__DIR__) . '/shared/bootstrap.php';
require_admin();
require_once __DIR__ . '/includes/mailing.php';

$pdo = mailing_pdo();
if (!mailing_tables_ready($pdo)) mailing_redirect('index.php');
$h = 'mailing_h';

$id = (int)($_GET['id'] ?? $_POST['id'] ?? 0);
$st = $pdo->prepare("SELECT * FROM mailing_campaigns WHERE id = ?");
$st->execute([$id]);
$c = $st->fetch(PDO::FETCH_ASSOC);
if (!$c) { mailing_flash('Newsletter introuvable.', 'err'); mailing_redirect('index.php'); }

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!mailing_csrf_ok()) { http_response_code(400); exit('Session expirée : rechargez la page.'); }
    $action = (string)($_POST['action'] ?? '');
    if ($action === 'cancel' && in_array($c['status'], ['queued', 'sending'], true)) {
        $pdo->prepare("UPDATE mailing_campaigns SET status = 'cancelled', finished_at = NOW() WHERE id = ?")->execute([$id]);
        audit_log('mailing', 'update', 'campaign', $id, (string)$c['subject'], ['status' => 'cancelled']);
        mailing_flash("Envoi annulé. Les messages déjà partis ne peuvent pas être rappelés.");
    } elseif ($action === 'delete' && in_array($c['status'], ['draft', 'cancelled'], true)) {
        $pdo->prepare("DELETE FROM mailing_campaigns WHERE id = ?")->execute([$id]);
        audit_log('mailing', 'delete', 'campaign', $id, (string)$c['subject']);
        mailing_flash("Newsletter supprimée.");
        mailing_redirect('index.php');
    } elseif ($action === 'duplicate') {
        $pdo->prepare("INSERT INTO mailing_campaigns (subject, preheader, blocks, audiences, created_by)
            SELECT CONCAT(LEFT(subject, 180), ' (copie)'), preheader, blocks, audiences, ? FROM mailing_campaigns WHERE id = ?")
            ->execute([current_volunteer_id(), $id]);
        mailing_flash("Copie créée en brouillon.");
        mailing_redirect('compose.php?id=' . (int)$pdo->lastInsertId());
    }
    mailing_redirect('campaign.php?id=' . $id);
}

$cnt = $pdo->prepare("SELECT status, COUNT(*) FROM mailing_recipients WHERE campaign_id = ? GROUP BY status");
$cnt->execute([$id]);
$by = ['pending' => 0, 'sent' => 0, 'failed' => 0];
foreach ($cnt->fetchAll(PDO::FETCH_KEY_PAIR) as $k => $n) $by[$k] = (int)$n;
$total = (int)$c['recipients_total'];
$done = $by['sent'] + $by['failed'];
$nExcluded = count(mailing_campaign_exclusions($pdo, $id));
$failures = $pdo->prepare("SELECT email, error FROM mailing_recipients WHERE campaign_id = ? AND status = 'failed' ORDER BY id LIMIT 100");
$failures->execute([$id]);
$failures = $failures->fetchAll(PDO::FETCH_ASSOC);
$audiences = mailing_audiences();
$lists = implode(', ', array_map(static fn(string $k): string => $audiences[$k]['label'], mailing_parse_audiences((string)$c['audiences'])));
[$label, $tone] = mailing_status_label((string)$c['status']);
$csrf = mailing_csrf();

mailing_page_start((string)$c['subject'], 'mailing-history', 'Détail');
?>
<div class="tu-ph"><div>
  <div class="tu-ph-title"><?= $h($c['subject']) ?></div>
  <div class="tu-ph-sub"><span class="ml-pill ml-pill-<?= $h($tone) ?>"><?= $h($label) ?></span>
    <?php if ($c['status'] === 'queued'): ?> · <?= $c['scheduled_at'] ? 'Départ le ' . $h(date('d/m/Y à H:i', strtotime((string)$c['scheduled_at']))) : 'Départ à la prochaine minute' ?><?php endif; ?>
    · Listes : <?= $h($lists) ?></div>
</div></div>

<div style="display:grid;grid-template-columns:minmax(0,1fr) minmax(0,420px);gap:20px;align-items:start;">
  <div>
    <div class="tu-card" style="margin-bottom:20px;">
      <div class="ml-card-h"><b>Avancement</b></div>
      <div class="ml-card-b">
        <?php if ($total > 0): ?>
          <div class="ml-bar"><i style="width:<?= (int)round($done / $total * 100) ?>%"></i></div>
          <p style="margin:8px 0 0;font-size:13px;"><b><?= $by['sent'] ?></b> envoyés sur <?= $total ?><?= $by['failed'] ? ' · <b>' . $by['failed'] . '</b> en échec' : '' ?><?= $by['pending'] ? ' · ' . $by['pending'] . ' en attente' : '' ?></p>
          <?php if ($nExcluded): ?><p class="ml-muted"><?= $nExcluded ?> personne<?= $nExcluded > 1 ? 's' : '' ?> décochée<?= $nExcluded > 1 ? 's' : '' ?> pour cet envoi.</p><?php endif; ?>
          <p class="ml-muted">« Envoyé » signifie remis au serveur de messagerie. Les rebonds (boîte pleine, adresse inconnue) ne sont pas suivis.</p>
        <?php else: ?>
          <p class="ml-muted" style="margin:0;">La liste des destinataires est figée au moment du départ.</p>
        <?php endif; ?>
        <form method="post" style="display:flex;gap:10px;flex-wrap:wrap;margin-top:14px;">
          <input type="hidden" name="csrf" value="<?= $h($csrf) ?>"><input type="hidden" name="id" value="<?= $id ?>">
          <?php if (in_array($c['status'], ['queued', 'sending'], true)): ?>
            <button name="action" value="cancel" class="tu-btn tu-btn-s" onclick="return confirm('Annuler cet envoi ? Les messages restants ne partiront pas.');">Annuler l'envoi</button>
          <?php endif; ?>
          <button name="action" value="duplicate" class="tu-btn tu-btn-s">Dupliquer en brouillon</button>
          <?php if ($c['status'] === 'cancelled'): ?>
            <button name="action" value="delete" class="tu-btn tu-btn-s" onclick="return confirm('Supprimer définitivement cette newsletter ?');">Supprimer</button>
          <?php endif; ?>
        </form>
      </div>
    </div>
    <?php if ($failures): ?>
    <div class="tu-card">
      <div class="ml-card-h"><b>Échecs</b><p>Adresses pour lesquelles le serveur de messagerie a refusé le message<?= $by['failed'] > 100 ? ' (100 premières)' : '' ?>. Corrigez-les dans la fiche concernée.</p></div>
      <table class="tu-tbl"><tbody>
      <?php foreach ($failures as $f): ?><tr><td><?= $h($f['email']) ?></td><td class="ml-muted"><?= $h($f['error']) ?></td></tr><?php endforeach; ?>
      </tbody></table>
    </div>
    <?php endif; ?>
  </div>
  <div class="tu-card" style="position:sticky;top:16px;">
    <div class="ml-card-h"><b>Message envoyé</b></div>
    <iframe src="preview.php?id=<?= $id ?>" title="Aperçu" style="width:100%;height:620px;border:0;background:#f5f0e8;border-radius:0 0 14px 14px;"></iframe>
  </div>
</div>
<?php mailing_page_end();
