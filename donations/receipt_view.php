<?php
declare(strict_types=1);

// donations/receipt_view.php
// Reçu fiscal : aperçu HTML, impression, téléchargement PDF et envoi par e-mail.

require_once dirname(__DIR__) . '/shared/bootstrap.php';
require_admin(); // l'accès au module est contrôlé par shared/bootstrap.php (droits par module)

require_once __DIR__ . '/includes/receipt.php';

function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

$pdo = _bootstrap_get_pdo();
if (!($pdo instanceof PDO)) {
    http_response_code(500);
    echo "Connexion base de données indisponible.";
    exit;
}

$base = function_exists('suite_base') ? suite_base() : '';

$d = receipt_build($pdo, (int)($_GET['id'] ?? 0), require __DIR__ . '/config.php', false);
if (isset($d['error'])) {
    http_response_code((int)$d['code']);
    echo h($d['error']);
    exit;
}
$id = (int)$d['id'];

$flash = $_SESSION['receipt_flash'] ?? null;
unset($_SESSION['receipt_flash']);
$lastMail = receipt_last_email($pdo, $id);
$hasReceipt = $d['number'] !== '';

$title = "Reçu fiscal – " . ($hasReceipt ? $d['number'] : "don #{$id}");
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= h($title) ?></title>
<style>
:root{--bd:#e5e7eb;--mut:#6b7280;--bg:#f9fafb;--ink:#111827;}
*{box-sizing:border-box}
body{margin:0;background:var(--bg);color:var(--ink);font-family:system-ui,-apple-system,Segoe UI,Roboto,Ubuntu,'Helvetica Neue',Arial,sans-serif;font-weight:400}
.wrap{max-width:920px;margin:24px auto;padding:0 14px}
.card{background:#fff;border:1px solid var(--bd);border-radius:16px;padding:18px}
.head{display:flex;justify-content:space-between;align-items:flex-start;gap:12px;flex-wrap:wrap}
h1{margin:0;font-size:20px;font-weight:700}
.sub{color:var(--mut);font-size:13px;margin-top:4px}
.actions{display:flex;gap:8px;flex-wrap:wrap}
.btn{border:1px solid var(--bd);background:#fff;border-radius:999px;padding:8px 12px;font-weight:600;cursor:pointer}
.btn-primary{background:#111827;color:#fff;border-color:#111827}
.btn-mail{background:#d4732a;color:#fff;border-color:#d4732a}
a.btn{text-decoration:none;color:inherit;display:inline-block}
a.btn.btn-primary{color:#fff}
.flash{margin:0 0 14px;padding:10px 14px;border-radius:12px;font-size:13.5px;font-weight:600}
.flash.ok{background:#ecfdf5;color:#065f46;border:1px solid #a7f3d0}
.flash.ko{background:#fef2f2;color:#991b1b;border:1px solid #fecaca}
.sigimg{max-width:220px;max-height:90px;display:block;margin-top:6px}
.mailnote{color:var(--mut);font-size:12px;margin-top:10px}
.grid{display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-top:14px}
@media (max-width:720px){.grid{grid-template-columns:1fr}}
.block{border:1px solid var(--bd);border-radius:14px;padding:12px}
.block h3{margin:0 0 10px;font-size:14px;font-weight:700}
.row{display:flex;justify-content:space-between;gap:12px;padding:6px 0;border-top:1px solid #f3f4f6}
.row:first-of-type{border-top:none}
.k{color:var(--mut);font-weight:600;font-size:12px}
.v{font-weight:500;font-size:13px;text-align:right}
.big{font-size:18px;font-weight:600}
.legal{margin-top:14px;border-top:1px solid var(--bd);padding-top:12px;color:#374151;font-size:12.5px;line-height:1.45}
.mono{font-family:ui-monospace,SFMono-Regular,Menlo,Monaco,Consolas,'Liberation Mono','Courier New',monospace}
@media print{
  body{background:#fff}
  .wrap{max-width:none;margin:0;padding:0}
  .card{border:none;border-radius:0}
  .actions,.flash,.mailnote{display:none !important}
}
</style>
</head>
<body>

<div class="wrap">
  <?php if (is_array($flash)): ?>
    <div class="flash <?= !empty($flash['ok']) ? 'ok' : 'ko' ?>"><?= h($flash['text'] ?? '') ?></div>
  <?php endif; ?>
  <div class="card">
    <div class="head">
      <div>
        <h1><?= h($title) ?></h1>
        <div class="sub">Date du reçu : <?= h($d['receipt_date']) ?></div>
      </div>
      <div class="actions">
        <a class="btn" href="<?= h($base) ?>/donations/index.php?year=<?= (int)$d['donation_year'] ?>">← Retour</a>
        <?php if ($hasReceipt): ?>
          <a class="btn" target="_blank" href="<?= h($base) ?>/donations/receipt_pdf.php?id=<?= $id ?>">Télécharger PDF</a>
          <?php if (filter_var($d['donor_email'], FILTER_VALIDATE_EMAIL)): ?>
            <form method="post" action="<?= h($base) ?>/donations/receipt_send.php" style="display:inline"
                  onsubmit="return confirm('Envoyer le reçu fiscal <?= h($d['number']) ?> par e-mail à <?= h($d['donor_email']) ?> ?');">
              <input type="hidden" name="donation_id" value="<?= $id ?>">
              <button class="btn btn-mail" type="submit">Envoyer par email</button>
            </form>
          <?php endif; ?>
          <button class="btn btn-primary" onclick="window.print()">Imprimer</button>
        <?php endif; ?>
      </div>
    </div>
    <?php if ($hasReceipt && !filter_var($d['donor_email'], FILTER_VALIDATE_EMAIL)): ?>
      <div class="mailnote">Envoi par e-mail indisponible : aucune adresse e-mail valide pour ce donateur.</div>
    <?php elseif ($lastMail): ?>
      <div class="mailnote">Dernier envoi par e-mail : <?= h(date('d/m/Y à H:i', strtotime((string)$lastMail['created_at']))) ?><?php
        $dj = json_decode((string)($lastMail['details_json'] ?? ''), true);
        if (!empty($dj['destinataire'])) echo ' — ' . h($dj['destinataire']);
        if (!empty($lastMail['actor_name'])) echo ' (par ' . h($lastMail['actor_name']) . ')'; ?></div>
    <?php endif; ?>

    <div class="grid">
      <div class="block">
        <h3>Organisme</h3>
        <div class="row"><div class="k">Organisme</div><div class="v"><?= h($d['org_name']) ?></div></div>
        <?php if ($d['org_lines']): ?>
          <div class="row"><div class="k">Adresse</div><div class="v"><?= implode('<br>', array_map('h', $d['org_lines'])) ?></div></div>
        <?php endif; ?>
        <?php if ($d['org_rna'] !== ''): ?><div class="row"><div class="k">RNA</div><div class="v"><?= h($d['org_rna']) ?></div></div><?php endif; ?>
        <?php if ($d['org_siret'] !== ''): ?><div class="row"><div class="k">SIRET</div><div class="v mono"><?= h($d['org_siret']) ?></div></div><?php endif; ?>
      </div>

      <div class="block">
        <h3>Donateur</h3>
        <div class="row"><div class="k">Donateur</div><div class="v"><?= h($d['donor_name'] ?: '—') ?></div></div>
        <?php if ($d['donor_email'] !== ''): ?><div class="row"><div class="k">Email</div><div class="v"><?= h($d['donor_email']) ?></div></div><?php endif; ?>
        <?php if ($d['donor_lines']): ?><div class="row"><div class="k">Adresse</div><div class="v"><?= implode('<br>', array_map('h', $d['donor_lines'])) ?></div></div><?php endif; ?>
      </div>
    </div>

    <div class="block" style="margin-top:12px">
      <h3>Don</h3>
      <div class="row"><div class="k">Date du don</div><div class="v"><?= h($d['donation_date']) ?></div></div>
      <div class="row"><div class="k">Nature</div><div class="v">Don en numéraire</div></div>
      <div class="row"><div class="k">Montant</div><div class="v big"><?= h($d['amount_fr']) ?></div></div>
    </div>

    <div class="legal">
      <strong>Mentions légales :</strong><br>
      Le présent reçu atteste que l'organisme ci‑dessus a reçu un don en numéraire du donateur mentionné.
      Ce don ouvre droit à une réduction d'impôt au titre de l'article 200 du Code général des impôts
      (et/ou article 238 bis pour les entreprises, selon la situation du donateur), sous réserve de la réglementation en vigueur.<br><br>
      L'organisme certifie sur l'honneur remplir les conditions prévues par la loi pour bénéficier des dispositions fiscales relatives aux dons.<br><br>
      Fait à <?= h($d['done_at'] !== '' ? $d['done_at'] : '________________') ?>, le <?= h($d['receipt_date']) ?><br>
      Pour l'association,
      <?php if ($d['signatory']):
          $sp = $d['signatory']['path'];
          $mime = strtolower(pathinfo($sp, PATHINFO_EXTENSION)) === 'png' ? 'image/png' : 'image/jpeg'; ?>
        <img class="sigimg" alt="Signature" src="data:<?= $mime ?>;base64,<?= base64_encode((string)file_get_contents($sp)) ?>">
        <strong><?= h($d['signatory']['name']) ?></strong><br><?= h($d['signatory']['role']) ?>
      <?php else: ?>
        <br><br><br>Le Trésorier
      <?php endif; ?>
    </div>
  </div>
</div>

</body>
</html>
