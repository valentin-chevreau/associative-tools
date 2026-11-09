<?php
declare(strict_types=1);

// mailing/queue.php — File d'envoi : état de la tâche planifiée, newsletters en cours, messages automatiques.
// Réservé au rôle super_admin (outil de diagnostic).

require_once dirname(__DIR__) . '/shared/bootstrap.php';
require_super_admin();
require_once __DIR__ . '/includes/automations.php';

$pdo = mailing_pdo();
$h = 'mailing_h';
$ready = mailing_tables_ready($pdo);
$autoReady = $ready && mailing_auto_tables_ready($pdo);
$ruleNames = [];

// ── Tâche planifiée ──
$cron = null;
$cronTable = true;
try {
    $cron = $pdo->query("SELECT *, TIMESTAMPDIFF(SECOND, last_run_at, NOW()) AS age FROM mailing_cron_state WHERE name = 'send_queue'")->fetch(PDO::FETCH_ASSOC) ?: null;
} catch (Throwable) {
    $cronTable = false;
}

function mq_ago(int $s): string {
    if ($s < 60) return 'il y a ' . $s . ' s';
    if ($s < 3600) return 'il y a ' . intdiv($s, 60) . ' min';
    if ($s < 86400) return 'il y a ' . intdiv($s, 3600) . ' h ' . intdiv($s % 3600, 60) . ' min';
    return 'il y a ' . intdiv($s, 86400) . ' j';
}

// ── Newsletters en file ──
$camps = [];
if ($ready) {
    $camps = $pdo->query("SELECT c.id, c.subject, c.status, c.scheduled_at,
            (SELECT COUNT(*) FROM mailing_recipients r WHERE r.campaign_id = c.id) AS n_total,
            (SELECT COUNT(*) FROM mailing_recipients r WHERE r.campaign_id = c.id AND r.status = 'sent') AS n_sent,
            (SELECT COUNT(*) FROM mailing_recipients r WHERE r.campaign_id = c.id AND r.status = 'failed') AS n_failed
        FROM mailing_campaigns c WHERE c.status IN ('queued','sending') ORDER BY c.scheduled_at IS NULL DESC, c.scheduled_at, c.id")->fetchAll(PDO::FETCH_ASSOC);
}

// ── Messages automatiques ──
$fType = (string)($_GET['type'] ?? '');
$fStatus = (string)($_GET['status'] ?? '');
if ($fType !== '' && !preg_match('/^r\d+$/', $fType)) $fType = '';
if (!in_array($fStatus, ['sent', 'retry', 'failed', 'skipped'], true)) $fStatus = '';

$due = [];
$log = [];
$counts = [];
if ($autoReady) {
    foreach (mailing_rules_list($pdo) as $rule) {
        $ruleNames['r' . $rule['id']] = $rule['name'];
        if (!$rule['enabled']) continue;
        foreach (mailing_rule_due($pdo, $rule, $rule['site_url'] !== '' ? $rule['site_url'] : 'x', 200) as $it) {
            $due[] = ['type' => 'r' . $rule['id'], 'email' => $it['email'], 'ref' => $it['ref']];
        }
    }
    foreach ($pdo->query("SELECT status, COUNT(*) n FROM mailing_auto_log WHERE created_at >= NOW() - INTERVAL 7 DAY GROUP BY status")->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $counts[$r['status']] = (int)$r['n'];
    }
    $where = []; $args = [];
    if ($fType !== '') { $where[] = 'type = ?'; $args[] = $fType; }
    if ($fStatus !== '') { $where[] = 'status = ?'; $args[] = $fStatus; }
    $st = $pdo->prepare("SELECT * FROM mailing_auto_log" . ($where ? ' WHERE ' . implode(' AND ', $where) : '') . " ORDER BY id DESC LIMIT 100");
    $st->execute($args);
    $log = $st->fetchAll(PDO::FETCH_ASSOC);
}
$statusLabel = ['sent' => ['Envoyé', 'green'], 'retry' => ['Nouvel essai', 'amber'], 'failed' => ['Échec', 'red'], 'skipped' => ['Ignoré', 'sand']];

mailing_page_start("File d'envoi", 'mailing-queue', "File d'envoi",
    '<a href="queue.php" class="tu-btn tu-btn-s tu-btn-sm">Actualiser</a>');
?>
<div class="tu-ph"><div>
  <div class="tu-ph-title">File d'envoi</div>
  <div class="tu-ph-sub">Ce qui part, ce qui attend, et si la tâche planifiée tourne bien (super-admin)</div>
</div></div>

<?php
// État de la tâche planifiée
if (!$cronTable) { $cTone = 'sand'; $cTitle = 'Suivi indisponible'; $cText = "Exécutez la migration 004 (Administration, Migrations) pour suivre le dernier passage."; }
elseif (!$cron) { $cTone = 'red'; $cTitle = 'Jamais exécutée'; $cText = "Aucun passage enregistré. Vérifiez la ligne de crontab de www-data et le fichier /var/log/mailing_queue.log."; }
elseif ((int)$cron['age'] > 300) { $cTone = 'red'; $cTitle = 'À l\'arrêt ?'; $cText = 'Dernier passage ' . mq_ago((int)$cron['age']) . '. La tâche est censée tourner chaque minute.'; }
else {
    $cTone = 'green'; $cTitle = 'En marche';
    $cText = 'Dernier passage ' . mq_ago((int)$cron['age']) . ' · ' . (int)$cron['last_sent'] . ' envoyé(s), ' . (int)$cron['last_failed'] . ' en échec';
}
if ($cron && !empty($cron['last_error'])) { $cText .= ' · Erreur : ' . $cron['last_error']; if ($cTone === 'green') $cTone = 'amber'; }
?>
<section class="tu-card" style="padding:16px 20px;margin-bottom:18px;display:flex;align-items:center;gap:14px;flex-wrap:wrap;">
  <span class="ml-pill ml-pill-<?= $h($cTone) ?>"><?= $h($cTitle) ?></span>
  <span style="font-size:13px;"><b>Tâche planifiée</b> · <?= $h($cText) ?></span>
</section>

<?php if (!$ready): ?>
  <div class="tu-card" style="padding:20px;">Les tables du module Mailing ne sont pas installées (Administration, Migrations).</div>
<?php else: ?>

<section class="tu-card" style="margin-bottom:18px;overflow-x:auto;">
  <div class="ml-card-h"><b>Newsletters en file</b><p>Planifiées ou en cours d'envoi</p></div>
  <?php if (!$camps): ?>
    <div class="ml-card-b ml-muted">Aucune newsletter en attente.</div>
  <?php else: ?>
  <table class="tu-tbl"><thead><tr><th>Objet</th><th>Statut</th><th>Départ</th><th>Avancement</th></tr></thead><tbody>
    <?php foreach ($camps as $c):
        [$lab, $tone] = mailing_status_label((string)$c['status']);
        $tot = (int)$c['n_total']; $done = (int)$c['n_sent'] + (int)$c['n_failed'];
        $pct = $tot > 0 ? (int)round($done * 100 / $tot) : 0; ?>
      <tr>
        <td><a href="campaign.php?id=<?= (int)$c['id'] ?>"><?= $h($c['subject'] ?: '(sans objet)') ?></a></td>
        <td><span class="ml-pill ml-pill-<?= $h($tone) ?>"><?= $h($lab) ?></span></td>
        <td><?= $c['scheduled_at'] ? $h(date('d/m/Y H:i', strtotime((string)$c['scheduled_at']))) : 'Prochaine minute' ?></td>
        <td style="min-width:200px;">
          <div class="ml-bar"><i style="width:<?= $pct ?>%"></i></div>
          <span class="ml-muted"><?= (int)$c['n_sent'] ?> envoyés sur <?= $tot ?><?= (int)$c['n_failed'] ? ' · ' . (int)$c['n_failed'] . ' en échec' : '' ?></span>
        </td>
      </tr>
    <?php endforeach; ?>
  </tbody></table>
  <?php endif; ?>
</section>

<?php if (!$autoReady): ?>
  <div class="tu-card" style="padding:20px;">Les tables des messages automatiques ne sont pas installées (migration 003).</div>
<?php else: ?>

<section class="tu-card" style="margin-bottom:18px;overflow-x:auto;">
  <div class="ml-card-h"><b>Messages automatiques à envoyer</b><p>Au prochain passage de la tâche planifiée (seulement les messages activés)</p></div>
  <?php if (!$due): ?>
    <div class="ml-card-b ml-muted">Rien en attente.</div>
  <?php else: ?>
  <table class="tu-tbl"><thead><tr><th>Message</th><th>Destinataire</th><th>Référence</th></tr></thead><tbody>
    <?php foreach ($due as $d): ?>
      <tr><td><?= $h($ruleNames[$d['type']] ?? 'Règle supprimée') ?></td><td><?= $h($d['email']) ?></td><td class="ml-muted"><?= $h($d['ref']) ?></td></tr>
    <?php endforeach; ?>
  </tbody></table>
  <?php endif; ?>
</section>

<section class="tu-card" style="margin-bottom:18px;overflow-x:auto;">
  <div class="ml-card-h" style="display:flex;justify-content:space-between;align-items:center;gap:12px;flex-wrap:wrap;">
    <div><b>Journal des messages automatiques</b>
      <p>100 derniers · 7 derniers jours :
        <?= (int)($counts['sent'] ?? 0) ?> envoyé(s), <?= (int)($counts['retry'] ?? 0) ?> nouvel essai, <?= (int)($counts['failed'] ?? 0) ?> échec(s), <?= (int)($counts['skipped'] ?? 0) ?> ignoré(s)</p></div>
    <form method="get" style="display:flex;gap:8px;flex-wrap:wrap;">
      <select name="type" class="tu-input" onchange="this.form.submit()">
        <option value="">Tous les messages</option>
        <?php foreach ($ruleNames as $k => $label): ?><option value="<?= $h($k) ?>" <?= $fType === $k ? 'selected' : '' ?>><?= $h($label) ?></option><?php endforeach; ?>
      </select>
      <select name="status" class="tu-input" onchange="this.form.submit()">
        <option value="">Tous les statuts</option>
        <?php foreach ($statusLabel as $k => $l): ?><option value="<?= $h($k) ?>" <?= $fStatus === $k ? 'selected' : '' ?>><?= $h($l[0]) ?></option><?php endforeach; ?>
      </select>
    </form>
  </div>
  <?php if (!$log): ?>
    <div class="ml-card-b ml-muted">Aucune entrée.</div>
  <?php else: ?>
  <table class="tu-tbl"><thead><tr><th>Date</th><th>Message</th><th>Destinataire</th><th>Statut</th><th>Essais</th><th>Erreur</th></tr></thead><tbody>
    <?php foreach ($log as $r): [$lab, $tone] = $statusLabel[$r['status']] ?? [$r['status'], 'sand']; ?>
      <tr>
        <td style="white-space:nowrap;"><?= $h(date('d/m/Y H:i', strtotime((string)($r['sent_at'] ?: $r['created_at'])))) ?></td>
        <td><?= $h($ruleNames[$r['type']] ?? ($r['type'] === 'reminder' || $r['type'] === 'welcome' ? 'Ancien message' : 'Règle supprimée')) ?></td>
        <td><?= $h($r['email']) ?></td>
        <td><span class="ml-pill ml-pill-<?= $h($tone) ?>"><?= $h($lab) ?></span></td>
        <td><?= (int)$r['attempts'] ?></td>
        <td class="ml-muted"><?= $h($r['error'] ?? '') ?></td>
      </tr>
    <?php endforeach; ?>
  </tbody></table>
  <?php endif; ?>
</section>
<?php endif; ?>
<?php endif; ?>
<?php mailing_page_end();
