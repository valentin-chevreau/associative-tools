<?php
// reports/activity.php
// CRA — compte-rendu d'activité (planning + caisse + dons + adhésions + convois)
// Module « Rapports » : l'accès est contrôlé par shared/bootstrap.php (droit « reports »).
// Version: slots-aware + accès public + accès interne par code (sans être admin global)
//
// Principes appliqués :
// 1) Le CRA ne prend en compte QUE les créneaux/événements passés (à l’instant T).
// 2) Les "permanences" ne sont PAS comptées comme des "actions".
// 3) Les actions excluent aussi la catégorie "Logistique" (si planning_event_types.category_label = 'Logistique').
// 4) Les recettes (caisse) sont intégrées de façon cohérente (KPI + chips + qualité de donnée).
// 5) On garde la répartition par catégories/types (AG-friendly), et le bloc admin pilotage.
//
// ✅ Slots : si planning_event_slots existe + event_registrations.slot_id existe,
//    alors les heures / présences / couverture min sont calculées AU NIVEAU DU CRÉNEAU (slot).

require_once dirname(__DIR__) . '/shared/bootstrap.php';
require_admin();
require_once __DIR__ . '/includes/metrics.php';
require_once dirname(__DIR__) . '/planning/includes/event_types.php';

$config = [];
$pdo = _bootstrap_get_pdo();
if ($pdo instanceof PDO) { rep_pdo_prepare_mode($pdo); } // requêtes à paramètres nommés réutilisés

function h($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

// ------------------------------------------------------------
// (Optionnel) mini-loader .env (sans dépendance)
// Place un fichier .env à la racine du projet (../.env depuis /admin)
// Exemple :
//   REPORT_ACTIVITY_ADMIN_CODE=MonCodeInterne
// ------------------------------------------------------------
(function () {
    $envPath = realpath(__DIR__ . '/../.env') ?: realpath(__DIR__ . '/../planning/.env');
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

// Tout utilisateur qui atteint ce module a le droit « Rapports » : pas de code interne séparé.
$canSeeAdmin = true;
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
   - table planning_event_slots existe ?
   - colonne event_registrations.slot_id existe ?
===================================================== */
$hasSlots = false;
$hasSlotIdCol = false;
try {
    $stmt = $pdo->query("SHOW TABLES LIKE 'planning_event_slots'");
    $hasSlots = (bool)$stmt->fetchColumn();
} catch (Throwable $e) {
    $hasSlots = false;
}
try {
    $stmt = $pdo->query("SHOW COLUMNS FROM planning_event_registrations LIKE 'slot_id'");
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
            FROM planning_event_types " . (($mode === 'public') ? "WHERE is_active=1" : "") . "
            ORDER BY category_sort ASC, category_label ASC, sort_order ASC, label ASC";
    $stmt = $pdo->query($sql);
    $typeMeta = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $hasCategoryCols = true;
} catch (Throwable $e) {
    $hasCategoryCols = false;
    try {
        $sql = "SELECT code, label, is_active, sort_order
                FROM planning_event_types " . (($mode === 'public') ? "WHERE is_active=1" : "") . "
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

// Condition "actions" (nécessite LEFT JOIN planning_event_types et ON et.code = e.event_type)
$scopeActionsSql = "e.event_type <> :perm AND COALESCE(et.category_label,'') <> :logcat";

// --- Scopes adaptés slots vs legacy ---
// Pour compter des "évènements" (events) :
//   - legacy : e.start_datetime / e.end_datetime
//   - slots  : s.start_datetime / s.end_datetime (s = planning_event_slots)
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
              FROM planning_events e
              JOIN planning_event_slots s ON s.event_id = e.id
              LEFT JOIN planning_event_registrations r ON r.slot_id = s.id AND r.status='present'
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
              FROM planning_events e
              LEFT JOIN planning_event_registrations r
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
            FROM planning_events e
            LEFT JOIN planning_event_registrations r ON r.event_id = e.id AND r.status='present'
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
        FROM planning_events e
        JOIN planning_event_slots s ON s.event_id = e.id
        LEFT JOIN planning_event_types et ON et.code = e.event_type
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
        FROM planning_events e
        LEFT JOIN planning_event_types et ON et.code = e.event_type
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
        FROM planning_events e
        JOIN planning_event_slots s ON s.event_id = e.id
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
        FROM planning_events e
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
        FROM planning_events e
        JOIN planning_event_slots s ON s.event_id = e.id
        LEFT JOIN planning_event_types et ON et.code = e.event_type
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
        FROM planning_events e
        LEFT JOIN planning_event_types et ON et.code = e.event_type
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
        FROM planning_events e
        JOIN planning_event_slots s ON s.event_id = e.id
        WHERE {$eventsYearSql}
          AND e.is_cancelled = 1
          AND {$eventsPastSql}
    ");
    $stmt->execute(['start' => $start, 'end' => $end]);
    $totalCancelled = (int)$stmt->fetchColumn();
} else {
    $stmt = $pdo->prepare("
        SELECT COUNT(*)
        FROM planning_events e
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
        FROM planning_event_registrations r
        JOIN planning_events e ON e.id = r.event_id
        LEFT JOIN planning_event_slots s ON s.id = r.slot_id
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
        FROM planning_event_registrations r
        JOIN planning_events e ON e.id = r.event_id
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
        FROM planning_event_registrations r
        JOIN planning_events e ON e.id = r.event_id
        LEFT JOIN planning_event_slots s ON s.id = r.slot_id
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
        FROM planning_event_registrations r
        JOIN planning_events e ON e.id = r.event_id
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
        FROM planning_event_registrations r
        JOIN planning_events e ON e.id = r.event_id
        LEFT JOIN planning_event_slots s ON s.id = r.slot_id
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
        FROM planning_event_registrations r
        JOIN planning_events e ON e.id = r.event_id
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
        FROM planning_event_registrations r
        JOIN planning_events e ON e.id = r.event_id
        LEFT JOIN planning_event_slots s ON s.id = r.slot_id
        LEFT JOIN planning_event_types et ON et.code = e.event_type
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
        FROM planning_event_registrations r
        JOIN planning_events e ON e.id = r.event_id
        LEFT JOIN planning_event_types et ON et.code = e.event_type
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

    // Base unifiée : les tables de la caisse (caisse_*) sont dans la même base que le planning.
    $caisseDb = $planningDb ?: null;

    if ($caisseDb) {
        $stmtChk = $pdo->prepare("
            SELECT COUNT(*)
            FROM information_schema.TABLES
            WHERE TABLE_SCHEMA = :db
              AND TABLE_NAME IN ('caisse_evenements','caisse_ventes')
        ");
        $stmtChk->execute(['db' => $caisseDb]);
        $tablesOk = ((int)$stmtChk->fetchColumn() === 2);

        $colOk = false;
        if ($tablesOk) {
            $stmtCol = $pdo->prepare("
                SELECT COUNT(*)
                FROM information_schema.COLUMNS
                WHERE TABLE_SCHEMA = :db
                  AND TABLE_NAME = 'caisse_evenements'
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
                    FROM `{$caisseDb}`.`caisse_ventes` v
                    JOIN `{$caisseDb}`.`caisse_evenements` ev ON ev.id = v.evenement_id
                    JOIN `{$planningDb}`.`planning_events` e ON e.id = ev.planning_event_id
                    JOIN `{$planningDb}`.`planning_event_slots` s ON s.event_id = e.id
                    LEFT JOIN `{$planningDb}`.`planning_event_types` et ON et.code = e.event_type
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
                    FROM `{$caisseDb}`.`caisse_ventes` v
                    JOIN `{$caisseDb}`.`caisse_evenements` ev ON ev.id = v.evenement_id
                    JOIN `{$planningDb}`.`planning_events` e ON e.id = ev.planning_event_id
                    LEFT JOIN `{$planningDb}`.`planning_event_types` et ON et.code = e.event_type
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
                    SELECT vp.methode AS paiement, COALESCE(SUM(vp.montant), 0) AS amount
                    FROM `{$caisseDb}`.`caisse_ventes` v
                    JOIN `{$caisseDb}`.`caisse_vente_paiements` vp ON vp.vente_id = v.id
                    JOIN `{$caisseDb}`.`caisse_evenements` ev ON ev.id = v.evenement_id
                    JOIN `{$planningDb}`.`planning_events` e ON e.id = ev.planning_event_id
                    JOIN `{$planningDb}`.`planning_event_slots` s ON s.event_id = e.id
                    LEFT JOIN `{$planningDb}`.`planning_event_types` et ON et.code = e.event_type
                    WHERE s.start_datetime BETWEEN :start AND :end
                      AND e.is_cancelled = 0
                      AND s.end_datetime < {$nowSql}
                      AND {$scopeActionsSql}
                    GROUP BY vp.methode
                ");
            } else {
                $stmt = $pdo->prepare("
                    SELECT vp.methode AS paiement, COALESCE(SUM(vp.montant), 0) AS amount
                    FROM `{$caisseDb}`.`caisse_ventes` v
                    JOIN `{$caisseDb}`.`caisse_vente_paiements` vp ON vp.vente_id = v.id
                    JOIN `{$caisseDb}`.`caisse_evenements` ev ON ev.id = v.evenement_id
                    JOIN `{$planningDb}`.`planning_events` e ON e.id = ev.planning_event_id
                    LEFT JOIN `{$planningDb}`.`planning_event_types` et ON et.code = e.event_type
                    WHERE e.start_datetime BETWEEN :start AND :end
                      AND e.is_cancelled = 0
                      AND e.end_datetime < {$nowSql}
                      AND {$scopeActionsSql}
                    GROUP BY vp.methode
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
                    FROM `{$caisseDb}`.`caisse_ventes` v
                    JOIN `{$caisseDb}`.`caisse_evenements` ev ON ev.id = v.evenement_id
                    JOIN `{$planningDb}`.`planning_events` e ON e.id = ev.planning_event_id
                    JOIN `{$planningDb}`.`planning_event_slots` s ON s.event_id = e.id
                    LEFT JOIN `{$planningDb}`.`planning_event_types` et ON et.code = e.event_type
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
                    FROM `{$caisseDb}`.`caisse_ventes` v
                    JOIN `{$caisseDb}`.`caisse_evenements` ev ON ev.id = v.evenement_id
                    JOIN `{$planningDb}`.`planning_events` e ON e.id = ev.planning_event_id
                    LEFT JOIN `{$planningDb}`.`planning_event_types` et ON et.code = e.event_type
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

            $cash['note'] = "Recettes brutes (ventes) issues de l’outil caisse, liées via caisse_evenements.planning_event_id (actions uniquement).";
        } else {
            $cash['note'] = "Outil caisse détecté, mais tables/colonne planning_event_id non trouvées.";
        }
    } else {
        $cash['note'] = "Base de données indisponible.";
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
    // Le module dons a été extrait de planning (base unifiée) : la table
    // s'appelle désormais "donations" (anciennement "planning_donations").
    // On la lit ici en lecture seule, purement pour ce rapport d'activité.
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

        $donations['note'] = "Dons financiers enregistrés dans la table donations (ex. HelloAsso) — module dons autonome.";
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
      FROM planning_event_slots s
      JOIN planning_events e ON e.id = s.event_id
      LEFT JOIN planning_event_types et ON et.code = e.event_type
      LEFT JOIN (
        SELECT slot_id, COUNT(*) AS actual
        FROM planning_event_registrations
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
      FROM planning_event_slots s
      JOIN planning_events e ON e.id = s.event_id
      LEFT JOIN planning_event_types et ON et.code = e.event_type
      LEFT JOIN (
        SELECT slot_id, COUNT(*) AS actual
        FROM planning_event_registrations
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
      FROM planning_events e
      LEFT JOIN planning_event_types et ON et.code = e.event_type
      LEFT JOIN (
        SELECT event_id, COUNT(*) AS actual
        FROM planning_event_registrations
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
      FROM planning_events e
      LEFT JOIN planning_event_types et ON et.code = e.event_type
      LEFT JOIN (
        SELECT event_id, COUNT(*) AS actual
        FROM planning_event_registrations
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
      FROM planning_events e
      JOIN planning_event_slots s ON s.event_id = e.id
      LEFT JOIN planning_event_registrations r
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
      FROM planning_events e
      LEFT JOIN planning_event_registrations r
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
            FROM planning_events e
            JOIN planning_event_slots s ON s.event_id = e.id
            LEFT JOIN planning_event_registrations r
              ON r.slot_id = s.id AND r.status = 'present'
            LEFT JOIN planning_event_types et ON et.code = e.event_type
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
            FROM planning_events e
            LEFT JOIN event_registrations r
              ON r.event_id = e.id AND r.status = 'present'
            LEFT JOIN planning_event_types et ON et.code = e.event_type
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
            FROM planning_event_registrations r
            JOIN users v ON v.id = r.volunteer_id
            JOIN planning_event_slots s ON s.id = r.slot_id
            JOIN planning_events e ON e.id = s.event_id
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
            FROM planning_event_registrations r
            JOIN users v ON v.id = r.volunteer_id
            JOIN planning_events e ON e.id = r.event_id
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
          FROM planning_event_slots s
          JOIN planning_events e ON e.id = s.event_id
          LEFT JOIN planning_event_types et ON et.code = e.event_type
          LEFT JOIN (
            SELECT slot_id, COUNT(*) AS actual
            FROM planning_event_registrations
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
          FROM planning_events e
          LEFT JOIN planning_event_types et ON et.code = e.event_type
          LEFT JOIN (
            SELECT event_id, COUNT(*) AS actual
            FROM planning_event_registrations
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
$base = suite_base() . '/reports';
$modePublicUrl = $base . "/activity.php?year={$year}&mode=public";
$modeAdminUrl  = $base . "/activity.php?year={$year}&mode=admin";
$prevYearUrl   = $base . "/activity.php?year=" . ($year - 1) . "&mode=" . $mode;
$nextYearUrl   = $base . "/activity.php?year=" . ($year + 1) . "&mode=" . $mode;

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

/* =====================================================
   8) Indicateurs enrichis : comparaison N-1, évolution mensuelle,
      fidélisation, impact terrain, contrôles qualité
===================================================== */
[$curStart, $curEnd, $curNow] = rep_period($year);
$curM = rep_period_metrics($pdo, $curStart, $curEnd, $curNow);
[$prevStart, $prevEnd, $prevNow, $prevAtDate] = rep_prev_period($year);
$prevM = rep_period_metrics($pdo, $prevStart, $prevEnd, $prevNow);
$prevLabel = ($year - 1) . ($prevAtDate ? ' (même date)' : '');
$hasPrev = ($prevM['presences'] + $prevM['actions'] + $prevM['permanences'] + $prevM['logistics'] + $prevM['donations_count']) > 0;
/** Badge de tendance vs N-1 pour une clé de rep_period_metrics (vide s'il n'y a pas d'historique). */
$delta = static function (string $key, string $unit = '', int $dec = 0) use ($hasPrev, $curM, $prevM, $prevLabel): string {
    return $hasPrev ? rep_delta_html((float)$curM[$key], (float)$prevM[$key], $prevLabel, $unit, $dec) : '';
};
$monthly = rep_monthly($pdo, $year);
$monthLabels = ['Jan','Fév','Mar','Avr','Mai','Juin','Juil','Août','Sep','Oct','Nov','Déc'];
$volStats = rep_volunteer_stats($pdo, $year);
$volSum = $volStats['summary'];
$qualityChecks = ($mode === 'admin' && $canSeeAdmin) ? rep_quality_checks($pdo, $year) : [];

?>
<style>
@media print {
  .tu-sb, .tu-topbar, #tu-overlay, .tu-sb-close, .tu-hamburger, .tu-sb-toggle { display:none !important; }
  .tu-main { margin-left:0 !important; padding:0 !important; }
  .tu-card, .tu-kpi { break-inside:avoid; box-shadow:none !important; }
}
/* Compte-rendu d'activité — styles propres à la page (tokens du design system suite_nav.css) */
.tu-main .tu-pg { max-width: none; }
.tu-main .tu-pg .tu-kpi-grid { grid-template-columns: repeat(5, minmax(0, 1fr)); gap: 12px; margin-bottom: 16px; }
.tu-main .tu-pg .tu-kpi { padding: 14px 16px; border-radius: 12px; box-shadow: none; }
.tu-main .tu-pg .tu-kpi-val { font-size: 24px; line-height: 1.15; }
.tu-main .tu-pg .tu-kpi-lbl { font-size: 12px; margin-top: 3px; }
@media (max-width: 1400px) { .tu-main .tu-pg .tu-kpi-grid { grid-template-columns: repeat(4, minmax(0, 1fr)); } }
@media (max-width: 1000px) { .tu-main .tu-pg .tu-kpi-grid { grid-template-columns: repeat(3, minmax(0, 1fr)); } }
@media (max-width: 640px)  { .tu-main .tu-pg .tu-kpi-grid { grid-template-columns: repeat(2, minmax(0, 1fr)); } }
.cra-sec { display:flex; align-items:center; gap:12px; margin:26px 0 12px; }
.cra-sec:first-of-type { margin-top:6px; }
.cra-sec-lbl { font-size:10.5px; font-weight:700; text-transform:uppercase; letter-spacing:.09em; color:var(--tu-ink-300); white-space:nowrap; }
.cra-sec-line { flex:1; height:1px; background:var(--tu-ink-100); }
.cra-pad { padding:20px 22px; }
.cra-title { font-family:var(--tu-font-d); font-weight:700; font-size:15px; color:var(--tu-ink-900); margin:0 0 4px; }
.cra-note { font-size:12px; color:var(--tu-ink-300); line-height:1.5; }
.cra-chips { display:flex; gap:8px; flex-wrap:wrap; align-items:center; margin-top:12px; }
.cra-cols { display:grid; grid-template-columns:repeat(auto-fit,minmax(280px,1fr)); gap:14px; }
.cra-share { display:flex; gap:10px; flex-wrap:wrap; align-items:center; }
.cra-share code { font-size:11.5px; background:var(--tu-ink-50); border:1px solid var(--tu-ink-100); border-radius:8px; padding:5px 9px; color:var(--tu-ink-700); word-break:break-all; }
.cra-cat-head { display:flex; justify-content:space-between; align-items:center; gap:10px; flex-wrap:wrap; padding:16px 18px 12px; }
.cra-cat-head .cra-title { margin:0; font-size:16px; }
.cra-cat-tot { display:flex; gap:6px; flex-wrap:wrap; align-items:center; }
.cra-tbl td.num, .cra-tbl th.num { text-align:right; white-space:nowrap; }
.cra-tbl .cra-total td { background:var(--tu-sand-50); font-weight:800; color:var(--tu-ink-900); }
.cra-tbl .cra-type td:first-child { padding-left:28px; position:relative; font-weight:600; }
.cra-tbl .cra-type td:first-child:before { content:"↳"; position:absolute; left:12px; color:var(--tu-ink-200); font-weight:400; }
.cra-tbl .cra-detail td { font-size:12px; color:var(--tu-ink-500); background:var(--tu-surface); padding-top:8px; padding-bottom:8px; }
.cra-tbl .cra-detail td:first-child { padding-left:44px; color:var(--tu-ink-700); }
.cra-tbl .cra-detail-date { color:var(--tu-ink-300); font-weight:700; margin-right:8px; }
.cra-tbl-wrap { overflow-x:auto; }
.cra-method { margin:0; padding-left:18px; font-size:12.5px; color:var(--tu-ink-500); line-height:1.7; }
.cra-delta { display:inline-block; font-size:10.5px; font-weight:700; margin-top:3px; }
.cra-delta.up { color:var(--tu-green-main, #2a7d4a); }
.cra-delta.down { color:var(--tu-red-main, #b3402f); }
.cra-delta.flat { color:var(--tu-ink-400, #8a7a68); }
.cra-gate { border-color:rgba(232,146,74,.45); background:rgba(232,146,74,.07); }
</style>

<?php
/** Une tuile KPI du design system. */
function cra_kpi(string $value, string $label, string $sub = '', string $tone = '', string $delta = ''): void {
    echo '<div class="tu-kpi' . ($tone !== '' ? ' ' . h($tone) : '') . '"' . ($sub !== '' ? ' title="' . h(html_entity_decode(strip_tags($sub), ENT_QUOTES, 'UTF-8')) . '"' : '') . '>'
       . '<div class="tu-kpi-val">' . $value . '</div>'
       . '<div class="tu-kpi-lbl">' . h($label) . '</div>'
       . ($delta !== '' ? '<div>' . $delta . '</div>' : '')
       . '</div>';
}
function cra_sec(string $label): void {
    echo '<div class="cra-sec"><span class="cra-sec-lbl">' . h($label) . '</span><span class="cra-sec-line"></span></div>';
}

$fmt1  = static fn($n) => number_format((float)$n, 1, ',', ' ');
$fmt2  = static fn($n) => number_format((float)$n, 2, ',', ' ');
$fmt0  = static fn($n) => number_format((float)$n, 0, ',', ' ');
$indexUrl = function_exists('suite_url') ? suite_url('/index.php') : (suite_base() . '/index.php');
?>

<!-- Topbar -->
<div class="tu-topbar">
  <div class="tu-bc">
    <a href="<?= h($indexUrl) ?>" style="color:inherit;text-decoration:none;">Accueil</a>
    <span class="tu-bc-sep">›</span>
    <a href="index.php" style="color:inherit;text-decoration:none;">Rapports</a>
    <span class="tu-bc-sep">›</span>
    <span class="tu-bc-cur">Compte-rendu d'activité</span>
  </div>
  <div class="tu-topbar-acts">
    <a class="tu-btn tu-btn-s tu-btn-sm" href="<?= h($prevYearUrl) ?>">← <?= (int)($year - 1) ?></a>
    <a class="tu-btn tu-btn-s tu-btn-sm" href="<?= h($nextYearUrl) ?>"><?= (int)($year + 1) ?> →</a>
    <div class="tu-tabs">
      <a class="tu-tab <?= $mode === 'public' ? 'tu-on' : '' ?>" href="<?= h($modePublicUrl) ?>" style="text-decoration:none;">Public</a>
      <a class="tu-tab <?= $mode === 'admin' ? 'tu-on' : '' ?>" href="<?= h($modeAdminUrl) ?>" style="text-decoration:none;">Interne</a>
    </div>
    <a class="tu-btn tu-btn-s tu-btn-sm" href="<?= h($base) ?>/index.php">← Rapports</a>
  </div>
</div>

<div class="tu-pg">

  <div class="tu-ph">
    <div>
      <div class="tu-ph-title">Compte-rendu d'activité <?= (int)$year ?></div>
      <div class="tu-ph-sub">AG · financeurs · collectivités — chiffres consolidés et lecture de l'activité</div>
      <div class="cra-chips" style="margin-top:10px;">
        <span class="tu-bdg <?= $mode === 'admin' ? 'tu-bdg-red' : 'tu-bdg-blue' ?>"><?= h($badgeText) ?></span>
        <span class="tu-bdg tu-bdg-ink">Année civile <?= (int)$year ?></span>
        <span class="tu-bdg tu-bdg-ink">Évènements passés</span>
        <?php if ($useSlots): ?><span class="tu-bdg tu-bdg-amber">Mode créneaux</span><?php endif; ?>
        <?php if ($cash['enabled']): ?><span class="tu-bdg tu-bdg-teal">+ Caisse</span><?php endif; ?>
        <?php if (!empty($donations['enabled'])): ?><span class="tu-bdg tu-bdg-green">+ Dons</span><?php endif; ?>
      </div>
    </div>
  </div>

  <?php if ($mode === 'admin' && !$canSeeAdmin): ?>
    <div class="tu-card cra-pad cra-gate tu-mb4">
      <div class="cra-title">Accès interne</div>
      <p class="cra-note" style="margin:0 0 12px;">
        Cette vue contient des données nominatives et des alertes de pilotage. Saisis le code interne pour l'ouvrir.
      </p>
      <form method="post" style="display:flex;gap:8px;flex-wrap:wrap;align-items:center;">
        <input class="tu-input" style="max-width:300px;" type="password" name="access_code" placeholder="Code interne…" autocomplete="off">
        <button type="submit" class="tu-btn tu-btn-p tu-btn-sm">Déverrouiller</button>
        <a class="tu-btn tu-btn-s tu-btn-sm" href="<?= h($modePublicUrl) ?>">Rester en public</a>
      </form>
      <?php if ($internalCode === ''): ?>
        <p class="cra-note" style="margin:12px 0 0;">
          Aucun code n'est configuré : ajoute <code>REPORT_ACTIVITY_ADMIN_CODE</code> dans <code>.env</code>
          (ou <code>$config['report_activity_admin_code']</code>).
        </p>
      <?php endif; ?>
    </div>
  <?php endif; ?>

  <!-- ───────────── KPI ───────────── -->
  <div class="tu-kpi-grid">
    <?php
      cra_kpi((string)(int)$totalActions, 'Actions réalisées', 'Hors permanences, logistique et annulés', 'amber', $delta('actions'));
      cra_kpi((string)(int)$totalPermanences, 'Permanences réalisées', 'Hors annulés', '', $delta('permanences'));
      cra_kpi((string)(int)$totalLogistics, 'Logistique réalisée', 'Catégorie « ' . h($LOGISTICS_CATEGORY_LABEL) . ' »', '', $delta('logistics'));
    ?>
    <?php
      cra_kpi((string)(int)$totalVolunteers, 'Bénévoles mobilisés', 'Au moins 1 présence sur la période', 'teal', $delta('volunteers'));
      cra_kpi((string)(int)$totalPresences, 'Présences bénévoles', 'Total des « présent »', '', $delta('presences'));
      cra_kpi(h($fmt1($totalHours)) . ' h', 'Heures de bénévolat', 'Durée × présence' . ($useSlots ? ' (par créneau)' : ''), '', $delta('hours', ' h', 1));
      cra_kpi(h($fmt0($volunteerValue)) . ' €', 'Valorisation du bénévolat', h($fmt2($smicHourly)) . ' €/h · ' . h($smicSourceNote), 'green', $delta('hours', ' h', 1));
      cra_kpi(
          $coverageRate !== null ? h($fmt1($coverageRate)) . ' %' : '—',
          'Couverture des besoins',
          $coverageRate !== null
              ? 'Min atteint : ' . (int)$coverageCovered . ' / ' . (int)$coverageTotal . ' (' . (int)$underCoveredCount . ' sous-doté(s))'
              : 'Aucun « min bénévoles » défini',
          ($coverageRate !== null && $coverageRate < 70) ? 'red' : ''
      );
    ?>
  <?php if ($cash['enabled'] || !empty($donations['enabled'])): ?>
      <?php
        if ($cash['enabled']) {
            cra_kpi(h($fmt2($cash['total_sales'])) . ' €', 'Recettes (caisse)', 'Ventes brutes · ' . (int)$cash['linked_actions'] . ' action(s)', 'teal', $delta('cash_sales', ' €', 0));
            cra_kpi($cashAvgPerAction !== null ? h($fmt2($cashAvgPerAction)) . ' €' : '—', 'Recette moyenne / action', 'Recettes caisse / actions liées');
            cra_kpi($cashEuroPerVolunteerHour !== null ? h($fmt2($cashEuroPerVolunteerHour)) . ' €' : '—', '€ / heure bénévole', 'Recettes / heures bénévoles (actions)');
        }
        if (!empty($donations['enabled'])) {
            cra_kpi(h($fmt2($donations['amount'])) . ' €', 'Dons financiers', 'Dons payés (toutes sources)', 'green', $delta('donations_amount', ' €', 0));
            cra_kpi((string)(int)$donations['count'], 'Nombre de dons', 'Dons payés sur l\'année', '', $delta('donations_count'));
            cra_kpi(h($fmt2($donations['eligible_amount'])) . ' €', 'Dons éligibles reçu fiscal', 'Base pour les CERFA');
        }
      ?>
  <?php endif; ?>
  </div>
  <?php if ($hasPrev): ?>
    <p class="cra-note" style="margin:-6px 2px 14px;">Tendances comparées à <?= h($prevLabel) ?> — survolez un badge pour voir la valeur de référence.</p>
  <?php endif; ?>

  <!-- ───────────── Évolution mensuelle ───────────── -->
  <?php cra_sec('Évolution mensuelle'); ?>
  <div class="rep-grid2">
    <div class="tu-card cra-pad">
      <div class="cra-title">Heures de bénévolat par mois</div>
      <?= rep_bars_html($monthLabels, array_map(fn($x) => $x['hours'], array_values($monthly)), 'h', 'amber') ?>
    </div>
    <div class="tu-card cra-pad">
      <div class="cra-title">Présences bénévoles par mois</div>
      <?= rep_bars_html($monthLabels, array_map(fn($x) => $x['presences'], array_values($monthly)), '', 'teal') ?>
    </div>
  </div>

  <!-- ───────────── Fidélisation des bénévoles ───────────── -->
  <?php if ((int)$volSum['active'] > 0): ?>
    <?php cra_sec('Mobilisation et fidélisation'); ?>
    <div class="tu-kpi-grid">
      <?php
        cra_kpi((string)(int)$volSum['new'], 'Nouveaux bénévoles', 'Première présence enregistrée cette année', 'teal');
        cra_kpi((string)(int)$volSum['regulars'], 'Bénévoles réguliers', (int)$volSum['regular_threshold'] . ' présences ou plus sur l\'année');
        cra_kpi($volSum['retention'] !== null ? (int)$volSum['retention'] . ' %' : '—', 'Fidélisation', $volSum['retention'] !== null ? (int)$volSum['returning'] . ' bénévole(s) de ' . ($year - 1) . ' sur ' . (int)$volSum['prev_active'] . ' sont revenus' : 'Pas d\'historique l\'an dernier');
        cra_kpi($volSum['top3_share'] !== null ? (int)$volSum['top3_share'] . ' %' : '—', 'Poids des 3 plus actifs', 'Part des heures assurée par les 3 bénévoles les plus présents (dépendance)', ((int)($volSum['top3_share'] ?? 0) >= 60) ? 'red' : '');
        cra_kpi(h($fmt1($volSum['avg_hours'])) . ' h', 'Heures / bénévole', 'Moyenne sur les bénévoles actifs');
      ?>
    </div>
  <?php endif; ?>

  <!-- ───────────── Impact et ressources ───────────── -->
  <?php if ($curM['convoys'] > 0 || $curM['members'] > 0 || $curM['grants_count'] > 0): ?>
    <?php cra_sec('Impact terrain et ressources'); ?>
    <div class="tu-kpi-grid">
      <?php
        if ($curM['convoys'] > 0) {
            cra_kpi((string)(int)$curM['convoys'], 'Convois expédiés', 'Statut « expédié », départ sur l\'année', 'amber', $delta('convoys'));
            cra_kpi((string)(int)$curM['boxes'], 'Colis expédiés', 'Cartons enregistrés dans les convois expédiés', '', $delta('boxes'));
        }
        if ($curM['members'] > 0) {
            cra_kpi((string)(int)$curM['members'], 'Adhésions validées', 'Cotisations ' . (int)$year, 'teal', $delta('members'));
            cra_kpi(h($fmt0($curM['members_amount'])) . ' €', 'Cotisations encaissées', 'Adhésions validées', 'green', $delta('members_amount', ' €', 0));
        }
        if ($curM['grants_count'] > 0) {
            cra_kpi(h($fmt0($curM['grants_granted'])) . ' €', 'Subventions accordées', (int)$curM['grants_count'] . ' demande(s) accordée(s) pour ' . (int)$year, 'green', $delta('grants_granted', ' €', 0));
        }
      ?>
    </div>
  <?php endif; ?>

  <!-- ───────────── Synthèse ───────────── -->
  <?php cra_sec('Synthèse'); ?>
  <div class="tu-card cra-pad">
    <div class="cra-title">Prête pour l'AG et les financeurs</div>
    <p style="margin:0;font-size:13.5px;line-height:1.65;color:var(--tu-ink-700);"><?= h($autoSummary) ?></p>

    <?php if (!empty($donations['enabled']) && (float)$donations['amount'] > 0): ?>
      <div class="cra-chips">
        <span class="tu-bdg tu-bdg-green">Dons : <?= h($fmt2($donations['amount'])) ?> €</span>
        <span class="tu-bdg tu-bdg-ink"><?= (int)$donations['count'] ?> don(s)</span>
        <span class="tu-bdg tu-bdg-ink">Éligibles reçu fiscal : <?= h($fmt2($donations['eligible_amount'])) ?> €</span>
        <?php foreach (($donations['by_source'] ?? []) as $src => $amount): ?>
          <span class="tu-bdg tu-bdg-amber"><?= h($src) ?> · <?= h($fmt2($amount)) ?> €</span>
        <?php endforeach; ?>
      </div>
      <p class="cra-note" style="margin:8px 0 0;"><?= h($donations['note']) ?></p>
    <?php endif; ?>

    <?php if ($cash['enabled']): ?>
      <?php $tot = (float)$cash['total_sales']; ?>
      <div class="cra-chips">
        <?php foreach (($cash['total_sales_by_payment'] ?? []) as $pay => $amount): ?>
          <?php $pct = ($tot > 0) ? round(((float)$amount / $tot) * 100, 0) : 0; ?>
          <span class="tu-bdg tu-bdg-teal"><?= h($pay) ?> · <?= h($fmt2($amount)) ?> € (<?= (int)$pct ?> %)</span>
        <?php endforeach; ?>
        <span class="tu-bdg tu-bdg-ink">Recettes brutes · pas de coût d'achat dans l'outil</span>
      </div>

      <div class="cra-chips">
        <span class="tu-bdg tu-bdg-green">Actions réalisées : <?= (int)$totalActions ?></span>
        <span class="tu-bdg <?= ($unlinkedActions !== null && $unlinkedActions > 0) ? 'tu-bdg-amber' : 'tu-bdg-green' ?>">Liées à la caisse : <?= (int)$cash['linked_actions'] ?></span>
        <?php if ($unlinkedActions !== null): ?>
          <span class="tu-bdg <?= ($unlinkedActions > 0) ? 'tu-bdg-amber' : 'tu-bdg-green' ?>">À relier : <?= (int)$unlinkedActions ?></span>
        <?php endif; ?>
      </div>
      <p class="cra-note" style="margin:8px 0 0;"><?= h($cash['note']) ?></p>

      <?php
        $topCats = [];
        foreach (($cash['total_sales_by_category'] ?? []) as $cat => $amount) {
            $topCats[] = ['cat' => (string)$cat, 'amount' => (float)$amount];
        }
        $topCats = array_slice($topCats, 0, 3);
      ?>
      <?php if (!empty($topCats)): ?>
        <div style="margin-top:14px;">
          <div class="cra-note" style="font-weight:700;color:var(--tu-ink-500);">Top catégories (recettes)</div>
          <div class="cra-chips" style="margin-top:6px;">
            <?php foreach ($topCats as $it): ?>
              <?php $pct = ($tot > 0) ? round(($it['amount'] / $tot) * 100, 0) : 0; ?>
              <span class="tu-bdg tu-bdg-teal"><?= h($it['cat']) ?> · <?= h($fmt2($it['amount'])) ?> € (<?= (int)$pct ?> %)</span>
            <?php endforeach; ?>
          </div>
        </div>
      <?php endif; ?>
    <?php else: ?>
      <p class="cra-note" style="margin:10px 0 0;">Recettes (outil caisse) : non consolidées. <?= h($cash['note']) ?></p>
    <?php endif; ?>
  </div>

  <!-- ───────────── Encarts de lecture ───────────── -->
  <?php cra_sec('Lecture AG'); ?>
  <p class="cra-note" style="margin:-4px 0 12px;">Permanences (récurrent), actions (mission) et logistique (support) sont lues séparément.</p>
  <div class="cra-cols">
    <div class="tu-card cra-pad">
      <div class="cra-title">Permanences</div>
      <p class="cra-note" style="margin:0;">Activité récurrente, suivie à part (non assimilée à une action).</p>
      <div class="cra-chips">
        <span class="tu-bdg tu-bdg-ink"><?= (int)$boxPermanences['events'] ?> permanences</span>
        <span class="tu-bdg tu-bdg-ink"><?= (int)$boxPermanences['presences'] ?> présences</span>
        <span class="tu-bdg tu-bdg-ink"><?= h($fmt1($boxPermanences['hours'])) ?> h</span>
        <span class="tu-bdg tu-bdg-green"><?= h($fmt0($boxPermanences['value'])) ?> €</span>
      </div>
    </div>

    <div class="tu-card cra-pad">
      <div class="cra-title">Actions / événements</div>
      <p class="cra-note" style="margin:0;">Cœur d'activité (hors permanences et hors logistique).</p>
      <div class="cra-chips">
        <span class="tu-bdg tu-bdg-amber"><?= (int)$totalActions ?> actions</span>
        <span class="tu-bdg tu-bdg-ink"><?= (int)$boxActions['presences'] ?> présences</span>
        <span class="tu-bdg tu-bdg-ink"><?= h($fmt1($totalHoursActions)) ?> h</span>
        <span class="tu-bdg tu-bdg-green"><?= h($fmt0(round($totalHoursActions * $smicHourly, 0))) ?> €</span>
        <?php if ($cash['enabled']): ?>
          <span class="tu-bdg tu-bdg-teal"><?= h($fmt2($cash['total_sales'])) ?> € recettes</span>
          <?php if ($cashAvgPerAction !== null): ?><span class="tu-bdg tu-bdg-teal"><?= h($fmt2($cashAvgPerAction)) ?> €/action</span><?php endif; ?>
          <?php if ($cashEuroPerVolunteerHour !== null): ?><span class="tu-bdg tu-bdg-teal"><?= h($fmt2($cashEuroPerVolunteerHour)) ?> €/h bénévole</span><?php endif; ?>
        <?php endif; ?>
      </div>
    </div>

    <div class="tu-card cra-pad">
      <div class="cra-title">Logistique</div>
      <p class="cra-note" style="margin:0;">Support opérationnel : rend visible l'effort « hors vitrine ».</p>
      <div class="cra-chips">
        <span class="tu-bdg tu-bdg-ink"><?= (int)$boxLogistics['events'] ?> actions</span>
        <span class="tu-bdg tu-bdg-ink"><?= (int)$boxLogistics['presences'] ?> présences</span>
        <span class="tu-bdg tu-bdg-ink"><?= h($fmt1($boxLogistics['hours'])) ?> h</span>
        <span class="tu-bdg tu-bdg-green"><?= h($fmt0($boxLogistics['value'])) ?> €</span>
      </div>
    </div>
  </div>

  <!-- ───────────── Répartition par catégories ───────────── -->
  <?php cra_sec('Répartition par catégories d\'activité'); ?>
  <p class="cra-note" style="margin:-4px 0 12px;">Un bloc par catégorie, puis le détail par type et par évènement (évènements/créneaux passés uniquement).</p>

  <div style="display:flex;flex-direction:column;gap:14px;">
    <?php foreach ($categories as $cat): ?>
      <?php
        $pctHours = ($totalCatHours > 0) ? round(((float)$cat['totals']['hours'] / $totalCatHours) * 100, 1) : 0.0;
        $pctValue = ($totalCatValue > 0) ? round(((int)$cat['totals']['value'] / $totalCatValue) * 100, 1) : 0.0;
      ?>
      <div class="tu-card" style="overflow:hidden;">
        <div class="cra-cat-head">
          <h4 class="cra-title"><?= h($cat['name']) ?></h4>
          <div class="cra-cat-tot">
            <span class="tu-bdg tu-bdg-ink"><?= (int)$cat['totals']['events'] ?> év.</span>
            <span class="tu-bdg tu-bdg-ink"><?= (int)$cat['totals']['presences'] ?> présences</span>
            <span class="tu-bdg tu-bdg-ink"><?= h($fmt1($cat['totals']['hours'])) ?> h</span>
            <span class="tu-bdg tu-bdg-green"><?= h($fmt0($cat['totals']['value'])) ?> €</span>

            <?php if ($mode === 'admin' && $canSeeAdmin): ?>
              <span class="tu-bdg tu-bdg-blue"><?= h($fmt1($pctHours)) ?> % effort</span>
              <span class="tu-bdg tu-bdg-blue"><?= h($fmt1($pctValue)) ?> % valeur</span>
              <?php if ($cash['enabled'] && !empty($cash['total_sales_by_category'])): ?>
                <?php
                  $catSales = (float)($cash['total_sales_by_category'][$cat['name']] ?? 0);
                  $pctSales = ((float)$cash['total_sales'] > 0) ? round(($catSales / (float)$cash['total_sales']) * 100, 1) : 0.0;
                ?>
                <span class="tu-bdg tu-bdg-teal"><?= h($fmt2($catSales)) ?> € recettes (<?= h($fmt1($pctSales)) ?> %)</span>
              <?php endif; ?>
            <?php endif; ?>
          </div>
        </div>

        <div class="cra-tbl-wrap">
          <table class="tu-tbl cra-tbl">
            <thead>
              <tr>
                <th>Type</th>
                <th class="num">Événements</th>
                <th class="num">Présences</th>
                <th class="num">Heures</th>
                <th class="num">Valorisation</th>
              </tr>
            </thead>
            <tbody>
              <tr class="cra-total">
                <td>Total catégorie</td>
                <td class="num"><?= (int)$cat['totals']['events'] ?></td>
                <td class="num"><?= (int)$cat['totals']['presences'] ?></td>
                <td class="num"><?= h($fmt1($cat['totals']['hours'])) ?> h</td>
                <td class="num"><?= h($fmt0($cat['totals']['value'])) ?> €</td>
              </tr>

              <?php foreach ($cat['types'] as $tr): ?>
                <tr class="cra-type">
                  <td>
                    <?= h($tr['label']) ?>
                    <?php if ($mode === 'admin' && $canSeeAdmin && (int)$tr['active'] === 0): ?>
                      <span class="tu-bdg tu-bdg-red" style="margin-left:6px;">inactif</span>
                    <?php endif; ?>
                    <?php if ((string)$tr['code'] === $PERMANENCE_CODE): ?>
                      <span class="tu-bdg tu-bdg-ink" style="margin-left:6px;">permanence</span>
                    <?php endif; ?>
                  </td>
                  <td class="num"><?= (int)$tr['events'] ?></td>
                  <td class="num"><?= (int)$tr['presences'] ?></td>
                  <td class="num"><?= h($fmt1($tr['hours'])) ?> h</td>
                  <td class="num"><?= h($fmt0($tr['value'])) ?> €</td>
                </tr>
                <?php foreach (($detailsByType[(string)$tr['code']] ?? []) as $dr): ?>
                  <?php $dDate = !empty($dr['start_dt']) ? date('d/m/Y', strtotime($dr['start_dt'])) : ''; ?>
                  <tr class="cra-detail">
                    <td><span class="cra-detail-date"><?= h($dDate) ?></span><?= h($dr['title']) ?></td>
                    <td class="num"></td>
                    <td class="num"><?= (int)$dr['presences'] ?></td>
                    <td class="num"><?= h($fmt1($dr['hours'])) ?> h</td>
                    <td class="num"><?= h($fmt0($dr['value'])) ?> €</td>
                  </tr>
                <?php endforeach; ?>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>

        <?php
          $catHasMeeting = false;
          if (!empty($MEETING_CODE)) {
              foreach (($cat['types'] ?? []) as $__tr) {
                  if ((string)($__tr['code'] ?? '') === (string)$MEETING_CODE) { $catHasMeeting = true; break; }
              }
          }
        ?>
        <?php if ($catHasMeeting && !empty($meetingEvents)): ?>
          <div style="padding:14px 18px 16px;border-top:1px solid var(--tu-ink-100);">
            <div class="cra-note" style="font-weight:700;color:var(--tu-ink-500);margin-bottom:6px;">Détail des réunions</div>
            <div class="cra-tbl-wrap">
              <table class="tu-tbl cra-tbl">
                <thead>
                  <tr><th>Date</th><th>Réunion</th><th class="num">Présences</th><th class="num">Heures</th></tr>
                </thead>
                <tbody>
                  <?php foreach ($meetingEvents as $m): ?>
                    <tr>
                      <td><?= h(!empty($m['start_dt']) ? date('d/m/Y', strtotime($m['start_dt'])) : '') ?></td>
                      <td style="font-weight:600;"><?= h((string)($m['title'] ?? 'Réunion')) ?></td>
                      <td class="num"><?= (int)($m['presences'] ?? 0) ?></td>
                      <td class="num"><?= h($fmt1((float)($m['hours'] ?? 0.0))) ?> h</td>
                    </tr>
                  <?php endforeach; ?>
                </tbody>
              </table>
            </div>
          </div>
        <?php endif; ?>
      </div>
    <?php endforeach; ?>
  </div>

  <p class="cra-note" style="margin:10px 2px 0;">
    Valorisation = heures bénévoles estimées × <?= h($fmt2($smicHourly)) ?> €/h (<?= h($smicSourceNote) ?>).
  </p>

  <!-- ───────────── Pilotage interne ───────────── -->
  <?php if ($mode === 'admin' && $canSeeAdmin): ?>
    <?php cra_sec('Pilotage interne'); ?>
    <p class="cra-note" style="margin:-4px 0 12px;">Réservé à l'équipe : détail nominatif et points d'alerte (évènements passés).</p>
    <div class="cra-cols">
      <div class="tu-card" style="overflow:hidden;">
        <div class="cra-cat-head"><h4 class="cra-title">Top bénévoles</h4></div>
        <?php if (empty($topVolunteers)): ?>
          <p class="cra-note" style="padding:0 18px 16px;margin:0;">Aucune présence enregistrée sur la période.</p>
        <?php else: ?>
          <table class="tu-tbl cra-tbl">
            <thead><tr><th>Bénévole</th><th class="num">Présences</th></tr></thead>
            <tbody>
              <?php foreach ($topVolunteers as $v): ?>
                <?php $name = trim(($v['first_name'] ?? '') . ' ' . ($v['last_name'] ?? '')); ?>
                <tr><td><?= h($name ?: 'Bénévole') ?></td><td class="num"><?= (int)$v['cnt'] ?></td></tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        <?php endif; ?>
      </div>

      <div class="tu-card" style="overflow:hidden;">
        <div class="cra-cat-head"><h4 class="cra-title"><?= $useSlots ? 'Créneaux sous-dotés' : 'Actions sous-dotées' ?></h4></div>
        <?php if (empty($underCovered)): ?>
          <p class="cra-note" style="padding:0 18px 16px;margin:0;">Aucune sous-dotation : le minimum est atteint partout.</p>
        <?php else: ?>
          <table class="tu-tbl cra-tbl">
            <thead>
              <tr><th>Date</th><th><?= $useSlots ? 'Créneau / événement' : 'Événement' ?></th><th class="num">Présents / min</th></tr>
            </thead>
            <tbody>
              <?php foreach ($underCovered as $e): ?>
                <tr>
                  <td><?= h(date('d/m/Y', strtotime($e['start_datetime'] ?? ''))) ?></td>
                  <td>
                    <?= h($e['title'] ?? '') ?>
                    <?php if ($useSlots && !empty($e['end_datetime'])): ?>
                      <span class="tu-bdg tu-bdg-ink" style="margin-left:6px;"><?= h(date('H:i', strtotime($e['start_datetime']))) ?> → <?= h(date('H:i', strtotime($e['end_datetime']))) ?></span>
                    <?php endif; ?>
                  </td>
                  <td class="num"><strong><?= (int)($e['actual'] ?? 0) ?></strong> / <?= (int)($e['min_volunteers'] ?? 0) ?></td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        <?php endif; ?>
      </div>
    </div>
  <?php endif; ?>

  <!-- ───────────── Qualité des données (interne) ───────────── -->
  <?php if ($mode === 'admin' && $canSeeAdmin && !empty($qualityChecks)): ?>
    <?php cra_sec('Qualité des données'); ?>
    <div class="tu-card cra-pad">
      <div class="cra-title">À vérifier avant l'AG</div>
      <div style="margin-top:8px;">
        <?php foreach ($qualityChecks as $qc): ?>
          <div style="display:flex;gap:10px;align-items:baseline;padding:7px 0;border-top:1px solid var(--tu-ink-100);">
            <span class="tu-bdg <?= $qc['level'] === 'ok' ? 'tu-bdg-green' : 'tu-bdg-amber' ?>" style="min-width:34px;text-align:center;"><?= (int)$qc['count'] ?></span>
            <div>
              <div style="font-size:13px;font-weight:600;"><?= h($qc['label']) ?></div>
              <?php if ($qc['level'] !== 'ok'): ?><div class="cra-note"><?= h($qc['hint']) ?></div><?php endif; ?>
            </div>
          </div>
        <?php endforeach; ?>
        <?php if ($cash['enabled'] && $unlinkedActions !== null): ?>
          <div style="display:flex;gap:10px;align-items:baseline;padding:7px 0;border-top:1px solid var(--tu-ink-100);">
            <span class="tu-bdg <?= $unlinkedActions > 0 ? 'tu-bdg-amber' : 'tu-bdg-green' ?>" style="min-width:34px;text-align:center;"><?= (int)$unlinkedActions ?></span>
            <div>
              <div style="font-size:13px;font-weight:600;">Actions non liées à la caisse</div>
              <?php if ($unlinkedActions > 0): ?><div class="cra-note">Renseigner « événement planning » sur l'événement caisse correspondant, si une vente a eu lieu.</div><?php endif; ?>
            </div>
          </div>
        <?php endif; ?>
      </div>
    </div>
  <?php endif; ?>

  <!-- ───────────── Méthodologie ───────────── -->
  <?php cra_sec('Méthodologie et périmètre'); ?>
  <div class="tu-card cra-pad">
    <ul class="cra-method">
      <li>Périmètre : uniquement les événements <strong>passés</strong> (<?= $useSlots ? 'créneaux dont la fin est antérieure à maintenant' : 'fin antérieure à maintenant' ?>).</li>
      <li>Source : événements et présences enregistrés dans l'outil de planning. Les événements annulés sont exclus.</li>
      <li>Heures bénévoles : durée de l'événement ou du créneau × nombre de présences « présent ».</li>
      <li>Valorisation : heures bénévoles × SMIC horaire brut de référence.</li>
      <li>Tendances : comparaison avec l'année précédente (à la même date pour l'année en cours, année complète sinon).</li>
      <li>Fidélisation : « nouveau » = première présence enregistrée cette année ; « régulier » = au moins <?= (int)$volSum['regular_threshold'] ?> présences ; fidélisation = part des bénévoles de l'an dernier revenus.</li>
      <li>Couverture des besoins : « présents » comparés au « min bénévoles » (actions uniquement<?= $useSlots ? ', par créneau' : '' ?>).</li>
      <?php if ($cash['enabled']): ?>
        <li>Recettes : ventes brutes de l'outil caisse, liées aux <strong>actions</strong> via <code>caisse_evenements.planning_event_id</code>.</li>
      <?php endif; ?>
    </ul>
  </div>

  <!-- ───────────── Partage ───────────── -->
  <div class="tu-card cra-pad" style="margin-top:14px;">
    <div class="cra-title">Partager ce compte-rendu</div>
    <p class="cra-note" style="margin:0 0 10px;">Pour un financeur ou l'AG, transmets la <strong>version publique</strong> (anonymisée) en PDF : bouton ci-dessous, puis « Enregistrer au format PDF ». Les liens ne sont ouverts qu'aux utilisateurs connectés disposant du droit « Rapports ».</p>
    <div class="cra-share" style="margin-bottom:10px;">
      <?php if ($mode === 'public'): ?>
        <button type="button" class="tu-btn tu-btn-p tu-btn-sm" onclick="window.print()">Imprimer / enregistrer en PDF</button>
      <?php else: ?>
        <a class="tu-btn tu-btn-p tu-btn-sm" href="<?= h($modePublicUrl) ?>">Ouvrir la version publique pour l'exporter</a>
      <?php endif; ?>
    </div>
    <div class="cra-share">
      <code id="cra-url-public"><?= h($modePublicUrl) ?></code>
      <button type="button" class="tu-btn tu-btn-s tu-btn-xs" onclick="craCopy('cra-url-public', this)">Copier le lien public</button>
    </div>
    <div class="cra-share" style="margin-top:8px;">
      <code id="cra-url-admin"><?= h($modeAdminUrl) ?></code>
      <button type="button" class="tu-btn tu-btn-s tu-btn-xs" onclick="craCopy('cra-url-admin', this)">Copier le lien interne</button>
    </div>
  </div>

</div><!-- /tu-pg -->

<script>
function craCopy(id, btn) {
  var el = document.getElementById(id);
  if (!el) return;
  var txt = el.textContent.trim();
  var done = function () {
    var old = btn.textContent;
    btn.textContent = 'Copié ✓';
    setTimeout(function () { btn.textContent = old; }, 1600);
  };
  if (navigator.clipboard && navigator.clipboard.writeText) {
    navigator.clipboard.writeText(txt).then(done, done);
  } else {
    var ta = document.createElement('textarea');
    ta.value = txt; document.body.appendChild(ta); ta.select();
    try { document.execCommand('copy'); } catch (e) {}
    document.body.removeChild(ta); done();
  }
}
</script>

<?php
$content = ob_get_clean();
$suiteActiveItem = 'reports-cra';
include __DIR__ . '/includes/layout.php';
