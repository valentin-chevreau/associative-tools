<?php
// toggle_registration.php
// Legacy + Slots (robuste) + MULTI-SLOTS
// - En mode slots : on autorise plusieurs inscriptions sur plusieurs créneaux du même event.
// - Si la DB a encore UNIQUE(event_id, volunteer_id), le multi-slot est impossible : on loggue et on fait un fallback safe.
// - Si created_at n'existe pas, on n'essaie pas de l'insérer.
// - Log discret dans /tmp/toggle_registration.log

require_once __DIR__ . '/includes/app.php';
global $pdo;

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Force erreurs PDO en exception
try { $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION); } catch (Throwable $e) {}

function log_toggle(string $msg): void {
    $line = '[' . date('Y-m-d H:i:s') . '] ' . $msg . PHP_EOL;
    @file_put_contents('/tmp/toggle_registration.log', $line, FILE_APPEND);
}

function safe_redirect(string $redirect): void {
    if ($redirect && strpos($redirect, '://') === false) {
        header('Location: ' . $redirect);
    } else {
        header('Location: events.php');
    }
    exit;
}

$currentVolunteer = getCurrentVolunteer();
if (!$currentVolunteer) {
    safe_redirect($_POST['redirect'] ?? 'events.php');
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    safe_redirect('events.php');
}

$eventId  = (int)($_POST['event_id'] ?? 0);
$slotId   = (int)($_POST['slot_id'] ?? 0); // optionnel
$action   = (string)($_POST['action'] ?? '');
$redirect = (string)($_POST['redirect'] ?? 'events.php');

if ($eventId <= 0 || !in_array($action, ['join', 'leave'], true)) {
    safe_redirect('events.php');
}

$volunteerId = (int)($currentVolunteer['id'] ?? 0);
if ($volunteerId <= 0) {
    safe_redirect('events.php');
}

// ---------------------------------------------------------
// Détection "mode slots"
// ---------------------------------------------------------
$hasSlotsTable = false;
$hasSlotIdCol  = false;

try {
    $stmt = $pdo->query("SHOW TABLES LIKE 'event_slots'");
    $hasSlotsTable = (bool)$stmt->fetchColumn();
} catch (Throwable $e) { $hasSlotsTable = false; }

try {
    $stmt = $pdo->query("SHOW COLUMNS FROM event_registrations LIKE 'slot_id'");
    $hasSlotIdCol = (bool)$stmt->fetchColumn();
} catch (Throwable $e) { $hasSlotIdCol = false; }

$useSlots = ($hasSlotsTable && $hasSlotIdCol && $slotId > 0);

// created_at existe ?
$hasCreatedAt = false;
try {
    $stmt = $pdo->query("SHOW COLUMNS FROM event_registrations LIKE 'created_at'");
    $hasCreatedAt = (bool)$stmt->fetchColumn();
} catch (Throwable $e) { $hasCreatedAt = false; }

// Détection d'une contrainte UNIQUE(event_id, volunteer_id) (bloquante pour multi-slot)
$hasUniqueEventVolunteer = false;
try {
    $idx = $pdo->query("SHOW INDEX FROM event_registrations")->fetchAll(PDO::FETCH_ASSOC);
    $uniqueGroups = []; // key_name => [non_unique, cols...]
    foreach ($idx as $r) {
        $key = (string)$r['Key_name'];
        $nonUnique = (int)$r['Non_unique']; // 0 => UNIQUE
        $col = (string)$r['Column_name'];
        if (!isset($uniqueGroups[$key])) $uniqueGroups[$key] = ['non_unique'=>$nonUnique, 'cols'=>[]];
        $uniqueGroups[$key]['cols'][(int)$r['Seq_in_index']] = $col;
    }
    foreach ($uniqueGroups as $g) {
        if ((int)$g['non_unique'] !== 0) continue;
        ksort($g['cols']);
        $cols = array_values($g['cols']);
        if ($cols === ['event_id','volunteer_id']) {
            $hasUniqueEventVolunteer = true;
            break;
        }
    }
} catch (Throwable $e) {
    // si ça foire, on ne bloque pas, mais on ne peut pas être certain
    $hasUniqueEventVolunteer = false;
}

try {
    // ---------------------------------------------------------
    // 1) Vérifier event (existe + pas annulé)
    // ---------------------------------------------------------
    $stmt = $pdo->prepare("SELECT * FROM events WHERE id = :id AND is_cancelled = 0 LIMIT 1");
    $stmt->execute(['id' => $eventId]);
    $event = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$event) {
        safe_redirect($redirect);
    }

    // ---------------------------------------------------------
    // 2) MODE SLOTS
    // ---------------------------------------------------------
    if ($useSlots) {
        // Slot appartient à l'event ?
        $stmt = $pdo->prepare("
            SELECT s.*
            FROM event_slots s
            WHERE s.id = :sid AND s.event_id = :eid
            LIMIT 1
        ");
        $stmt->execute(['sid' => $slotId, 'eid' => $eventId]);
        $slot = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$slot) {
            safe_redirect($redirect);
        }

        // Slot passé ?
        if (!empty($slot['end_datetime'])) {
            $now = new DateTimeImmutable('now');
            $slotEnd = new DateTimeImmutable((string)$slot['end_datetime']);
            if ($slotEnd < $now) {
                safe_redirect($redirect);
            }
        }

        if ($action === 'join') {
            // Capacité max slot ?
            if (array_key_exists('max_volunteers', $slot) && $slot['max_volunteers'] !== null) {
                $max = (int)$slot['max_volunteers'];
                $stmt = $pdo->prepare("
                    SELECT COUNT(*)
                    FROM event_registrations
                    WHERE slot_id = :sid AND status = 'present'
                ");
                $stmt->execute(['sid' => $slotId]);
                $count = (int)$stmt->fetchColumn();
                if ($count >= $max) {
                    safe_redirect($redirect);
                }
            }

            // Déjà inscrit sur CE slot ? -> idempotent
            $stmt = $pdo->prepare("
                SELECT id
                FROM event_registrations
                WHERE volunteer_id = :vid AND slot_id = :sid
                LIMIT 1
            ");
            $stmt->execute(['vid' => $volunteerId, 'sid' => $slotId]);
            $existingId = (int)($stmt->fetchColumn() ?: 0);

            if ($existingId > 0) {
                $stmt = $pdo->prepare("UPDATE event_registrations SET status='present' WHERE id=:id LIMIT 1");
                $stmt->execute(['id' => $existingId]);
                safe_redirect($redirect);
            }

            // IMPORTANT :
            // On NE réutilise PLUS une ligne "event_id + volunteer_id" (sinon impossible de multi-slot).
            // Si la DB a encore UNIQUE(event_id, volunteer_id), l'INSERT va échouer.
            if ($hasCreatedAt) {
                $stmt = $pdo->prepare("
                    INSERT INTO event_registrations (event_id, volunteer_id, status, slot_id, created_at)
                    VALUES (:eid, :vid, 'present', :sid, NOW())
                ");
            } else {
                $stmt = $pdo->prepare("
                    INSERT INTO event_registrations (event_id, volunteer_id, status, slot_id)
                    VALUES (:eid, :vid, 'present', :sid)
                ");
            }

            try {
                $stmt->execute([
                    'eid' => $eventId,
                    'vid' => $volunteerId,
                    'sid' => $slotId,
                ]);
            } catch (Throwable $e) {
                // Si UNIQUE(event_id, volunteer_id) encore présent -> multi-slot impossible
                if ($hasUniqueEventVolunteer) {
                    log_toggle("MULTISLOT BLOCKED (unique event_id+volunteer_id) : " . $e->getMessage() . " | eid={$eventId} sid={$slotId} vid={$volunteerId}");
                } else {
                    log_toggle("INSERT SLOT ERROR: " . $e->getMessage() . " | eid={$eventId} sid={$slotId} vid={$volunteerId}");
                }

                // Fallback safe : on ne casse rien, on redirige
                safe_redirect($redirect);
            }

            safe_redirect($redirect);
        }

        // leave (slots) : on supprime uniquement l'inscription de CE slot
        $stmt = $pdo->prepare("
            DELETE FROM event_registrations
            WHERE volunteer_id = :vid
              AND slot_id = :sid
            LIMIT 1
        ");
        $stmt->execute(['vid' => $volunteerId, 'sid' => $slotId]);

        safe_redirect($redirect);
    }

    // ---------------------------------------------------------
    // 3) MODE LEGACY (inchangé)
    // ---------------------------------------------------------
    if ($action === 'join') {
        if (!is_null($event['max_volunteers'])) {
            $stmt = $pdo->prepare("
                SELECT COUNT(*)
                FROM event_registrations
                WHERE event_id = :eid AND status = 'present'
            ");
            $stmt->execute(['eid' => $eventId]);
            $count = (int)$stmt->fetchColumn();
            if ($count >= (int)$event['max_volunteers']) {
                safe_redirect($redirect);
            }
        }

        $stmt = $pdo->prepare("
            INSERT INTO event_registrations (event_id, volunteer_id, status)
            VALUES (:eid, :vid, 'present')
            ON DUPLICATE KEY UPDATE status = VALUES(status)
        ");
        $stmt->execute([
            'eid' => $eventId,
            'vid' => $volunteerId,
        ]);
    } else {
        $stmt = $pdo->prepare("
            DELETE FROM event_registrations
            WHERE event_id = :eid AND volunteer_id = :vid
        ");
        $stmt->execute([
            'eid' => $eventId,
            'vid' => $volunteerId,
        ]);
    }

    safe_redirect($redirect);

} catch (Throwable $e) {
    log_toggle("ERROR: " . $e->getMessage() . " | event_id={$eventId} slot_id={$slotId} action={$action} vid={$volunteerId} useSlots=" . ($useSlots ? '1' : '0'));
    safe_redirect($redirect);
}
