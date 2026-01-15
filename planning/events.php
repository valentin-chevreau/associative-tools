<?php
$title = "Planning bénévoles";
ob_start();

require_once __DIR__ . '/includes/app.php';
global $pdo;

require_once __DIR__ . '/includes/event_types.php';

// RAW : peut être une liste de rows OU une map code => label
$eventTypesRaw = get_event_types($pdo, true);

/**
 * Normalisation : on veut une liste de :
 *   [ ['code' => 'xxx', 'label' => 'YYY'], ... ]
 * pour l’affichage (chips) + validation.
 */
$eventTypes = [];
if (is_array($eventTypesRaw)) {
    $first = reset($eventTypesRaw);

    // Cas A : liste de rows (array)
    if (is_array($first)) {
        foreach ($eventTypesRaw as $row) {
            if (!is_array($row)) continue;
            $code = (string)($row['code'] ?? '');
            $label = (string)($row['label'] ?? '');
            if ($code === '') continue;
            $eventTypes[] = ['code' => $code, 'label' => ($label !== '' ? $label : $code)];
        }
    }
    // Cas B : map code => label
    else {
        foreach ($eventTypesRaw as $code => $label) {
            $code = (string)$code;
            if ($code === '') continue;
            $label = (string)$label;
            $eventTypes[] = ['code' => $code, 'label' => ($label !== '' ? $label : $code)];
        }
    }
}

function h($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

$currentVolunteer = getCurrentVolunteer();

// Gestion sélection bénévole (auto-submit)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['selected_volunteer'])) {
    $vid = (int)($_POST['selected_volunteer'] ?: 0);
    setCurrentVolunteerId($vid > 0 ? $vid : null);
    $currentVolunteer = getCurrentVolunteer();
}

// Mois / année depuis l’URL ou mois courant
$month = isset($_GET['month']) ? (int)$_GET['month'] : (int)date('n');
$year  = isset($_GET['year'])  ? (int)$_GET['year']  : (int)date('Y');

if ($month < 1) $month = 1;
if ($month > 12) $month = 12;
if ($year < 2000) $year = (int)date('Y');

// Filtre FO : type d'événement (code)
$filterType = isset($_GET['type']) ? trim((string)$_GET['type']) : '';
$validTypeCodes = array_map(fn($t) => (string)$t['code'], $eventTypes);

// Si type invalide => reset
if ($filterType !== '' && !in_array($filterType, $validTypeCodes, true)) {
    $filterType = '';
}

// Début / fin de mois
$firstDay = new DateTimeImmutable(sprintf('%04d-%02d-01', $year, $month));
$lastDay  = $firstDay->modify('last day of this month');

$startStr = $firstDay->format('Y-m-d 00:00:00');
$endStr   = $lastDay->format('Y-m-d 23:59:59');

$now = new DateTime();
$isCurrentMonth = ((int)date('n') === $month && (int)date('Y') === $year);

$currentUrl = $_SERVER['REQUEST_URI'] ?? 'events.php';

// Helper URL mois en conservant le filtre type
function month_url($month, $year, $type = '') {
    $qs = ['month' => (int)$month, 'year' => (int)$year];
    if ($type !== '') $qs['type'] = $type;
    return 'events.php?' . http_build_query($qs);
}

// Label type courant
$filterTypeLabel = '';
if ($filterType !== '') {
    $filterTypeLabel = event_type_label($eventTypesRaw, $filterType);
    if (!$filterTypeLabel) $filterTypeLabel = $filterType;
}

// Navigation mois
$prevMonthDate = $firstDay->modify('-1 month');
$nextMonthDate = $firstDay->modify('+1 month');

$prevMonth = (int)$prevMonthDate->format('n');
$prevYear  = (int)$prevMonthDate->format('Y');
$nextMonth = (int)$nextMonthDate->format('n');
$nextYear  = (int)$nextMonthDate->format('Y');

/* =========================================================
   Détection “mode slots”
   - table event_slots existe ?
   - colonne event_registrations.slot_id existe ?
========================================================= */
$hasSlots = false;
$hasSlotIdCol = false;

try {
    $stmt = $pdo->query("SHOW TABLES LIKE 'event_slots'");
    $hasSlots = (bool)$stmt->fetchColumn();
} catch (Throwable $e) {
    $hasSlots = false;
}

try {
    $stmt = $pdo->query("SHOW COLUMNS FROM event_registrations LIKE 'slot_id'");
    $hasSlotIdCol = (bool)$stmt->fetchColumn();
} catch (Throwable $e) {
    $hasSlotIdCol = false;
}

$useSlots = ($hasSlots && $hasSlotIdCol);

/* =========================================================
   Récupération des événements
   - Mode slots : on récupère les events du mois, puis leurs slots
   - Mode legacy : on récupère les events du mois directement
========================================================= */

// Liste des bénévoles actifs
$stmtVol = $pdo->query("SELECT * FROM volunteers WHERE is_active = 1 ORDER BY last_name, first_name");
$volunteers = $stmtVol->fetchAll(PDO::FETCH_ASSOC);

// Mes inscriptions (slot_id si slots, sinon event_id)
$myRegistrations = [];
if ($currentVolunteer) {
    if ($useSlots) {
        $stmt2 = $pdo->prepare("
            SELECT slot_id
            FROM event_registrations
            WHERE volunteer_id = :vid
              AND status = 'present'
              AND slot_id IS NOT NULL
        ");
        $stmt2->execute(['vid' => $currentVolunteer['id']]);
        $myRegistrations = $stmt2->fetchAll(PDO::FETCH_COLUMN);
        $myRegistrations = array_map('intval', $myRegistrations ?: []);
    } else {
        $stmt2 = $pdo->prepare("
            SELECT event_id
            FROM event_registrations
            WHERE volunteer_id = :vid
              AND status = 'present'
        ");
        $stmt2->execute(['vid' => $currentVolunteer['id']]);
        $myRegistrations = $stmt2->fetchAll(PDO::FETCH_COLUMN);
        $myRegistrations = array_map('intval', $myRegistrations ?: []);
    }
}

$eventsByDay = [];

if ($useSlots) {
    // 1) Events du mois (annulés exclus)
    $sqlEvents = "
        SELECT e.*
        FROM events e
        WHERE e.start_datetime BETWEEN :start AND :end
          AND e.is_cancelled = 0
    ";
    $params = ['start' => $startStr, 'end' => $endStr];

    if ($filterType !== '') {
        $sqlEvents .= " AND e.event_type = :etype ";
        $params['etype'] = $filterType;
    }

    $sqlEvents .= " ORDER BY e.start_datetime ASC ";

    $stmt = $pdo->prepare($sqlEvents);
    $stmt->execute($params);
    $events = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (!empty($events)) {
        $eventIds = array_map(fn($e) => (int)$e['id'], $events);
        $in = implode(',', array_fill(0, count($eventIds), '?'));

        // 2) Slots du mois, rattachés aux events du mois
        //    - mois courant : on masque les slots déjà terminés
        $sqlSlots = "
            SELECT
              s.*,
              (SELECT COUNT(*)
               FROM event_registrations r
               WHERE r.slot_id = s.id AND r.status = 'present') AS registered_count
            FROM event_slots s
            WHERE s.event_id IN ($in)
              AND s.start_datetime BETWEEN ? AND ?
        ";
        $slotParams = array_merge($eventIds, [$startStr, $endStr]);

        if ($isCurrentMonth) {
            $sqlSlots .= " AND s.end_datetime >= NOW() ";
        }

        $sqlSlots .= " ORDER BY s.start_datetime ASC ";

        $stmtS = $pdo->prepare($sqlSlots);
        $stmtS->execute($slotParams);
        $slots = $stmtS->fetchAll(PDO::FETCH_ASSOC);

        // Index events
        $eventsIndex = [];
        foreach ($events as $e) {
            $eventsIndex[(int)$e['id']] = $e;
        }

        // 3) Groupement par jour (jour du slot)
        foreach ($slots as $s) {
            $slotStart = new DateTime($s['start_datetime']);
            $key = $slotStart->format('Y-m-d');

            $eid = (int)$s['event_id'];
            $event = $eventsIndex[$eid] ?? null;
            if (!$event) continue;

            if (!isset($eventsByDay[$key])) $eventsByDay[$key] = [];
            if (!isset($eventsByDay[$key][$eid])) {
                $eventsByDay[$key][$eid] = [
                    'event' => $event,
                    'slots' => []
                ];
            }
            $eventsByDay[$key][$eid]['slots'][] = $s;
        }

        // 4) Normaliser la structure finale : $eventsByDay[day] = list of grouped events
        foreach ($eventsByDay as $day => $map) {
            $eventsByDay[$day] = array_values($map);
        }
    }
} else {
    // Legacy : événements du mois (annulés exclus) + registered_count global
    $sql = "
        SELECT e.*,
               (SELECT COUNT(*) FROM event_registrations r
                WHERE r.event_id = e.id AND r.status = 'present') AS registered_count
        FROM events e
        WHERE e.start_datetime BETWEEN :start AND :end
          AND e.is_cancelled = 0
    ";

    $params = ['start' => $startStr, 'end' => $endStr];

    if ($filterType !== '') {
        $sql .= " AND e.event_type = :etype ";
        $params['etype'] = $filterType;
    }

    // Mois courant : on ne montre pas les events terminés
    if ($isCurrentMonth) {
        $sql .= " AND e.end_datetime >= NOW() ";
    }

    $sql .= " ORDER BY e.start_datetime ASC ";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $events = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($events as $event) {
        $key = (new DateTime($event['start_datetime']))->format('Y-m-d');
        $eventsByDay[$key][] = $event;
    }
}

// Mois FR
$monthsFr = [
    1 => 'Janvier', 2 => 'Février', 3 => 'Mars', 4 => 'Avril',
    5 => 'Mai', 6 => 'Juin', 7 => 'Juillet', 8 => 'Août',
    9 => 'Septembre', 10 => 'Octobre', 11 => 'Novembre', 12 => 'Décembre'
];
$monthLabel = ($monthsFr[$month] ?? (string)$month) . ' ' . $year;
?>

<style>
.fo-row{display:flex;gap:10px;flex-wrap:wrap;align-items:center;justify-content:space-between;}
.fo-actions{display:flex;gap:8px;flex-wrap:wrap;align-items:center;}

.fo-chips{
  display:flex;
  gap:8px;
  flex-wrap:nowrap;
  overflow:auto;
  padding-bottom:4px;
  -webkit-overflow-scrolling: touch;
  margin-top:10px;
}
.fo-chip{
  display:inline-flex;
  align-items:center;
  gap:6px;
  padding:8px 12px;
  border-radius:999px;
  border:1px solid #e5e7eb;
  background:#fff;
  font-size:13px;
  color:#111827;
  text-decoration:none;
  white-space:nowrap;
  flex:0 0 auto;
}
.fo-chip:hover{background:#f9fafb;}
.fo-chip.on{
  border-color:transparent;
  background:linear-gradient(135deg,#10b981,#22c55e);
  color:#fff;
  font-weight:900;
}

/* ✅ Style select (conservé) */
.form-control{
  width:100%;
  padding:10px 12px;
  border:1px solid #d1d5db;
  border-radius:12px;
  background:#fff;
  font-size:14px;
  outline:none;
  line-height:1.2;
  box-sizing:border-box;
}
select.form-control{
  appearance:none;
  -webkit-appearance:none;
  -moz-appearance:none;
  padding-right:40px;
  background-image:
    linear-gradient(45deg, transparent 50%, #6b7280 50%),
    linear-gradient(135deg, #6b7280 50%, transparent 50%);
  background-position:
    calc(100% - 18px) 50%,
    calc(100% - 12px) 50%;
  background-size:6px 6px, 6px 6px;
  background-repeat:no-repeat;
}
.form-control:focus{
  border-color:#93c5fd;
  box-shadow:0 0 0 3px rgba(147,197,253,0.35);
}

.vol-box{display:flex;flex-direction:column;gap:10px;}
.vol-selected{
  display:inline-flex;
  align-items:center;
  gap:8px;
  padding:8px 12px;
  border-radius:12px;
  background:#f9fafb;
  border:1px solid #e5e7eb;
  font-size:13px;
  font-weight:700;
  width:fit-content;
}

.day-title{display:flex;align-items:center;justify-content:space-between;gap:10px;flex-wrap:wrap;}

/* =========================================================
   UI “version 1” : badge type + noms plus gros + avatar bleu
========================================================= */
.event-block{border:1px solid #e5e7eb;border-radius:14px;background:#fff;padding:14px;}
.event-head{display:flex;align-items:flex-start;justify-content:space-between;gap:10px;flex-wrap:wrap;}
.event-title{margin:0;font-weight:900;font-size:15px;line-height:1.2;}
.event-meta{margin-top:4px;font-size:12px;color:#6b7280;}

.type-badge{
  display:inline-flex;
  align-items:center;
  gap:6px;
  padding:6px 10px;
  border-radius:999px;
  border:1px solid #e5e7eb;
  background:#f9fafb;
  font-size:12px;
  font-weight:800;
  color:#111827;
  white-space:nowrap;
}

.slot-card{
  margin-top:12px;
  border:1px solid #eef2f7;
  background:#f8fafc;
  border-radius:14px;
  padding:12px;
}
.slot-row{display:flex;align-items:flex-start;justify-content:space-between;gap:12px;flex-wrap:wrap;}
.slot-left{min-width:220px;}
.slot-right{display:flex;flex-direction:column;align-items:flex-end;gap:6px;min-width:220px;}
.slot-time{
  font-weight:950;
  font-size:22px;
  line-height:1.1;
  letter-spacing:-0.02em;
}
.slot-sub{margin-top:6px;font-size:13px;color:#374151;font-weight:700;}
.slot-sub .muted{color:#6b7280;font-weight:600;}

.me-badge{
  display:inline-flex;
  align-items:center;
  gap:6px;
  padding:6px 10px;
  border-radius:999px;
  background:#dcfce7;
  border:1px solid #bbf7d0;
  color:#166534;
  font-size:12px;
  font-weight:900;
}

/* Attendees */
.attendees-row{
  display:flex;
  gap:10px;
  flex-wrap:wrap;
  margin-top:10px;
}
.attendee-pill{
  display:inline-flex;
  align-items:center;
  gap:10px;
  padding:10px 12px;
  border-radius:999px;
  border:1px solid #e5e7eb;
  background:#fff;
  font-size:14px;
  font-weight:800;
  color:#111827;
}
.attendee-avatar{
  width:30px;
  height:30px;
  border-radius:999px;
  display:inline-flex;
  align-items:center;
  justify-content:center;
  background:#dbeafe;
  border:1px solid #bfdbfe;
  color:#1d4ed8;
  font-weight:900;
  font-size:12px;
  line-height:1;
}

/* Boutons zone (cohérent avec tes boutons existants) */
.slot-actions{display:flex;flex-direction:column;gap:6px;align-items:flex-end;}
.slot-actions .badge{align-self:flex-end;}

/* =========================================================
   ✅ FIX UNIQUEMENT : placement/rendu des boutons
   (inchangé desktop/tablette)
========================================================= */
.slot-row{
  display:flex;
  align-items:center;
  justify-content:space-between;
  gap:12px;
  flex-wrap:nowrap;
}
.slot-left{
  flex:1 1 auto;
  min-width:0;
}
.slot-right{
  flex:0 0 240px;
  min-width:240px;
  display:flex;
  flex-direction:column;
  align-items:flex-end;
  gap:8px;
}
.slot-actions{
  display:flex;
  flex-direction:column;
  gap:8px;
  align-items:flex-end;
  width:100%;
}
.slot-actions form{
  margin:0;
  width:auto;
  display:flex;
  justify-content:flex-end;
}
.slot-actions button{
  width:auto;
  min-width:0;
  padding:10px 14px;
  border-radius:999px;
  font-weight:800;
  line-height:1;
  white-space:nowrap;
}
@media (min-width: 721px){
  .slot-actions button{ min-width:140px; }
}
@media (max-width: 720px){
  .slot-actions form{ width:100%; }
  .slot-actions button{ width:100%; min-width:0; }
}

/* =========================================================
   ✅ MOBILE UNIQUEMENT : compacter les slots (SANS toucher desktop)
   - réduit la hauteur globale
   - évite l'heure sur 2 lignes
   - compresse badges, textes, liste bénévoles, boutons
========================================================= */
@media (max-width: 640px){

  /* Carte slot moins “haute” */
  .slot-card{
    padding:10px 10px;
    border-radius:14px;
    margin-top:10px;
  }

  /* On garde 2 colonnes (contenu + actions) mais plus compact */
  .slot-row{
    gap:10px;
  }

  .slot-right{
    flex:0 0 160px;     /* colonne action plus étroite */
    min-width:160px;
    gap:6px;
  }

  /* Heure: 1 ligne, plus compacte */
  .slot-time{
    font-size:20px;
    line-height:1.05;
    white-space:nowrap; /* évite le “09:00 ->” puis “10:30” */
  }

  /* Badge “Moi” plus petit (moins gourmand) */
  .me-badge{
    padding:4px 8px;
    font-size:11px;
  }

  /* Texte “Inscrits / Min” plus serré */
  .slot-sub{
    margin-top:4px;
    font-size:12px;
    line-height:1.2;
  }

  /* Attendees: beaucoup moins de padding/hauteur */
  .attendees-row{
    gap:6px;
    margin-top:8px;
  }

  .attendee-pill{
    gap:8px;
    padding:6px 8px;
    font-size:13px;
    font-weight:800;
  }

  .attendee-avatar{
    width:24px;
    height:24px;
    font-size:11px;
  }

  /* “Aucun bénévole…” réduit (moins de hauteur vide) */
  .slot-left > p.muted{
    margin-top:8px !important;
    margin-bottom:0 !important;
    font-size:12px;
    line-height:1.25;
  }

  /* Zone action: moins de texte/espacement */
  .slot-actions{
    gap:6px;
  }

  .slot-actions .badge{
    font-size:12px;
    padding:6px 8px;
    border-radius:12px;
  }

  .slot-actions button{
    padding:9px 12px;   /* bouton moins haut */
    font-size:14px;
  }

  /* Le texte “Tu n’es pas inscrit…” : plus compact */
  .slot-right .muted{
    font-size:12px;
    line-height:1.2;
  }
}
</style>

<div class="card">
  <div class="fo-row">
    <div>
      <h2 style="margin:0 0 6px;">Planning bénévoles</h2>
      <p class="muted" style="margin:0;">
        Choisis ton profil, puis confirme (ou annule) ta présence aux événements du mois sélectionné.
      </p>
      <?php if ($isCurrentMonth): ?>
        <p class="muted" style="margin:8px 0 0;">
          ℹ️ Ce mois-ci, les événements déjà terminés sont masqués.
        </p>
      <?php endif; ?>
    </div>

    <div class="muted" style="font-weight:700;"><?= h($monthLabel) ?></div>
  </div>

  <div class="fo-actions" style="margin-top:10px;">
    <a href="<?= h(month_url($prevMonth, $prevYear, $filterType)) ?>"><button type="button">&larr; Mois précédent</button></a>
    <a href="<?= h(month_url((int)date('n'), (int)date('Y'), $filterType)) ?>"><button type="button">Mois courant</button></a>
    <a href="<?= h(month_url($nextMonth, $nextYear, $filterType)) ?>"><button type="button">Mois suivant &rarr;</button></a>
  </div>

  <!-- Filtre type (chips) -->
  <div class="fo-chips" aria-label="Filtrer par type d'événement">
    <a class="fo-chip <?= $filterType === '' ? 'on' : '' ?>"
       href="<?= h(month_url($month, $year, '')) ?>">
      Tous les types
    </a>
    <?php foreach ($eventTypes as $t): ?>
      <?php
        $code = (string)$t['code'];
        $label = (string)$t['label'];
        $isOn = ($filterType === $code);
      ?>
      <a class="fo-chip <?= $isOn ? 'on' : '' ?>"
         href="<?= h(month_url($month, $year, $code)) ?>">
        <?= h($label) ?>
      </a>
    <?php endforeach; ?>
  </div>

  <?php if ($filterType !== ''): ?>
    <p class="muted" style="margin:10px 0 0;">
      Filtre actif : <strong><?= h($filterTypeLabel ?: $filterType) ?></strong>
    </p>
  <?php endif; ?>
</div>

<div class="card">
  <h3 style="margin-top:0;">Qui suis-je ?</h3>
  <p class="muted" style="margin:0 0 10px;">
    Choisis ton nom, le changement est pris en compte immédiatement.
  </p>

  <div class="vol-box">
    <?php if ($currentVolunteer): ?>
      <?php $me = trim(($currentVolunteer['last_name'] ?? '') . ' ' . ($currentVolunteer['first_name'] ?? '')); ?>
      <div class="vol-selected">👤 <?= h($me ?: 'Bénévole') ?></div>
    <?php endif; ?>

    <form method="post" id="volunteerForm">
      <select class="form-control" name="selected_volunteer" onchange="document.getElementById('volunteerForm').submit();">
        <option value=""><?= $currentVolunteer ? 'Changer de nom…' : 'Choisir mon nom…' ?></option>
        <?php foreach ($volunteers as $v): ?>
          <?php
            $name = trim(($v['last_name'] ?? '') . ' ' . ($v['first_name'] ?? ''));
            $sel = ($currentVolunteer && (int)$currentVolunteer['id'] === (int)$v['id']);
          ?>
          <option value="<?= (int)$v['id'] ?>" <?= $sel ? 'selected' : '' ?>>
            <?= h($name ?: 'Bénévole') ?>
          </option>
        <?php endforeach; ?>
      </select>
    </form>
  </div>
</div>

<?php if (empty($eventsByDay)): ?>
  <div class="card">
    <p class="muted">
      Aucun événement trouvé pour ce mois<?= $filterType !== '' ? ' avec ce filtre.' : '.' ?>
    </p>
  </div>
<?php endif; ?>

<?php if (!$currentVolunteer): ?>
  <p class="muted"><strong>Sélectionne ton nom ci-dessus pour pouvoir confirmer ta présence.</strong></p>
<?php endif; ?>

<?php foreach ($eventsByDay as $day => $dayEvents): ?>
  <?php
    $dayDate = new DateTime($day);
    $labelJour = $dayDate->format('d/m/Y');
  ?>
  <div class="card">
    <div class="day-title">
      <h3 style="margin:0;"><?= h($labelJour) ?></h3>
      <div class="muted" style="font-weight:700;">
        <?= $useSlots ? count($dayEvents) . " événement(s)" : count($dayEvents) . " événement(s)" ?>
      </div>
    </div>

    <div style="margin-top:10px; display:flex; flex-direction:column; gap:12px;">
      <?php if ($useSlots): ?>

        <?php foreach ($dayEvents as $bundle): ?>
          <?php
            $event = $bundle['event'];
            $slots = $bundle['slots'] ?? [];

            $typeCode  = (string)($event['event_type'] ?? '');
            $typeLabel = event_type_label($eventTypesRaw, $typeCode);
            if (!$typeLabel) $typeLabel = $typeCode ?: 'Type';

            $eventTitle = (string)($event['title'] ?? 'Événement');

            // “Inscrits (global)” = somme des inscrits sur slots
            $globalRegistered = 0;
            foreach ($slots as $s) $globalRegistered += (int)($s['registered_count'] ?? 0);
          ?>

          <div class="event-block">
            <div class="event-head">
              <div>
                <div class="type-badge"><?= h($typeLabel) ?></div>
                <p class="event-title" style="margin-top:8px;"><?= h($eventTitle) ?></p>
                <div class="event-meta">
                  Inscrits (global) : <strong><?= (int)$globalRegistered ?></strong>
                </div>
              </div>
            </div>

            <?php foreach ($slots as $slot): ?>
              <?php
                $slotId = (int)$slot['id'];
                $start = new DateTime($slot['start_datetime']);
                $end   = new DateTime($slot['end_datetime']);

                $registered = (int)($slot['registered_count'] ?? 0);
                $min        = (int)($slot['min_volunteers'] ?? 0);
                $max        = array_key_exists('max_volunteers', $slot) ? (is_null($slot['max_volunteers']) ? null : (int)$slot['max_volunteers']) : null;

                $isRegistered = $currentVolunteer ? in_array($slotId, $myRegistrations, true) : false;
                $isPast = ($end < $now);

                // Attendees du slot
                $stmtAtt = $pdo->prepare("
                    SELECT v.first_name, v.last_name
                    FROM event_registrations r
                    JOIN volunteers v ON v.id = r.volunteer_id
                    WHERE r.slot_id = :sid AND r.status = 'present'
                    ORDER BY v.last_name, v.first_name
                ");
                $stmtAtt->execute(['sid' => $slotId]);
                $attendees = $stmtAtt->fetchAll(PDO::FETCH_ASSOC);
              ?>

              <div class="slot-card" style="<?= $isPast ? 'opacity:0.7;' : '' ?>">
                <div class="slot-row">
                  <div class="slot-left">
                    <div style="display:flex; align-items:center; gap:10px; flex-wrap:wrap;">
                      <div class="slot-time"><?= h($start->format('H:i')) ?> → <?= h($end->format('H:i')) ?></div>
                      <?php if ($isRegistered): ?>
                        <span class="me-badge">✅ Moi</span>
                      <?php endif; ?>
                    </div>

                    <div class="slot-sub">
                      Inscrits : <strong><?= (int)$registered ?></strong><?php if ($max !== null): ?>/<?= (int)$max ?><?php endif; ?>
                      <?php if ($min > 0): ?> <span class="muted">· Min : <?= (int)$min ?></span><?php endif; ?>
                    </div>

                    <?php if (!empty($attendees)): ?>
                      <div class="attendees-row">
                        <?php foreach ($attendees as $a): ?>
                          <?php
                            $fullName = trim(($a['first_name'] ?? '') . ' ' . ($a['last_name'] ?? ''));
                            $initials = '';
                            if (!empty($a['first_name'])) $initials .= mb_substr($a['first_name'], 0, 1);
                            if (!empty($a['last_name']))  $initials .= mb_substr($a['last_name'], 0, 1);
                            if ($initials === '' && $fullName !== '') $initials = mb_substr($fullName, 0, 1);
                          ?>
                          <div class="attendee-pill">
                            <span class="attendee-avatar"><?= h(mb_strtoupper($initials)) ?></span>
                            <span><?= h($fullName ?: 'Bénévole') ?></span>
                          </div>
                        <?php endforeach; ?>
                      </div>
                    <?php else: ?>
                      <p class="muted" style="margin:10px 0 0;">Aucun bénévole inscrit sur ce créneau.</p>
                    <?php endif; ?>
                  </div>

                  <div class="slot-right">
                    <?php if ($currentVolunteer && !$isPast): ?>
                      <div class="slot-actions">
                        <?php if ($isRegistered): ?>
                          <div class="badge" style="background:#dcfce7; border-color:transparent; color:#166534;">
                            ✅ Tu es inscrit(e) sur ce créneau.
                          </div>
                          <form method="post" action="toggle_registration.php" style="margin:0;">
                            <input type="hidden" name="event_id" value="<?= (int)$event['id'] ?>">
                            <input type="hidden" name="slot_id" value="<?= (int)$slotId ?>">
                            <input type="hidden" name="redirect" value="<?= h($currentUrl) ?>">
                            <button type="submit" name="action" value="leave" class="danger">Je ne viens pas</button>
                          </form>
                        <?php else: ?>
                          <div class="muted" style="font-weight:700;">Tu n’es pas inscrit(e) sur ce créneau.</div>
                          <form method="post" action="toggle_registration.php" style="margin:0;">
                            <input type="hidden" name="event_id" value="<?= (int)$event['id'] ?>">
                            <input type="hidden" name="slot_id" value="<?= (int)$slotId ?>">
                            <input type="hidden" name="redirect" value="<?= h($currentUrl) ?>">
                            <button type="submit" name="action" value="join" class="primary">Je viens</button>
                          </form>
                        <?php endif; ?>
                      </div>
                    <?php elseif ($isPast): ?>
                      <p class="muted" style="margin:0;">Créneau passé – non modifiable.</p>
                    <?php else: ?>
                      <p class="muted" style="margin:0;">Sélectionne ton nom en haut de page pour confirmer ta présence.</p>
                    <?php endif; ?>
                  </div>
                </div>
              </div>

            <?php endforeach; ?>
          </div>

        <?php endforeach; ?>

      <?php else: ?>

        <?php foreach ($dayEvents as $event): ?>
          <?php
            $start = new DateTime($event['start_datetime']);
            $end   = new DateTime($event['end_datetime']);

            $registered = (int)($event['registered_count'] ?? 0);
            $min        = (int)($event['min_volunteers'] ?? 0);
            $max        = is_null($event['max_volunteers']) ? null : (int)$event['max_volunteers'];

            $isRegistered = $currentVolunteer ? in_array((int)$event['id'], $myRegistrations, true) : false;
            $isPast       = $end < $now;

            $typeCode  = (string)($event['event_type'] ?? '');
            $typeLabel = event_type_label($eventTypesRaw, $typeCode);
            if (!$typeLabel) $typeLabel = $typeCode ?: 'Type';

            // Liste bénévoles inscrits
            $stmtAtt = $pdo->prepare("
                SELECT v.first_name, v.last_name
                FROM event_registrations r
                JOIN volunteers v ON v.id = r.volunteer_id
                WHERE r.event_id = :eid AND r.status = 'present'
                ORDER BY v.last_name, v.first_name
            ");
            $stmtAtt->execute(['eid' => $event['id']]);
            $attendees = $stmtAtt->fetchAll(PDO::FETCH_ASSOC);
          ?>

          <div class="event-block" style="<?= $isPast ? 'opacity:0.7;' : '' ?>">
            <div class="event-head">
              <div>
                <div class="type-badge"><?= h($typeLabel) ?></div>
                <p class="event-title" style="margin-top:8px;">
                  <?= h($start->format('H:i')) ?> → <?= h($end->format('H:i')) ?> · <?= h($event['title']) ?>
                </p>
                <div class="event-meta">
                  Inscrits : <strong><?= (int)$registered ?></strong><?php if ($max !== null): ?>/<?= (int)$max ?><?php endif; ?>
                  <?php if ($min > 0): ?> · Min souhaité : <?= (int)$min ?><?php endif; ?>
                </div>
              </div>
            </div>

            <?php if (!empty($attendees)): ?>
              <div class="attendees-row" style="margin-top:12px;">
                <?php foreach ($attendees as $a): ?>
                  <?php
                    $fullName = trim(($a['first_name'] ?? '') . ' ' . ($a['last_name'] ?? ''));
                    $initials = '';
                    if (!empty($a['first_name'])) $initials .= mb_substr($a['first_name'], 0, 1);
                    if (!empty($a['last_name']))  $initials .= mb_substr($a['last_name'], 0, 1);
                    if ($initials === '' && $fullName !== '') $initials = mb_substr($fullName, 0, 1);
                  ?>
                  <div class="attendee-pill">
                    <span class="attendee-avatar"><?= h(mb_strtoupper($initials)) ?></span>
                    <span><?= h($fullName ?: 'Bénévole') ?></span>
                  </div>
                <?php endforeach; ?>
              </div>
            <?php else: ?>
              <p class="muted" style="margin:10px 0 0;">Aucun bénévole inscrit pour cet événement.</p>
            <?php endif; ?>

            <div style="margin-top:12px;">
              <?php if ($currentVolunteer && !$isPast): ?>
                <div style="display:flex; flex-direction:column; gap:6px; max-width:380px; margin-left:auto;">
                  <?php if ($isRegistered): ?>
                    <div class="badge" style="background:#dcfce7; border-color:transparent; color:#166534; align-self:flex-end;">
                      ✅ Ta présence est confirmée.
                    </div>
                    <form method="post" action="toggle_registration.php" style="margin:0; align-self:flex-end;">
                      <input type="hidden" name="event_id" value="<?= (int)$event['id'] ?>">
                      <input type="hidden" name="redirect" value="<?= h($currentUrl) ?>">
                      <button type="submit" name="action" value="leave" class="danger">Je ne viens pas</button>
                    </form>
                  <?php else: ?>
                    <div class="muted" style="font-weight:700; align-self:flex-end;">Tu n’es pas encore inscrit(e).</div>
                    <form method="post" action="toggle_registration.php" style="margin:0; align-self:flex-end;">
                      <input type="hidden" name="event_id" value="<?= (int)$event['id'] ?>">
                      <input type="hidden" name="redirect" value="<?= h($currentUrl) ?>">
                      <button type="submit" name="action" value="join" class="primary">Je viens</button>
                    </form>
                  <?php endif; ?>
                </div>
              <?php elseif ($isPast): ?>
                <p class="muted" style="margin:0;">Événement passé – présence non modifiable.</p>
              <?php else: ?>
                <p class="muted" style="margin:0;">Sélectionne ton nom en haut de page pour confirmer ta présence.</p>
              <?php endif; ?>
            </div>
          </div>

        <?php endforeach; ?>

      <?php endif; ?>
    </div>
  </div>
<?php endforeach; ?>

<?php
$content = ob_get_clean();
include __DIR__ . '/includes/layout.php';
