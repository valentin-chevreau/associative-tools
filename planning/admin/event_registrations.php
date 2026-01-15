<?php
// admin/event_registrations.php
// Page "Inscrits" d'un évènement — slots-aware
//
// Objectifs :
// - Afficher les inscrits "présents" pour un event
// - Si les créneaux (event_slots) existent et que event_registrations.slot_id existe,
//   alors on gère les inscrits PAR CRÉNEAU (slot)
// - Permettre d'ajouter/supprimer des présences sur le bon créneau (utile pour rattraper l'historique)

require_once __DIR__ . '/../includes/app.php';
$config = require __DIR__ . '/../config/config.php';
global $pdo;

if (empty($_SESSION['is_admin']) && empty($_SESSION['admin_authenticated'])) {
    header("Location: {$config['base_url']}/admin/login.php");
    exit;
}

function h($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

$eventId = 0;
if (isset($_GET['id'])) { $eventId = (int)$_GET['id']; }
elseif (isset($_GET['event_id'])) { $eventId = (int)$_GET['event_id']; }

if ($eventId <= 0) {
    http_response_code(400);
    echo "<div class='card' style='border:1px solid #fca5a5;background:#fff7f7;'><strong>Évènement invalide.</strong></div>";
    exit;
}

// ------------------------------------------------------------
// Détection slots
// ------------------------------------------------------------
$hasSlotsTable = false;
$hasSlotIdCol  = false;

try {
    $st = $pdo->query("SHOW TABLES LIKE 'event_slots'");
    $hasSlotsTable = (bool)$st->fetchColumn();
} catch (Throwable $e) { $hasSlotsTable = false; }

try {
    $st = $pdo->query("SHOW COLUMNS FROM event_registrations LIKE 'slot_id'");
    $hasSlotIdCol = (bool)$st->fetchColumn();
} catch (Throwable $e) { $hasSlotIdCol = false; }

$useSlots = ($hasSlotsTable && $hasSlotIdCol);

// ------------------------------------------------------------
// Event
// ------------------------------------------------------------
$stmt = $pdo->prepare("SELECT * FROM events WHERE id = :id LIMIT 1");
$stmt->execute(['id' => $eventId]);
$event = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$event) {
    http_response_code(404);
    echo "<div class='card' style='border:1px solid #fca5a5;background:#fff7f7;'><strong>Évènement introuvable.</strong></div>";
    exit;
}

$eventTitle = (string)($event['title'] ?? 'Évènement');
$eventStart = (string)($event['start_datetime'] ?? '');
$eventEnd   = (string)($event['end_datetime'] ?? '');

// ------------------------------------------------------------
// Slots (si dispo)
// ------------------------------------------------------------
$slots = [];
if ($useSlots) {
    $st = $pdo->prepare("SELECT * FROM event_slots WHERE event_id = :eid ORDER BY start_datetime ASC");
    $st->execute(['eid' => $eventId]);
    $slots = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

function slot_label(array $s): string {
    $sd = (string)($s['start_datetime'] ?? '');
    $ed = (string)($s['end_datetime'] ?? '');
    if ($sd === '') return 'Créneau';
    $d  = date('d/m/Y', strtotime($sd));
    $h1 = date('H:i', strtotime($sd));
    $h2 = ($ed !== '') ? date('H:i', strtotime($ed)) : '';
    return $h2 ? "{$d} · {$h1} → {$h2}" : "{$d} · {$h1}";
}

// Slot sélectionné (pour ajout)
$selectedSlotId = null;
if ($useSlots) {
    if (isset($_POST['slot_id'])) $selectedSlotId = (int)$_POST['slot_id'];
    elseif (isset($_GET['slot_id'])) $selectedSlotId = (int)$_GET['slot_id'];

    if ($selectedSlotId !== null && $selectedSlotId <= 0) $selectedSlotId = null;

    // Si un seul créneau -> pré-sélection
    if ($selectedSlotId === null && count($slots) === 1) {
        $selectedSlotId = (int)($slots[0]['id'] ?? 0) ?: null;
    }
}

// ------------------------------------------------------------
// Actions POST (add/delete)
// ------------------------------------------------------------
$errors = [];
$flash  = null;

// ✅ Cas UX : si on est en mode slots et que le POST contient juste slot_id (changement de sélection),
// on ne tente PAS d'ajouter une présence.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['do_add_present']) && $useSlots) {
    $tmpVolunteer = (int)($_POST['volunteer_id'] ?? 0);
    if ($tmpVolunteer <= 0) {
        // juste un refresh de sélection
        // (slot_id déjà capturé via $selectedSlotId)
        // on ne fait rien
    } else {
        // Ajout présence
        $volunteerId = $tmpVolunteer;
        $slotId = isset($_POST['slot_id']) && (int)$_POST['slot_id'] > 0 ? (int)$_POST['slot_id'] : null;
        $selectedSlotId = $slotId; // conserve sélection

        if ($volunteerId <= 0) $errors[] = "Bénévole invalide.";
        if ($slotId !== null) {
            $okSlot = false;
            foreach ($slots as $s) {
                if ((int)$s['id'] === (int)$slotId) { $okSlot = true; break; }
            }
            if (!$okSlot) $errors[] = "Créneau invalide.";
        }

        if (empty($errors)) {
            try {
                $st = $pdo->prepare("
                    INSERT INTO event_registrations (event_id, volunteer_id, slot_id, status, created_at)
                    VALUES (:eid, :vid, :sid, 'present', NOW())
                ");
                $st->execute([
                    'eid' => $eventId,
                    'vid' => $volunteerId,
                    'sid' => $slotId,
                ]);
                $flash = "Présence ajoutée.";
            } catch (Throwable $e) {
                $errors[] = "Impossible d'ajouter la présence : " . $e->getMessage();
            }
        }
    }
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['do_add_present'])) {
    // Legacy (sans slots)
    $volunteerId = (int)($_POST['volunteer_id'] ?? 0);

    if ($volunteerId <= 0) $errors[] = "Bénévole invalide.";

    if (empty($errors)) {
        try {
            $st = $pdo->prepare("
                INSERT INTO event_registrations (event_id, volunteer_id, status, created_at)
                VALUES (:eid, :vid, 'present', NOW())
            ");
            $st->execute([
                'eid' => $eventId,
                'vid' => $volunteerId,
            ]);
            $flash = "Présence ajoutée.";
        } catch (Throwable $e) {
            $errors[] = "Impossible d'ajouter la présence : " . $e->getMessage();
        }
    }
}

// Suppression présence
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['do_delete_present'])) {
    $regId = (int)($_POST['registration_id'] ?? 0);
    if ($regId <= 0) {
        $errors[] = "Inscription invalide.";
    } else {
        try {
            $st = $pdo->prepare("DELETE FROM event_registrations WHERE id = :id AND event_id = :eid AND status='present'");
            $st->execute(['id' => $regId, 'eid' => $eventId]);
            $flash = "Présence supprimée.";
        } catch (Throwable $e) {
            $errors[] = "Suppression impossible : " . $e->getMessage();
        }
    }
}

// ------------------------------------------------------------
// Chargement inscrits (présents)
// ------------------------------------------------------------
$regs = [];
if ($useSlots) {
    $st = $pdo->prepare("
        SELECT
          r.id AS reg_id,
          r.slot_id,
          v.id AS volunteer_id,
          v.first_name, v.last_name, v.email
        FROM event_registrations r
        JOIN volunteers v ON v.id = r.volunteer_id
        WHERE r.event_id = :eid
          AND r.status = 'present'
        ORDER BY COALESCE(r.slot_id, 0) ASC, v.last_name ASC, v.first_name ASC
    ");
    $st->execute(['eid' => $eventId]);
    $regs = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
} else {
    $st = $pdo->prepare("
        SELECT
          r.id AS reg_id,
          v.id AS volunteer_id,
          v.first_name, v.last_name, v.email
        FROM event_registrations r
        JOIN volunteers v ON v.id = r.volunteer_id
        WHERE r.event_id = :eid
          AND r.status = 'present'
        ORDER BY v.last_name ASC, v.first_name ASC
    ");
    $st->execute(['eid' => $eventId]);
    $regs = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

// Groupement par slot
$regsBySlot = [];
if ($useSlots) {
    foreach ($regs as $r) {
        $sid = $r['slot_id'] ?? null;
        $key = ($sid === null || (int)$sid === 0) ? 'none' : (string)(int)$sid;
        if (!isset($regsBySlot[$key])) $regsBySlot[$key] = [];
        $regsBySlot[$key][] = $r;
    }
} else {
    $regsBySlot['all'] = $regs;
}

// ------------------------------------------------------------
// Bénévoles disponibles (pour ajout)
// - slots : on exclut seulement ceux déjà présents sur CE créneau (ou "sans créneau")
// - legacy : on exclut ceux déjà présents sur l'event
// ------------------------------------------------------------
$volunteersAvail = [];
try {
    if ($useSlots) {
        if ($selectedSlotId === null) {
            // "Sans créneau" (slot_id NULL/0)
            $sql = "
                SELECT v.*
                FROM volunteers v
                WHERE v.is_active = 1
                  AND v.id NOT IN (
                    SELECT r.volunteer_id
                    FROM event_registrations r
                    WHERE r.event_id = :eid
                      AND r.status = 'present'
                      AND (r.slot_id IS NULL OR r.slot_id = 0)
                  )
                ORDER BY v.last_name, v.first_name
            ";
            $st = $pdo->prepare($sql);
            $st->execute(['eid' => $eventId]);
        } else {
            $sql = "
                SELECT v.*
                FROM volunteers v
                WHERE v.is_active = 1
                  AND v.id NOT IN (
                    SELECT r.volunteer_id
                    FROM event_registrations r
                    WHERE r.event_id = :eid
                      AND r.status = 'present'
                      AND r.slot_id = :sid
                  )
                ORDER BY v.last_name, v.first_name
            ";
            $st = $pdo->prepare($sql);
            $st->execute(['eid' => $eventId, 'sid' => $selectedSlotId]);
        }
        $volunteersAvail = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } else {
        $st = $pdo->prepare("
            SELECT v.*
            FROM volunteers v
            WHERE v.is_active = 1
              AND v.id NOT IN (
                SELECT r.volunteer_id
                FROM event_registrations r
                WHERE r.event_id = :eid AND r.status = 'present'
              )
            ORDER BY v.last_name, v.first_name
        ");
        $st->execute(['eid' => $eventId]);
        $volunteersAvail = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }
} catch (Throwable $e) {
    $volunteersAvail = [];
    $errors[] = "Impossible de charger les bénévoles : " . $e->getMessage();
}

$title = "Inscrits — " . $eventTitle;
ob_start();
?>
<style>
.kpi-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:12px;}
.kpi{border:1px solid #e5e7eb;border-radius:14px;background:#fff;padding:14px;}
.kpi .v{font-size:22px;font-weight:900;letter-spacing:-0.02em;line-height:1.05;}
.kpi .l{margin-top:6px;font-size:12px;color:#6b7280;font-weight:700;}
.table{width:100%;border-collapse:collapse;}
.table th{text-align:left;font-size:12px;color:#6b7280;padding:8px 6px;border-bottom:1px solid #e5e7eb;}
.table td{padding:8px 6px;border-bottom:1px solid #f3f4f6;font-size:13px;vertical-align:top;}
.small{font-size:12px;color:#6b7280;}
.form-row{display:grid;grid-template-columns:repeat(12,1fr);gap:10px;}
.col-12{grid-column:span 12;}
.col-6{grid-column:span 6;}
.col-4{grid-column:span 4;}
.col-3{grid-column:span 3;}
@media (max-width:720px){.col-6,.col-4,.col-3{grid-column:span 12;}}
.form-group label{display:block;font-size:12px;color:#6b7280;font-weight:700;margin:0 0 4px;}
.form-control, .form-select{width:100%;padding:10px 12px;border:1px solid #e5e7eb;border-radius:12px;background:#fff;font-size:14px;}
.form-select{appearance:none;background-image:linear-gradient(45deg, transparent 50%, #6b7280 50%),linear-gradient(135deg, #6b7280 50%, transparent 50%),linear-gradient(to right, transparent, transparent);background-position:calc(100% - 18px) calc(1em + 2px),calc(100% - 13px) calc(1em + 2px),calc(100% - 2.5em) 0.5em;background-size:5px 5px,5px 5px,1px 1.5em;background-repeat:no-repeat;}
.btn{padding:10px 14px;border:1px solid #e5e7eb;border-radius:12px;background:#111827;color:#fff;font-weight:800;cursor:pointer;}
.btn-outline-danger{background:#fff;color:#b91c1c;border-color:#fecaca;}
.btn-outline-danger:hover{background:#fff5f5;}
.btn-sm{padding:7px 10px;border-radius:10px;font-size:12px;}
.slot-card{border:1px solid #e5e7eb;border-radius:14px;background:#fff;padding:14px;margin-top:12px;}
.slot-head{display:flex;justify-content:space-between;gap:10px;flex-wrap:wrap;align-items:flex-end;}
.slot-title{margin:0;font-size:15px;font-weight:900;}
.badge{display:inline-flex;align-items:center;gap:6px;padding:4px 10px;border-radius:999px;font-size:12px;font-weight:700;border:1px solid #e5e7eb;background:#f9fafb;color:#111827;}
</style>

<div class="card">
  <div style="display:flex;justify-content:space-between;gap:10px;flex-wrap:wrap;align-items:flex-end;">
    <div>
      <h2 style="margin:0 0 6px;">Inscrits — <?= h($eventTitle) ?></h2>
      <div class="small">
        <?php if ($eventStart): ?>
          <?= h(date('d/m/Y H:i', strtotime($eventStart))) ?>
          <?php if ($eventEnd): ?> → <?= h(date('d/m/Y H:i', strtotime($eventEnd))) ?><?php endif; ?>
        <?php else: ?>
          —
        <?php endif; ?>
        <?php if ($useSlots): ?>
          · <span class="badge">mode créneaux</span>
        <?php endif; ?>
      </div>
    </div>
    <div style="display:flex;gap:8px;flex-wrap:wrap;">
      <a href="<?= h($config['base_url']) ?>/admin/event_edit.php?id=<?= (int)$eventId ?>"><button type="button">← Évènement</button></a>
      <a href="<?= h($config['base_url']) ?>/admin/events_list.php"><button type="button">← Liste</button></a>
    </div>
  </div>
</div>

<?php if (!empty($errors)): ?>
  <div class="card" style="border:1px solid #fca5a5;background:#fff7f7;">
    <strong>Erreurs</strong>
    <ul class="small" style="margin:8px 0 0; padding-left:18px;">
      <?php foreach ($errors as $e): ?><li><?= h($e) ?></li><?php endforeach; ?>
    </ul>
  </div>
<?php endif; ?>

<?php if ($flash): ?>
  <div class="card" style="border:1px solid #bbf7d0;background:#f0fdf4;">
    <strong><?= h($flash) ?></strong>
  </div>
<?php endif; ?>

<div class="card">
  <h3 style="margin-top:0;">Ajouter une présence</h3>

  <form method="post" class="form-row" autocomplete="off">
    <input type="hidden" name="do_add_present" value="1">

    <?php if ($useSlots): ?>
      <div class="form-group col-6">
        <label>Créneau</label>
        <select class="form-select" name="slot_id" onchange="this.form.submit()">
          <option value="" <?= $selectedSlotId === null ? 'selected' : '' ?>>Sans créneau (historique / legacy)</option>
          <?php foreach ($slots as $s): ?>
            <?php $sid = (int)($s['id'] ?? 0); ?>
            <option value="<?= $sid ?>" <?= ($selectedSlotId !== null && (int)$selectedSlotId === $sid) ? 'selected' : '' ?>>
              <?= h(slot_label($s)) ?>
            </option>
          <?php endforeach; ?>
        </select>
        <div class="small" style="margin-top:6px;">Astuce : sélectionne le créneau avant d’ajouter les bénévoles (rattrapage historique).</div>
      </div>
    <?php endif; ?>

    <div class="form-group col-6">
      <label>Bénévole</label>
      <select class="form-select" name="volunteer_id" required>
        <option value="">Choisir…</option>
        <?php foreach ($volunteersAvail as $v): ?>
          <?php
            $name = trim(($v['last_name'] ?? '') . ' ' . ($v['first_name'] ?? ''));
            if ($name === '') $name = (string)($v['first_name'] ?? 'Bénévole');
          ?>
          <option value="<?= (int)$v['id'] ?>"><?= h($name) ?><?= !empty($v['email']) ? " — " . h($v['email']) : "" ?></option>
        <?php endforeach; ?>
      </select>
      <?php if (empty($volunteersAvail)): ?>
        <div class="small" style="margin-top:6px;">Aucun bénévole disponible pour ce créneau (ou tous déjà présents).</div>
      <?php endif; ?>
    </div>

    <div class="col-12" style="display:flex;justify-content:flex-end;gap:10px;align-items:center;">
      <button class="btn" type="submit" <?= empty($volunteersAvail) ? 'disabled' : '' ?>>Ajouter</button>
    </div>
  </form>
</div>

<?php if ($useSlots): ?>
  <?php
    // Index slots by id for quick lookup
    $slotById = [];
    foreach ($slots as $s) { $slotById[(int)$s['id']] = $s; }

    // Ordre d’affichage : slots puis "sans créneau" en dernier
    $keys = array_keys($regsBySlot);
    usort($keys, function($a,$b){
        if ($a === 'none') return 1;
        if ($b === 'none') return -1;
        return (int)$a <=> (int)$b;
    });
  ?>

  <?php foreach ($keys as $key): ?>
    <?php
      $list = $regsBySlot[$key] ?? [];
      $slotTitle = "Sans créneau (historique / legacy)";
      if ($key !== 'none') {
          $sid = (int)$key;
          $slotTitle = isset($slotById[$sid]) ? slot_label($slotById[$sid]) : "Créneau #{$sid}";
      }
    ?>
    <div class="slot-card">
      <div class="slot-head">
        <h4 class="slot-title"><?= h($slotTitle) ?></h4>
        <span class="badge"><?= count($list) ?> présent(s)</span>
      </div>

      <?php if (empty($list)): ?>
        <p class="small" style="margin:10px 0 0;">Aucune présence enregistrée sur ce créneau.</p>
      <?php else: ?>
        <table class="table" style="margin-top:10px;">
          <thead>
            <tr>
              <th>Bénévole</th>
              <th>Email</th>
              <th style="width:1%;white-space:nowrap;">Action</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($list as $r): ?>
              <?php
                $name = trim(($r['last_name'] ?? '') . ' ' . ($r['first_name'] ?? ''));
                if ($name === '') $name = (string)($r['first_name'] ?? 'Bénévole');
              ?>
              <tr>
                <td><strong><?= h($name) ?></strong></td>
                <td><?= !empty($r['email']) ? h($r['email']) : "<span class='small'>—</span>" ?></td>
                <td>
                  <form method="post" style="display:inline" onsubmit="return confirm('Supprimer cette présence ?');">
                    <input type="hidden" name="do_delete_present" value="1">
                    <input type="hidden" name="registration_id" value="<?= (int)$r['reg_id'] ?>">
                    <?php if ($useSlots): ?>
                      <input type="hidden" name="slot_id" value="<?= $key === 'none' ? '' : (int)$key ?>">
                    <?php endif; ?>
                    <button type="submit" class="btn btn-sm btn-outline-danger">Supprimer</button>
                  </form>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      <?php endif; ?>
    </div>
  <?php endforeach; ?>

<?php else: ?>

  <div class="card">
    <h3 style="margin-top:0;">Présents</h3>

    <?php if (empty($regsBySlot['all'])): ?>
      <p class="small">Aucune présence enregistrée.</p>
    <?php else: ?>
      <table class="table">
        <thead>
          <tr>
            <th>Bénévole</th>
            <th>Email</th>
            <th style="width:1%;white-space:nowrap;">Action</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($regsBySlot['all'] as $r): ?>
            <?php
              $name = trim(($r['last_name'] ?? '') . ' ' . ($r['first_name'] ?? ''));
              if ($name === '') $name = (string)($r['first_name'] ?? 'Bénévole');
            ?>
            <tr>
              <td><strong><?= h($name) ?></strong></td>
              <td><?= !empty($r['email']) ? h($r['email']) : "<span class='small'>—</span>" ?></td>
              <td>
                <form method="post" style="display:inline" onsubmit="return confirm('Supprimer cette présence ?');">
                  <input type="hidden" name="do_delete_present" value="1">
                  <input type="hidden" name="registration_id" value="<?= (int)$r['reg_id'] ?>">
                  <button type="submit" class="btn btn-sm btn-outline-danger">Supprimer</button>
                </form>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    <?php endif; ?>
  </div>

<?php endif; ?>

<?php
$content = ob_get_clean();
include __DIR__ . '/../includes/layout.php';
