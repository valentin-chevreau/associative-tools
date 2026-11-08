<?php
declare(strict_types=1);

// mailing/lists.php — Listes de diffusion : calculées depuis l'existant, avec ajouts / retraits manuels
// et choix « newsletter oui / non » de chaque personne.

require_once dirname(__DIR__) . '/shared/bootstrap.php';
require_admin();
require_once __DIR__ . '/includes/mailing.php';

$pdo = mailing_pdo();
$ready = mailing_tables_ready($pdo);
$audiences = mailing_audiences();
$me = current_volunteer_id();
$h = 'mailing_h';

$key = (string)($_GET['list'] ?? '');
if ($key !== '' && !isset($audiences[$key])) $key = '';

if ($ready && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!mailing_csrf_ok()) { http_response_code(400); exit('Session expirée : rechargez la page.'); }
    $action = (string)($_POST['action'] ?? '');
    $pk = (string)($_POST['list'] ?? '');
    $email = mb_strtolower(trim((string)($_POST['email'] ?? '')));
    $back = 'lists.php' . ($pk !== '' ? '?list=' . urlencode($pk) : '');

    if ($action === 'add' && isset($audiences[$pk])) {
        $err = mailing_add_to_list($pdo, $pk, $email, (string)($_POST['name'] ?? ''), $me);
        if ($err) mailing_flash($err, 'err');
        else { audit_log('mailing', 'create', 'list_member', null, $audiences[$pk]['label'], ['list' => $pk]); mailing_flash("Personne ajoutée à « {$audiences[$pk]['label']} »."); }
    } elseif ($action === 'remove' && isset($audiences[$pk])) {
        mailing_remove_from_list($pdo, $pk, $email, $me);
        audit_log('mailing', 'delete', 'list_member', null, $audiences[$pk]['label'], ['list' => $pk]);
        mailing_flash("Personne retirée de « {$audiences[$pk]['label']} ». Elle reste dans les autres listes.");
    } elseif ($action === 'restore' && isset($audiences[$pk])) {
        $pdo->prepare("DELETE FROM mailing_exclusions WHERE list_key = ? AND email = ?")->execute([$pk, $email]);
        mailing_flash("Personne remise dans « {$audiences[$pk]['label']} ».");
    } elseif ($action === 'suppress') {
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) mailing_flash("Adresse e-mail invalide.", 'err');
        else { mailing_suppress($pdo, $email); audit_log('mailing', 'update', 'optout', null, 'Désinscription', ['by' => 'admin']); mailing_flash("Cette adresse ne recevra plus aucune newsletter."); }
    } elseif ($action === 'unsuppress') {
        mailing_unsuppress($pdo, $email);
        audit_log('mailing', 'update', 'optin', null, 'Réinscription', ['by' => 'admin']);
        mailing_flash("Cette adresse peut de nouveau recevoir les newsletters.");
    }
    mailing_redirect($back);
}

mailing_page_start('Listes de diffusion', 'mailing-lists', 'Listes de diffusion');
?>
<div class="tu-ph"><div>
  <div class="tu-ph-title">Listes de diffusion</div>
  <div class="tu-ph-sub">Calculées à partir des données de la suite ; vous pouvez y ajouter ou en retirer des personnes</div>
</div></div>

<?php if (!$ready): mailing_migration_notice(); else:
$csrf = mailing_csrf();
$suppressed = $pdo->query("SELECT email, source, created_at FROM mailing_suppressions ORDER BY created_at DESC")->fetchAll(PDO::FETCH_ASSOC);

if ($key === ''): ?>
  <div class="ml-grid" style="margin-bottom:22px;">
  <?php foreach ($audiences as $k => $a):
      $r = mailing_resolve($pdo, [$k]); $members = count(mailing_members($pdo, $k)); ?>
    <a href="lists.php?list=<?= $h($k) ?>" class="tu-card" style="display:block;padding:18px;text-decoration:none;color:inherit;">
      <span class="ml-pill"><?= $h($a['source']) ?></span>
      <div style="font-family:var(--tu-font-d);font-weight:800;font-size:28px;margin:10px 0 2px;"><?= (int)($r['per'][$k] ?? 0) ?></div>
      <div style="font-weight:700;"><?= $h($a['label']) ?></div>
      <div class="ml-muted" style="margin-top:4px;"><?= $h($a['desc']) ?></div>
      <?php if ($members > ($r['per'][$k] ?? 0)): ?>
        <div class="ml-muted" style="margin-top:6px;"><?= $members - (int)$r['per'][$k] ?> désinscrit<?= $members - (int)$r['per'][$k] > 1 ? 's' : '' ?> exclu<?= $members - (int)$r['per'][$k] > 1 ? 's' : '' ?></div>
      <?php endif; ?>
    </a>
  <?php endforeach; ?>
  </div>
<?php else:
  $a = $audiences[$key]; $members = mailing_members($pdo, $key); $excluded = mailing_excluded_members($pdo, $key); ?>
  <p style="margin:0 0 14px;"><a href="lists.php" style="color:var(--tu-amber-600);font-weight:700;">← Toutes les listes</a></p>
  <div class="tu-card" style="margin-bottom:22px;">
    <div class="ml-card-h"><b><?= $h($a['label']) ?></b><p><?= $h($a['desc']) ?> Retirer quelqu'un ici ne le retire que de cette liste.</p></div>
    <form method="post" class="ml-card-b" style="display:flex;gap:10px;flex-wrap:wrap;align-items:end;border-bottom:1px solid var(--tu-ink-100);">
      <input type="hidden" name="csrf" value="<?= $h($csrf) ?>"><input type="hidden" name="action" value="add"><input type="hidden" name="list" value="<?= $h($key) ?>">
      <div><label class="ml-lbl">Adresse e-mail</label><input type="email" name="email" class="tu-input" required style="min-width:240px;"></div>
      <div><label class="ml-lbl">Nom (facultatif)</label><input type="text" name="name" class="tu-input" maxlength="190" style="min-width:200px;"></div>
      <button type="submit" class="tu-btn tu-btn-p">Ajouter à la liste</button>
    </form>
    <div style="overflow-x:auto;">
    <table class="tu-tbl"><thead><tr><th>Nom</th><th>E-mail</th><th>Origine</th><th>Newsletter</th><th></th></tr></thead><tbody>
    <?php foreach ($members as $m): ?>
      <tr>
        <td><?= $h($m['name']) ?></td><td><?= $h($m['email']) ?></td>
        <td><span class="ml-pill"><?= $m['origin'] === 'manuel' ? 'Ajouté ici' : $h($a['source']) ?></span></td>
        <td><?= $m['suppressed'] ? '<span class="ml-pill ml-pill-red">Désinscrit</span>' : '<span class="ml-pill ml-pill-green">Autorisée</span>' ?></td>
        <td style="text-align:right;white-space:nowrap;">
          <form method="post" style="display:inline;">
            <input type="hidden" name="csrf" value="<?= $h($csrf) ?>"><input type="hidden" name="email" value="<?= $h($m['email']) ?>"><input type="hidden" name="list" value="<?= $h($key) ?>">
            <?php if ($m['suppressed']): ?>
              <button name="action" value="unsuppress" class="tu-btn tu-btn-s tu-btn-sm">Réinscrire</button>
            <?php else: ?>
              <button name="action" value="suppress" class="tu-btn tu-btn-s tu-btn-sm" onclick="return confirm('Cette personne ne recevra plus aucune newsletter, dans aucune liste. Continuer ?');">Désinscrire</button>
            <?php endif; ?>
            <button name="action" value="remove" class="tu-btn tu-btn-s tu-btn-sm">Retirer de la liste</button>
          </form>
        </td>
      </tr>
    <?php endforeach; if (!$members): ?><tr><td colspan="5" class="ml-muted" style="padding:18px;">Aucune personne dans cette liste.</td></tr><?php endif; ?>
    </tbody></table></div>
  </div>

  <?php if ($excluded): ?>
  <div class="tu-card" style="margin-bottom:22px;">
    <div class="ml-card-h"><b>Retirés de cette liste</b><p>Ces personnes ne reçoivent rien via cette liste.</p></div>
    <div class="ml-card-b">
    <?php foreach ($excluded as $m): ?>
      <form method="post" style="display:flex;justify-content:space-between;gap:10px;padding:6px 0;font-size:13px;">
        <span><?= $h($m['name'] !== '' ? $m['name'] . ' — ' : '') . $h($m['email']) ?></span>
        <input type="hidden" name="csrf" value="<?= $h($csrf) ?>"><input type="hidden" name="email" value="<?= $h($m['email']) ?>"><input type="hidden" name="list" value="<?= $h($key) ?>">
        <button name="action" value="restore" class="tu-btn tu-btn-s tu-btn-sm">Remettre</button>
      </form>
    <?php endforeach; ?>
    </div>
  </div>
  <?php endif; ?>
<?php endif; ?>

  <div class="tu-card" style="margin-bottom:22px;">
    <div class="ml-card-h"><b>Désinscrits (<?= count($suppressed) ?>)</b><p>Personnes qui ne veulent plus recevoir de newsletter, quelle que soit la liste. Elles se désinscrivent par le lien en bas des e-mails ; vous pouvez aussi enregistrer une demande reçue autrement, ou réinscrire quelqu'un qui le demande.</p></div>
    <form method="post" class="ml-card-b" style="display:flex;gap:10px;flex-wrap:wrap;align-items:end;border-bottom:1px solid var(--tu-ink-100);">
      <input type="hidden" name="csrf" value="<?= $h($csrf) ?>"><input type="hidden" name="action" value="suppress"><input type="hidden" name="list" value="<?= $h($key) ?>">
      <div><label class="ml-lbl">Désinscrire une adresse</label><input type="email" name="email" class="tu-input" required style="min-width:260px;"></div>
      <button type="submit" class="tu-btn tu-btn-s">Enregistrer le refus</button>
    </form>
    <?php if ($suppressed): ?>
    <table class="tu-tbl"><thead><tr><th>E-mail</th><th>Comment</th><th>Date</th><th></th></tr></thead><tbody>
    <?php foreach ($suppressed as $s): ?>
      <tr><td><?= $h($s['email']) ?></td><td><?= $s['source'] === 'link' ? 'Lien de désinscription' : 'Saisi par un admin' ?></td><td><?= $h(date('d/m/Y', strtotime((string)$s['created_at']))) ?></td>
      <td style="text-align:right;"><form method="post" style="display:inline;"><input type="hidden" name="csrf" value="<?= $h($csrf) ?>"><input type="hidden" name="email" value="<?= $h($s['email']) ?>"><input type="hidden" name="list" value="<?= $h($key) ?>"><button name="action" value="unsuppress" class="tu-btn tu-btn-s tu-btn-sm">Réinscrire</button></form></td></tr>
    <?php endforeach; ?></tbody></table>
    <?php endif; ?>
  </div>
<?php endif; ?>
<?php mailing_page_end();
