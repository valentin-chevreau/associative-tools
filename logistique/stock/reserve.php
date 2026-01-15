<?php
// stock/reserve.php — Réserver du matériel pour une famille (dossiers/allocations)
// PHP 7.4+

if (session_status() === PHP_SESSION_NONE) session_start();
require_once __DIR__ . '/../db.php';

// ⚠️ IMPORTANT: pas de header.php avant les redirections

$isAdmin = !empty($_SESSION['is_admin']);
if (!$isAdmin) {
    http_response_code(403);
    echo "<div class='alert alert-danger'>Accès interdit.</div>";
    exit;
}

function h(string $s): string { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }
function get_int(string $k, int $d=0): int { return isset($_GET[$k]) ? (int)$_GET[$k] : $d; }
function post_int(string $k, int $d=0): int { return isset($_POST[$k]) ? (int)$_POST[$k] : $d; }
function post_str(string $k, string $d=''): string { return isset($_POST[$k]) ? trim((string)$_POST[$k]) : $d; }

$familyId = get_int('family_id', 0);
if ($familyId <= 0) {
    http_response_code(404);
    echo "Famille introuvable.";
    exit;
}

// Charger famille
$fst = $pdo->prepare("SELECT * FROM families WHERE id = ?");
$fst->execute([$familyId]);
$family = $fst->fetch(PDO::FETCH_ASSOC);
if (!$family) {
    http_response_code(404);
    echo "Famille introuvable.";
    exit;
}

// allocation_id (dossier) optionnel
$allocationId = get_int('allocation_id', 0);

// Trouver dossier "ouvert" par défaut (dernier réservé/attribué non terminé/annulé)
function findDefaultAllocationId(PDO $pdo, int $familyId): int {
    $st = $pdo->prepare("
        SELECT id
        FROM stock_allocations
        WHERE family_id = ?
          AND status IN ('reserved','allocated')
        ORDER BY created_at DESC, id DESC
        LIMIT 1
    ");
    $st->execute([$familyId]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    return $row ? (int)$row['id'] : 0;
}

// Créer un dossier (seulement si demandé explicitement)
function createAllocation(PDO $pdo, int $familyId, string $notes=''): int {
    $now = date('Y-m-d H:i:s');
    $ins = $pdo->prepare("
        INSERT INTO stock_allocations (family_id, status, reserved_at, notes, created_by)
        VALUES (?, 'reserved', ?, ?, ?)
    ");
    $createdBy = isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : null;
    $ins->execute([$familyId, $now, ($notes !== '' ? $notes : null), $createdBy]);
    return (int)$pdo->lastInsertId();
}

// Ajuster quantité d’un item (+/-) et statut simple basé sur quantité restante
function adjustItemQty(PDO $pdo, int $itemId, int $delta): void {
    // delta négatif => on consomme du stock disponible
    // delta positif => on "rend" du stock
    $pdo->prepare("UPDATE stock_items SET quantity = quantity + ? WHERE id = ?")
        ->execute([$delta, $itemId]);

    // Remettre une borne min à 0 (sécurité)
    $pdo->prepare("UPDATE stock_items SET quantity = GREATEST(quantity, 0) WHERE id = ?")
        ->execute([$itemId]);

    // statut auto: available si qty>0 sinon reserved (simple, cohérent avec V1)
    $pdo->prepare("
        UPDATE stock_items
        SET status = CASE WHEN quantity > 0 THEN 'available' ELSE 'reserved' END
        WHERE id = ?
    ")->execute([$itemId]);
}

// --- POST ACTIONS (AVANT TOUT HTML) ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = post_str('action');

    // Choix/refresh du dossier courant
    $postedAlloc = post_int('allocation_id', 0);
    if ($postedAlloc > 0) $allocationId = $postedAlloc;

    if ($action === 'new_allocation') {
        $notes = post_str('new_notes');
        $newId = createAllocation($pdo, $familyId, $notes);
        $_SESSION['flash_success'] = "Nouveau dossier créé.";
        header('Location: ' . APP_BASE . '/stock/reserve.php?family_id=' . $familyId . '&allocation_id=' . $newId);
        exit;
    }

    if ($action === 'select_allocation') {
        // juste navigation
        $sel = post_int('allocation_id', 0);
        if ($sel <= 0) $sel = findDefaultAllocationId($pdo, $familyId);
        header('Location: ' . APP_BASE . '/stock/reserve.php?family_id=' . $familyId . ($sel > 0 ? ('&allocation_id=' . $sel) : ''));
        exit;
    }

    // Pour modifier/ajouter des lignes: il faut un dossier existant.
    // -> IMPORTANT: on NE crée PAS de dossier si absent (c’est exactement ton problème).
    if (in_array($action, ['add_item','update_line','remove_line','update_alloc_notes','cancel_alloc'], true)) {
        if ($allocationId <= 0) {
            $_SESSION['flash_error'] = "Choisis un dossier existant ou crée-en un nouveau (on n’en crée plus automatiquement).";
            header('Location: ' . APP_BASE . '/stock/reserve.php?family_id=' . $familyId);
            exit;
        }

        // Vérifier que ce dossier appartient à la famille
        $chk = $pdo->prepare("SELECT * FROM stock_allocations WHERE id = ? AND family_id = ?");
        $chk->execute([$allocationId, $familyId]);
        $allocRow = $chk->fetch(PDO::FETCH_ASSOC);
        if (!$allocRow) {
            $_SESSION['flash_error'] = "Dossier introuvable pour cette famille.";
            header('Location: ' . APP_BASE . '/stock/reserve.php?family_id=' . $familyId);
            exit;
        }

        if ($action === 'update_alloc_notes') {
            $notes = post_str('notes');
            $pdo->prepare("UPDATE stock_allocations SET notes = ? WHERE id = ?")
                ->execute([($notes !== '' ? $notes : null), $allocationId]);
            $_SESSION['flash_success'] = "Notes du dossier mises à jour.";
            header('Location: ' . APP_BASE . '/stock/reserve.php?family_id=' . $familyId . '&allocation_id=' . $allocationId);
            exit;
        }

        if ($action === 'cancel_alloc') {
            // Annule le dossier + rend le stock des lignes
            $pdo->beginTransaction();
            try {
                $lines = $pdo->prepare("SELECT item_id, qty FROM stock_allocation_items WHERE allocation_id = ?");
                $lines->execute([$allocationId]);
                $all = $lines->fetchAll(PDO::FETCH_ASSOC);

                foreach ($all as $ln) {
                    $itemId = (int)$ln['item_id'];
                    $qty = (int)$ln['qty'];
                    if ($qty > 0) adjustItemQty($pdo, $itemId, +$qty);
                }

                $pdo->prepare("UPDATE stock_allocations SET status='canceled' WHERE id = ?")
                    ->execute([$allocationId]);

                $pdo->commit();
                $_SESSION['flash_success'] = "Dossier annulé (stock remis).";
            } catch (Throwable $e) {
                $pdo->rollBack();
                $_SESSION['flash_error'] = "Erreur lors de l’annulation du dossier.";
            }
            header('Location: ' . APP_BASE . '/stock/reserve.php?family_id=' . $familyId);
            exit;
        }

        if ($action === 'add_item') {
            $itemId = post_int('item_id', 0);
            $qtyAdd = max(1, post_int('qty', 1));

            // Lire stock dispo
            $it = $pdo->prepare("SELECT id, quantity, title FROM stock_items WHERE id = ? AND is_active = 1");
            $it->execute([$itemId]);
            $item = $it->fetch(PDO::FETCH_ASSOC);

            if (!$item) {
                $_SESSION['flash_error'] = "Objet introuvable.";
                header('Location: ' . APP_BASE . '/stock/reserve.php?family_id=' . $familyId . '&allocation_id=' . $allocationId);
                exit;
            }

            $available = (int)$item['quantity'];
            if ($available <= 0) {
                $_SESSION['flash_error'] = "Stock indisponible pour cet objet.";
                header('Location: ' . APP_BASE . '/stock/reserve.php?family_id=' . $familyId . '&allocation_id=' . $allocationId);
                exit;
            }

            $qtyAdd = min($qtyAdd, $available);

            $pdo->beginTransaction();
            try {
                // Upsert ligne
                $exists = $pdo->prepare("SELECT id, qty FROM stock_allocation_items WHERE allocation_id = ? AND item_id = ?");
                $exists->execute([$allocationId, $itemId]);
                $row = $exists->fetch(PDO::FETCH_ASSOC);

                if ($row) {
                    $pdo->prepare("UPDATE stock_allocation_items SET qty = qty + ? WHERE id = ?")
                        ->execute([$qtyAdd, (int)$row['id']]);
                } else {
                    $pdo->prepare("INSERT INTO stock_allocation_items (allocation_id, item_id, qty) VALUES (?,?,?)")
                        ->execute([$allocationId, $itemId, $qtyAdd]);
                }

                // Consommer le stock dispo
                adjustItemQty($pdo, $itemId, -$qtyAdd);

                $pdo->commit();
                $_SESSION['flash_success'] = "Ajouté au dossier.";
            } catch (Throwable $e) {
                $pdo->rollBack();
                $_SESSION['flash_error'] = "Erreur lors de l’ajout.";
            }

            header('Location: ' . APP_BASE . '/stock/reserve.php?family_id=' . $familyId . '&allocation_id=' . $allocationId);
            exit;
        }

        if ($action === 'update_line') {
            $lineId = post_int('line_id', 0);
            $newQty = max(0, post_int('qty', 0));

            // Lire ligne + item
            $ls = $pdo->prepare("
                SELECT ai.id, ai.qty, ai.item_id, si.quantity
                FROM stock_allocation_items ai
                JOIN stock_items si ON si.id = ai.item_id
                WHERE ai.id = ? AND ai.allocation_id = ?
            ");
            $ls->execute([$lineId, $allocationId]);
            $ln = $ls->fetch(PDO::FETCH_ASSOC);

            if (!$ln) {
                $_SESSION['flash_error'] = "Ligne introuvable.";
                header('Location: ' . APP_BASE . '/stock/reserve.php?family_id=' . $familyId . '&allocation_id=' . $allocationId);
                exit;
            }

            $oldQty = (int)$ln['qty'];
            $itemId = (int)$ln['item_id'];
            $availableNow = (int)$ln['quantity']; // stock dispo actuel (déjà décrémenté)

            // delta = new - old
            $delta = $newQty - $oldQty;

            // si delta > 0 => on consomme encore du stock dispo
            if ($delta > 0 && $delta > $availableNow) {
                $delta = $availableNow;
                $newQty = $oldQty + $delta;
            }

            $pdo->beginTransaction();
            try {
                if ($newQty <= 0) {
                    $pdo->prepare("DELETE FROM stock_allocation_items WHERE id = ?")->execute([$lineId]);
                    // rendre tout l'ancien qty au stock
                    adjustItemQty($pdo, $itemId, +$oldQty);
                } else {
                    $pdo->prepare("UPDATE stock_allocation_items SET qty = ? WHERE id = ?")
                        ->execute([$newQty, $lineId]);

                    if ($delta !== 0) {
                        // delta > 0 => consommer (négatif), delta < 0 => rendre (positif)
                        adjustItemQty($pdo, $itemId, -$delta);
                    }
                }

                $pdo->commit();
                $_SESSION['flash_success'] = "Ligne mise à jour.";
            } catch (Throwable $e) {
                $pdo->rollBack();
                $_SESSION['flash_error'] = "Erreur lors de la mise à jour.";
            }

            header('Location: ' . APP_BASE . '/stock/reserve.php?family_id=' . $familyId . '&allocation_id=' . $allocationId);
            exit;
        }

        if ($action === 'remove_line') {
            $lineId = post_int('line_id', 0);

            $ls = $pdo->prepare("
                SELECT ai.id, ai.qty, ai.item_id
                FROM stock_allocation_items ai
                WHERE ai.id = ? AND ai.allocation_id = ?
            ");
            $ls->execute([$lineId, $allocationId]);
            $ln = $ls->fetch(PDO::FETCH_ASSOC);

            if (!$ln) {
                $_SESSION['flash_error'] = "Ligne introuvable.";
                header('Location: ' . APP_BASE . '/stock/reserve.php?family_id=' . $familyId . '&allocation_id=' . $allocationId);
                exit;
            }

            $pdo->beginTransaction();
            try {
                $qty = (int)$ln['qty'];
                $itemId = (int)$ln['item_id'];

                $pdo->prepare("DELETE FROM stock_allocation_items WHERE id = ?")->execute([$lineId]);
                adjustItemQty($pdo, $itemId, +$qty);

                $pdo->commit();
                $_SESSION['flash_success'] = "Objet retiré du dossier.";
            } catch (Throwable $e) {
                $pdo->rollBack();
                $_SESSION['flash_error'] = "Erreur lors de la suppression.";
            }

            header('Location: ' . APP_BASE . '/stock/reserve.php?family_id=' . $familyId . '&allocation_id=' . $allocationId);
            exit;
        }
    }
}

// Si allocationId non fourni => propose par défaut le dernier dossier ouvert (sans le créer)
if ($allocationId <= 0) {
    $allocationId = findDefaultAllocationId($pdo, $familyId);
}

// Charger liste dossiers de la famille
$allocListStmt = $pdo->prepare("
    SELECT id, status, created_at, reserved_at, notes
    FROM stock_allocations
    WHERE family_id = ?
    ORDER BY created_at DESC, id DESC
");
$allocListStmt->execute([$familyId]);
$allocList = $allocListStmt->fetchAll(PDO::FETCH_ASSOC);

// Charger dossier courant + lignes
$currentAlloc = null;
$lines = [];
if ($allocationId > 0) {
    $ca = $pdo->prepare("SELECT * FROM stock_allocations WHERE id = ? AND family_id = ?");
    $ca->execute([$allocationId, $familyId]);
    $currentAlloc = $ca->fetch(PDO::FETCH_ASSOC);

    if ($currentAlloc) {
        $ls = $pdo->prepare("
            SELECT ai.id AS line_id, ai.qty,
                   si.id AS item_id, si.title, si.unit, si.quantity AS available_qty,
                   sc.label AS category_label
            FROM stock_allocation_items ai
            JOIN stock_items si ON si.id = ai.item_id
            JOIN stock_categories sc ON sc.id = si.category_id
            WHERE ai.allocation_id = ?
            ORDER BY sc.label, si.title
        ");
        $ls->execute([$allocationId]);
        $lines = $ls->fetchAll(PDO::FETCH_ASSOC);
    }
}

// Liste des items disponibles (recherche simple)
$q = trim((string)($_GET['q'] ?? ''));
$qLike = '%' . $q . '%';

$itemsSql = "
    SELECT si.id, si.title, si.quantity, si.status,
           sc.label AS category_label,
           sl.label AS location_label
    FROM stock_items si
    JOIN stock_categories sc ON sc.id = si.category_id
    LEFT JOIN stock_locations sl ON sl.id = si.location_id
    WHERE si.is_active = 1
";
$params = [];
if ($q !== '') {
    $itemsSql .= " AND (si.title LIKE ? OR sc.label LIKE ? OR sl.label LIKE ?) ";
    $params = [$qLike, $qLike, $qLike];
}
$itemsSql .= " ORDER BY (si.quantity>0) DESC, sc.label, si.title LIMIT 200";
$itemsStmt = $pdo->prepare($itemsSql);
$itemsStmt->execute($params);
$items = $itemsStmt->fetchAll(PDO::FETCH_ASSOC);

// Maintenant seulement on peut inclure header.php
require_once __DIR__ . '/../header.php';

// Flash
if (!empty($_SESSION['flash_success'])) {
    echo '<div class="alert alert-success">' . h($_SESSION['flash_success']) . '</div>';
    unset($_SESSION['flash_success']);
}
if (!empty($_SESSION['flash_error'])) {
    echo '<div class="alert alert-danger">' . h($_SESSION['flash_error']) . '</div>';
    unset($_SESSION['flash_error']);
}

$familyName = trim((string)($family['lastname'] ?? '') . ' ' . (string)($family['firstname'] ?? ''));
?>
<div class="container" style="max-width: 1100px;">

  <div class="d-flex justify-content-between align-items-start gap-3 mb-3">
    <div>
      <h1 class="h4 mb-1">Réserver du matériel</h1>
      <div class="text-muted small">
        Famille : <b><?= h($familyName !== '' ? $familyName : ('Famille #' . $familyId)) ?></b>
        <?php $city = trim((string)($family['city'] ?? '')); ?>
        <?php if ($city !== ''): ?> • <?= h($city) ?><?php endif; ?>
      </div>
    </div>
    <div class="text-end">
      <a class="btn btn-outline-secondary btn-sm" href="<?= h(APP_BASE) ?>/families/view.php?id=<?= (int)$familyId ?>">← Retour famille</a>
    </div>
  </div>

  <!-- Choix dossier -->
  <div class="card shadow-sm mb-3">
    <div class="card-body">
      <div class="d-flex flex-column flex-md-row justify-content-between align-items-md-end gap-3">
        <div class="flex-grow-1">
          <label class="form-label">Dossier de réservation</label>
          <form method="post" class="d-flex gap-2">
            <input type="hidden" name="action" value="select_allocation">
            <select class="form-select" name="allocation_id">
              <option value="0">— Aucun (choisir ou créer) —</option>
              <?php foreach ($allocList as $a): ?>
                <?php
                  $aid = (int)$a['id'];
                  $label = "Dossier #{$aid} — " . (string)$a['status'] . " — " . (string)$a['created_at'];
                ?>
                <option value="<?= $aid ?>" <?= ($allocationId === $aid) ? 'selected' : '' ?>>
                  <?= h($label) ?>
                </option>
              <?php endforeach; ?>
            </select>
            <button class="btn btn-outline-primary" type="submit">Ouvrir</button>
          </form>
          <div class="form-text">
            Par défaut, on réutilise le dernier dossier ouvert. On ne crée plus de dossier automatiquement.
          </div>
        </div>

        <div style="min-width:320px;">
          <label class="form-label">Créer un nouveau dossier (optionnel)</label>
          <form method="post" class="d-flex gap-2">
            <input type="hidden" name="action" value="new_allocation">
            <input type="text" name="new_notes" class="form-control" placeholder="Note (ex: Visite #2, déménagement…)">
            <button class="btn btn-primary" type="submit">+ Nouveau</button>
          </form>
        </div>
      </div>
    </div>
  </div>

  <!-- Dossier courant -->
  <div class="card shadow-sm mb-3">
    <div class="card-body">
      <div class="d-flex justify-content-between align-items-start gap-3">
        <div>
          <h2 class="h6 mb-1">Dossier courant</h2>
          <?php if (!$currentAlloc): ?>
            <div class="text-muted small">Aucun dossier sélectionné.</div>
          <?php else: ?>
            <div class="text-muted small">
              Dossier #<?= (int)$currentAlloc['id'] ?> • statut : <b><?= h((string)$currentAlloc['status']) ?></b>
              • créé : <?= h((string)$currentAlloc['created_at']) ?>
            </div>
          <?php endif; ?>
        </div>

        <?php if ($currentAlloc): ?>
          <div class="text-end">
            <form method="post" class="d-inline">
              <input type="hidden" name="action" value="cancel_alloc">
              <input type="hidden" name="allocation_id" value="<?= (int)$allocationId ?>">
              <button class="btn btn-outline-danger btn-sm" type="submit"
                      onclick="return confirm('Annuler ce dossier ? (le stock sera remis)');">
                Annuler le dossier
              </button>
            </form>
          </div>
        <?php endif; ?>
      </div>

      <?php if ($currentAlloc): ?>
        <hr>
        <form method="post" class="d-flex gap-2 align-items-end">
          <input type="hidden" name="action" value="update_alloc_notes">
          <input type="hidden" name="allocation_id" value="<?= (int)$allocationId ?>">
          <div class="flex-grow-1">
            <label class="form-label">Notes du dossier</label>
            <input type="text" name="notes" class="form-control" value="<?= h((string)($currentAlloc['notes'] ?? '')) ?>">
          </div>
          <button class="btn btn-outline-primary" type="submit">Enregistrer</button>
        </form>
      <?php endif; ?>
    </div>
  </div>

  <!-- Lignes du dossier -->
  <div class="card shadow-sm mb-3">
    <div class="card-body">
      <h2 class="h6 mb-3">Objets dans le dossier</h2>

      <?php if (!$currentAlloc): ?>
        <div class="alert alert-info mb-0">Choisis un dossier (ou crée-en un) pour ajouter des objets.</div>
      <?php elseif (empty($lines)): ?>
        <div class="text-muted">Aucun objet pour l’instant.</div>
      <?php else: ?>
        <div class="table-responsive">
          <table class="table table-sm align-middle">
            <thead>
              <tr>
                <th>Catégorie</th>
                <th>Objet</th>
                <th class="text-end" style="width:140px;">Quantité</th>
                <th class="text-end" style="width:160px;">Actions</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($lines as $ln): ?>
                <tr>
                  <td><?= h((string)$ln['category_label']) ?></td>
                  <td><?= h((string)$ln['title']) ?></td>
                  <td class="text-end">
                    <form method="post" class="d-flex justify-content-end gap-2">
                      <input type="hidden" name="action" value="update_line">
                      <input type="hidden" name="allocation_id" value="<?= (int)$allocationId ?>">
                      <input type="hidden" name="line_id" value="<?= (int)$ln['line_id'] ?>">
                      <input type="number" name="qty" class="form-control form-control-sm text-end"
                             style="width:110px;"
                             min="0" value="<?= (int)$ln['qty'] ?>">
                      <button class="btn btn-outline-primary btn-sm" type="submit">OK</button>
                    </form>
                    <div class="form-text text-end">
                      Stock dispo restant: <?= (int)$ln['available_qty'] ?>
                    </div>
                  </td>
                  <td class="text-end">
                    <form method="post" class="d-inline">
                      <input type="hidden" name="action" value="remove_line">
                      <input type="hidden" name="allocation_id" value="<?= (int)$allocationId ?>">
                      <input type="hidden" name="line_id" value="<?= (int)$ln['line_id'] ?>">
                      <button class="btn btn-outline-danger btn-sm" type="submit"
                              onclick="return confirm('Retirer cet objet du dossier ?');">
                        Retirer
                      </button>
                    </form>
                  </td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      <?php endif; ?>
    </div>
  </div>

  <!-- Ajouter des items -->
  <div class="card shadow-sm">
    <div class="card-body">
      <div class="d-flex flex-column flex-md-row justify-content-between align-items-md-end gap-2 mb-2">
        <div>
          <h2 class="h6 mb-1">Ajouter du matériel</h2>
          <div class="text-muted small">Recherche et ajout rapide dans le dossier sélectionné.</div>
        </div>
        <form method="get" class="d-flex gap-2">
          <input type="hidden" name="family_id" value="<?= (int)$familyId ?>">
          <?php if ($allocationId > 0): ?><input type="hidden" name="allocation_id" value="<?= (int)$allocationId ?>"><?php endif; ?>
          <input type="text" name="q" class="form-control form-control-sm" style="width:260px;"
                 value="<?= h($q) ?>" placeholder="Rechercher…">
          <button class="btn btn-outline-secondary btn-sm">Rechercher</button>
        </form>
      </div>

      <div class="table-responsive">
        <table class="table table-sm align-middle mb-0">
          <thead>
            <tr>
              <th>Catégorie</th>
              <th>Objet</th>
              <th>Lieu</th>
              <th class="text-end" style="width:110px;">Dispo</th>
              <th class="text-end" style="width:220px;">Ajouter</th>
            </tr>
          </thead>
          <tbody>
            <?php if (empty($items)): ?>
              <tr><td colspan="5" class="text-muted">Aucun objet.</td></tr>
            <?php else: ?>
              <?php foreach ($items as $it): ?>
                <?php
                  $avail = (int)$it['quantity'];
                  $disabled = ($avail <= 0 || !$currentAlloc);
                ?>
                <tr class="<?= $avail <= 0 ? 'text-muted' : '' ?>">
                  <td><?= h((string)$it['category_label']) ?></td>
                  <td><?= h((string)$it['title']) ?></td>
                  <td><?= h((string)($it['location_label'] ?? '—')) ?></td>
                  <td class="text-end"><?= $avail ?></td>
                  <td class="text-end">
                    <form method="post" class="d-inline-flex gap-2 justify-content-end">
                      <input type="hidden" name="action" value="add_item">
                      <input type="hidden" name="allocation_id" value="<?= (int)$allocationId ?>">
                      <input type="hidden" name="item_id" value="<?= (int)$it['id'] ?>">
                      <input type="number" name="qty" class="form-control form-control-sm text-end"
                             style="width:90px;" min="1" max="<?= max(1,$avail) ?>" value="1" <?= $disabled ? 'disabled' : '' ?>>
                      <button class="btn btn-sm btn-success" type="submit" <?= $disabled ? 'disabled' : '' ?>>
                        + Ajouter
                      </button>
                    </form>
                    <?php if (!$currentAlloc): ?>
                      <div class="form-text text-end">Choisis un dossier.</div>
                    <?php endif; ?>
                  </td>
                </tr>
              <?php endforeach; ?>
            <?php endif; ?>
          </tbody>
        </table>
      </div>

    </div>
  </div>

</div>

<?php require_once __DIR__ . '/../footer.php'; ?>
