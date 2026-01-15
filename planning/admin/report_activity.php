<?php
// admin/report_activity.php
// CRA Planning (+ consolidation optionnelle CAISSE)
// Version: slots-aware + accès public + accès interne par code (sans être admin global)
//
// Principes appliqués :
// 1) Le CRA ne prend en compte QUE les créneaux/événements passés (à l’instant T).
// 2) Les "permanences" ne sont PAS comptées comme des "actions".
// 3) Les actions excluent aussi la catégorie "Logistique" (si event_types.category_label = 'Logistique').
// 4) Les recettes (caisse) sont intégrées de façon cohérente (KPI + chips + qualité de donnée).
// 5) On garde la répartition par catégories/types (AG-friendly), et le bloc admin pilotage.
//
// ✅ Slots : si event_slots existe + event_registrations.slot_id existe,
//    alors les heures / présences / couverture min sont calculées AU NIVEAU DU CRÉNEAU (slot).

require_once __DIR__ . '/../includes/app.php';
$config = require __DIR__ . '/../config/config.php';
global $pdo;

require_once __DIR__ . '/../includes/event_types.php';

function h($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

// ------------------------------------------------------------
// (Optionnel) mini-loader .env (sans dépendance)
// Place un fichier .env à la racine du projet (../.env depuis /admin)
// Exemple :
//   REPORT_ACTIVITY_ADMIN_CODE=MonCodeInterne
//   CAISSE_DBNAME=noel2025_outilcaisse
// ------------------------------------------------------------
(function () {
    $envPath = realpath(__DIR__ . '/../.env');
    if (!$envPath || !is_readable($envPath)) return;

    $lines = file($envPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if (!$lines) return;

    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#')) continue;
        if (!str_contains($line, '=')) continue;

        [$k, $v] = explode('=', $line, 2);
        $k = trim($k);
        $v = trim($v);

        if ($k === '') continue;

        // retire guillemets simples/doubles
        if ((str_starts_with($v, '"') && str_ends_with($v, '"')) || (str_starts_with($v, "'") && str_ends_with($v, "'"))) {
            $v = substr($v, 1, -1);
        }

        if (getenv($k) === false) {
            putenv($k . '=' . $v);
        }
        if (!isset($_ENV[$k])) {
            $_ENV[$k] = $v;
        }
    }
})();

function envv(string $k, $default = null) {
    $v = $_ENV[$k] ?? getenv($k);
    return ($v === false || $v === null || $v === '') ? $default : $v;
}

if (!($pdo instanceof PDO)) {
    echo "<div style='padding:12px;border:1px solid #fca5a5;background:#fff7f7;border-radius:12px;color:#991b1b;font-weight:700;'>
            DEBUG FAIL : \$pdo n'est pas initialisé (PDO null). Vérifie includes/app.php et la config DB.
          </div>";
    exit;
}

$year = isset($_GET['year']) ? (int)$_GET['year'] : (int)date('Y');
if ($year < 2000 || $year > ((int)date('Y') + 1)) $year = (int)date('Y');

/**
 * Mode d’affichage :
 * - public : anonymisé (AG / financeurs)
 * - admin  : détaillé interne (nominatif + pilotage)
 */
$mode = (isset($_GET['mode']) && $_GET['mode'] === 'admin') ? 'admin' : 'public';

// ------------------------------------------------------------
// Accès
// - public : toujours accessible
// - admin  : accessible si admin global (session) OU code interne (REPORT_ACTIVITY_ADMIN_CODE)
// ------------------------------------------------------------
$internalCode = (string)envv('REPORT_ACTIVITY_ADMIN_CODE', $config['report_activity_admin_code'] ?? '');

$canSeeAdmin = !empty($_SESSION['admin_authenticated']);
if (!$canSeeAdmin && $mode === 'admin') {
    if (!empty($_SESSION['report_activity_internal_ok'])) {
        $canSeeAdmin = true;
    } else {
        $postedCode = isset($_POST['access_code']) ? trim((string)$_POST['access_code']) : '';
        $getCode    = isset($_GET['code']) ? trim((string)$_GET['code']) : '';

        $try = $postedCode !== '' ? $postedCode : $getCode;
        if ($try !== '' && $internalCode !== '' && hash_equals($internalCode, $try)) {
            $_SESSION['report_activity_internal_ok'] = true;
            $canSeeAdmin = true;
        }
    }
}

// Si mode=admin mais pas autorisé -> on reste sur admin et on affiche un écran de saisie code plus bas.
$title = "CRA $year";
ob_start();

$start = sprintf('%d-01-01 00:00:00', $year);
$end   = sprintf('%d-12-31 23:59:59', $year);

// "à l'instant T" => uniquement passé
$nowSql = "NOW()";

// Règles métier
$PERMANENCE_CODE = 'permanence';
$LOGISTICS_CATEGORY_LABEL = 'Logistique';

// SMIC horaire brut (valorisation bénévolat)
$smicHourlyByYear = [
    2025 => 11.88,
    2026 => 12.02,
];
$smicHourly = $smicHourlyByYear[$year] ?? end($smicHourlyByYear);
$smicSourceNote = isset($smicHourlyByYear[$year])
    ? "SMIC horaire brut ($year)"
    : "SMIC horaire brut (taux de référence, à ajuster si besoin)";

/* =====================================================
   Détection “mode slots”
   - table event_slots existe ?
   - colonne event_registrations.slot_id existe ?
===================================================== */
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

/* =====================================================
   0) TYPES D'ÉVÉNEMENTS (référentiel)
===================================================== */
// public : actifs uniquement ; admin : actifs + inactifs
$eventTypes = [];
try {
    $eventTypes = get_event_types($pdo, $mode === 'public'); // public => onlyActive=true
} catch (Throwable $e) {
    $eventTypes = [
        'permanence' => 'Permanence',
        'evenement'  => 'Événement',
    ];
}

// Meta (ordre stable + catégories via category_label/category_sort si présent)
$typeMeta = [];
$hasCategoryCols = false;

try {
    $sql = "SELECT code, label, is_active, sort_order, category_label, category_sort
            FROM event_types " . (($mode === 'public') ? "WHERE is_active=1" : "") . "
            ORDER BY category_sort ASC, category_label ASC, sort_order ASC, label ASC";
    $stmt = $pdo->query($sql);
    $typeMeta = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $hasCategoryCols = true;
} catch (Throwable $e) {
    $hasCategoryCols = false;
    try {
        $sql = "SELECT code, label, is_active, sort_order
                FROM event_types " . (($mode === 'public') ? "WHERE is_active=1" : "") . "
                ORDER BY sort_order ASC, label ASC";
        $stmt = $pdo->query($sql);
        $typeMeta = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e2) {
        $typeMeta = [];
    }
}

if (empty($typeMeta)) {
    foreach ($eventTypes as $code => $label) {
        $typeMeta[] = [
            'code' => $code,
            'label' => $label,
            'is_active' => 1,
            'sort_order' => 0,
            'category_label' => 'Autres',
            'category_sort' => 100
        ];
    }
    $hasCategoryCols = true;
}

/* =====================================================
   Helpers "scope"
   - passé
   - actions = hors permanences + hors logistique
===================================================== */

// Condition "actions" (nécessite LEFT JOIN event_types et ON et.code = e.event_type)
$scopeActionsSql = "e.event_type <> :perm AND COALESCE(et.category_label,'') <> :logcat";

// --- Scopes adaptés slots vs legacy ---
// Pour compter des "évènements" (events) :
//   - legacy : e.start_datetime / e.end_datetime
//   - slots  : s.start_datetime / s.end_datetime (s = event_slots)
$eventsYearSql = $useSlots ? "s.start_datetime BETWEEN :start AND :end" : "e.start_datetime BETWEEN :start AND :end";
$eventsPastSql = $useSlots ? "s.end_datetime < {$nowSql}" : "e.end_datetime < {$nowSql}";

// Pour compter des "présences" (registrations) :
//   - legacy : r.event_id -> e
//   - slots  : r.slot_id -> s (et r.event_id existe toujours pour joindre e)
$regYearSql = $useSlots
    ? "COALESCE(s.start_datetime, e.start_datetime) BETWEEN :start AND :end"
    : "e.start_datetime BETWEEN :start AND :end";
$regPastSql = $useSlots
    ? "COALESCE(s.end_datetime, e.end_datetime) < {$nowSql}"
    : "e.end_datetime < {$nowSql}";
$regDurationMinutesSql = $useSlots
    ? "TIMESTAMPDIFF(MINUTE, COALESCE(s.start_datetime, e.start_datetime), COALESCE(s.end_datetime, e.end_datetime))"
    : "TIMESTAMPDIFF(MINUTE, e.start_datetime, e.end_datetime)";

/* =====================================================
   1ter) DÉTAIL DES RÉUNIONS (hors permanences)
   - Affiché en bas de la catégorie correspondante (plus parlant)
   - Pas de détail pour les permanences (volontaire)
   - Compatible slots : durée = durée du slot (si slot_id) sinon durée event (legacy)
===================================================== */

// Détecter le code "réunion" dans le référentiel (label contient "réunion"/"reunion")
$MEETING_CODE = null;
try {
    foreach ($typeMeta as $t) {
        $lbl = mb_strtolower((string)($t['label'] ?? ''));
        if ($lbl !== '' && (mb_strpos($lbl, 'réunion') !== false || mb_strpos($lbl, 'reunion') !== false)) {
            $MEETING_CODE = (string)($t['code'] ?? null);
            break;
        }
    }
} catch (Throwable $e) {
    $MEETING_CODE = null;
}
if (!$MEETING_CODE) {
    foreach (['reunion','reunions','meeting','meetings'] as $c) {
        if (isset($eventTypes[$c])) { $MEETING_CODE = $c; break; }
    }
}

// Liste détaillée des réunions (passées, non annulées, année civile)
$meetingEvents = [];
if ($MEETING_CODE) {
    if ($useSlots) {
        // UNION slots + legacy (évite les doublons)
        $stmt = $pdo->prepare("
            SELECT
              x.event_id,
              x.title,
              MIN(x.start_dt) AS start_dt,
              MAX(x.end_dt) AS end_dt,
              SUM(x.presences) AS presences,
              ROUND(COALESCE(SUM(x.minutes),0)/60, 1) AS hours
            FROM (
              SELECT
                e.id AS event_id,
                e.title AS title,
                MIN(s.start_datetime) AS start_dt,
                MAX(s.end_datetime) AS end_dt,
                COUNT(r.id) AS presences,
                COALESCE(SUM(CASE WHEN r.id IS NULL THEN 0 ELSE TIMESTAMPDIFF(MINUTE, s.start_datetime, s.end_datetime) END),0) AS minutes
              FROM events e
              JOIN event_slots s ON s.event_id = e.id
              LEFT JOIN event_registrations r ON r.slot_id = s.id AND r.status='present'
              WHERE e.event_type = :meet
                AND e.is_cancelled = 0
                AND s.start_datetime BETWEEN :start AND :end
                AND s.end_datetime < {$nowSql}
              GROUP BY e.id, e.title

              UNION ALL

              SELECT
                e.id AS event_id,
                e.title AS title,
                e.start_datetime AS start_dt,
                e.end_datetime   AS end_dt,
                COUNT(r.id) AS presences,
                COALESCE(SUM(CASE WHEN r.id IS NULL THEN 0 ELSE TIMESTAMPDIFF(MINUTE, e.start_datetime, e.end_datetime) END),0) AS minutes
              FROM events e
              LEFT JOIN event_registrations r
                ON r.event_id = e.id AND r.status='present' AND (r.slot_id IS NULL OR r.slot_id = 0)
              WHERE e.event_type = :meet
                AND e.is_cancelled = 0
                AND e.start_datetime BETWEEN :start AND :end
                AND e.end_datetime < {$nowSql}
              GROUP BY e.id, e.title
            ) x
            GROUP BY x.event_id, x.title
            ORDER BY start_dt ASC
        ");
        $stmt->execute(['meet'=>$MEETING_CODE, 'start'=>$start, 'end'=>$end]);
    } else {
        $stmt = $pdo->prepare("
            SELECT
              e.id AS event_id,
              e.title AS title,
              e.start_datetime AS start_dt,
              e.end_datetime AS end_dt,
              COUNT(r.id) AS presences,
              ROUND(COALESCE(SUM(CASE WHEN r.id IS NULL THEN 0 ELSE TIMESTAMPDIFF(MINUTE, e.start_datetime, e.end_datetime) END),0)/60, 1) AS hours
            FROM events e
            LEFT JOIN event_registrations r ON r.event_id = e.id AND r.status='present'
            WHERE e.event_type = :meet
              AND e.is_cancelled = 0
              AND e.start_datetime BETWEEN :start AND :end
              AND e.end_datetime < {$nowSql}
            GROUP BY e.id, e.title
            ORDER BY e.start_datetime ASC
        ");
        $stmt->execute(['meet'=>$MEETING_CODE, 'start'=>$start, 'end'=>$end]);
    }

    $meetingEvents = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

/* =====================================================
   1) STATISTIQUES GLOBALES (PASSÉES UNIQUEMENT)
===================================================== */

// Total actions (passées, hors annulés)
if ($useSlots) {
    $stmt = $pdo->prepare("
        SELECT COUNT(DISTINCT e.id)
        FROM events e
        JOIN event_slots s ON s.event_id = e.id
        LEFT JOIN event_types et ON et.code = e.event_type
        WHERE {$eventsYearSql}
          AND e.is_cancelled = 0
          AND {$eventsPastSql}
          AND {$scopeActionsSql}
    ");
    $stmt->execute([
        'start' => $start,
        'end'   => $end,
        'perm'  => $PERMANENCE_CODE,
        'logcat'=> $LOGISTICS_CATEGORY_LABEL
    ]);
    $totalActions = (int)$stmt->fetchColumn();
} else {
    $stmt = $pdo->prepare("
        SELECT COUNT(*)
        FROM events e
        LEFT JOIN event_types et ON et.code = e.event_type
        WHERE {$eventsYearSql}
          AND e.is_cancelled = 0
          AND {$eventsPastSql}
          AND {$scopeActionsSql}
    ");
    $stmt->execute([
        'start' => $start,
        'end'   => $end,
        'perm'  => $PERMANENCE_CODE,
        'logcat'=> $LOGISTICS_CATEGORY_LABEL
    ]);
    $totalActions = (int)$stmt->fetchColumn();
}

// Total permanences (passées, hors annulés)
if ($useSlots) {
    $stmt = $pdo->prepare("
        SELECT COUNT(DISTINCT e.id)
        FROM events e
        JOIN event_slots s ON s.event_id = e.id
        WHERE {$eventsYearSql}
          AND e.is_cancelled = 0
          AND {$eventsPastSql}
          AND e.event_type = :perm
    ");
    $stmt->execute(['start' => $start, 'end' => $end, 'perm' => $PERMANENCE_CODE]);
    $totalPermanences = (int)$stmt->fetchColumn();
} else {
    $stmt = $pdo->prepare("
        SELECT COUNT(*)
        FROM events e
        WHERE {$eventsYearSql}
          AND e.is_cancelled = 0
          AND {$eventsPastSql}
          AND e.event_type = :perm
    ");
    $stmt->execute(['start' => $start, 'end' => $end, 'perm' => $PERMANENCE_CODE]);
    $totalPermanences = (int)$stmt->fetchColumn();
}

// Total logistique (passées, hors annulés)
if ($useSlots) {
    $stmt = $pdo->prepare("
        SELECT COUNT(DISTINCT e.id)
        FROM events e
        JOIN event_slots s ON s.event_id = e.id
        LEFT JOIN event_types et ON et.code = e.event_type
        WHERE {$eventsYearSql}
          AND e.is_cancelled = 0
          AND {$eventsPastSql}
          AND COALESCE(et.category_label,'') = :logcat
    ");
    $stmt->execute(['start' => $start, 'end' => $end, 'logcat' => $LOGISTICS_CATEGORY_LABEL]);
    $totalLogistics = (int)$stmt->fetchColumn();
} else {
    $stmt = $pdo->prepare("
        SELECT COUNT(*)
        FROM events e
        LEFT JOIN event_types et ON et.code = e.event_type
        WHERE {$eventsYearSql}
          AND e.is_cancelled = 0
          AND {$eventsPastSql}
          AND COALESCE(et.category_label,'') = :logcat
    ");
    $stmt->execute(['start' => $start, 'end' => $end, 'logcat' => $LOGISTICS_CATEGORY_LABEL]);
    $totalLogistics = (int)$stmt->fetchColumn();
}

// Total annulés (passés) — cohérent “à date”
// (En slots, on considère "passé" si au moins un slot passé)
if ($useSlots) {
    $stmt = $pdo->prepare("
        SELECT COUNT(DISTINCT e.id)
        FROM events e
        JOIN event_slots s ON s.event_id = e.id
        WHERE {$eventsYearSql}
          AND e.is_cancelled = 1
          AND {$eventsPastSql}
    ");
    $stmt->execute(['start' => $start, 'end' => $end]);
    $totalCancelled = (int)$stmt->fetchColumn();
} else {
    $stmt = $pdo->prepare("
        SELECT COUNT(*)
        FROM events e
        WHERE {$eventsYearSql}
          AND e.is_cancelled = 1
          AND {$eventsPastSql}
    ");
    $stmt->execute(['start' => $start, 'end' => $end]);
    $totalCancelled = (int)$stmt->fetchColumn();
}

// Total présences (passées, hors annulés) — slots-aware
if ($useSlots) {
    $stmt = $pdo->prepare("
        SELECT COUNT(*)
        FROM event_registrations r
        JOIN events e ON e.id = r.event_id
        LEFT JOIN event_slots s ON s.id = r.slot_id
        WHERE r.status = 'present'
          AND r.slot_id IS NOT NULL
          AND {$regYearSql}
          AND e.is_cancelled = 0
          AND {$regPastSql}
    ");
    $stmt->execute(['start' => $start, 'end' => $end]);
    $totalPresences = (int)$stmt->fetchColumn();
} else {
    $stmt = $pdo->prepare("
        SELECT COUNT(*)
        FROM event_registrations r
        JOIN events e ON e.id = r.event_id
        WHERE r.status = 'present'
          AND {$regYearSql}
          AND e.is_cancelled = 0
          AND {$regPastSql}
    ");
    $stmt->execute(['start' => $start, 'end' => $end]);
    $totalPresences = (int)$stmt->fetchColumn();
}

// Bénévoles distincts (passés, hors annulés)
if ($useSlots) {
    $stmt = $pdo->prepare("
        SELECT COUNT(DISTINCT r.volunteer_id)
        FROM event_registrations r
        JOIN events e ON e.id = r.event_id
        LEFT JOIN event_slots s ON s.id = r.slot_id
        WHERE r.status = 'present'
          AND r.slot_id IS NOT NULL
          AND {$regYearSql}
          AND e.is_cancelled = 0
          AND {$regPastSql}
    ");
    $stmt->execute(['start' => $start, 'end' => $end]);
    $totalVolunteers = (int)$stmt->fetchColumn();
} else {
    $stmt = $pdo->prepare("
        SELECT COUNT(DISTINCT r.volunteer_id)
        FROM event_registrations r
        JOIN events e ON e.id = r.event_id
        WHERE r.status = 'present'
          AND {$regYearSql}
          AND e.is_cancelled = 0
          AND {$regPastSql}
    ");
    $stmt->execute(['start' => $start, 'end' => $end]);
    $totalVolunteers = (int)$stmt->fetchColumn();
}

// Heures bénévoles (tous events/slots passés, hors annulés)
if ($useSlots) {
    $stmt = $pdo->prepare("
        SELECT ROUND(
          COALESCE(SUM({$regDurationMinutesSql}), 0) / 60,
          1
        )
        FROM event_registrations r
        JOIN events e ON e.id = r.event_id
        LEFT JOIN event_slots s ON s.id = r.slot_id
        WHERE r.status = 'present'
          AND r.slot_id IS NOT NULL
          AND {$regYearSql}
          AND e.is_cancelled = 0
          AND {$regPastSql}
    ");
    $stmt->execute(['start' => $start, 'end' => $end]);
    $totalHours = (float)$stmt->fetchColumn();
} else {
    $stmt = $pdo->prepare("
        SELECT ROUND(
          COALESCE(SUM({$regDurationMinutesSql}), 0) / 60,
          1
        )
        FROM event_registrations r
        JOIN events e ON e.id = r.event_id
        WHERE r.status = 'present'
          AND {$regYearSql}
          AND e.is_cancelled = 0
          AND {$regPastSql}
    ");
    $stmt->execute(['start' => $start, 'end' => $end]);
    $totalHours = (float)$stmt->fetchColumn();
}

$volunteerValue = (int)round($totalHours * $smicHourly, 0);

// Heures bénévoles "actions" uniquement (utile ratio €/h vs caisse)
if ($useSlots) {
    $stmt = $pdo->prepare("
        SELECT ROUND(
          COALESCE(SUM({$regDurationMinutesSql}), 0) / 60,
          1
        )
        FROM event_registrations r
        JOIN events e ON e.id = r.event_id
        LEFT JOIN event_slots s ON s.id = r.slot_id
        LEFT JOIN event_types et ON et.code = e.event_type
        WHERE r.status = 'present'
          AND r.slot_id IS NOT NULL
          AND {$regYearSql}
          AND e.is_cancelled = 0
          AND {$regPastSql}
          AND {$scopeActionsSql}
    ");
    $stmt->execute([
        'start' => $start,
        'end'   => $end,
        'perm'  => $PERMANENCE_CODE,
        'logcat'=> $LOGISTICS_CATEGORY_LABEL
    ]);
    $totalHoursActions = (float)$stmt->fetchColumn();
} else {
    $stmt = $pdo->prepare("
        SELECT ROUND(
          COALESCE(SUM({$regDurationMinutesSql}), 0) / 60,
          1
        )
        FROM event_registrations r
        JOIN events e ON e.id = r.event_id
        LEFT JOIN event_types et ON et.code = e.event_type
        WHERE r.status = 'present'
          AND {$regYearSql}
          AND e.is_cancelled = 0
          AND {$regPastSql}
          AND {$scopeActionsSql}
    ");
    $stmt->execute([
        'start' => $start,
        'end'   => $end,
        'perm'  => $PERMANENCE_CODE,
        'logcat'=> $LOGISTICS_CATEGORY_LABEL
    ]);
    $totalHoursActions = (float)$stmt->fetchColumn();
}

/* =====================================================
   1bis) RECETTES (outil CAISSE) — DB séparée
   - Lien : evenements.planning_event_id (caisse) => events.id (planning)
   - Scope : actions uniquement + passées (slots-aware)
===================================================== */

$cash = [
    'enabled' => false,
    'db' => null,
    'total_sales' => 0.0,
    'total_sales_by_payment' => [],
    'total_sales_by_category' => [],
    'linked_actions' => 0,
    'note' => ''
];

try {
    $planningDb = (string)$pdo->query("SELECT DATABASE()")->fetchColumn();

    $caisseDb = $config['caisse_dbname'] ?? null;
    if (!$caisseDb) {
        $caisseDb = (string)envv('CAISSE_DBNAME', '');
        if ($caisseDb === '') $caisseDb = null;
    }
    if (!$caisseDb) {
        $caisseDb = preg_replace('/_planning$/', '_outilcaisse', $planningDb);
        if (!$caisseDb || $caisseDb === $planningDb) $caisseDb = null;
    }

    if ($caisseDb) {
        $stmtChk = $pdo->prepare("
            SELECT COUNT(*)
            FROM information_schema.TABLES
            WHERE TABLE_SCHEMA = :db
              AND TABLE_NAME IN ('evenements','ventes')
        ");
        $stmtChk->execute(['db' => $caisseDb]);
        $tablesOk = ((int)$stmtChk->fetchColumn() === 2);

        $colOk = false;
        if ($tablesOk) {
            $stmtCol = $pdo->prepare("
                SELECT COUNT(*)
                FROM information_schema.COLUMNS
                WHERE TABLE_SCHEMA = :db
                  AND TABLE_NAME = 'evenements'
                  AND COLUMN_NAME = 'planning_event_id'
            ");
            $stmtCol->execute(['db' => $caisseDb]);
            $colOk = ((int)$stmtCol->fetchColumn() === 1);
        }

        if ($tablesOk && $colOk) {
            $cash['enabled'] = true;
            $cash['db'] = $caisseDb;

            // Total ventes liées à des actions planning (passées, hors permanences, hors logistique)
            // Slots : l'action est liée par event_id (planning_event_id) -> on filtre "passé" via slots s (au moins un slot passé)
            if ($useSlots) {
                $stmt = $pdo->prepare("
                    SELECT
                      COALESCE(SUM(v.total), 0) AS total_sales,
                      COUNT(DISTINCT ev.planning_event_id) AS linked_actions
                    FROM `{$caisseDb}`.`ventes` v
                    JOIN `{$caisseDb}`.`evenements` ev ON ev.id = v.evenement_id
                    JOIN `{$planningDb}`.`events` e ON e.id = ev.planning_event_id
                    JOIN `{$planningDb}`.`event_slots` s ON s.event_id = e.id
                    LEFT JOIN `{$planningDb}`.`event_types` et ON et.code = e.event_type
                    WHERE s.start_datetime BETWEEN :start AND :end
                      AND e.is_cancelled = 0
                      AND s.end_datetime < {$nowSql}
                      AND {$scopeActionsSql}
                ");
            } else {
                $stmt = $pdo->prepare("
                    SELECT
                      COALESCE(SUM(v.total), 0) AS total_sales,
                      COUNT(DISTINCT ev.planning_event_id) AS linked_actions
                    FROM `{$caisseDb}`.`ventes` v
                    JOIN `{$caisseDb}`.`evenements` ev ON ev.id = v.evenement_id
                    JOIN `{$planningDb}`.`events` e ON e.id = ev.planning_event_id
                    LEFT JOIN `{$planningDb}`.`event_types` et ON et.code = e.event_type
                    WHERE e.start_datetime BETWEEN :start AND :end
                      AND e.is_cancelled = 0
                      AND e.end_datetime < {$nowSql}
                      AND {$scopeActionsSql}
                ");
            }
            $stmt->execute([
                'start' => $start,
                'end'   => $end,
                'perm'  => $PERMANENCE_CODE,
                'logcat'=> $LOGISTICS_CATEGORY_LABEL
            ]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
            $cash['total_sales'] = (float)($row['total_sales'] ?? 0);
            $cash['linked_actions'] = (int)($row['linked_actions'] ?? 0);

            // Ventilation par paiement
            if ($useSlots) {
                $stmt = $pdo->prepare("
                    SELECT v.paiement, COALESCE(SUM(v.total), 0) AS amount
                    FROM `{$caisseDb}`.`ventes` v
                    JOIN `{$caisseDb}`.`evenements` ev ON ev.id = v.evenement_id
                    JOIN `{$planningDb}`.`events` e ON e.id = ev.planning_event_id
                    JOIN `{$planningDb}`.`event_slots` s ON s.event_id = e.id
                    LEFT JOIN `{$planningDb}`.`event_types` et ON et.code = e.event_type
                    WHERE s.start_datetime BETWEEN :start AND :end
                      AND e.is_cancelled = 0
                      AND s.end_datetime < {$nowSql}
                      AND {$scopeActionsSql}
                    GROUP BY v.paiement
                ");
            } else {
                $stmt = $pdo->prepare("
                    SELECT v.paiement, COALESCE(SUM(v.total), 0) AS amount
                    FROM `{$caisseDb}`.`ventes` v
                    JOIN `{$caisseDb}`.`evenements` ev ON ev.id = v.evenement_id
                    JOIN `{$planningDb}`.`events` e ON e.id = ev.planning_event_id
                    LEFT JOIN `{$planningDb}`.`event_types` et ON et.code = e.event_type
                    WHERE e.start_datetime BETWEEN :start AND :end
                      AND e.is_cancelled = 0
                      AND e.end_datetime < {$nowSql}
                      AND {$scopeActionsSql}
                    GROUP BY v.paiement
                ");
            }
            $stmt->execute([
                'start' => $start,
                'end'   => $end,
                'perm'  => $PERMANENCE_CODE,
                'logcat'=> $LOGISTICS_CATEGORY_LABEL
            ]);
            $pairs = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
            $cash['total_sales_by_payment'] = array_map('floatval', $pairs ?: []);

            // Ventilation recettes par catégorie (planning)
            if ($useSlots) {
                $stmt = $pdo->prepare("
                    SELECT
                      COALESCE(et.category_label, 'Autres') AS cat,
                      COALESCE(SUM(v.total), 0) AS amount
                    FROM `{$caisseDb}`.`ventes` v
                    JOIN `{$caisseDb}`.`evenements` ev ON ev.id = v.evenement_id
                    JOIN `{$planningDb}`.`events` e ON e.id = ev.planning_event_id
                    JOIN `{$planningDb}`.`event_slots` s ON s.event_id = e.id
                    LEFT JOIN `{$planningDb}`.`event_types` et ON et.code = e.event_type
                    WHERE s.start_datetime BETWEEN :start AND :end
                      AND e.is_cancelled = 0
                      AND s.end_datetime < {$nowSql}
                      AND {$scopeActionsSql}
                    GROUP BY cat
                    ORDER BY amount DESC
                ");
            } else {
                $stmt = $pdo->prepare("
                    SELECT
                      COALESCE(et.category_label, 'Autres') AS cat,
                      COALESCE(SUM(v.total), 0) AS amount
                    FROM `{$caisseDb}`.`ventes` v
                    JOIN `{$caisseDb}`.`evenements` ev ON ev.id = v.evenement_id
                    JOIN `{$planningDb}`.`events` e ON e.id = ev.planning_event_id
                    LEFT JOIN `{$planningDb}`.`event_types` et ON et.code = e.event_type
                    WHERE e.start_datetime BETWEEN :start AND :end
                      AND e.is_cancelled = 0
                      AND e.end_datetime < {$nowSql}
                      AND {$scopeActionsSql}
                    GROUP BY cat
                    ORDER BY amount DESC
                ");
            }
            $stmt->execute([
                'start' => $start,
                'end'   => $end,
                'perm'  => $PERMANENCE_CODE,
                'logcat'=> $LOGISTICS_CATEGORY_LABEL
            ]);
            $pairs = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
            $cash['total_sales_by_category'] = array_map('floatval', $pairs ?: []);

            $cash['note'] = "Recettes brutes (ventes) issues de l’outil caisse, liées via planning_event_id (actions uniquement).";
        } else {
            $cash['note'] = "Outil caisse détecté, mais tables/colonne planning_event_id non trouvées.";
        }
    } else {
        $cash['note'] = "DB caisse non configurée (ajoute CAISSE_DBNAME dans .env ou \$config['caisse_dbname']).";
    }
} catch (Throwable $e) {
    $cash['enabled'] = false;
    $cash['note'] = "Recettes caisse indisponibles : " . $e->getMessage();
}

/* =====================================================
/* =====================================================
   1ter) DONS FINANCIERS (HelloAsso / autres) — Planning DB
   - Table : donations
   - Périmètre : année civile (donation_date)
===================================================== */

$donations = [
    'enabled' => false,
    'count' => 0,
    'amount' => 0.0,
    'eligible_count' => 0,
    'eligible_amount' => 0.0,
    'by_source' => [],
    'note' => ''
];

try {
    $stmtDon = $pdo->query("SHOW TABLES LIKE 'donations'");
    $hasDonations = (bool)$stmtDon->fetchColumn();

    if ($hasDonations) {
        $donations['enabled'] = true;

        // Total dons payés sur l'année
        $stmt = $pdo->prepare("
            SELECT
              COUNT(*) AS cnt,
              COALESCE(SUM(amount), 0) AS amount,
              SUM(CASE WHEN receipt_eligible=1 THEN 1 ELSE 0 END) AS elig_cnt,
              COALESCE(SUM(CASE WHEN receipt_eligible=1 THEN amount ELSE 0 END), 0) AS elig_amount
            FROM donations
            WHERE donation_date BETWEEN :start AND :end
              AND status = 'paid'
        ");
        $stmt->execute(['start' => $start, 'end' => $end]);
        $r = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
        $donations['count'] = (int)($r['cnt'] ?? 0);
        $donations['amount'] = (float)($r['amount'] ?? 0);
        $donations['eligible_count'] = (int)($r['elig_cnt'] ?? 0);
        $donations['eligible_amount'] = (float)($r['elig_amount'] ?? 0);

        // Ventilation par source (helloasso / manuel / virement...)
        $stmt = $pdo->prepare("
            SELECT COALESCE(source,'unknown') AS src, COALESCE(SUM(amount),0) AS amount
            FROM donations
            WHERE donation_date BETWEEN :start AND :end
              AND status = 'paid'
            GROUP BY src
            ORDER BY amount DESC
        ");
        $stmt->execute(['start' => $start, 'end' => $end]);
        $pairs = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
        $donations['by_source'] = array_map('floatval', $pairs ?: []);

        $donations['note'] = "Dons financiers enregistrés dans la table donations (ex. HelloAsso).";
    } else {
        $donations['note'] = "Table donations non trouvée (module dons non installé).";
    }
} catch (Throwable $e) {
    $donations['enabled'] = false;
    $donations['note'] = "Dons indisponibles : " . $e->getMessage();
}

/* =====================================================
   2) COUVERTURE DES BESOINS (min bénévoles)
   -> actions passées uniquement
   -> slots-aware : on compare présents vs min sur CHAQUE SLOT
===================================================== */


if ($useSlots) {
    $stmt = $pdo->prepare("
      SELECT
        COUNT(*) AS total,
        SUM(CASE WHEN COALESCE(x.actual, 0) >= s.min_volunteers THEN 1 ELSE 0 END) AS covered
      FROM event_slots s
      JOIN events e ON e.id = s.event_id
      LEFT JOIN event_types et ON et.code = e.event_type
      LEFT JOIN (
        SELECT slot_id, COUNT(*) AS actual
        FROM event_registrations
        WHERE status = 'present' AND slot_id IS NOT NULL
        GROUP BY slot_id
      ) x ON x.slot_id = s.id
      WHERE s.start_datetime BETWEEN :start AND :end
        AND e.is_cancelled = 0
        AND s.end_datetime < {$nowSql}
        AND s.min_volunteers > 0
        AND {$scopeActionsSql}
    ");
    $stmt->execute([
        'start' => $start,
        'end'   => $end,
        'perm'  => $PERMANENCE_CODE,
        'logcat'=> $LOGISTICS_CATEGORY_LABEL
    ]);
    $coverage = $stmt->fetch(PDO::FETCH_ASSOC);
    $coverageTotal   = (int)($coverage['total'] ?? 0);
    $coverageCovered = (int)($coverage['covered'] ?? 0);
    $coverageRate = ($coverageTotal > 0) ? round(($coverageCovered / $coverageTotal) * 100, 1) : null;

    $stmt = $pdo->prepare("
      SELECT COUNT(*)
      FROM event_slots s
      JOIN events e ON e.id = s.event_id
      LEFT JOIN event_types et ON et.code = e.event_type
      LEFT JOIN (
        SELECT slot_id, COUNT(*) AS actual
        FROM event_registrations
        WHERE status = 'present' AND slot_id IS NOT NULL
        GROUP BY slot_id
      ) x ON x.slot_id = s.id
      WHERE s.start_datetime BETWEEN :start AND :end
        AND e.is_cancelled = 0
        AND s.end_datetime < {$nowSql}
        AND s.min_volunteers > 0
        AND {$scopeActionsSql}
        AND COALESCE(x.actual, 0) < s.min_volunteers
    ");
    $stmt->execute([
        'start' => $start,
        'end'   => $end,
        'perm'  => $PERMANENCE_CODE,
        'logcat'=> $LOGISTICS_CATEGORY_LABEL
    ]);
    $underCoveredCount = (int)$stmt->fetchColumn();
} else {
    $stmt = $pdo->prepare("
      SELECT
        COUNT(*) AS total,
        SUM(CASE WHEN COALESCE(x.actual, 0) >= e.min_volunteers THEN 1 ELSE 0 END) AS covered
      FROM events e
      LEFT JOIN event_types et ON et.code = e.event_type
      LEFT JOIN (
        SELECT event_id, COUNT(*) AS actual
        FROM event_registrations
        WHERE status = 'present'
        GROUP BY event_id
      ) x ON x.event_id = e.id
      WHERE e.start_datetime BETWEEN :start AND :end
        AND e.is_cancelled = 0
        AND e.end_datetime < {$nowSql}
        AND e.min_volunteers > 0
        AND {$scopeActionsSql}
    ");
    $stmt->execute([
        'start' => $start,
        'end'   => $end,
        'perm'  => $PERMANENCE_CODE,
        'logcat'=> $LOGISTICS_CATEGORY_LABEL
    ]);
    $coverage = $stmt->fetch(PDO::FETCH_ASSOC);
    $coverageTotal   = (int)($coverage['total'] ?? 0);
    $coverageCovered = (int)($coverage['covered'] ?? 0);
    $coverageRate = ($coverageTotal > 0) ? round(($coverageCovered / $coverageTotal) * 100, 1) : null;

    $stmt = $pdo->prepare("
      SELECT COUNT(*)
      FROM events e
      LEFT JOIN event_types et ON et.code = e.event_type
      LEFT JOIN (
        SELECT event_id, COUNT(*) AS actual
        FROM event_registrations
        WHERE status = 'present'
        GROUP BY event_id
      ) x ON x.event_id = e.id
      WHERE e.start_datetime BETWEEN :start AND :end
        AND e.is_cancelled = 0
        AND e.end_datetime < {$nowSql}
        AND e.min_volunteers > 0
        AND {$scopeActionsSql}
        AND COALESCE(x.actual, 0) < e.min_volunteers
    ");
    $stmt->execute([
        'start' => $start,
        'end'   => $end,
        'perm'  => $PERMANENCE_CODE,
        'logcat'=> $LOGISTICS_CATEGORY_LABEL
    ]);
    $underCoveredCount = (int)$stmt->fetchColumn();
}

/* =====================================================
   3) AGRÉGATS PAR TYPE (dynamique) — PASSÉ UNIQUEMENT
   Slots-aware :
     - events_count : nombre d’évènements (distincts) ayant au moins un slot passé
     - presences/hours : calculés à partir des inscriptions sur slots (r.slot_id)
===================================================== */

if ($useSlots) {
    $stmt = $pdo->prepare("
      SELECT
        e.event_type,
        COUNT(DISTINCT e.id) AS events_count,
        COUNT(r.id) AS presences_count,
        ROUND(
          COALESCE(SUM(CASE WHEN r.id IS NULL THEN 0 ELSE TIMESTAMPDIFF(MINUTE, s.start_datetime, s.end_datetime) END), 0) / 60,
          1
        ) AS hours_count
      FROM events e
      JOIN event_slots s ON s.event_id = e.id
      LEFT JOIN event_registrations r
        ON r.slot_id = s.id AND r.status='present'
      WHERE s.start_datetime BETWEEN :start AND :end
        AND e.is_cancelled = 0
        AND s.end_datetime < {$nowSql}
      GROUP BY e.event_type
    ");
    $stmt->execute(['start' => $start, 'end' => $end]);
} else {
    $stmt = $pdo->prepare("
      SELECT
        e.event_type,
        COUNT(DISTINCT e.id) AS events_count,
        SUM(CASE WHEN r.id IS NULL THEN 0 ELSE 1 END) AS presences_count,
        ROUND(
          COALESCE(SUM(CASE WHEN r.id IS NULL THEN 0 ELSE TIMESTAMPDIFF(MINUTE, e.start_datetime, e.end_datetime) END), 0) / 60,
          1
        ) AS hours_count
      FROM events e
      LEFT JOIN event_registrations r
        ON r.event_id = e.id AND r.status='present'
      WHERE e.start_datetime BETWEEN :start AND :end
        AND e.is_cancelled = 0
        AND e.end_datetime < {$nowSql}
      GROUP BY e.event_type
    ");
    $stmt->execute(['start' => $start, 'end' => $end]);
}
$typeAggRaw = $stmt->fetchAll(PDO::FETCH_ASSOC);

$typeAgg = [];
foreach ($typeAggRaw as $r) {
    $code = (string)$r['event_type'];
    $hours = (float)$r['hours_count'];
    $typeAgg[$code] = [
        'events' => (int)$r['events_count'],
        'presences' => (int)$r['presences_count'],
        'hours' => $hours,
        'value' => (int)round($hours * $smicHourly, 0),
    ];
}

/* =====================================================
   4) CONSTRUCTION DES LIGNES + REGROUPEMENT PAR CATÉGORIE
===================================================== */

$typeRows = [];
$seenCodes = [];

foreach ($typeMeta as $t) {
    $code = (string)$t['code'];
    $seenCodes[$code] = true;

    $label = $eventTypes[$code] ?? ($t['label'] ?? $code);
    $agg = $typeAgg[$code] ?? ['events'=>0,'presences'=>0,'hours'=>0.0,'value'=>0];

    $typeRows[] = [
        'code' => $code,
        'label' => $label,
        'category' => $hasCategoryCols ? ((string)($t['category_label'] ?? 'Autres')) : 'Activité',
        'category_sort' => $hasCategoryCols ? (int)($t['category_sort'] ?? 100) : 100,
        'events' => (int)$agg['events'],
        'presences' => (int)$agg['presences'],
        'hours' => (float)$agg['hours'],
        'value' => (int)$agg['value'],
        'active' => (int)($t['is_active'] ?? 1),
    ];
}

if ($mode === 'admin') {
    foreach ($typeAgg as $code => $agg) {
        if (!isset($seenCodes[$code])) {
            $typeRows[] = [
                'code' => $code,
                'label' => "Type non référencé : {$code}",
                'category' => 'Autres',
                'category_sort' => 999,
                'events' => (int)$agg['events'],
                'presences' => (int)$agg['presences'],
                'hours' => (float)$agg['hours'],
                'value' => (int)$agg['value'],
                'active' => 0,
            ];
        }
    }
}

// Groupement par catégorie
$categories = [];
foreach ($typeRows as $tr) {
    $cat = $tr['category'] ?: 'Autres';
    $catSort = (int)$tr['category_sort'];

    if (!isset($categories[$cat])) {
        $categories[$cat] = [
            'name' => $cat,
            'sort' => $catSort,
            'totals' => ['events'=>0, 'presences'=>0, 'hours'=>0.0, 'value'=>0],
            'types' => []
        ];
    } else {
        $categories[$cat]['sort'] = min($categories[$cat]['sort'], $catSort);
    }

    $categories[$cat]['types'][] = $tr;
    $categories[$cat]['totals']['events'] += (int)$tr['events'];
    $categories[$cat]['totals']['presences'] += (int)$tr['presences'];
    $categories[$cat]['totals']['hours'] += (float)$tr['hours'];
    $categories[$cat]['totals']['value'] += (int)$tr['value'];
}

uasort($categories, function($a, $b) {
    if ($a['sort'] === $b['sort']) return strcasecmp($a['name'], $b['name']);
    return $a['sort'] <=> $b['sort'];
});

// Totaux globaux (pour %)
$totalCatHours = 0.0;
$totalCatValue = 0;
foreach ($categories as $cat) {
    $totalCatHours += (float)$cat['totals']['hours'];
    $totalCatValue += (int)$cat['totals']['value'];
}


/* =====================================================
   4bis) DÉTAIL PAR ÉVÉNEMENT (titres)
   - Objectif : rendre la lecture plus parlante que la répétition des types.
   - Règle : on affiche le détail pour tous les types SAUF les permanences.
   - Mode créneaux : on agrège par event (somme des slots passés) + présences/h.
===================================================== */

$detailsByType = [];

try {
    if ($useSlots) {
        $stmt = $pdo->prepare("
            SELECT
              e.event_type AS type_code,
              e.id AS event_id,
              e.title AS title,
              MIN(s.start_datetime) AS start_dt,
              MAX(s.end_datetime)  AS end_dt,
              SUM(CASE WHEN r.id IS NULL THEN 0 ELSE 1 END) AS presences_count,
              ROUND(
                COALESCE(SUM(CASE WHEN r.id IS NULL THEN 0 ELSE TIMESTAMPDIFF(MINUTE, s.start_datetime, s.end_datetime) END), 0) / 60,
                1
              ) AS hours_count
            FROM events e
            JOIN event_slots s ON s.event_id = e.id
            LEFT JOIN event_registrations r
              ON r.slot_id = s.id AND r.status = 'present'
            LEFT JOIN event_types et ON et.code = e.event_type
            WHERE s.start_datetime BETWEEN :start AND :end
              AND e.is_cancelled = 0
              AND s.end_datetime < {$nowSql}
            GROUP BY e.event_type, e.id
            ORDER BY start_dt ASC
        ");
        $stmt->execute(['start' => $start, 'end' => $end]);
    } else {
        $stmt = $pdo->prepare("
            SELECT
              e.event_type AS type_code,
              e.id AS event_id,
              e.title AS title,
              e.start_datetime AS start_dt,
              e.end_datetime  AS end_dt,
              SUM(CASE WHEN r.id IS NULL THEN 0 ELSE 1 END) AS presences_count,
              ROUND(
                COALESCE(SUM(CASE WHEN r.id IS NULL THEN 0 ELSE TIMESTAMPDIFF(MINUTE, e.start_datetime, e.end_datetime) END), 0) / 60,
                1
              ) AS hours_count
            FROM events e
            LEFT JOIN event_registrations r
              ON r.event_id = e.id AND r.status = 'present'
            LEFT JOIN event_types et ON et.code = e.event_type
            WHERE e.start_datetime BETWEEN :start AND :end
              AND e.is_cancelled = 0
              AND e.end_datetime < {$nowSql}
            GROUP BY e.event_type, e.id
            ORDER BY start_dt ASC
        ");
        $stmt->execute(['start' => $start, 'end' => $end]);
    }

    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    foreach ($rows as $r) {
        $code = (string)($r['type_code'] ?? '');
        if ($code === '') continue;

        // Exclure les permanences du détail (trop verbeux / pas demandé)
        if ($code === $PERMANENCE_CODE) continue;

        $hours = (float)($r['hours_count'] ?? 0);
        $detailsByType[$code][] = [
            'event_id'   => (int)($r['event_id'] ?? 0),
            'title'      => (string)($r['title'] ?? 'Événement'),
            'start_dt'   => (string)($r['start_dt'] ?? ''),
            'end_dt'     => (string)($r['end_dt'] ?? ''),
            'presences'  => (int)($r['presences_count'] ?? 0),
            'hours'      => $hours,
            'value'      => (int)round($hours * $smicHourly, 0),
        ];
    }
} catch (Throwable $e) {
    // On ne casse pas l’affichage si le détail échoue
    $detailsByType = [];
}


/* =====================================================
   5) ADMIN ONLY (pilotage) — PASSÉ UNIQUEMENT
   Slots-aware : sous-dotation au niveau slot si slots
===================================================== */
$topVolunteers = [];
$underCovered = [];

if ($mode === 'admin' && $canSeeAdmin) {
    if ($useSlots) {
        $stmt = $pdo->prepare("
            SELECT v.first_name, v.last_name, COUNT(*) cnt
            FROM event_registrations r
            JOIN volunteers v ON v.id = r.volunteer_id
            JOIN event_slots s ON s.id = r.slot_id
            JOIN events e ON e.id = s.event_id
            WHERE r.status='present'
              AND r.slot_id IS NOT NULL
              AND s.start_datetime BETWEEN :start AND :end
              AND e.is_cancelled = 0
              AND s.end_datetime < {$nowSql}
            GROUP BY v.id
            ORDER BY cnt DESC, v.last_name, v.first_name
            LIMIT 10
        ");
        $stmt->execute(['start' => $start, 'end' => $end]);
    } else {
        $stmt = $pdo->prepare("
            SELECT v.first_name, v.last_name, COUNT(*) cnt
            FROM event_registrations r
            JOIN volunteers v ON v.id = r.volunteer_id
            JOIN events e ON e.id = r.event_id
            WHERE r.status='present'
              AND e.start_datetime BETWEEN :start AND :end
              AND e.is_cancelled = 0
              AND e.end_datetime < {$nowSql}
            GROUP BY v.id
            ORDER BY cnt DESC, v.last_name, v.first_name
            LIMIT 10
        ");
        $stmt->execute(['start' => $start, 'end' => $end]);
    }
    $topVolunteers = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if ($useSlots) {
        $stmt = $pdo->prepare("
          SELECT
            e.id AS event_id,
            e.title,
            s.id AS slot_id,
            s.start_datetime,
            s.end_datetime,
            s.min_volunteers,
            COALESCE(x.actual, 0) AS actual
          FROM event_slots s
          JOIN events e ON e.id = s.event_id
          LEFT JOIN event_types et ON et.code = e.event_type
          LEFT JOIN (
            SELECT slot_id, COUNT(*) AS actual
            FROM event_registrations
            WHERE status = 'present' AND slot_id IS NOT NULL
            GROUP BY slot_id
          ) x ON x.slot_id = s.id
          WHERE s.start_datetime BETWEEN :start AND :end
            AND e.is_cancelled = 0
            AND s.end_datetime < {$nowSql}
            AND s.min_volunteers > 0
            AND {$scopeActionsSql}
            AND COALESCE(x.actual, 0) < s.min_volunteers
          ORDER BY s.start_datetime ASC
        ");
        $stmt->execute([
            'start' => $start,
            'end'   => $end,
            'perm'  => $PERMANENCE_CODE,
            'logcat'=> $LOGISTICS_CATEGORY_LABEL
        ]);
    } else {
        $stmt = $pdo->prepare("
          SELECT e.id, e.title, e.start_datetime, e.min_volunteers, COALESCE(x.actual, 0) AS actual
          FROM events e
          LEFT JOIN event_types et ON et.code = e.event_type
          LEFT JOIN (
            SELECT event_id, COUNT(*) AS actual
            FROM event_registrations
            WHERE status = 'present'
            GROUP BY event_id
          ) x ON x.event_id = e.id
          WHERE e.start_datetime BETWEEN :start AND :end
            AND e.is_cancelled = 0
            AND e.end_datetime < {$nowSql}
            AND e.min_volunteers > 0
            AND {$scopeActionsSql}
            AND COALESCE(x.actual, 0) < e.min_volunteers
          ORDER BY e.start_datetime ASC
        ");
        $stmt->execute([
            'start' => $start,
            'end'   => $end,
            'perm'  => $PERMANENCE_CODE,
            'logcat'=> $LOGISTICS_CATEGORY_LABEL
        ]);
    }
    $underCovered = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/* =====================================================
   6) KPI dérivés (recettes)
===================================================== */
$cashAvgPerAction = ($cash['enabled'] && $cash['linked_actions'] > 0)
    ? (float)$cash['total_sales'] / (int)$cash['linked_actions']
    : null;

$cashEuroPerVolunteerHour = ($cash['enabled'] && $totalHoursActions > 0.0)
    ? (float)$cash['total_sales'] / (float)$totalHoursActions
    : null;

// Qualité donnée caisse : actions réalisées vs actions liées
$unlinkedActions = null;
if ($cash['enabled']) {
    $unlinkedActions = max(0, (int)$totalActions - (int)$cash['linked_actions']);
}

/* =====================================================
   7) Encarts "lecture AG" : Permanences / Actions / Logistique
===================================================== */

$boxPermanences = ['events' => 0, 'presences' => 0, 'hours' => 0.0, 'value' => 0];
$boxActions     = ['events' => 0, 'presences' => 0, 'hours' => 0.0, 'value' => 0];
$boxLogistics   = ['events' => 0, 'presences' => 0, 'hours' => 0.0, 'value' => 0];

// On calcule via agrégats plutôt que recoder 15 requêtes
foreach ($typeRows as $tr) {
    $code = (string)$tr['code'];
    $cat  = (string)$tr['category'];

    if ($code === $PERMANENCE_CODE) {
        $boxPermanences['events']    += (int)$tr['events'];
        $boxPermanences['presences'] += (int)$tr['presences'];
        $boxPermanences['hours']     += (float)$tr['hours'];
        $boxPermanences['value']     += (int)$tr['value'];
        continue;
    }

    if (mb_strtolower($cat) === mb_strtolower($LOGISTICS_CATEGORY_LABEL)) {
        $boxLogistics['events']    += (int)$tr['events'];
        $boxLogistics['presences'] += (int)$tr['presences'];
        $boxLogistics['hours']     += (float)$tr['hours'];
        $boxLogistics['value']     += (int)$tr['value'];
        continue;
    }

    $boxActions['events']    += (int)$tr['events'];
    $boxActions['presences'] += (int)$tr['presences'];
    $boxActions['hours']     += (float)$tr['hours'];
    $boxActions['value']     += (int)$tr['value'];
}

/* =====================================================
   UI helpers + Synthèse
===================================================== */
$base = $config['base_url'] ?? '';
$modePublicUrl = $base . "/admin/report_activity.php?year={$year}&mode=public";
$modeAdminUrl  = $base . "/admin/report_activity.php?year={$year}&mode=admin";
$prevYearUrl   = $base . "/admin/report_activity.php?year=" . ($year - 1) . "&mode=" . $mode;
$nextYearUrl   = $base . "/admin/report_activity.php?year=" . ($year + 1) . "&mode=" . $mode;

$badgeClass = ($mode === 'admin') ? 'admin' : 'public';
$badgeText  = ($mode === 'admin') ? 'Version interne' : 'Version publique (anonymisée)';

$coverageSentence = ($coverageRate !== null)
    ? "Le minimum de bénévoles requis a été atteint sur {$coverageCovered} créneau(x)/action(s) sur {$coverageTotal} (soit {$coverageRate} %)."
    : "Aucun minimum de bénévoles n’a été défini sur les actions/créneaux passés.";

$timeScopeNote = $useSlots
    ? "NB : mode créneaux activé — seuls les créneaux (slots) passés sont comptabilisés."
    : "NB : seuls les événements déjà réalisés (passés) sont comptabilisés.";

$autoSummary = "En {$year} (à date), l’association a réalisé {$totalActions} action(s) et assuré {$totalPermanences} permanence(s), "
             . "mobilisant {$totalVolunteers} bénévole(s) distinct(s) pour {$totalPresences} présence(s). "
             . "Cela représente environ " . number_format($totalHours, 1, ',', ' ') . " h de bénévolat, soit une valorisation estimée à "
             . number_format($volunteerValue, 0, ',', ' ') . " € (base : {$smicSourceNote}). "
             . $coverageSentence;
if (!empty($donations['enabled']) && (float)$donations['amount'] > 0) {
    $autoSummary .= " Par ailleurs, " . number_format((float)$donations['amount'], 2, ',', ' ') . " € de dons financiers ont été enregistrés sur la période.";
}

?>
<style>
.cra-header{display:flex;justify-content:space-between;align-items:flex-start;gap:12px;flex-wrap:wrap;}
.cra-subtools{display:flex;gap:8px;flex-wrap:wrap;align-items:center;}
.cra-badge{display:inline-flex;align-items:center;gap:6px;padding:4px 10px;border-radius:999px;font-size:12px;font-weight:700;border:1px solid #e5e7eb;background:#f9fafb;color:#111827;}
.cra-badge.public{background:#e0f2fe;border-color:#bae6fd;color:#075985;}
.cra-badge.admin{background:#fee2e2;border-color:#fecaca;color:#991b1b;}

.cra-actions{display:flex;gap:8px;flex-wrap:wrap;}
.cra-linkbtn{text-decoration:none;}
.cra-pill{display:inline-flex;align-items:center;gap:6px;padding:6px 10px;border-radius:999px;border:1px solid #e5e7eb;background:#ffffff;font-size:12px;color:#111827;}
.cra-pill:hover{background:#f9fafb;}
.cra-pill.primary{border-color:transparent;background:linear-gradient(135deg,#2563eb,#3b82f6);color:white;font-weight:700;}
.cra-pill.danger{border-color:#fecaca;background:#fee2e2;color:#991b1b;font-weight:700;}

.cra-kpis{display:grid;grid-template-columns:repeat(auto-fit,minmax(170px,1fr));gap:12px;margin-top:12px;}
.cra-kpi{background:#f9fafb;border:1px solid #e5e7eb;border-radius:14px;padding:14px;}
.cra-kpi-value{font-size:28px;font-weight:800;line-height:1.05;letter-spacing:-0.02em;}
.cra-kpi-label{margin-top:6px;font-size:12px;color:#6b7280;font-weight:600;}
.cra-kpi-hint{margin-top:6px;font-size:11px;color:#6b7280;}

.cra-callout{border:1px solid #e5e7eb;background:#ffffff;border-radius:14px;padding:14px;}
.cra-callout-title{font-weight:800;margin-bottom:6px;}
.cra-callout p{margin:0;color:#111827;}

.cra-note{font-size:12px;color:#6b7280;}
.cra-table{width:100%;border-collapse:collapse;}
.cra-table th{text-align:left;font-size:12px;color:#6b7280;padding:8px 6px;border-bottom:1px solid #e5e7eb;}
.cra-table td{padding:8px 6px;border-bottom:1px solid #f3f4f6;font-size:13px;}

.cra-grid-2{display:grid;grid-template-columns:repeat(auto-fit,minmax(260px,1fr));gap:14px;}

.cra-cat{border:1px solid #e5e7eb;border-radius:14px;padding:14px;background:#fff;}
.cra-cat-header{display:flex;justify-content:space-between;align-items:flex-end;gap:10px;flex-wrap:wrap;margin-bottom:10px;}
.cra-cat-title{font-weight:900;font-size:16px;margin:0;}
.cra-cat-totals{display:flex;gap:10px;flex-wrap:wrap;align-items:center;}
.cra-mini{background:#f9fafb;border:1px solid #e5e7eb;border-radius:999px;padding:6px 10px;font-size:12px;}
.cra-mini.adminpct{background:#eef2ff;border-color:#c7d2fe;color:#3730a3;font-weight:700;}
.cra-mini.cash{background:#ecfeff;border-color:#a5f3fc;color:#155e75;font-weight:800;}

.cra-chips{display:flex;gap:8px;flex-wrap:wrap;margin-top:10px;}
.cra-chip{display:inline-flex;align-items:center;gap:8px;padding:8px 12px;border-radius:999px;border:1px solid #e5e7eb;background:#fff;font-size:13px;color:#111827;}
.cra-chip strong{font-weight:900;}
.cra-chip.muted{background:#f9fafb;color:#374151;}
.cra-chip.good{background:#dcfce7;border-color:#bbf7d0;color:#166534;font-weight:800;}
.cra-chip.warn{background:#ffedd5;border-color:#fed7aa;color:#9a3412;font-weight:800;}

.cra-boxes{display:grid;grid-template-columns:repeat(auto-fit,minmax(260px,1fr));gap:12px;margin-top:12px;}
.cra-box{border:1px solid #e5e7eb;border-radius:14px;background:#fff;padding:14px;}
.cra-box h4{margin:0 0 6px;font-size:15px;font-weight:900;}
.cra-box p{margin:0 0 10px;}
.cra-box .cra-chips{margin-top:6px;}

/* ✅ indentation “détails” sous une catégorie */
.cra-indent{display:inline-block;padding-left:14px;position:relative;}
.cra-indent:before{content:"↳";position:absolute;left:0;color:#9ca3af;}
.cra-total-row td{background:#f9fafb;font-weight:900;border-bottom:1px solid #e5e7eb;}
.cra-total-row td:first-child{color:#111827;}

/* Détail par événement */
.cra-detail td{
  font-size:12px;
  color:#374151;
  background:#fcfcfd;
}
.cra-detail td:first-child{
  padding-left:28px;
  color:#111827;
  font-weight:700;
}
.cra-detail .cra-detail-date{color:#6b7280;font-weight:700;margin-right:6px;}

</style>

<div class="card">
  <div class="cra-header">
    <div>
      <h2 style="margin:0 0 6px;">Compte-rendu d’activité <?= (int)$year ?></h2>
      <div class="cra-subtools">
        <span class="cra-badge <?= $badgeClass ?>"><?= h($badgeText) ?></span>
        <span class="badge">Période : <?= h($year) ?> (année civile)</span>
        <span class="badge">Périmètre : évènements passés</span>
        <span class="badge">Source : planning</span>
        <?php if ($useSlots): ?><span class="badge">mode créneaux</span><?php endif; ?>
        <?php if ($cash['enabled']): ?><span class="badge">+ caisse</span><?php endif; ?>
        <?php if (!empty($donations['enabled'])): ?><span class="badge">+ dons</span><?php endif; ?>
      </div>
      <p class="muted" style="margin-top:8px;">AG · financeurs · collectivités — chiffres consolidés + lecture de l’activité.</p>
      <p class="cra-note" style="margin-top:6px;"><?= h($timeScopeNote) ?></p>

      <div class="cra-chips" style="margin-top:10px;">
        <span class="cra-chip muted">Lien public : <strong><?= h($modePublicUrl) ?></strong></span>
        <span class="cra-chip muted">Lien interne : <strong><?= h($modeAdminUrl) ?></strong> (code requis si non admin)</span>
      </div>
    </div>

    <div class="cra-actions">
      <a class="cra-linkbtn" href="<?= h($prevYearUrl) ?>"><span class="cra-pill">← <?= $year - 1 ?></span></a>
      <a class="cra-linkbtn" href="<?= h($nextYearUrl) ?>"><span class="cra-pill"><?= $year + 1 ?> →</span></a>
      <a class="cra-linkbtn" href="<?= h($modePublicUrl) ?>"><span class="cra-pill <?= $mode === 'public' ? 'primary' : '' ?>">Public</span></a>
      <a class="cra-linkbtn" href="<?= h($modeAdminUrl) ?>"><span class="cra-pill <?= $mode === 'admin' ? 'danger' : '' ?>">Interne</span></a>
      <?php if (!empty($base)): ?>
        <a class="cra-linkbtn" href="<?= h($base) ?>/admin/reports_list.php"><span class="cra-pill">← Rapports</span></a>
      <?php endif; ?>
    </div>
  </div>

  <?php if ($mode === 'admin' && !$canSeeAdmin): ?>
    <div class="cra-callout" style="margin-top:12px;border-color:#fed7aa;background:#fff7ed;">
      <div class="cra-callout-title">Accès interne</div>
      <p class="cra-note" style="margin-bottom:10px;">
        Cette vue contient des données nominatives et des alertes de pilotage.
        Saisis le code interne pour l’ouvrir.
      </p>
      <form method="post" style="display:flex; gap:8px; flex-wrap:wrap; align-items:center;">
        <input class="form-control" style="max-width:320px;" type="password" name="access_code" placeholder="Code interne…" autocomplete="off">
        <button type="submit" class="danger">Déverrouiller</button>
        <a class="cra-linkbtn" href="<?= h($modePublicUrl) ?>"><span class="cra-pill primary">Rester en public</span></a>
      </form>
      <?php if ($internalCode === ''): ?>
        <p class="cra-note" style="margin-top:10px;">
          ⚠️ Aucun code n’est configuré. Ajoute <code>REPORT_ACTIVITY_ADMIN_CODE</code> dans <code>.env</code>
          (ou <code>\$config['report_activity_admin_code']</code>).
        </p>
      <?php endif; ?>
    </div>
  <?php endif; ?>

  <div class="cra-kpis">
    <div class="cra-kpi">
      <div class="cra-kpi-value"><?= (int)$totalActions ?></div>
      <div class="cra-kpi-label">Actions réalisées</div>
      <div class="cra-kpi-hint">Hors permanences · hors logistique · hors annulés · passées.</div>
    </div>

    <div class="cra-kpi">
      <div class="cra-kpi-value"><?= (int)$totalPermanences ?></div>
      <div class="cra-kpi-label">Permanences réalisées</div>
      <div class="cra-kpi-hint">Hors annulés · passées.</div>
    </div>

    <div class="cra-kpi">
      <div class="cra-kpi-value"><?= (int)$totalLogistics ?></div>
      <div class="cra-kpi-label">Logistique réalisée</div>
      <div class="cra-kpi-hint">Catégorie “<?= h($LOGISTICS_CATEGORY_LABEL) ?>” · passées.</div>
    </div>

    <div class="cra-kpi">
      <div class="cra-kpi-value"><?= (int)$totalVolunteers ?></div>
      <div class="cra-kpi-label">Bénévoles mobilisés</div>
      <div class="cra-kpi-hint">Au moins 1 présence sur la période.</div>
    </div>

    <div class="cra-kpi">
      <div class="cra-kpi-value"><?= (int)$totalPresences ?></div>
      <div class="cra-kpi-label">Présences bénévoles</div>
      <div class="cra-kpi-hint">Total des “présent” (évènements/créneaux passés).</div>
    </div>

    <div class="cra-kpi">
      <div class="cra-kpi-value"><?= h(number_format($totalHours, 1, ',', ' ')) ?> h</div>
      <div class="cra-kpi-label">Heures de bénévolat</div>
      <div class="cra-kpi-hint">Durée × présence (slots si dispo).</div>
    </div>

    <div class="cra-kpi">
      <div class="cra-kpi-value"><?= h(number_format($volunteerValue, 0, ',', ' ')) ?> €</div>
      <div class="cra-kpi-label">Valorisation du bénévolat</div>
      <div class="cra-kpi-hint">Base : <?= h(number_format($smicHourly, 2, ',', ' ')) ?> €/h (<?= h($smicSourceNote) ?>).</div>
    </div>

    <div class="cra-kpi">
      <div class="cra-kpi-value">
        <?= $coverageRate !== null ? h(number_format($coverageRate, 1, ',', ' ')) . ' %' : '—' ?>
      </div>
      <div class="cra-kpi-label">Couverture des besoins</div>
      <div class="cra-kpi-hint">
        <?php if ($coverageRate !== null): ?>
          Min atteint : <?= (int)$coverageCovered ?> / <?= (int)$coverageTotal ?> (<?= (int)$underCoveredCount ?> sous-doté(s)).
        <?php else: ?>
          Aucun “min bénévoles” défini sur les actions/créneaux passés.
        <?php endif; ?>
      </div>
    </div>

    <?php if ($cash['enabled']): ?>
      <div class="cra-kpi">
        <div class="cra-kpi-value"><?= h(number_format((float)$cash['total_sales'], 2, ',', ' ')) ?> €</div>
        <div class="cra-kpi-label">Recettes (caisse)</div>
        <div class="cra-kpi-hint">Ventes brutes liées à <?= (int)$cash['linked_actions'] ?> action(s).</div>
      </div>

      <div class="cra-kpi">
        <div class="cra-kpi-value">
          <?= $cashAvgPerAction !== null ? h(number_format($cashAvgPerAction, 2, ',', ' ')) . ' €' : '—' ?>
        </div>
        <div class="cra-kpi-label">Recette moyenne / action</div>
        <div class="cra-kpi-hint">Recettes caisse / actions liées.</div>
      </div>

      <div class="cra-kpi">
        <div class="cra-kpi-value">
          <?= $cashEuroPerVolunteerHour !== null ? h(number_format($cashEuroPerVolunteerHour, 2, ',', ' ')) . ' €' : '—' ?>
        </div>
        <div class="cra-kpi-label">€ / heure bénévole</div>
        <div class="cra-kpi-hint">Recettes caisse / heures bénévoles (actions).</div>
      </div>
    <?php endif; ?>

    <?php if (!empty($donations['enabled'])): ?>
      <div class="cra-kpi">
        <div class="cra-kpi-value"><?= h(number_format((float)$donations['amount'], 2, ',', ' ')) ?> €</div>
        <div class="cra-kpi-label">Dons financiers</div>
        <div class="cra-kpi-hint">Total des dons payés (HelloAsso + autres sources).</div>
      </div>

      <div class="cra-kpi">
        <div class="cra-kpi-value"><?= (int)$donations['count'] ?></div>
        <div class="cra-kpi-label">Nombre de dons</div>
        <div class="cra-kpi-hint">Dons payés sur l’année.</div>
      </div>

      <div class="cra-kpi">
        <div class="cra-kpi-value"><?= h(number_format((float)$donations['eligible_amount'], 2, ',', ' ')) ?> €</div>
        <div class="cra-kpi-label">Dons éligibles reçu fiscal</div>
        <div class="cra-kpi-hint">Base pour CERFA (si éligible).</div>
      </div>
    <?php endif; ?>
  </div>
</div>

<div class="card cra-callout">
  <div class="cra-callout-title">Synthèse (prête AG / financeurs)</div>
  <p><?= h($autoSummary) ?></p>

  <?php if (!empty($donations['enabled']) && (float)$donations['amount'] > 0): ?>
    <div class="cra-chips" style="margin-top:12px;">
      <span class="cra-chip good">Dons : <strong><?= h(number_format((float)$donations['amount'], 2, ',', ' ')) ?> €</strong></span>
      <span class="cra-chip">Nombre : <strong><?= (int)$donations['count'] ?></strong></span>
      <span class="cra-chip muted">Éligibles reçu fiscal : <strong><?= h(number_format((float)$donations['eligible_amount'], 2, ',', ' ')) ?> €</strong></span>
      <span class="cra-chip muted"><?= h($donations['note']) ?></span>
    </div>

    <?php if (!empty($donations['by_source'])): ?>
      <div class="cra-chips" style="margin-top:8px;">
        <?php foreach ($donations['by_source'] as $src => $amount): ?>
          <span class="cra-chip">
            <strong><?= h($src) ?></strong>
            <?= h(number_format((float)$amount, 2, ',', ' ')) ?> €
          </span>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  <?php endif; ?>

  <?php if ($cash['enabled']): ?>
    <div class="cra-chips">
      <?php
        $tot = (float)$cash['total_sales'];
        foreach (($cash['total_sales_by_payment'] ?? []) as $pay => $amount):
          $amount = (float)$amount;
          $pct = ($tot > 0) ? round(($amount / $tot) * 100, 0) : 0;
      ?>
        <span class="cra-chip">
          <strong><?= h($pay) ?></strong>
          <?= h(number_format($amount, 2, ',', ' ')) ?> €
          <span class="cra-note">(<?= (int)$pct ?> %)</span>
        </span>
      <?php endforeach; ?>
      <span class="cra-chip muted">Recettes brutes · pas de coût d’achat dans l’outil</span>
    </div>

    <div class="cra-chips" style="margin-top:12px;">
      <span class="cra-chip good">Actions réalisées : <strong><?= (int)$totalActions ?></strong></span>
      <span class="cra-chip <?= ($unlinkedActions !== null && $unlinkedActions > 0) ? 'warn' : 'good' ?>">
        Liées à la caisse : <strong><?= (int)$cash['linked_actions'] ?></strong>
      </span>
      <?php if ($unlinkedActions !== null): ?>
        <span class="cra-chip <?= ($unlinkedActions > 0) ? 'warn' : 'good' ?>">
          À relier : <strong><?= (int)$unlinkedActions ?></strong>
        </span>
      <?php endif; ?>
      <span class="cra-chip muted"><?= h($cash['note']) ?></span>
    </div>

    <?php
      $topCats = [];
      if (!empty($cash['total_sales_by_category'])) {
          foreach ($cash['total_sales_by_category'] as $cat => $amount) {
              $topCats[] = ['cat' => (string)$cat, 'amount' => (float)$amount];
          }
          $topCats = array_slice($topCats, 0, 3);
      }
    ?>
    <?php if (!empty($topCats)): ?>
      <div style="margin-top:12px;">
        <div class="cra-note" style="font-weight:800;">Top catégories (recettes)</div>
        <div class="cra-chips">
          <?php foreach ($topCats as $it): ?>
            <?php $pct = ($tot > 0) ? round(((float)$it['amount'] / $tot) * 100, 0) : 0; ?>
            <span class="cra-chip">
              <strong><?= h($it['cat']) ?></strong>
              <?= h(number_format((float)$it['amount'], 2, ',', ' ')) ?> €
              <span class="cra-note">(<?= (int)$pct ?> %)</span>
            </span>
          <?php endforeach; ?>
        </div>
      </div>
    <?php endif; ?>
  <?php else: ?>
    <p class="cra-note" style="margin-top:8px;">
      Recettes (outil caisse) : non consolidées. <?= h($cash['note']) ?>
    </p>
  <?php endif; ?>
</div>

<div class="card">
  <h3>Encarts de lecture (AG)</h3>
  <p class="muted">Lecture séparée : permanences (récurrent), actions/événements (mission), logistique (support).</p>

  <div class="cra-boxes">
    <div class="cra-box">
      <h4>Permanences</h4>
      <p class="cra-note">Activité récurrente · suivie à part (non assimilée à une action).</p>
      <div class="cra-chips">
        <span class="cra-chip"><strong><?= (int)$boxPermanences['events'] ?></strong> permanences</span>
        <span class="cra-chip"><strong><?= (int)$boxPermanences['presences'] ?></strong> présences</span>
        <span class="cra-chip"><strong><?= h(number_format((float)$boxPermanences['hours'], 1, ',', ' ')) ?></strong> h</span>
        <span class="cra-chip"><strong><?= h(number_format((int)$boxPermanences['value'], 0, ',', ' ')) ?></strong> €</span>
      </div>
    </div>

    <div class="cra-box">
      <h4>Actions / événements</h4>
      <p class="cra-note">Cœur d’activité (hors permanences et hors logistique).</p>
      <div class="cra-chips">
        <span class="cra-chip"><strong><?= (int)$totalActions ?></strong> actions</span>
        <span class="cra-chip"><strong><?= (int)$boxActions['presences'] ?></strong> présences</span>
        <span class="cra-chip"><strong><?= h(number_format((float)$totalHoursActions, 1, ',', ' ')) ?></strong> h</span>
        <span class="cra-chip"><strong><?= h(number_format((int)round($totalHoursActions * $smicHourly, 0), 0, ',', ' ')) ?></strong> €</span>
        <?php if ($cash['enabled']): ?>
          <span class="cra-chip"><strong><?= h(number_format((float)$cash['total_sales'], 2, ',', ' ')) ?></strong> € recettes</span>
          <?php if ($cashAvgPerAction !== null): ?>
            <span class="cra-chip"><strong><?= h(number_format($cashAvgPerAction, 2, ',', ' ')) ?></strong> €/action</span>
          <?php endif; ?>
          <?php if ($cashEuroPerVolunteerHour !== null): ?>
            <span class="cra-chip"><strong><?= h(number_format($cashEuroPerVolunteerHour, 2, ',', ' ')) ?></strong> €/h bénévole</span>
          <?php endif; ?>
        <?php endif; ?>
      </div>
    </div>

    <div class="cra-box">
      <h4>Logistique</h4>
      <p class="cra-note">Support opérationnel · utile à isoler pour rendre visible l’effort “non vitrine”.</p>
      <div class="cra-chips">
        <span class="cra-chip"><strong><?= (int)$boxLogistics['events'] ?></strong> actions</span>
        <span class="cra-chip"><strong><?= (int)$boxLogistics['presences'] ?></strong> présences</span>
        <span class="cra-chip"><strong><?= h(number_format((float)$boxLogistics['hours'], 1, ',', ' ')) ?></strong> h</span>
        <span class="cra-chip"><strong><?= h(number_format((int)$boxLogistics['value'], 0, ',', ' ')) ?></strong> €</span>
      </div>
    </div>
  </div>
</div>

<div class="card">
  <h3>Répartition par catégories d’activité</h3>
  <p class="muted">Blocs par catégorie, puis détail par type (évènements/créneaux passés uniquement).</p>

  <div class="cra-grid-2" style="grid-template-columns: 1fr; gap:12px;">
    <?php foreach ($categories as $cat): ?>
      <?php
        $pctHours = ($totalCatHours > 0) ? round(((float)$cat['totals']['hours'] / $totalCatHours) * 100, 1) : 0.0;
        $pctValue = ($totalCatValue > 0) ? round(((int)$cat['totals']['value'] / $totalCatValue) * 100, 1) : 0.0;
      ?>
      <div class="cra-cat">
        <div class="cra-cat-header">
          <h4 class="cra-cat-title"><?= h($cat['name']) ?></h4>

          <div class="cra-cat-totals">
            <span class="cra-mini"><strong><?= (int)$cat['totals']['events'] ?></strong> év.</span>
            <span class="cra-mini"><strong><?= (int)$cat['totals']['presences'] ?></strong> présences</span>
            <span class="cra-mini"><strong><?= h(number_format((float)$cat['totals']['hours'], 1, ',', ' ')) ?></strong> h</span>
            <span class="cra-mini"><strong><?= h(number_format((int)$cat['totals']['value'], 0, ',', ' ')) ?></strong> €</span>

            <?php if ($mode === 'admin' && $canSeeAdmin): ?>
              <span class="cra-mini adminpct"><strong><?= h(number_format($pctHours, 1, ',', ' ')) ?> %</strong> effort (h)</span>
              <span class="cra-mini adminpct"><strong><?= h(number_format($pctValue, 1, ',', ' ')) ?> %</strong> valeur (€)</span>

              <?php if ($cash['enabled'] && !empty($cash['total_sales_by_category'])): ?>
                <?php
                  $catSales = (float)($cash['total_sales_by_category'][$cat['name']] ?? 0);
                  $pctSales = ((float)$cash['total_sales'] > 0) ? round(($catSales / (float)$cash['total_sales']) * 100, 1) : 0.0;
                ?>
                <span class="cra-mini cash"><strong><?= h(number_format($catSales, 2, ',', ' ')) ?> €</strong> recettes</span>
                <span class="cra-mini cash"><strong><?= h(number_format($pctSales, 1, ',', ' ')) ?> %</strong> recettes</span>
              <?php endif; ?>
            <?php endif; ?>
          </div>
        </div>

        <table class="cra-table">
          <thead>
            <tr>
              <th>Type</th>
              <th>Événements</th>
              <th>Présences</th>
              <th>Heures</th>
              <th>Valorisation</th>
            </tr>
          </thead>
          <tbody>
            <!-- ✅ ligne total catégorie -->
            <tr class="cra-total-row">
              <td>Total catégorie</td>
              <td><?= (int)$cat['totals']['events'] ?></td>
              <td><?= (int)$cat['totals']['presences'] ?></td>
              <td><?= h(number_format((float)$cat['totals']['hours'], 1, ',', ' ')) ?> h</td>
              <td><?= h(number_format((int)$cat['totals']['value'], 0, ',', ' ')) ?> €</td>
            </tr>

            <?php foreach ($cat['types'] as $tr): ?>
              <tr>
                <td>
                  <span class="cra-indent"><strong><?= h($tr['label']) ?></strong></span>
                  <?php if ($mode === 'admin' && $canSeeAdmin && (int)$tr['active'] === 0): ?>
                    <span class="badge danger" style="margin-left:6px;">inactif</span>
                  <?php endif; ?>
                  <?php if ((string)$tr['code'] === $PERMANENCE_CODE): ?>
                    <span class="badge" style="margin-left:6px;">permanence</span>
                  <?php endif; ?>
                </td>
                <td><?= (int)$tr['events'] ?></td>
                <td><?= (int)$tr['presences'] ?></td>
                <td><?= h(number_format((float)$tr['hours'], 1, ',', ' ')) ?> h</td>
                <td><?= h(number_format((int)$tr['value'], 0, ',', ' ')) ?> €</td>
              </tr>
              <?php
                // Détail par événement (titres) — plus parlant que la répétition.
                $drows = $detailsByType[(string)$tr['code']] ?? [];
                foreach ($drows as $dr):
                  $dDate = !empty($dr['start_dt']) ? date('d/m/Y', strtotime($dr['start_dt'])) : '';
              ?>
                <tr class="cra-detail">
                  <td><span class="cra-detail-date"><?= h($dDate) ?></span><?= h($dr['title']) ?></td>
                  <td></td>
                  <td><?= (int)$dr['presences'] ?></td>
                  <td><?= h(number_format((float)$dr['hours'], 1, ',', ' ')) ?> h</td>
                  <td><?= h(number_format((int)$dr['value'], 0, ',', ' ')) ?> €</td>
                </tr>
              <?php endforeach; ?>
            <?php endforeach; ?>
          </tbody>
        
</table>

        <?php
          $catHasMeeting = false;
          if (!empty($MEETING_CODE)) {
              foreach (($cat['types'] ?? []) as $__tr) {
                  if ((string)($__tr['code'] ?? '') === (string)$MEETING_CODE) { $catHasMeeting = true; break; }
              }
          }
        ?>
        <?php if ($catHasMeeting && !empty($meetingEvents)): ?>
          <div style="margin-top:12px;">
            <div class="cra-note" style="font-weight:800;">Détail des réunions</div>
            <table class="cra-table" style="margin-top:6px;">
              <thead>
                <tr>
                  <th>Date</th>
                  <th>Réunion</th>
                  <th>Présences</th>
                  <th>Heures</th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($meetingEvents as $m): ?>
                  <?php
                    $d = !empty($m['start_dt']) ? date('d/m/Y', strtotime($m['start_dt'])) : '';
                    $titleM = (string)($m['title'] ?? 'Réunion');
                    $presM = (int)($m['presences'] ?? 0);
                    $hrsM  = (float)($m['hours'] ?? 0.0);
                  ?>
                  <tr>
                    <td><?= h($d) ?></td>
                    <td><strong><?= h($titleM) ?></strong></td>
                    <td><?= (int)$presM ?></td>
                    <td><?= h(number_format($hrsM, 1, ',', ' ')) ?> h</td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        <?php endif; ?>

      </div>
    <?php endforeach; ?>
  </div>

  <p class="cra-note" style="margin-top:10px;">
    Valorisation = heures bénévoles estimées × <?= h(number_format($smicHourly, 2, ',', ' ')) ?> €/h (<?= h($smicSourceNote) ?>).
  </p>
</div>

<div class="card">
  <h3>Méthodologie & périmètre</h3>
  <ul class="cra-note" style="margin:0; padding-left:18px;">
    <li>Périmètre CRA : uniquement les événements <strong>passés</strong> (<?= $useSlots ? 'créneaux : end_datetime &lt; maintenant' : 'end_datetime &lt; maintenant' ?>).</li>
    <li>Source : événements et présences enregistrées dans l’outil de planning.</li>
    <li>Sont exclus : événements annulés.</li>
    <li>Heures bénévoles : durée de l’événement/créneau × nombre de présences “présent”.</li>
    <li>Valorisation : heures bénévoles × SMIC brut de référence.</li>
    <li>Couverture des besoins : comparaison “présents” vs “min bénévoles” (actions uniquement, slots si dispo).</li>
    <?php if ($cash['enabled']): ?>
      <li>Recettes : ventes brutes (outil caisse) liées aux <strong>actions</strong> via <code>evenements.planning_event_id</code>.</li>
    <?php endif; ?>
  </ul>
</div>

<?php if ($mode === 'admin' && $canSeeAdmin): ?>
  <div class="card cra-admin-box">
    <h3>Données internes (pilotage)</h3>
    <p class="cra-note">Réservé à l’équipe interne : détails nominatif + points d’alerte (évènements passés).</p>

    <div class="cra-grid-2" style="margin-top:10px;">
      <div>
        <h3 style="margin-top:0;">Top bénévoles (interne)</h3>
        <?php if (empty($topVolunteers)): ?>
          <p class="muted">Aucune présence enregistrée sur la période.</p>
        <?php else: ?>
          <table class="cra-table">
            <thead><tr><th>Bénévole</th><th>Présences</th></tr></thead>
            <tbody>
              <?php foreach ($topVolunteers as $v): ?>
                <?php $name = trim(($v['first_name'] ?? '') . ' ' . ($v['last_name'] ?? '')); ?>
                <tr><td><?= h($name ?: 'Bénévole') ?></td><td><?= (int)$v['cnt'] ?></td></tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        <?php endif; ?>
      </div>

      <div>
        <h3 style="margin-top:0;"><?= $useSlots ? 'Créneaux sous-dotés' : 'Actions sous-dotées' ?></h3>
        <?php if (empty($underCovered)): ?>
          <p class="muted">Aucune sous-dotation (min atteint partout).</p>
        <?php else: ?>
          <table class="cra-table">
            <thead>
              <tr>
                <th>Date</th>
                <th><?= $useSlots ? 'Créneau / événement' : 'Événement' ?></th>
                <th>Présents / Min</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($underCovered as $e): ?>
                <tr>
                  <td><?= h(date('d/m/Y', strtotime($e['start_datetime'] ?? ''))) ?></td>
                  <td>
                    <?= h($e['title'] ?? '') ?>
                    <?php if ($useSlots && !empty($e['end_datetime'])): ?>
                      <span class="badge" style="margin-left:6px;"><?= h(date('H:i', strtotime($e['start_datetime']))) ?> → <?= h(date('H:i', strtotime($e['end_datetime']))) ?></span>
                    <?php endif; ?>
                  </td>
                  <td><strong><?= (int)($e['actual'] ?? 0) ?></strong> / <?= (int)($e['min_volunteers'] ?? 0) ?></td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        <?php endif; ?>
      </div>
    </div>
  </div>
<?php endif; ?>

<div class="card">
  <div style="display:flex; gap:8px; flex-wrap:wrap; align-items:center; justify-content:space-between;">
    <div class="cra-note">Astuce : pour la version financeurs, partage la <strong>version publique</strong> (anonymisée).</div>
    <div class="cra-actions">
      <a class="cra-linkbtn" href="<?= h($modePublicUrl) ?>"><span class="cra-pill <?= $mode === 'public' ? 'primary' : '' ?>">Ouvrir version publique</span></a>
      <a class="cra-linkbtn" href="<?= h($modeAdminUrl) ?>"><span class="cra-pill <?= $mode === 'admin' ? 'danger' : '' ?>">Ouvrir version interne</span></a>
    </div>
  </div>
</div>

<?php
$content = ob_get_clean();
include __DIR__ . '/../includes/layout.php';
