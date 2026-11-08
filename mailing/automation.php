<?php
declare(strict_types=1);

// mailing/automation.php — Modifier l'objet et le contenu (blocs) d'un message automatique, et s'envoyer un test.

require_once dirname(__DIR__) . '/shared/bootstrap.php';
require_admin();
require_once __DIR__ . '/includes/automations.php';

$pdo = mailing_pdo();
$defs = mailing_auto_definitions();
$type = (string)($_GET['type'] ?? $_POST['type'] ?? '');
if (!isset($defs[$type]) || !mailing_tables_ready($pdo) || !mailing_auto_tables_ready($pdo)) mailing_redirect('automations.php');
$def = $defs[$type];
$h = 'mailing_h';
$me = current_volunteer_id();

$cfg = mailing_auto_get($pdo, $type);
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!mailing_csrf_ok()) { http_response_code(400); exit('Session expirée : rechargez la page.'); }
    $action = (string)($_POST['action'] ?? 'save');

    if ($action === 'reset') {
        mailing_auto_reset_content($pdo, $type);
        audit_log('mailing', 'update', 'automation', null, $def['label'], ['content' => 'reset']);
        mailing_flash('Texte par défaut rétabli.');
        mailing_redirect('automation.php?type=' . $type);
    }

    $subject = trim((string)($_POST['subject'] ?? ''));
    $blocks = mailing_blocks_sanitize($pdo, json_decode((string)($_POST['blocks'] ?? '[]'), true), true);
    if ($subject === '') $errors[] = "L'objet est obligatoire.";
    if (!$blocks) $errors[] = "Le message est vide : ajoutez au moins un bloc.";

    if (!$errors) {
        mailing_auto_save_content($pdo, $type, $subject, $blocks);
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
                [$dow, $dm] = mailing_fr_date(strtotime('+1 day 09:00'));
                $m = mailing_auto_build($subject, $blocks, [
                    'prenom' => (string)($u['first_name'] ?? ''), 'evenement' => 'Chargement du convoi', 'date' => $dow . ' ' . $dm,
                    'horaires' => 'de 9h à 12h', 'planning_url' => $site . '/planning/events.php'], $site, str_repeat('0', 32));
                $err = suite_mail_send($addr, '[TEST] ' . $m['subject'], $m['text'], $m['html'], [], $def['channel']);
                mailing_flash($err === null ? "Message de test envoyé à $addr, avec des valeurs d'exemple." : "Envoi du test impossible : $err", $err === null ? 'ok' : 'err');
            }
        } else {
            audit_log('mailing', 'update', 'automation', null, $def['label'], ['content' => 'saved']);
            mailing_flash('Message enregistré.');
        }
        mailing_redirect('automation.php?type=' . $type);
    }
    $cfg['subject'] = $subject;
    $cfg['blocks'] = $blocks;
}

$base = suite_base();
$assetV = static fn(string $f): int => (int)@filemtime(dirname(__DIR__) . $f);
$csrf = mailing_csrf();
$editorData = [
    'blocks' => $cfg['blocks'], 'events' => [], 'csrf' => $csrf, 'allowVars' => true,
    'previewUrl' => $base . '/mailing/block_preview.php', 'uploadUrl' => $base . '/mailing/upload_image.php', 'imageBase' => $base . '/mailing/image.php?f=',
];

mailing_page_start($def['label'], 'mailing-auto', $def['label']);
?>
<link rel="stylesheet" href="<?= $h($base) ?>/assets/css/mailing_editor.css?v=<?= $assetV('/assets/css/mailing_editor.css') ?>">
<div class="tu-ph"><div>
  <div class="tu-ph-title"><?= $h($def['label']) ?></div>
  <div class="tu-ph-sub"><?= $h($def['desc']) ?></div>
</div></div>

<?php if ($errors): ?>
  <div style="background:var(--tu-red-soft);border-radius:12px;padding:12px 16px;margin-bottom:16px;color:var(--tu-red-main);font-size:13px;"><ul style="margin:0;padding-left:16px;"><?php foreach ($errors as $e): ?><li><?= $h($e) ?></li><?php endforeach; ?></ul></div>
<?php endif; ?>

<script type="application/json" id="mlData"><?= json_encode($editorData, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?></script>

<form method="post">
  <input type="hidden" name="csrf" value="<?= $h($csrf) ?>"><input type="hidden" name="type" value="<?= $h($type) ?>">
  <input type="hidden" name="blocks" id="mlBlocksInput" value="[]">

  <div class="tu-card" style="margin-bottom:20px;">
    <div class="ml-card-h"><b>Objet</b><p>Variables utilisables dans l'objet et dans les textes : <code><?= $h($def['vars']) ?></code>. Elles sont remplacées pour chaque personne.</p></div>
    <div class="ml-card-b"><input class="tu-input" name="subject" maxlength="190" value="<?= $h($cfg['subject']) ?>" required style="width:100%;box-sizing:border-box;"></div>
  </div>

  <div class="tu-card" style="margin-bottom:20px;">
    <div class="ml-card-h"><b>Contenu</b><p>Pour un bouton, mettez <code>{planning_url}</code> dans le lien pour ouvrir le planning. Le planning n'est ouvert qu'aux bénévoles déclarés.</p></div>
    <div class="ml-card-b"><div class="mle"><div class="mle-canvas" id="mlCanvas"></div><aside class="mle-panel" id="mlPanel"></aside></div></div>
  </div>

  <div style="display:flex;gap:10px;flex-wrap:wrap;">
    <button name="action" value="save" class="tu-btn tu-btn-p">Enregistrer</button>
    <button name="action" value="test" class="tu-btn tu-btn-s">M'envoyer un test</button>
    <a href="automations.php" class="tu-btn tu-btn-s">Retour</a>
    <?php if ($cfg['customized']): ?>
      <button name="action" value="reset" class="tu-btn tu-btn-s" style="margin-left:auto;" onclick="return confirm('Revenir au texte par défaut ? Votre version sera perdue.');" formnovalidate>Rétablir le texte par défaut</button>
    <?php endif; ?>
  </div>
</form>

<script src="<?= $h($base) ?>/assets/js/mailing_editor.js?v=<?= $assetV('/assets/js/mailing_editor.js') ?>"></script>
<?php mailing_page_end();
