<?php
declare(strict_types=1);

// mailing/compose.php — Rédiger une newsletter : destinataires, message, contenu, test, envoi ou planification.

require_once dirname(__DIR__) . '/shared/bootstrap.php';
require_admin();
require_once __DIR__ . '/includes/mailing.php';

$pdo = mailing_pdo();
$ready = mailing_tables_ready($pdo);
$audiences = mailing_audiences();
$me = current_volunteer_id();
$h = 'mailing_h';

$id = (int)($_GET['id'] ?? $_POST['id'] ?? 0);
$c = ['subject' => '', 'preheader' => '', 'title' => '', 'body' => '', 'button_label' => '', 'button_url' => '', 'audiences' => '', 'status' => 'draft', 'scheduled_at' => null];
if ($ready && $id > 0) {
    $st = $pdo->prepare("SELECT * FROM mailing_campaigns WHERE id = ?");
    $st->execute([$id]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if (!$row) { mailing_flash('Newsletter introuvable.', 'err'); mailing_redirect('index.php'); }
    if ($row['status'] !== 'draft') mailing_redirect('campaign.php?id=' . $id);
    $c = $row;
}

$errors = [];
if ($ready && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!mailing_csrf_ok()) { http_response_code(400); exit('Session expirée : rechargez la page.'); }
    $action = (string)($_POST['action'] ?? 'save');
    $c['subject']      = trim((string)($_POST['subject'] ?? ''));
    $c['preheader']    = trim((string)($_POST['preheader'] ?? ''));
    $c['title']        = trim((string)($_POST['title'] ?? ''));
    $c['body']         = trim((string)($_POST['body'] ?? ''));
    $c['button_label'] = trim((string)($_POST['button_label'] ?? ''));
    $c['button_url']   = trim((string)($_POST['button_url'] ?? ''));
    $keys = mailing_parse_audiences(implode(',', array_map('strval', (array)($_POST['audiences'] ?? []))));
    $c['audiences'] = implode(',', $keys);
    $when = trim((string)($_POST['scheduled_at'] ?? ''));

    if ($c['subject'] === '') $errors[] = "L'objet est obligatoire.";
    if (mb_strlen($c['subject']) > 190) $errors[] = "L'objet est trop long (190 caractères maximum).";
    if (($c['button_label'] === '') !== ($c['button_url'] === '')) $errors[] = "Le bouton demande un texte et un lien.";
    if ($c['button_url'] !== '' && !preg_match('~^https?://[^\s]+$~i', $c['button_url'])) $errors[] = "Le lien du bouton doit commencer par http:// ou https://.";

    if ($action === 'send' || $action === 'test') {
        if ($c['body'] === '') $errors[] = "Le message est vide.";
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
        if (!$errors && !mailing_resolve($pdo, $keys)['recipients']) $errors[] = "Les listes choisies ne contiennent aucun destinataire.";
    }

    if (!$errors) {
        $params = [$c['subject'], mb_substr($c['preheader'], 0, 190), mb_substr($c['title'], 0, 190), $c['body'], mb_substr($c['button_label'], 0, 60), mb_substr($c['button_url'], 0, 500), $c['audiences']];
        if ($id > 0) {
            $pdo->prepare("UPDATE mailing_campaigns SET subject=?, preheader=?, title=?, body=?, button_label=?, button_url=?, audiences=? WHERE id=? AND status='draft'")
                ->execute([...$params, $id]);
        } else {
            $pdo->prepare("INSERT INTO mailing_campaigns (subject, preheader, title, body, button_label, button_url, audiences, created_by) VALUES (?,?,?,?,?,?,?,?)")
                ->execute([...$params, $me]);
            $id = (int)$pdo->lastInsertId();
            audit_log('mailing', 'create', 'campaign', $id, $c['subject']);
        }

        if ($action === 'test') {
            $to = $pdo->prepare("SELECT email FROM users WHERE id = ?");
            $to->execute([$me]);
            $addr = trim((string)$to->fetchColumn());
            if (!filter_var($addr, FILTER_VALIDATE_EMAIL)) {
                mailing_flash("Votre fiche utilisateur n'a pas d'adresse e-mail valide : impossible d'envoyer le test.", 'err');
            } else {
                $url = mailing_unsubscribe_url(suite_mail_abs_url(''), str_repeat('0', 32));
                $err = suite_mail_send($addr, '[TEST] ' . $c['subject'], mailing_render_text($c, $url), mailing_render_html($c, $url), [], 'mailing');
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

// Adresses retenues par liste, pour calculer en direct le total sans doublon côté navigateur.
$sets = [];
$counts = [];
if ($ready) {
    foreach ($audiences as $k => $_) {
        $r = mailing_resolve($pdo, [$k]);
        $sets[$k] = array_keys($r['recipients']);
        $counts[$k] = count($sets[$k]);
    }
}
$selected = mailing_parse_audiences((string)$c['audiences']);
$mailOk = suite_mail_ready('mailing');

mailing_page_start('Nouvelle newsletter', 'mailing-compose', 'Nouvelle newsletter');
?>
<div class="tu-ph"><div>
  <div class="tu-ph-title"><?= $id ? 'Brouillon' : 'Nouvelle newsletter' ?></div>
  <div class="tu-ph-sub">Choisissez les destinataires, rédigez, testez, puis envoyez</div>
</div></div>

<?php if (!$ready): mailing_migration_notice(); else: ?>
<?php if ($errors): ?>
  <div style="background:var(--tu-red-soft);border-radius:12px;padding:12px 16px;margin-bottom:16px;color:var(--tu-red-main);font-size:13px;"><ul style="margin:0;padding-left:16px;"><?php foreach ($errors as $e): ?><li><?= $h($e) ?></li><?php endforeach; ?></ul></div>
<?php endif; ?>
<?php if (!$mailOk): ?>
  <div style="background:var(--tu-red-soft);border-radius:12px;padding:12px 16px;margin-bottom:16px;color:var(--tu-red-main);font-size:13px;">Aucune adresse d'expédition n'est configurée : définissez <code>MAIL_FROM</code> (ou <code>MAIL_FROM_MAILING</code>) dans le .env de la suite avant d'envoyer.</div>
<?php endif; ?>

<form method="post" id="mlForm" style="display:grid;grid-template-columns:minmax(0,1fr) minmax(0,420px);gap:20px;align-items:start;">
  <input type="hidden" name="csrf" value="<?= $h(mailing_csrf()) ?>"><input type="hidden" name="id" value="<?= (int)$id ?>">
  <div>
    <div class="tu-card" style="margin-bottom:20px;">
      <div class="ml-card-h"><b>1 · Destinataires</b><p>Les listes sont calculées depuis les données existantes ; les désinscrits sont automatiquement exclus. <a href="lists.php" style="color:var(--tu-amber-600);font-weight:700;">Gérer les listes</a></p></div>
      <div class="ml-card-b">
        <div class="ml-grid" style="grid-template-columns:repeat(auto-fit,minmax(200px,1fr));">
        <?php foreach ($audiences as $k => $a): ?>
          <label class="tu-card" style="display:block;padding:12px 14px;cursor:pointer;">
            <input type="checkbox" name="audiences[]" value="<?= $h($k) ?>" <?= in_array($k, $selected, true) ? 'checked' : '' ?> data-ml-aud>
            <b style="margin-left:6px;"><?= $h($a['label']) ?></b>
            <div class="ml-muted" style="margin-top:4px;"><?= (int)$counts[$k] ?> destinataire<?= $counts[$k] > 1 ? 's' : '' ?> · <?= $h($a['source']) ?></div>
          </label>
        <?php endforeach; ?>
        </div>
        <p id="mlTotal" style="margin:14px 0 0;font-size:13px;font-weight:700;"></p>
      </div>
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
      <div class="ml-card-h"><b>3 · Contenu</b><p>Séparez les paragraphes par une ligne vide. Les adresses web deviennent des liens.</p></div>
      <div class="ml-card-b">
        <div class="ml-field"><label class="ml-lbl" for="title">Titre dans le message</label><input class="tu-input" id="title" name="title" maxlength="190" value="<?= $h($c['title']) ?>"></div>
        <div class="ml-field"><label class="ml-lbl" for="body">Texte *</label><textarea class="tu-input" id="body" name="body" rows="12"><?= $h($c['body']) ?></textarea></div>
        <div style="display:grid;grid-template-columns:1fr 2fr;gap:12px;">
          <div class="ml-field" style="margin-bottom:0;"><label class="ml-lbl" for="button_label">Texte du bouton</label><input class="tu-input" id="button_label" name="button_label" maxlength="60" value="<?= $h($c['button_label']) ?>"></div>
          <div class="ml-field" style="margin-bottom:0;"><label class="ml-lbl" for="button_url">Lien du bouton</label><input class="tu-input" id="button_url" name="button_url" maxlength="500" placeholder="https://" value="<?= $h($c['button_url']) ?>"></div>
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
          <button name="action" value="send" class="tu-btn tu-btn-p" id="mlSend" onclick="return confirm('Envoyer cette newsletter à ' + document.getElementById('mlTotalN').textContent + ' personne(s) ?');" <?= $mailOk ? '' : 'disabled' ?>>Envoyer / planifier</button>
        </div>
      </div>
    </div>
  </div>

  <div class="tu-card" style="position:sticky;top:16px;">
    <div class="ml-card-h"><b>Aperçu</b><p><?= $id ? 'Reflète le dernier enregistrement.' : 'Enregistrez le brouillon pour voir l\'aperçu.' ?></p></div>
    <?php if ($id): ?>
      <iframe src="preview.php?id=<?= (int)$id ?>" title="Aperçu" style="width:100%;height:620px;border:0;background:#f5f0e8;border-radius:0 0 14px 14px;"></iframe>
    <?php endif; ?>
  </div>
</form>

<script>
(function () {
  var sets = <?= json_encode($sets, JSON_UNESCAPED_UNICODE) ?>;
  var boxes = document.querySelectorAll('[data-ml-aud]');
  var out = document.getElementById('mlTotal');
  function refresh() {
    var seen = {}, n = 0;
    boxes.forEach(function (b) {
      if (!b.checked) return;
      sets[b.value].forEach(function (e) { if (!seen[e]) { seen[e] = 1; n++; } });
    });
    out.innerHTML = n === 0 ? 'Aucun destinataire sélectionné'
      : '<span id="mlTotalN">' + n + '</span> destinataire' + (n > 1 ? 's' : '') + ' au total (doublons et désinscrits exclus)';
    if (n === 0) out.insertAdjacentHTML('beforeend', '<span id="mlTotalN" hidden>0</span>');
  }
  boxes.forEach(function (b) { b.addEventListener('change', refresh); });
  refresh();
})();
</script>
<?php endif; ?>
<?php mailing_page_end();
