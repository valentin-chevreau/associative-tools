<?php
declare(strict_types=1);

// mailing/compose.php — Rédiger une newsletter : destinataires (liste nominative), message, blocs, test, envoi ou planification.

require_once dirname(__DIR__) . '/shared/bootstrap.php';
require_admin();
require_once __DIR__ . '/includes/mailing.php';

$pdo = mailing_pdo();
$ready = mailing_tables_ready($pdo);
$audiences = mailing_audiences();
$me = current_volunteer_id();
$h = 'mailing_h';

$id = (int)($_GET['id'] ?? $_POST['id'] ?? 0);
$c = ['subject' => '', 'preheader' => '', 'blocks' => '[]', 'audiences' => '', 'status' => 'draft', 'scheduled_at' => null];
$excluded = [];
if ($ready && $id > 0) {
    $st = $pdo->prepare("SELECT * FROM mailing_campaigns WHERE id = ?");
    $st->execute([$id]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if (!$row) { mailing_flash('Newsletter introuvable.', 'err'); mailing_redirect('index.php'); }
    if ($row['status'] !== 'draft') mailing_redirect('campaign.php?id=' . $id);
    $c = $row;
    $excluded = array_keys(mailing_campaign_exclusions($pdo, $id));
}

$errors = [];
if ($ready && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!mailing_csrf_ok()) { http_response_code(400); exit('Session expirée : rechargez la page.'); }
    $action = (string)($_POST['action'] ?? 'save');
    $blocks = mailing_blocks_sanitize($pdo, json_decode((string)($_POST['blocks'] ?? '[]'), true));
    $c['subject']   = trim((string)($_POST['subject'] ?? ''));
    $c['preheader'] = trim((string)($_POST['preheader'] ?? ''));
    $c['blocks']    = json_encode($blocks, JSON_UNESCAPED_UNICODE);
    $keys = mailing_parse_audiences(implode(',', array_map('strval', (array)($_POST['audiences'] ?? []))));
    $c['audiences'] = implode(',', $keys);
    $excluded = array_values(array_filter(array_map('strval', (array)json_decode((string)($_POST['excluded'] ?? '[]'), true))));
    $when = trim((string)($_POST['scheduled_at'] ?? ''));

    if ($c['subject'] === '') $errors[] = "L'objet est obligatoire.";
    if (mb_strlen($c['subject']) > 190) $errors[] = "L'objet est trop long (190 caractères maximum).";

    if ($action === 'send' || $action === 'test') {
        if (!$blocks) $errors[] = "Le message est vide : ajoutez au moins un bloc.";
        if (!suite_mail_ready('mailing')) $errors[] = "Adresse d'expédition indéterminée : définissez MAIL_FROM dans le .env de la suite.";
    }
    $scheduledAt = null;
    if ($action === 'send') {
        if (!$keys) $errors[] = "Choisissez au moins une liste de destinataires.";
        if ($when !== '') {
            $ts = strtotime($when);
            if ($ts === false || $ts <= time()) $errors[] = "La date d'envoi planifiée doit être dans le futur.";
            else $scheduledAt = date('Y-m-d H:i:s', $ts);
        }
        $skip = [];
        foreach ($excluded as $e) $skip[mb_strtolower(trim($e))] = true;
        if (!$errors && !mailing_resolve($pdo, $keys, $skip)['recipients']) $errors[] = "Aucun destinataire : les listes sont vides ou toutes les personnes sont exclues.";
    }

    if (!$errors) {
        $params = [$c['subject'], mb_substr($c['preheader'], 0, 190), $c['blocks'], $c['audiences']];
        if ($id > 0) {
            $pdo->prepare("UPDATE mailing_campaigns SET subject=?, preheader=?, blocks=?, audiences=? WHERE id=? AND status='draft'")->execute([...$params, $id]);
        } else {
            $pdo->prepare("INSERT INTO mailing_campaigns (subject, preheader, blocks, audiences, created_by) VALUES (?,?,?,?,?)")->execute([...$params, $me]);
            $id = (int)$pdo->lastInsertId();
            audit_log('mailing', 'create', 'campaign', $id, $c['subject']);
        }
        mailing_save_campaign_exclusions($pdo, $id, $excluded);

        if ($action === 'test') {
            $to = $pdo->prepare("SELECT email FROM users WHERE id = ?");
            $to->execute([$me]);
            $addr = trim((string)$to->fetchColumn());
            if (!filter_var($addr, FILTER_VALIDATE_EMAIL)) {
                mailing_flash("Votre fiche utilisateur n'a pas d'adresse e-mail valide : impossible d'envoyer le test.", 'err');
            } else {
                $site = suite_mail_abs_url('');
                $url = mailing_unsubscribe_url($site, str_repeat('0', 32));
                $err = suite_mail_send($addr, '[TEST] ' . $c['subject'], mailing_render_text($c, $url), mailing_render_html($c, $url, $site), [], 'mailing');
                mailing_flash($err === null ? "Message de test envoyé à $addr (le lien de désinscription n'est pas actif dans un test)." : "Envoi du test impossible : $err", $err === null ? 'ok' : 'err');
            }
            mailing_redirect('compose.php?id=' . $id);
        }
        if ($action === 'send') {
            $pdo->prepare("UPDATE mailing_campaigns SET status='queued', scheduled_at=?, site_url=? WHERE id=? AND status='draft'")
                ->execute([$scheduledAt, suite_mail_abs_url(''), $id]);
            audit_log('mailing', 'update', 'campaign', $id, $c['subject'], ['status' => 'queued']);
            mailing_flash($scheduledAt ? 'Envoi planifié le ' . date('d/m/Y à H:i', strtotime($scheduledAt)) . '.' : "Envoi lancé : les messages partent par petits lots, quelques minutes suffisent pour les premiers.");
            mailing_redirect('campaign.php?id=' . $id);
        }
        mailing_flash('Brouillon enregistré.');
        mailing_redirect('compose.php?id=' . $id);
    }
}

// Annuaire nominatif des destinataires possibles (une ligne par adresse).
$directory = [];
if ($ready) {
    foreach (mailing_directory($pdo) as $email => $d) $directory[] = ['e' => $email, 'n' => $d['name'], 'l' => $d['lists']];
    usort($directory, static fn(array $a, array $b): int => strcasecmp($a['n'] !== '' ? $a['n'] : $a['e'], $b['n'] !== '' ? $b['n'] : $b['e']));
}
$counts = [];
foreach ($directory as $r) foreach ($r['l'] as $k) $counts[$k] = ($counts[$k] ?? 0) + 1;

// Évènements du planning proposables dans un bloc « Évènement ».
$events = [];
if ($ready) {
    try {
        $st = $pdo->query("SELECT id, title, start_datetime FROM planning_events WHERE is_cancelled = 0 AND start_datetime >= (NOW() - INTERVAL 7 DAY) ORDER BY start_datetime ASC LIMIT 60");
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $events[] = ['id' => (int)$r['id'], 'title' => (string)$r['title'], 'when' => date('d/m H:i', strtotime((string)$r['start_datetime']))];
        }
    } catch (Throwable) {
        $events = [];
    }
}

$selected = mailing_parse_audiences((string)$c['audiences']);
$mailOk = suite_mail_ready('mailing');
$csrf = mailing_csrf();
$base = suite_base();
$assetV = static fn(string $f): int => (int)@filemtime(dirname(__DIR__) . $f);
$editorData = [
    'blocks'     => mailing_blocks_decode((string)$c['blocks']),
    'logoUrl'    => mailing_logo_url($base),
    'events'     => $events,
    'directory'  => $directory,
    'excluded'   => $excluded,
    'audiences'  => array_map(static fn(array $a): string => $a['label'], $audiences),
    'csrf'       => $csrf,
    'previewUrl' => $base . '/mailing/block_preview.php',
    'uploadUrl'  => $base . '/mailing/upload_image.php',
    'imageBase'  => $base . '/mailing/image.php?f=',
];

mailing_page_start('Nouvelle newsletter', 'mailing-compose', 'Nouvelle newsletter');
?>
<link rel="stylesheet" href="<?= $h($base) ?>/assets/css/mailing_editor.css?v=<?= $assetV('/assets/css/mailing_editor.css') ?>">
<div class="tu-ph"><div>
  <div class="tu-ph-title"><?= $id ? 'Brouillon' : 'Nouvelle newsletter' ?></div>
  <div class="tu-ph-sub">Choisissez les destinataires, composez le message avec des blocs, testez, puis envoyez</div>
</div></div>

<?php if (!$ready): mailing_migration_notice(); else: ?>
<?php if ($errors): ?>
  <div style="background:var(--tu-red-soft);border-radius:12px;padding:12px 16px;margin-bottom:16px;color:var(--tu-red-main);font-size:13px;"><ul style="margin:0;padding-left:16px;"><?php foreach ($errors as $e): ?><li><?= $h($e) ?></li><?php endforeach; ?></ul></div>
<?php endif; ?>
<?php if (!$mailOk): ?>
  <div style="background:var(--tu-red-soft);border-radius:12px;padding:12px 16px;margin-bottom:16px;color:var(--tu-red-main);font-size:13px;">Aucune adresse d'expédition n'est configurée : définissez <code>MAIL_FROM</code> (ou <code>MAIL_FROM_MAILING</code>) dans le .env de la suite avant d'envoyer.</div>
<?php endif; ?>

<script type="application/json" id="mlData"><?= json_encode($editorData, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?></script>

<form method="post" id="mlForm">
  <input type="hidden" name="csrf" value="<?= $h($csrf) ?>"><input type="hidden" name="id" value="<?= (int)$id ?>">
  <input type="hidden" name="blocks" id="mlBlocksInput" value="[]"><input type="hidden" name="excluded" id="mlExcludedInput" value="[]">

  <div class="tu-card" style="margin-bottom:20px;">
    <div class="ml-card-h"><b>1 · Destinataires</b><p>Les listes sont calculées depuis les données existantes ; les désinscrits sont automatiquement exclus. <a href="lists.php" style="color:var(--tu-amber-600);font-weight:700;">Gérer les listes</a></p></div>
    <div class="ml-card-b">
      <div class="ml-grid" style="grid-template-columns:repeat(auto-fit,minmax(200px,1fr));">
      <?php foreach ($audiences as $k => $a): ?>
        <label class="tu-card" style="display:block;padding:12px 14px;cursor:pointer;">
          <input type="checkbox" name="audiences[]" value="<?= $h($k) ?>" <?= in_array($k, $selected, true) ? 'checked' : '' ?> data-ml-aud>
          <b style="margin-left:6px;"><?= $h($a['label']) ?></b>
          <div class="ml-muted" style="margin-top:4px;"><?= (int)($counts[$k] ?? 0) ?> destinataire<?= ($counts[$k] ?? 0) > 1 ? 's' : '' ?> · <?= $h($a['source']) ?></div>
        </label>
      <?php endforeach; ?>
      </div>
      <p id="mlTotal" style="margin:14px 0 0;font-size:13px;font-weight:700;"></p>
    </div>
    <details style="border-top:1px solid var(--tu-ink-100);">
      <summary style="padding:14px 18px;cursor:pointer;font-weight:700;font-size:13px;">Voir précisément qui reçoit ce message et exclure des personnes</summary>
      <div class="mlr-tools">
        <input type="search" id="mlrSearch" class="tu-input" placeholder="Rechercher un nom ou une adresse" style="min-width:260px;">
        <button type="button" class="mlr-chip is-on" id="mlrAll">Tous</button>
        <button type="button" class="mlr-chip" id="mlrOff">Exclus (0)</button>
        <button type="button" class="mlr-chip" id="mlrReset" style="margin-left:auto;border:0;background:none;color:var(--tu-amber-600);font-weight:700;">Tout recocher</button>
      </div>
      <div style="overflow-x:auto;"><table class="tu-tbl"><thead><tr><th></th><th>Nom</th><th>E-mail</th><th>Listes</th><th></th></tr></thead><tbody id="mlrBody"></tbody></table></div>
      <div class="mlr-pager"><span id="mlrCount"></span><span>Une personne présente dans plusieurs listes n'apparaît qu'une fois. Décocher quelqu'un ne l'exclut que de cet envoi.</span><span><button type="button" id="mlrPrev">‹ Précédent</button> <button type="button" id="mlrNext">Suivant ›</button></span></div>
    </details>
  </div>

  <div class="tu-card" style="margin-bottom:20px;">
    <div class="ml-card-h"><b>2 · Message</b></div>
    <div class="ml-card-b">
      <div class="ml-field"><label class="ml-lbl" for="subject">Objet *</label><input class="tu-input" id="subject" name="subject" maxlength="190" value="<?= $h($c['subject']) ?>" required></div>
      <div class="ml-field"><label class="ml-lbl" for="preheader">Texte d'aperçu (affiché à côté de l'objet dans la boîte de réception)</label><input class="tu-input" id="preheader" name="preheader" maxlength="190" value="<?= $h($c['preheader']) ?>"></div>
      <div class="ml-field" style="margin-bottom:0;"><label class="ml-lbl">Expéditeur</label>
        <?php $cfg = suite_mail_config('mailing'); ?>
        <div class="ml-muted"><?= $cfg['from'] !== '' ? $h($cfg['from_name']) . ' &lt;' . $h($cfg['from']) . '&gt;' : 'Non configuré' ?> — réglé dans le .env de la suite.</div>
      </div>
    </div>
  </div>

  <div class="tu-card" style="margin-bottom:20px;">
    <div class="ml-card-h"><b>3 · Contenu</b><p>Cliquez sur un bloc pour le régler, sur le « + » pour en ajouter un. L'aperçu est le message tel qu'il sera envoyé.</p></div>
    <div class="ml-card-b">
      <div class="mle">
        <div class="mle-canvas" id="mlCanvas"></div>
        <aside class="mle-panel" id="mlPanel"></aside>
      </div>
    </div>
  </div>

  <div class="tu-card">
    <div class="ml-card-h"><b>4 · Envoi</b><p>Les messages partent par lots d'environ <?= MAILING_BATCH_SIZE ?> par minute, pour ne pas être classés en courrier indésirable.</p></div>
    <div class="ml-card-b">
      <div class="ml-field"><label class="ml-lbl" for="scheduled_at">Planifier (laisser vide pour envoyer maintenant)</label><input type="datetime-local" class="tu-input" id="scheduled_at" name="scheduled_at" style="max-width:260px;"></div>
      <div style="display:flex;gap:10px;flex-wrap:wrap;">
        <button name="action" value="save" class="tu-btn tu-btn-s">Enregistrer le brouillon</button>
        <button name="action" value="test" class="tu-btn tu-btn-s">M'envoyer un test</button>
        <button name="action" value="send" class="tu-btn tu-btn-p" id="mlSendBtn" data-confirm="Envoyer cette newsletter ?" data-confirm-title="Envoyer la newsletter" data-confirm-label="Envoyer" data-confirm-tone="default" data-confirm-icon="mail" <?= $mailOk ? '' : 'disabled' ?>>Envoyer / planifier</button>
      </div>
    </div>
  </div>
</form>

<script>
(function () {
  var b = document.getElementById('mlSendBtn');
  if (!b) return;
  b.addEventListener('click', function () {
    var n = (document.getElementById('mlTotalN') || {}).textContent || '0';
    b.dataset.confirm = 'Envoyer cette newsletter à ' + n.trim() + ' personne(s) ?';
  }, true);
})();
</script>
<script src="<?= $h($base) ?>/assets/js/mailing_recipients.js?v=<?= $assetV('/assets/js/mailing_recipients.js') ?>"></script>
<script src="<?= $h($base) ?>/assets/js/mailing_editor.js?v=<?= $assetV('/assets/js/mailing_editor.js') ?>"></script>
<?php endif; ?>
<?php mailing_page_end();
