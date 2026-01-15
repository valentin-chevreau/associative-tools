<?php
require_once __DIR__ . '/../db.php';

if (session_status() === PHP_SESSION_NONE) session_start();

$isAdmin = !empty($_SESSION['is_admin']);
$simpleMode = !empty($_SESSION['simple_mode']);

function h(string $s): string { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }

$familyId = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($familyId <= 0) {
    echo "<div class='alert alert-danger'>Famille introuvable.</div>";
    require __DIR__ . '/../footer.php';
    exit;
}

/* Famille */
$stmt = $pdo->prepare("SELECT * FROM families WHERE id = ?");
$stmt->execute([$familyId]);
$family = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$family) {
    echo "<div class='alert alert-danger'>Famille introuvable.</div>";
    require __DIR__ . '/../footer.php';
    exit;
}

/* Allocations (dossiers) */
$allocStmt = $pdo->prepare("
    SELECT a.*
    FROM stock_allocations a
    WHERE a.family_id = ?
    ORDER BY a.created_at DESC, a.id DESC
");
$allocStmt->execute([$familyId]);
$allocations = $allocStmt->fetchAll(PDO::FETCH_ASSOC);

/* Items par allocation */
$itemsByAlloc = [];
if (!empty($allocations)) {
    $ids = array_map(fn($a) => (int)$a['id'], $allocations);
    $placeholders = implode(',', array_fill(0, count($ids), '?'));

    $linesStmt = $pdo->prepare("
        SELECT
            ai.allocation_id,
            ai.qty,
            si.title,
            sc.label AS category_label,
            sl.label AS location_label
        FROM stock_allocation_items ai
        JOIN stock_items si ON si.id = ai.item_id
        JOIN stock_categories sc ON sc.id = si.category_id
        LEFT JOIN stock_locations sl ON sl.id = si.location_id
        WHERE ai.allocation_id IN ($placeholders)
        ORDER BY sc.label, si.title
    ");
    $linesStmt->execute($ids);

    while ($r = $linesStmt->fetch(PDO::FETCH_ASSOC)) {
        $aid = (int)$r['allocation_id'];
        if (!isset($itemsByAlloc[$aid])) $itemsByAlloc[$aid] = [];
        $itemsByAlloc[$aid][] = $r;
    }
}

$familyName = trim((string)($family['lastname'] ?? '') . ' ' . (string)($family['firstname'] ?? ''));
$ref = trim((string)($family['public_ref'] ?? ''));
$city = trim((string)($family['city'] ?? ''));
$phone = trim((string)($family['phone'] ?? ''));
$email = trim((string)($family['email'] ?? ''));
$hn = trim((string)($family['housing_notes'] ?? ''));
$notes = trim((string)($family['notes'] ?? ''));

function labelAllocStatus(string $s): string {
    switch ($s) {
        case 'reserved': return 'Réservé';
        case 'allocated': return 'Attribué';
        case 'canceled': return 'Annulé';
        case 'completed': return 'Terminé';
        default: return $s;
    }
}
function badgeAllocStatus(string $s): string {
    switch ($s) {
        case 'reserved': return 'warning';
        case 'allocated': return 'primary';
        case 'completed': return 'success';
        case 'canceled': return 'secondary';
        default: return 'light';
    }
}

require_once __DIR__ . '/../header.php';
?>

<div class="d-flex flex-column flex-md-row justify-content-between align-items-md-start gap-2 mb-3">
  <div>
    <div class="d-flex align-items-center gap-2">
      <h1 class="h4 mb-0"><?= h($familyName !== '' ? $familyName : ('Famille #' . $familyId)) ?></h1>
      <?php if ($ref !== ''): ?>
        <span class="badge bg-light text-dark"><?= h($ref) ?></span>
      <?php endif; ?>
      <?php $st = (string)($family['status'] ?? 'active'); ?>
      <span class="badge bg-<?= $st === 'active' ? 'success' : 'secondary' ?>">
        <?= $st === 'active' ? 'Active' : 'Inactive' ?>
      </span>
    </div>

    <div class="text-muted small mt-1">
      <?= $city !== '' ? h($city) : '<em>Ville à définir</em>' ?>
      <?php if (!$simpleMode): ?>
        <?php if ($phone !== ''): ?> • <?= h($phone) ?><?php endif; ?>
        <?php if ($email !== ''): ?> • <?= h($email) ?><?php endif; ?>
      <?php endif; ?>
    </div>

    <?php if (!$simpleMode && $hn !== ''): ?>
      <div class="text-muted small mt-1"><?= h($hn) ?></div>
    <?php endif; ?>
  </div>

  <div class="text-md-end d-flex gap-2">
    <a class="btn btn-outline-secondary btn-sm" href="<?= h(APP_BASE) ?>/families/index.php">← Retour</a>

    <?php if ($isAdmin): ?>
      <a class="btn btn-outline-primary btn-sm" href="<?= h(APP_BASE) ?>/families/edit.php?id=<?= (int)$familyId ?>">Éditer</a>
      <a class="btn btn-primary btn-sm" href="<?= h(APP_BASE) ?>/stock/reserve.php?family_id=<?= (int)$familyId ?>">
        ➕ Réserver du matériel
      </a>
    <?php endif; ?>
  </div>
</div>

<?php if (!$simpleMode && $notes !== ''): ?>
  <div class="card shadow-sm mb-3">
    <div class="card-body">
      <h2 class="h6 mb-2">Notes</h2>
      <div class="small" style="white-space:pre-wrap;"><?= h($notes) ?></div>
    </div>
  </div>
<?php endif; ?>

<div class="card shadow-sm mb-3">
  <div class="card-body">
    <div class="d-flex justify-content-between align-items-start gap-2 mb-2">
      <div>
        <h2 class="h6 mb-1">Réservations / Attributions</h2>
        <div class="text-muted small">
          Un “dossier” peut regrouper plusieurs objets pour une même visite / emménagement.
        </div>
      </div>
      <div class="text-muted small">
        <?= count($allocations) ?> dossier(s)
      </div>
    </div>

    <?php if (empty($allocations)): ?>
      <div class="alert alert-info mb-0">Aucun dossier pour cette famille.</div>
    <?php else: ?>
      <?php foreach ($allocations as $a): ?>
        <?php
          $aid = (int)$a['id'];
          $lines = $itemsByAlloc[$aid] ?? [];
          $ast = (string)($a['status'] ?? 'reserved');
        ?>
        <div class="border rounded p-3 mb-3">
          <div class="d-flex flex-column flex-md-row justify-content-between align-items-md-start gap-2">
            <div>
              <div class="d-flex align-items-center gap-2">
                <div class="fw-bold">Dossier #<?= $aid ?></div>
                <span class="badge bg-<?= h(badgeAllocStatus($ast)) ?>"><?= h(labelAllocStatus($ast)) ?></span>
              </div>
              <div class="text-muted small mt-1">
                Créé : <?= h((string)($a['created_at'] ?? '')) ?>
                <?php if (!empty($a['reserved_at'])): ?> • Réservé : <?= h((string)$a['reserved_at']) ?><?php endif; ?>
                <?php if (!empty($a['allocated_at'])): ?> • Attribué : <?= h((string)$a['allocated_at']) ?><?php endif; ?>
                <?php if (!empty($a['completed_at'])): ?> • Terminé : <?= h((string)$a['completed_at']) ?><?php endif; ?>
              </div>
              <?php if (!empty($a['notes'])): ?>
                <div class="small mt-2"><b>Note :</b> <?= h((string)$a['notes']) ?></div>
              <?php endif; ?>
            </div>

            <?php if ($isAdmin): ?>
              <div class="text-md-end">
                <a class="btn btn-sm btn-outline-primary"
                   href="<?= h(APP_BASE) ?>/stock/reserve.php?family_id=<?= (int)$familyId ?>&allocation_id=<?= (int)$aid ?>">
                  Modifier ce dossier
                </a>
                <div class="text-muted small mt-1"><?= count($lines) ?> ligne(s)</div>
              </div>
            <?php endif; ?>
          </div>

          <?php if (empty($lines)): ?>
            <div class="text-muted small mt-2">Aucun objet dans ce dossier.</div>
          <?php else: ?>
            <div class="table-responsive mt-3">
              <table class="table table-sm align-middle mb-0">
                <thead>
                  <tr>
                    <th>Catégorie</th>
                    <th>Objet</th>
                    <th>Lieu</th>
                    <th class="text-end" style="width:120px;">Quantité</th>
                  </tr>
                </thead>
                <tbody>
                  <?php foreach ($lines as $ln): ?>
                    <tr>
                      <td><?= h((string)$ln['category_label']) ?></td>
                      <td><?= h((string)$ln['title']) ?></td>
                      <td><?= h((string)($ln['location_label'] ?? '—')) ?></td>
                      <td class="text-end"><?= (int)$ln['qty'] ?></td>
                    </tr>
                  <?php endforeach; ?>
                </tbody>
              </table>
            </div>
          <?php endif; ?>
        </div>
      <?php endforeach; ?>
    <?php endif; ?>
  </div>
</div>

<?php require __DIR__ . '/../footer.php'; ?>
