<?php
declare(strict_types=1);

// mailing/automation.php — Créer ou modifier une règle de message automatique : déclencheur, délai, heure, filtres, contenu (blocs).

require_once dirname(__DIR__) . '/shared/bootstrap.php';
require_admin();
require_once __DIR__ . '/includes/automations.php';

$pdo = mailing_pdo();
if (!mailing_tables_ready($pdo) || !mailing_auto_tables_ready($pdo)) mailing_redirect('automations.php');
$h = 'mailing_h';
$me = current_volunteer_id();
$defs = mailing_trigger_defs();
$typeLabels = mailing_event_type_labels($pdo);
$groupLabels = mailing_group_labels($pdo);

$id = (int)($_GET['id'] ?? $_POST['id'] ?? 0);
$rule = null;
if ($id > 0) {
    $rule = mailing_rule_get($pdo, $id);
    if (!$rule) mailing_redirect('automations.php');
    $trigger = $rule['trigger'];
} else {
    $trigger = (string)($_GET['trigger'] ?? $_POST['trigger'] ?? '');
}
$isNew = $rule === null;
$def = $defs[$trigger] ?? null;
$base = suite_base();

// Étape 1 d'une création : choisir le déclencheur.
if ($isNew && !$def) {
    mailing_page_start('Nouvelle règle', 'mailing-auto', 'Nouvelle règle');
    ?>
<div class="tu-ph"><div>
  <div class="tu-ph-title">Nouvelle règle</div>
  <div class="tu-ph-sub">Quand le message doit-il partir ?</div>
</div></div>
<div class="ml-grid" style="grid-template-columns:repeat(auto-fit,minmax(260px,1fr));margin-bottom:22px;">
  <?php foreach ($defs as $key => $d): ?>
    <a href="automation.php?new=1&amp;trigger=<?= $h($key) ?>" class="tu-card" style="padding:18px 20px;text-decoration:none;color:inherit;display:flex;flex-direction:column;gap:8px;">
      <b style="font-family:var(--tu-font-d);font-size:15px;"><?= $h($d['label']) ?></b>
      <span style="font-size:13px;color:var(--tu-ink-400);line-height:1.55;"><?= $h($d['desc']) ?></span>
      <span class="ml-muted">Modèle : « <?= $h($d['name']) ?> »</span>
    </a>
  <?php endforeach; ?>
</div>
<a href="automations.php" class="tu-btn tu-btn-s">Retour</a>
<?php
    mailing_page_end();
    exit;
}
if (!$def) mailing_redirect('automations.php');

// Valeurs affichées (règle existante, ou modèle pour une création).
$form = $rule ?? [
    'id' => 0, 'name' => $def['name'], 'trigger' => $trigger, 'delay' => $def['delay_default'], 'hour' => $def['hour_default'],
    'types' => [], 'groups' => [], 'enabled' => false, 'subject' => $def['subject'], 'blocks' => $def['blocks'], 'customized' => false,
];
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!mailing_csrf_ok()) { http_response_code(400); exit('Session expirée : rechargez la page.'); }
    $action = (string)($_POST['action'] ?? 'save');

    if ($action === 'reset' && $rule) {
        mailing_rule_reset_content($pdo, $id);
        audit_log('mailing', 'update', 'automation', $id, $rule['name'], ['content' => 'reset']);
        mailing_flash('Texte par défaut rétabli.');
        mailing_redirect('automation.php?id=' . $id);
    }
    if ($action === 'delete' && $rule) {
        mailing_rule_delete($pdo, $id);
        audit_log('mailing', 'delete', 'automation', $id, $rule['name']);
        mailing_flash("« {$rule['name']} » est supprimé.");
        mailing_redirect('automations.php');
    }

    $name = trim((string)($_POST['name'] ?? ''));
    $subject = trim((string)($_POST['subject'] ?? ''));
    $blocks = mailing_blocks_sanitize($pdo, json_decode((string)($_POST['blocks'] ?? '[]'), true), true);
    $delay = $def['delay'] ? max(0, min(30, (int)($_POST['delay'] ?? 0))) : 0;
    $hour = $def['hour'] ? max(0, min(23, (int)($_POST['hour'] ?? 0))) : 0;
    $types = $def['filters'] ? array_values(array_intersect(array_map('strval', (array)($_POST['types'] ?? [])), array_keys($typeLabels))) : [];
    $groups = $def['filters'] ? array_values(array_intersect(array_map('intval', (array)($_POST['groups'] ?? [])), array_keys($groupLabels))) : [];
    $wantOn = !empty($_POST['enabled']);
    if ($name === '') $errors[] = 'Donnez un nom à la règle.';
    if ($subject === '') $errors[] = "L'objet est obligatoire.";
    if (!$blocks) $errors[] = 'Le message est vide : ajoutez au moins un bloc.';
    if ($wantOn && !suite_mail_ready($def['channel'])) $errors[] = "Adresse d'expédition indéterminée : définissez MAIL_FROM dans le .env de la suite avant d'activer ce message.";

    if (!$errors) {
        $data = ['name' => $name, 'trigger' => $trigger, 'delay' => $delay, 'hour' => $hour, 'types' => $types, 'groups' => $groups, 'subject' => $subject, 'blocks' => $blocks];
        $savedId = mailing_rule_save($pdo, $isNew ? null : $id, $data);
        if ($wantOn !== (bool)($rule['enabled'] ?? false)) {
            mailing_rule_set_enabled($pdo, $savedId, $wantOn, suite_mail_abs_url(''));
        }
        audit_log('mailing', $isNew ? 'create' : 'update', 'automation', $savedId, $name, ['trigger' => $trigger, 'enabled' => $wantOn]);

        if ($action === 'test') {
            $to = $pdo->prepare("SELECT first_name, email FROM users WHERE id = ?");
            $to->execute([$me]);
            $u = $to->fetch(PDO::FETCH_ASSOC) ?: [];
            $addr = trim((string)($u['email'] ?? ''));
            if (!filter_var($addr, FILTER_VALIDATE_EMAIL)) {
                mailing_flash("Votre fiche utilisateur n'a pas d'adresse e-mail valide : impossible d'envoyer le test.", 'err');
            } elseif (!suite_mail_ready($def['channel'])) {
                mailing_flash("Adresse d'expédition indéterminée : définissez MAIL_FROM dans le .env de la suite.", 'err');
            } else {
                $site = suite_mail_abs_url('');
                $m = mailing_auto_build($subject, $blocks, mailing_trigger_sample_vars($trigger, (string)($u['first_name'] ?? '')), $site, str_repeat('0', 32));
                $err = suite_mail_send($addr, '[TEST] ' . $m['subject'], $m['text'], $m['html'], [], $def['channel']);
                mailing_flash($err === null ? "Message de test envoyé à $addr, avec des valeurs d'exemple." : "Envoi du test impossible : $err", $err === null ? 'ok' : 'err');
            }
        } else {
            mailing_flash($isNew ? 'Règle créée.' : 'Règle enregistrée.');
        }
        mailing_redirect('automation.php?id=' . $savedId);
    }
    $form = array_merge($form, ['name' => $name, 'subject' => $subject, 'blocks' => $blocks, 'delay' => $delay, 'hour' => $hour, 'types' => $types, 'groups' => $groups, 'enabled' => $wantOn]);
}

$assetV = static fn(string $f): int => (int)@filemtime(dirname(__DIR__) . $f);
$csrf = mailing_csrf();
$editorData = [
    'blocks' => $form['blocks'], 'logoUrl' => mailing_logo_url($base), 'events' => [], 'csrf' => $csrf, 'allowVars' => true,
    'previewUrl' => $base . '/mailing/block_preview.php', 'uploadUrl' => $base . '/mailing/upload_image.php', 'imageBase' => $base . '/mailing/image.php?f=',
];
$delays = array_merge(range(0, 14), [21, 30]);
$delayLabel = static function (int $n, string $trigger): string {
    if ($trigger === 'before_slot') return $n === 0 ? 'Le jour même' : ($n === 1 ? '1 jour avant (la veille)' : "$n jours avant");
    return $n === 0 ? 'Le jour même (une fois le créneau terminé)' : ($n === 1 ? '1 jour après (le lendemain)' : "$n jours après");
};

mailing_page_start($isNew ? 'Nouvelle règle' : $form['name'], 'mailing-auto', $isNew ? 'Nouvelle règle' : $form['name']);
?>
<link rel="stylesheet" href="<?= $h($base) ?>/assets/css/mailing_editor.css?v=<?= $assetV('/assets/css/mailing_editor.css') ?>">
<style>
  .ml-fields { display: grid; gap: 14px; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); }
  .ml-fields label { display: block; font-size: 12px; font-weight: 700; color: var(--tu-ink-500); margin-bottom: 5px; }
  .ml-checks { display: flex; flex-wrap: wrap; gap: 8px 16px; }
  .ml-checks label { display: inline-flex; align-items: center; gap: 6px; font-size: 13px; font-weight: 500; color: var(--tu-ink-700); margin: 0; }
</style>
<div class="tu-ph"><div>
  <div class="tu-ph-title"><?= $isNew ? 'Nouvelle règle' : $h($form['name']) ?></div>
  <div class="tu-ph-sub"><?= $h($def['label']) ?> · <?= $h($def['desc']) ?></div>
</div></div>

<?php if ($errors): ?>
  <div style="background:var(--tu-red-soft);border-radius:12px;padding:12px 16px;margin-bottom:16px;color:var(--tu-red-main);font-size:13px;"><ul style="margin:0;padding-left:16px;"><?php foreach ($errors as $e): ?><li><?= $h($e) ?></li><?php endforeach; ?></ul></div>
<?php endif; ?>

<script type="application/json" id="mlData"><?= json_encode($editorData, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?></script>

<form method="post">
  <input type="hidden" name="csrf" value="<?= $h($csrf) ?>">
  <?php if ($isNew): ?><input type="hidden" name="trigger" value="<?= $h($trigger) ?>"><?php else: ?><input type="hidden" name="id" value="<?= (int)$id ?>"><?php endif; ?>
  <input type="hidden" name="blocks" id="mlBlocksInput" value="[]">

  <div class="tu-card" style="margin-bottom:20px;">
    <div class="ml-card-h"><b>Quand et à qui</b><p>Les heures sont celles de Paris. Un message n'est jamais envoyé deux fois pour le même fait.</p></div>
    <div class="ml-card-b">
      <div class="ml-fields">
        <div style="grid-column:1/-1;"><label for="f-name">Nom de la règle</label><input id="f-name" class="tu-input" name="name" maxlength="80" value="<?= $h($form['name']) ?>" required style="width:100%;box-sizing:border-box;"></div>
        <?php if ($def['delay']): ?>
        <div><label for="f-delay">Délai</label>
          <select id="f-delay" class="tu-input" name="delay" style="width:100%;">
            <?php foreach ($delays as $n): ?><option value="<?= $n ?>" <?= (int)$form['delay'] === $n ? 'selected' : '' ?>><?= $h($delayLabel($n, $trigger)) ?></option><?php endforeach; ?>
          </select></div>
        <?php endif; ?>
        <?php if ($def['hour']): ?>
        <div><label for="f-hour">À partir de</label>
          <select id="f-hour" class="tu-input" name="hour" style="width:100%;">
            <?php for ($i = 0; $i < 24; $i++): ?><option value="<?= $i ?>" <?= (int)$form['hour'] === $i ? 'selected' : '' ?>><?= $i ?>h</option><?php endfor; ?>
          </select></div>
        <?php endif; ?>
      </div>
      <?php if (!$def['delay']): ?><p class="ml-muted" style="margin:12px 0 0;"><?= $trigger === 'on_registration' ? "Le message part dans la minute qui suit l'inscription." : "Le message part dans la minute qui suit l'ajout du bénévole." ?></p><?php endif; ?>

      <?php if ($def['filters'] && ($typeLabels || $groupLabels)): ?>
      <div style="margin-top:16px;display:grid;gap:14px;">
        <?php if ($typeLabels): ?>
        <div><label style="display:block;font-size:12px;font-weight:700;color:var(--tu-ink-500);margin-bottom:6px;">Types d'évènement <span class="ml-muted" style="font-weight:500;">(aucun coché = tous)</span></label>
          <div class="ml-checks"><?php foreach ($typeLabels as $code => $label): ?>
            <label><input type="checkbox" name="types[]" value="<?= $h($code) ?>" <?= in_array($code, $form['types'], true) ? 'checked' : '' ?>> <?= $h($label) ?></label>
          <?php endforeach; ?></div></div>
        <?php endif; ?>
        <?php if ($groupLabels): ?>
        <div><label style="display:block;font-size:12px;font-weight:700;color:var(--tu-ink-500);margin-bottom:6px;">Groupes de bénévoles <span class="ml-muted" style="font-weight:500;">(aucun coché = tous)</span></label>
          <div class="ml-checks"><?php foreach ($groupLabels as $gid => $label): ?>
            <label><input type="checkbox" name="groups[]" value="<?= (int)$gid ?>" <?= in_array($gid, $form['groups'], true) ? 'checked' : '' ?>> <?= $h($label) ?></label>
          <?php endforeach; ?></div></div>
        <?php endif; ?>
      </div>
      <?php endif; ?>
    </div>
  </div>

  <div class="tu-card" style="margin-bottom:20px;">
    <div class="ml-card-h"><b>Objet</b><p>Variables utilisables dans l'objet et dans les textes : <code><?= $h($def['vars']) ?></code>. Elles sont remplacées pour chaque personne.</p></div>
    <div class="ml-card-b"><input class="tu-input" name="subject" maxlength="190" value="<?= $h($form['subject']) ?>" required style="width:100%;box-sizing:border-box;"></div>
  </div>

  <div class="tu-card" style="margin-bottom:20px;">
    <div class="ml-card-h"><b>Contenu</b><p>Pour un bouton, mettez <code>{planning_url}</code> dans le lien pour ouvrir le planning. Le planning n'est ouvert qu'aux bénévoles déclarés.</p></div>
    <div class="ml-card-b"><div class="mle"><div class="mle-canvas" id="mlCanvas"></div><aside class="mle-panel" id="mlPanel"></aside></div></div>
  </div>

  <div class="tu-card" style="margin-bottom:20px;padding:14px 18px;">
    <label style="display:flex;align-items:center;gap:10px;font-size:14px;font-weight:700;cursor:pointer;">
      <input type="checkbox" name="enabled" value="1" <?= $form['enabled'] ? 'checked' : '' ?>>
      Activer cette règle
      <span class="ml-muted" style="font-weight:500;"><?= $trigger === 'new_volunteer' ? "(les bénévoles déjà présents ne recevront pas le message)" : "(à l'activation, les messages dus partent au prochain passage)" ?></span>
    </label>
  </div>

  <div style="display:flex;gap:10px;flex-wrap:wrap;">
    <button name="action" value="save" class="tu-btn tu-btn-p"><?= $isNew ? 'Créer la règle' : 'Enregistrer' ?></button>
    <button name="action" value="test" class="tu-btn tu-btn-s">M'envoyer un test</button>
    <a href="automations.php" class="tu-btn tu-btn-s">Retour</a>
    <?php if (!$isNew): ?>
      <span style="margin-left:auto;display:flex;gap:10px;flex-wrap:wrap;">
      <?php if ($form['customized']): ?>
        <button name="action" value="reset" class="tu-btn tu-btn-s" data-confirm-icon="refresh" data-confirm-label="Rétablir" data-confirm="Revenir au texte par défaut ? Votre version sera perdue." formnovalidate>Rétablir le texte par défaut</button>
      <?php endif; ?>
        <button name="action" value="delete" class="tu-btn tu-btn-s" style="color:var(--tu-red-main);" data-confirm="Supprimer la règle « <?= $h($form['name']) ?> » ? Les messages déjà envoyés restent dans le journal." data-confirm-title="Supprimer la règle" data-confirm-label="Supprimer" formnovalidate>Supprimer</button>
      </span>
    <?php endif; ?>
  </div>
</form>

<script src="<?= $h($base) ?>/assets/js/mailing_editor.js?v=<?= $assetV('/assets/js/mailing_editor.js') ?>"></script>
<?php mailing_page_end();
