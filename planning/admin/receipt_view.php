<?php
// admin/receipt_view.php
// Reçu fiscal (HTML imprimable) — sans génération PDF pour l'instant

require_once __DIR__ . '/../includes/app.php';
$config = require __DIR__ . '/../config/config.php';

if (session_status() === PHP_SESSION_NONE) session_start();
if (empty($_SESSION['is_admin']) && empty($_SESSION['admin_authenticated'])) {
    header("Location: {$config['base_url']}/admin/login.php");
    exit;
}

global $pdo;

function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

$id = (int)($_GET['id'] ?? 0);
if ($id <= 0) {
    http_response_code(400);
    echo "Reçu invalide.";
    exit;
}

$stmt = $pdo->prepare("SELECT * FROM donations WHERE id = :id LIMIT 1");
$stmt->execute(['id' => $id]);
$don = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$don) {
    http_response_code(404);
    echo "Don introuvable.";
    exit;
}
if ((string)($don['source'] ?? '') !== 'manual') {
    http_response_code(403);
    echo "Accès refusé : reçu fiscal disponible uniquement pour les dons manuels.";
    exit;
}

$orgName    = (string)($config['org_name'] ?? 'ASSOCIATION');
$orgRna     = (string)($config['org_rna'] ?? '');
$orgSiret   = (string)($config['org_siret'] ?? '');
$orgAddress = (string)($config['org_address'] ?? '');
$orgCity    = (string)($config['org_city'] ?? '');

$receiptNumber = (string)($don['receipt_number'] ?? '');
$receiptDate   = (string)($don['receipt_date'] ?? '');
$donationDate  = (string)($don['donation_date'] ?? '');

$receiptDateFr  = $receiptDate ? date('d/m/Y', strtotime($receiptDate)) : date('d/m/Y');
$donationDateFr = $donationDate ? date('d/m/Y H:i', strtotime($donationDate)) : '—';

$first = trim((string)($don['donor_first_name'] ?? ''));
$last  = trim((string)($don['donor_last_name'] ?? ''));
$donorName = trim($first . ' ' . $last);
$donorEmail = trim((string)($don['donor_email'] ?? ''));
$donorAddr  = trim((string)($don['donor_address'] ?? ''));
$donorZip   = trim((string)($don['donor_postal_code'] ?? ''));
$donorCity  = trim((string)($don['donor_city'] ?? ''));
$donorCountry = trim((string)($don['donor_country'] ?? ''));

$donorFullAddr = trim(implode(' ', array_filter([$donorAddr, $donorZip, $donorCity, $donorCountry])));
$amount = (float)($don['amount'] ?? 0);
$amountFr = number_format($amount, 2, ',', ' ') . " €";

$title = "Reçu fiscal – " . ($receiptNumber !== '' ? $receiptNumber : "don #{$id}");
ob_start();
?>
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
  .actions{display:none !important}
}
</style>

<div class="wrap">
  <div class="card">
    <div class="head">
      <div>
        <h1><?= h($title) ?></h1>
        <div class="sub">Date du reçu : <?= h($receiptDateFr) ?></div>
      </div>
      <div class="actions">
        <a class="btn" href="<?= h($config['base_url']) ?>/admin/donations.php?year=<?= (int)date('Y', strtotime($donationDate ?: 'now')) ?>">← Retour</a>
        <a class="btn" target="_blank" href="<?= h($config['base_url']) ?>/admin/receipt_pdf.php?id=<?= (int)$id ?>">Télécharger PDF</a>
        <button class="btn btn-primary" onclick="window.print()">Imprimer</button>
      </div>
    </div>

    <div class="grid">
      <div class="block">
        <h3>Organisme</h3>
        <div class="row"><div class="k">Organisme</div><div class="v"><?= h($orgName) ?></div></div>
        <?php if ($orgAddress !== '' || $orgCity !== ''): ?>
          <div class="row"><div class="k">Adresse</div><div class="v"><?= h(trim($orgAddress.' '.$orgCity)) ?></div></div>
        <?php endif; ?>
        <?php if ($orgRna !== ''): ?><div class="row"><div class="k">RNA</div><div class="v"><?= h($orgRna) ?></div></div><?php endif; ?>
        <?php if ($orgSiret !== ''): ?><div class="row"><div class="k">SIRET</div><div class="v mono"><?= h($orgSiret) ?></div></div><?php endif; ?>
      </div>

      <div class="block">
        <h3>Donateur</h3>
        <div class="row"><div class="k">Donateur</div><div class="v"><?= h($donorName ?: '—') ?></div></div>
        <?php if ($donorEmail !== ''): ?><div class="row"><div class="k">Email</div><div class="v"><?= h($donorEmail) ?></div></div><?php endif; ?>
        <?php if ($donorFullAddr !== ''): ?><div class="row"><div class="k">Adresse</div><div class="v"><?= h($donorFullAddr) ?></div></div><?php endif; ?>
      </div>
    </div>

    <div class="block" style="margin-top:12px">
      <h3>Don</h3>
      <div class="row"><div class="k">Date du don</div><div class="v"><?= h($donationDateFr) ?></div></div>
      <div class="row"><div class="k">Nature</div><div class="v">Don en numéraire</div></div>
      <div class="row"><div class="k">Montant</div><div class="v big"><?= h($amountFr) ?></div></div>
    </div>

    <div class="legal">
      <strong>Mentions légales :</strong><br>
      Le présent reçu atteste que l'organisme ci‑dessus a reçu un don en numéraire du donateur mentionné.
      Ce don ouvre droit à une réduction d'impôt au titre de l'article 200 du Code général des impôts
      (et/ou article 238 bis pour les entreprises, selon la situation du donateur), sous réserve de la réglementation en vigueur.<br><br>
      L'organisme certifie sur l'honneur remplir les conditions prévues par la loi pour bénéficier des dispositions fiscales relatives aux dons.<br><br>
      <span style="color:var(--mut)">Document interne (HTML imprimable). La génération PDF sera ajoutée ultérieurement.</span>
    </div>
  </div>
</div>

<?php
$content = ob_get_clean();
include __DIR__ . '/../includes/layout.php';
