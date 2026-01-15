<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$title = "Admin – Éditer événement";
ob_start();

require_once __DIR__ . '/../includes/app.php';
$config = require __DIR__ . '/../config/config.php';

// Protection admin
if (empty($_SESSION['is_admin']) && empty($_SESSION['admin_authenticated'])) {
    header('Location: ' . $config['base_url'] . '/admin/login.php');
    exit;
}

global $pdo;

require_once __DIR__ . '/../includes/event_types.php';
$eventTypes = get_event_types($pdo, true);

function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

function toDatetimeLocal($mysqlDatetime) {
    if (!$mysqlDatetime) return '';
    $dt = new DateTime($mysqlDatetime);
    return $dt->format('Y-m-d\TH:i');
}
function dtLocalToMysql($s){
    if (!$s) return null;
    // attend "Y-m-d\TH:i"
    $dt = DateTime::createFromFormat('Y-m-d\TH:i', $s);
    if (!$dt) return null;
    return $dt->format('Y-m-d H:i:00');
}
function isSafeRedirect($url){
    return $url && strpos($url, '://') === false && str_starts_with($url, '/');
}

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

$event = [
    'title'          => '',
    'description'    => '',
    'event_type'     => 'permanence',
    'start_datetime' => '',
    'end_datetime'   => '',
    'min_volunteers' => 0,
    'max_volunteers' => null,
    'is_cancelled'   => 0,
];

$error = '';
$success = '';

/* =========================================================
   Détection slots + colonnes attendues
========================================================= */
$hasSlotsTable = false;
$slotHasMax = false;

try {
    $stmt = $pdo->query("SHOW TABLES LIKE 'event_slots'");
    $hasSlotsTable = (bool)$stmt->fetchColumn();
} catch (Throwable $e) {
    $hasSlotsTable = false;
}

if ($hasSlotsTable) {
    try {
        $stmt = $pdo->query("SHOW COLUMNS FROM event_slots LIKE 'max_volunteers'");
        $slotHasMax = (bool)$stmt->fetchColumn();
    } catch (Throwable $e) {
        $slotHasMax = false;
    }
}

$useSlots = $hasSlotsTable; // si table existe, on considère “mode créneaux” dispo

/* =========================================================
   Chargement event (si édition)
========================================================= */
$existing = null;
$oldEnvelopeStart = null;
$oldEnvelopeEnd = null;

if ($id > 0) {
    $stmt = $pdo->prepare("SELECT * FROM events WHERE id = :id");
    $stmt->execute(['id' => $id]);
    $existing = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$existing) {
        $error = "Événement introuvable.";
    } else {
        $event = $existing;
        $oldEnvelopeStart = $existing['start_datetime'] ?? null;
        $oldEnvelopeEnd   = $existing['end_datetime'] ?? null;
    }
}

/* =========================================================
   Récup slots + stats
========================================================= */
$slots = [];
$slotCounts = []; // slot_id => count
$totalRegisteredAll = 0;

if ($useSlots && $id > 0 && empty($error)) {
    $stmt = $pdo->prepare("SELECT * FROM event_slots WHERE event_id = :eid ORDER BY start_datetime ASC, id ASC");
    $stmt->execute(['eid' => $id]);
    $slots = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // counts
    if (!empty($slots)) {
        $stmt = $pdo->prepare("
            SELECT slot_id, COUNT(*) AS c
            FROM event_registrations
            WHERE event_id = :eid AND status='present' AND slot_id IS NOT NULL
            GROUP BY slot_id
        ");
        $stmt->execute(['eid' => $id]);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $slotCounts[(int)$r['slot_id']] = (int)$r['c'];
            $totalRegisteredAll += (int)$r['c'];
        }
    }
}

/* =========================================================
   Helpers slots
========================================================= */
function slotsCreateDefault(PDO $pdo, int $eventId, string $startMysql, string $endMysql, int $min=0, $max=null) {
    // max nullable
    $stmt = $pdo->prepare("
        INSERT INTO event_slots (event_id, start_datetime, end_datetime, min_volunteers, max_volunteers, created_at)
        VALUES (:eid, :s, :e, :min, :max, NOW())
    ");
    $stmt->execute([
        'eid' => $eventId,
        's'   => $startMysql,
        'e'   => $endMysql,
        'min' => $min,
        'max' => $max
    ]);
}

function slotsNormalize(array $slots): array {
    usort($slots, function($a,$b){
        $da = strtotime($a['start_datetime']); $db = strtotime($b['start_datetime']);
        if ($da === $db) return ((int)$a['id'] <=> (int)$b['id']);
        return $da <=> $db;
    });
    return $slots;
}

function slotOverlapOrInvalid(string $sStart, string $sEnd): bool {
    $a = strtotime($sStart);
    $b = strtotime($sEnd);
    return ($a === false || $b === false || $b <= $a);
}

/* =========================================================
   POST handlers
========================================================= */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && empty($error)) {

    $redirect = $_POST['redirect'] ?? '';
    if (!$redirect || !isSafeRedirect($redirect)) {
        $redirect = $config['base_url'] . '/admin/events_list.php';
    }

    /* =========================================================
       ✅ NOUVEAU : Dupliquer EVENT (event + slots, sans inscriptions)
       - accessible uniquement si event existe (id>0)
       - copie fields + slots
       - ne copie PAS event_registrations
       - redirige vers le nouvel event
    ========================================================= */
    if ($id > 0 && isset($_POST['duplicate_event']) && (int)$_POST['duplicate_event'] === 1) {
        try {
            $pdo->beginTransaction();

            // Recharge event source (version la plus fraîche)
            $st = $pdo->prepare("SELECT * FROM events WHERE id = :id");
            $st->execute(['id' => $id]);
            $src = $st->fetch(PDO::FETCH_ASSOC);
            if (!$src) throw new RuntimeException("Événement introuvable.");

            // Nouveau titre (copie)
            $newTitle = trim((string)$src['title']);
            $newTitle = $newTitle !== '' ? ($newTitle . " (copie)") : "Événement (copie)";

            // Insert event
            $ins = $pdo->prepare("
                INSERT INTO events
                  (title, description, event_type, start_datetime, end_datetime, min_volunteers, max_volunteers, is_cancelled)
                VALUES
                  (:title, :description, :event_type, :start, :end, :min, :max, :is_cancelled)
            ");
            $ins->execute([
                'title'        => $newTitle,
                'description'  => (string)($src['description'] ?? ''),
                'event_type'   => (string)($src['event_type'] ?? 'permanence'),
                'start'        => (string)($src['start_datetime'] ?? ''),
                'end'          => (string)($src['end_datetime'] ?? ''),
                'min'          => (int)($src['min_volunteers'] ?? 0),
                'max'          => ($src['max_volunteers'] === null ? null : (int)$src['max_volunteers']),
                // On duplique en "actif" (pas annulé), sinon ça piège à la création
                'is_cancelled' => 0
            ]);

            $newId = (int)$pdo->lastInsertId();
            if ($newId <= 0) throw new RuntimeException("Impossible de créer la copie.");

            // Copier slots si mode slots
            if ($useSlots) {
                $stS = $pdo->prepare("
                    SELECT start_datetime, end_datetime, min_volunteers, max_volunteers
                    FROM event_slots
                    WHERE event_id = :eid
                    ORDER BY start_datetime ASC, id ASC
                ");
                $stS->execute(['eid' => $id]);
                $srcSlots = $stS->fetchAll(PDO::FETCH_ASSOC);

                if (!empty($srcSlots)) {
                    $insS = $pdo->prepare("
                        INSERT INTO event_slots (event_id, start_datetime, end_datetime, min_volunteers, max_volunteers, created_at)
                        VALUES (:eid, :s, :e, :min, :max, NOW())
                    ");
                    foreach ($srcSlots as $sl) {
                        $insS->execute([
                            'eid' => $newId,
                            's'   => (string)$sl['start_datetime'],
                            'e'   => (string)$sl['end_datetime'],
                            'min' => (int)($sl['min_volunteers'] ?? 0),
                            'max' => ($sl['max_volunteers'] === null ? null : (int)$sl['max_volunteers']),
                        ]);
                    }
                } else {
                    // Si pas de slots sur la source, on crée un slot par défaut = enveloppe
                    if (!empty($src['start_datetime']) && !empty($src['end_datetime'])) {
                        slotsCreateDefault(
                            $pdo,
                            $newId,
                            (string)$src['start_datetime'],
                            (string)$src['end_datetime'],
                            (int)($src['min_volunteers'] ?? 0),
                            ($src['max_volunteers'] === null ? null : (int)$src['max_volunteers'])
                        );
                    }
                }
            }

            $pdo->commit();
            header('Location: ' . $config['base_url'] . '/admin/event_edit.php?id=' . $newId . '&duplicated=1');
            exit;

        } catch (Throwable $e) {
            $pdo->rollBack();
            $error = "Erreur lors de la duplication : " . $e->getMessage();
        }
    }

    // Suppression EVENT (hard delete)
    if ($id > 0 && isset($_POST['delete_event']) && (int)$_POST['delete_event'] === 1) {
        try {
            $pdo->beginTransaction();

            // d'abord registrations (FK)
            $st = $pdo->prepare("DELETE FROM event_registrations WHERE event_id = :eid");
            $st->execute(['eid' => $id]);

            if ($useSlots) {
                $st = $pdo->prepare("DELETE FROM event_slots WHERE event_id = :eid");
                $st->execute(['eid' => $id]);
            }

            $st = $pdo->prepare("DELETE FROM events WHERE id = :eid");
            $st->execute(['eid' => $id]);

            $pdo->commit();
            header('Location: ' . $config['base_url'] . '/admin/events_list.php?deleted=1');
            exit;
        } catch (Throwable $e) {
            $pdo->rollBack();
            $error = "Erreur lors de la suppression : " . $e->getMessage();
        }
    }

    // =========================
    // Sauvegarde EVENT (enveloppe)
    // =========================
    if (isset($_POST['save_event']) && (int)$_POST['save_event'] === 1) {

        $title_e     = trim($_POST['title'] ?? '');
        $description = trim($_POST['description'] ?? '');
        $event_type  = (string)($_POST['event_type'] ?? 'permanence');

        // Vérif type
        if (!isset($eventTypes[$event_type])) {
            $error = "Type d’événement invalide.";
            $event_type = 'permanence';
        }

        $startLocal = $_POST['start_datetime'] ?? '';
        $endLocal   = $_POST['end_datetime'] ?? '';

        $startMysql = dtLocalToMysql($startLocal);
        $endMysql   = dtLocalToMysql($endLocal);

        $min = (int)($_POST['min_volunteers'] ?? 0);
        $max = ($_POST['max_volunteers'] ?? '') === '' ? null : (int)$_POST['max_volunteers'];
        $is_cancelled = isset($_POST['is_cancelled']) ? 1 : 0;

        if (!$title_e || !$startMysql || !$endMysql) {
            $error = "Merci de compléter au minimum le titre, la date de début et la date de fin.";
        } elseif (strtotime($endMysql) < strtotime($startMysql)) {
            $error = "La date/heure de fin doit être supérieure ou égale à la date/heure de début.";
        }

        // Stratégie autosync si multi-slots
        $syncMode = (string)($_POST['sync_mode'] ?? 'auto'); // auto|extend|shift|none
        // auto = si 1 slot => sync, si multi => ne touche pas (équivalent none) MAIS on propose via UI
        if (!in_array($syncMode, ['auto','extend','shift','none'], true)) $syncMode = 'auto';

        if (empty($error)) {
            try {
                $pdo->beginTransaction();

                if ($id > 0) {
                    // update event
                    $stmt = $pdo->prepare("
                        UPDATE events SET
                          title=:title, description=:description, event_type=:event_type,
                          start_datetime=:start, end_datetime=:end,
                          min_volunteers=:min, max_volunteers=:max,
                          is_cancelled=:is_cancelled
                        WHERE id=:id
                    ");
                    $stmt->execute([
                        'title' => $title_e,
                        'description' => $description,
                        'event_type' => $event_type,
                        'start' => $startMysql,
                        'end' => $endMysql,
                        'min' => $min,
                        'max' => $max,
                        'is_cancelled' => $is_cancelled,
                        'id' => $id
                    ]);

                    // Autosync slots
                    if ($useSlots) {
                        // reload slots count
                        $stmtS = $pdo->prepare("SELECT * FROM event_slots WHERE event_id=:eid ORDER BY start_datetime ASC, id ASC");
                        $stmtS->execute(['eid'=>$id]);
                        $curSlots = $stmtS->fetchAll(PDO::FETCH_ASSOC);
                        $slotCount = count($curSlots);

                        if ($slotCount === 1) {
                            // sync auto : slot suit enveloppe
                            $sid = (int)$curSlots[0]['id'];
                            $stmtU = $pdo->prepare("
                                UPDATE event_slots
                                SET start_datetime=:s, end_datetime=:e, min_volunteers=:min, max_volunteers=:max
                                WHERE id=:sid
                            ");
                            $stmtU->execute([
                                's'=>$startMysql,'e'=>$endMysql,'min'=>$min,'max'=>$max,'sid'=>$sid
                            ]);
                        } elseif ($slotCount > 1) {

                            // On applique seulement si extend/shift, sinon on ne touche pas
                            if ($syncMode === 'extend' || $syncMode === 'shift') {
                                $oldS = $oldEnvelopeStart ?: $startMysql;
                                $oldE = $oldEnvelopeEnd ?: $endMysql;

                                $oldS_ts = strtotime($oldS);
                                $oldE_ts = strtotime($oldE);
                                $newS_ts = strtotime($startMysql);
                                $newE_ts = strtotime($endMysql);

                                $earliest = $curSlots[0];
                                $latest = $curSlots[count($curSlots)-1];

                                if ($syncMode === 'extend') {
                                    // étendre uniquement les slots qui touchent les bords
                                    $touchStart = (strtotime($earliest['start_datetime']) === $oldS_ts);
                                    $touchEnd   = (strtotime($latest['end_datetime']) === $oldE_ts);

                                    if ($touchStart) {
                                        $stmtU = $pdo->prepare("UPDATE event_slots SET start_datetime=:s WHERE id=:id");
                                        $stmtU->execute(['s'=>$startMysql,'id'=>(int)$earliest['id']]);
                                    }
                                    if ($touchEnd) {
                                        $stmtU = $pdo->prepare("UPDATE event_slots SET end_datetime=:e WHERE id=:id");
                                        $stmtU->execute(['e'=>$endMysql,'id'=>(int)$latest['id']]);
                                    }

                                } else { // shift
                                    // décale tout le monde avec delta du début
                                    $delta = $newS_ts - $oldS_ts;

                                    foreach ($curSlots as $sl) {
                                        $sTs = strtotime($sl['start_datetime']) + $delta;
                                        $eTs = strtotime($sl['end_datetime']) + $delta;

                                        $stmtU = $pdo->prepare("UPDATE event_slots SET start_datetime=:s, end_datetime=:e WHERE id=:id");
                                        $stmtU->execute([
                                            's'=>date('Y-m-d H:i:00', $sTs),
                                            'e'=>date('Y-m-d H:i:00', $eTs),
                                            'id'=>(int)$sl['id']
                                        ]);
                                    }
                                }
                            }
                        }

                        // met à jour enveloppe mémoire pour prochains saves
                        $oldEnvelopeStart = $startMysql;
                        $oldEnvelopeEnd   = $endMysql;
                    }

                    $pdo->commit();
                    $success = "Événement mis à jour.";

                } else {
                    // INSERT event
                    $stmt = $pdo->prepare("
                        INSERT INTO events (title, description, event_type, start_datetime, end_datetime, min_volunteers, max_volunteers, is_cancelled)
                        VALUES (:title, :description, :event_type, :start, :end, :min, :max, :is_cancelled)
                    ");
                    $stmt->execute([
                        'title'=>$title_e,
                        'description'=>$description,
                        'event_type'=>$event_type,
                        'start'=>$startMysql,
                        'end'=>$endMysql,
                        'min'=>$min,
                        'max'=>$max,
                        'is_cancelled'=>$is_cancelled
                    ]);
                    $newId = (int)$pdo->lastInsertId();

                    // Draft slots (créés avant event) -> JSON
                    $draftJson = (string)($_POST['draft_slots_json'] ?? '');
                    $draftSlots = [];
                    if ($useSlots && $draftJson) {
                        $tmp = json_decode($draftJson, true);
                        if (is_array($tmp)) $draftSlots = $tmp;
                    }

                    if ($useSlots) {
                        if (!empty($draftSlots)) {
                            // insert draft slots
                            $stmtIns = $pdo->prepare("
                                INSERT INTO event_slots (event_id, start_datetime, end_datetime, min_volunteers, max_volunteers, created_at)
                                VALUES (:eid, :s, :e, :min, :max, NOW())
                            ");
                            foreach ($draftSlots as $ds) {
                                $s = dtLocalToMysql($ds['start'] ?? '');
                                $e = dtLocalToMysql($ds['end'] ?? '');
                                $dMin = (int)($ds['min'] ?? 0);
                                $dMax = (($ds['max'] ?? '') === '' || $ds['max'] === null) ? null : (int)$ds['max'];
                                if (!$s || !$e || strtotime($e) <= strtotime($s)) continue;

                                $stmtIns->execute([
                                    'eid'=>$newId,
                                    's'=>$s,
                                    'e'=>$e,
                                    'min'=>$dMin,
                                    'max'=>$dMax
                                ]);
                            }
                        } else {
                            // slot par défaut = enveloppe
                            slotsCreateDefault($pdo, $newId, $startMysql, $endMysql, $min, $max);
                        }
                    }

                    $pdo->commit();
                    header('Location: ' . $config['base_url'] . '/admin/event_edit.php?id=' . $newId . '&created=1');
                    exit;
                }

            } catch (Throwable $e) {
                $pdo->rollBack();
                $error = "Erreur lors de l’enregistrement : " . $e->getMessage();
            }
        }
    }

    // =========================
    // Actions slots (uniquement si event existe)
    // =========================
    if ($useSlots && $id > 0 && isset($_POST['slot_action'])) {

        $slotAction = (string)$_POST['slot_action'];

        try {
            $pdo->beginTransaction();

            // on recharge enveloppe fraîche
            $stmtEv = $pdo->prepare("SELECT start_datetime, end_datetime, min_volunteers, max_volunteers FROM events WHERE id=:id");
            $stmtEv->execute(['id'=>$id]);
            $env = $stmtEv->fetch(PDO::FETCH_ASSOC);
            $envS = $env['start_datetime'];
            $envE = $env['end_datetime'];
            $envMin = (int)($env['min_volunteers'] ?? 0);
            $envMax = $env['max_volunteers'] === null ? null : (int)$env['max_volunteers'];

            if ($slotAction === 'add') {
                $s = dtLocalToMysql($_POST['slot_start'] ?? '');
                $e = dtLocalToMysql($_POST['slot_end'] ?? '');
                $sMin = (int)($_POST['slot_min'] ?? $envMin);
                $sMax = ($_POST['slot_max'] ?? '') === '' ? null : (int)$_POST['slot_max'];

                if (!$s || !$e || strtotime($e) <= strtotime($s)) {
                    throw new RuntimeException("Créneau invalide.");
                }

                // on ne bloque PLUS avec “doit rester dans l’enveloppe” -> on autorise
                // mais on peut “étendre” l’enveloppe automatiquement si le slot sort
                $autoExtend = isset($_POST['auto_extend_envelope']) && (int)$_POST['auto_extend_envelope'] === 1;
                if ($autoExtend) {
                    $newEnvS = (strtotime($s) < strtotime($envS)) ? $s : $envS;
                    $newEnvE = (strtotime($e) > strtotime($envE)) ? $e : $envE;
                    if ($newEnvS !== $envS || $newEnvE !== $envE) {
                        $stU = $pdo->prepare("UPDATE events SET start_datetime=:s, end_datetime=:e WHERE id=:id");
                        $stU->execute(['s'=>$newEnvS,'e'=>$newEnvE,'id'=>$id]);
                        $envS = $newEnvS; $envE = $newEnvE;
                    }
                }

                $stmt = $pdo->prepare("
                    INSERT INTO event_slots (event_id, start_datetime, end_datetime, min_volunteers, max_volunteers, created_at)
                    VALUES (:eid, :s, :e, :min, :max, NOW())
                ");
                $stmt->execute(['eid'=>$id,'s'=>$s,'e'=>$e,'min'=>$sMin,'max'=>$sMax]);

            } elseif ($slotAction === 'delete_one') {
                $sid = (int)($_POST['slot_id'] ?? 0);
                if ($sid <= 0) throw new RuntimeException("Slot invalide.");

                // delete registrations liées
                $st = $pdo->prepare("DELETE FROM event_registrations WHERE slot_id=:sid");
                $st->execute(['sid'=>$sid]);

                $st = $pdo->prepare("DELETE FROM event_slots WHERE id=:sid AND event_id=:eid");
                $st->execute(['sid'=>$sid,'eid'=>$id]);

            } elseif ($slotAction === 'update_one') {
                $sid = (int)($_POST['slot_id'] ?? 0);
                $s = dtLocalToMysql($_POST['slot_start'] ?? '');
                $e = dtLocalToMysql($_POST['slot_end'] ?? '');
                $sMin = (int)($_POST['slot_min'] ?? 0);
                $sMax = ($_POST['slot_max'] ?? '') === '' ? null : (int)$_POST['slot_max'];

                if ($sid<=0 || !$s || !$e || strtotime($e) <= strtotime($s)) {
                    throw new RuntimeException("Créneau invalide.");
                }

                $st = $pdo->prepare("
                    UPDATE event_slots
                    SET start_datetime=:s, end_datetime=:e, min_volunteers=:min, max_volunteers=:max
                    WHERE id=:sid AND event_id=:eid
                ");
                $st->execute(['s'=>$s,'e'=>$e,'min'=>$sMin,'max'=>$sMax,'sid'=>$sid,'eid'=>$id]);

            } elseif ($slotAction === 'delete_all') {
                // delete registrations slots
                $st = $pdo->prepare("DELETE FROM event_registrations WHERE event_id=:eid AND slot_id IS NOT NULL");
                $st->execute(['eid'=>$id]);

                $st = $pdo->prepare("DELETE FROM event_slots WHERE event_id=:eid");
                $st->execute(['eid'=>$id]);

            } elseif ($slotAction === 'normalize') {
                // ici = juste tri visuel (déjà ORDER BY). Rien à faire côté DB.
                // On peut aussi “écraser” les secondes, mais inutile.
            } elseif ($slotAction === 'merge_two') {
                $a = (int)($_POST['merge_a'] ?? 0);
                $b = (int)($_POST['merge_b'] ?? 0);
                if ($a<=0 || $b<=0 || $a===$b) throw new RuntimeException("Sélection invalide.");

                $st1 = $pdo->prepare("SELECT * FROM event_slots WHERE id=:id AND event_id=:eid");
                $st1->execute(['id'=>$a,'eid'=>$id]); $sa = $st1->fetch(PDO::FETCH_ASSOC);
                $st1->execute(['id'=>$b,'eid'=>$id]); $sb = $st1->fetch(PDO::FETCH_ASSOC);
                if (!$sa || !$sb) throw new RuntimeException("Slots introuvables.");

                $saS = strtotime($sa['start_datetime']); $saE = strtotime($sa['end_datetime']);
                $sbS = strtotime($sb['start_datetime']); $sbE = strtotime($sb['end_datetime']);

                // On exige “consécutifs” (a finit quand b commence OU b finit quand a commence)
                $touch = ($saE === $sbS) || ($sbE === $saS);
                if (!$touch) throw new RuntimeException("Les créneaux ne sont pas consécutifs.");

                $newS = date('Y-m-d H:i:00', min($saS,$sbS));
                $newE = date('Y-m-d H:i:00', max($saE,$sbE));
                $newMin = min((int)$sa['min_volunteers'], (int)$sb['min_volunteers']);
                $newMax = null;
                $ma = $sa['max_volunteers']; $mb = $sb['max_volunteers'];
                if ($ma !== null && $mb !== null) $newMax = max((int)$ma, (int)$mb);

                // On garde A, on supprime B (et on rapatrie les registrations de B sur A)
                $stU = $pdo->prepare("UPDATE event_slots SET start_datetime=:s, end_datetime=:e, min_volunteers=:min, max_volunteers=:max WHERE id=:id AND event_id=:eid");
                $stU->execute(['s'=>$newS,'e'=>$newE,'min'=>$newMin,'max'=>$newMax,'id'=>$a,'eid'=>$id]);

                $stR = $pdo->prepare("UPDATE event_registrations SET slot_id=:a WHERE slot_id=:b");
                $stR->execute(['a'=>$a,'b'=>$b]);

                $stD = $pdo->prepare("DELETE FROM event_slots WHERE id=:b AND event_id=:eid");
                $stD->execute(['b'=>$b,'eid'=>$id]);

            } elseif ($slotAction === 'split_equal') {
                $parts = (int)($_POST['split_parts'] ?? 2);
                if (!in_array($parts, [2,3,4], true)) $parts = 2;

                // on supprime tout puis recrée
                $st = $pdo->prepare("DELETE FROM event_registrations WHERE event_id=:eid AND slot_id IS NOT NULL");
                $st->execute(['eid'=>$id]);
                $st = $pdo->prepare("DELETE FROM event_slots WHERE event_id=:eid");
                $st->execute(['eid'=>$id]);

                $startTs = strtotime($envS);
                $endTs   = strtotime($envE);
                $dur = $endTs - $startTs;
                $step = (int)floor($dur / $parts);

                $ins = $pdo->prepare("
                    INSERT INTO event_slots (event_id, start_datetime, end_datetime, min_volunteers, max_volunteers, created_at)
                    VALUES (:eid,:s,:e,:min,:max,NOW())
                ");

                for ($i=0;$i<$parts;$i++){
                    $sTs = $startTs + ($i*$step);
                    $eTs = ($i===$parts-1) ? $endTs : ($startTs + (($i+1)*$step));
                    $ins->execute([
                        'eid'=>$id,
                        's'=>date('Y-m-d H:i:00',$sTs),
                        'e'=>date('Y-m-d H:i:00',$eTs),
                        'min'=>$envMin,
                        'max'=>$envMax
                    ]);
                }

            } elseif ($slotAction === 'split_morning_afternoon') {
                // (env) 09-18 -> 09-13 + 14-18 (1h pause fixe)
                $envStartDT = new DateTime($envS);
                $envEndDT = new DateTime($envE);

                $mid = clone $envStartDT;
                $mid->setTime(13,0,0);
                $mid2 = clone $envStartDT;
                $mid2->setTime(14,0,0);

                // si enveloppe ne couvre pas, fallback “parts=2”
                if ($mid <= $envStartDT || $mid2 >= $envEndDT) {
                    $parts = 2;

                    $st = $pdo->prepare("DELETE FROM event_registrations WHERE event_id=:eid AND slot_id IS NOT NULL");
                    $st->execute(['eid'=>$id]);
                    $st = $pdo->prepare("DELETE FROM event_slots WHERE event_id=:eid");
                    $st->execute(['eid'=>$id]);

                    $startTs = strtotime($envS);
                    $endTs   = strtotime($envE);
                    $dur = $endTs - $startTs;
                    $step = (int)floor($dur / $parts);

                    $ins = $pdo->prepare("
                        INSERT INTO event_slots (event_id, start_datetime, end_datetime, min_volunteers, max_volunteers, created_at)
                        VALUES (:eid,:s,:e,:min,:max,NOW())
                    ");

                    for ($i=0;$i<$parts;$i++){
                        $sTs = $startTs + ($i*$step);
                        $eTs = ($i===$parts-1) ? $endTs : ($startTs + (($i+1)*$step));
                        $ins->execute([
                            'eid'=>$id,
                            's'=>date('Y-m-d H:i:00',$sTs),
                            'e'=>date('Y-m-d H:i:00',$eTs),
                            'min'=>$envMin,
                            'max'=>$envMax
                        ]);
                    }
                } else {
                    // delete all slots + registrations
                    $st = $pdo->prepare("DELETE FROM event_registrations WHERE event_id=:eid AND slot_id IS NOT NULL");
                    $st->execute(['eid'=>$id]);
                    $st = $pdo->prepare("DELETE FROM event_slots WHERE event_id=:eid");
                    $st->execute(['eid'=>$id]);

                    $ins = $pdo->prepare("
                        INSERT INTO event_slots (event_id, start_datetime, end_datetime, min_volunteers, max_volunteers, created_at)
                        VALUES (:eid,:s,:e,:min,:max,NOW())
                    ");
                    $ins->execute([
                        'eid'=>$id,'s'=>$envStartDT->format('Y-m-d H:i:00'),'e'=>$mid->format('Y-m-d H:i:00'),
                        'min'=>$envMin,'max'=>$envMax
                    ]);
                    $ins->execute([
                        'eid'=>$id,'s'=>$mid2->format('Y-m-d H:i:00'),'e'=>$envEndDT->format('Y-m-d H:i:00'),
                        'min'=>$envMin,'max'=>$envMax
                    ]);
                }

            } elseif ($slotAction === 'split_minutes') {
                $minutes = (int)($_POST['split_minutes'] ?? 120);
                if ($minutes < 15) $minutes = 15;

                $st = $pdo->prepare("DELETE FROM event_registrations WHERE event_id=:eid AND slot_id IS NOT NULL");
                $st->execute(['eid'=>$id]);
                $st = $pdo->prepare("DELETE FROM event_slots WHERE event_id=:eid");
                $st->execute(['eid'=>$id]);

                $startTs = strtotime($envS);
                $endTs   = strtotime($envE);
                $step = $minutes * 60;

                $ins = $pdo->prepare("
                    INSERT INTO event_slots (event_id, start_datetime, end_datetime, min_volunteers, max_volunteers, created_at)
                    VALUES (:eid,:s,:e,:min,:max,NOW())
                ");

                for ($t=$startTs; $t < $endTs; $t += $step) {
                    $sTs = $t;
                    $eTs = min($endTs, $t + $step);
                    if ($eTs <= $sTs) break;

                    $ins->execute([
                        'eid'=>$id,
                        's'=>date('Y-m-d H:i:00',$sTs),
                        'e'=>date('Y-m-d H:i:00',$eTs),
                        'min'=>$envMin,
                        'max'=>$envMax
                    ]);
                }
            }

            $pdo->commit();
            header('Location: ' . $config['base_url'] . '/admin/event_edit.php?id=' . $id . '&saved=1');
            exit;

        } catch (Throwable $e) {
            $pdo->rollBack();
            $error = "Erreur action créneau : " . $e->getMessage();
        }
    }
}

if (isset($_GET['created']) && $_GET['created'] == '1') $success = "Événement créé.";
if (isset($_GET['saved']) && $_GET['saved'] == '1') $success = $success ?: "Modifications enregistrées.";
if (isset($_GET['duplicated']) && $_GET['duplicated'] == '1') $success = $success ?: "Événement dupliqué.";

/* =========================================================
   UI computed summary
========================================================= */
$slotCount = ($useSlots && $id>0) ? count($slots) : 0;

$envLabel = '';
if (!empty($event['start_datetime']) && !empty($event['end_datetime'])) {
    $ds = new DateTime($event['start_datetime']);
    $de = new DateTime($event['end_datetime']);
    $envLabel = $ds->format('d/m/Y H:i') . ' → ' . $de->format('d/m/Y H:i');
}

// total inscrits (fallback : event-level)
if ($id > 0 && $totalRegisteredAll === 0) {
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM event_registrations WHERE event_id=:eid AND status='present'");
    $stmt->execute(['eid'=>$id]);
    $totalRegisteredAll = (int)$stmt->fetchColumn();
}
?>

<style>
/* --- Petites retouches locales, sans casser ton design global --- */
.admin-grid{
  display:grid;
  grid-template-columns: 1.1fr 0.9fr;
  gap:18px;
}
@media (max-width: 980px){
  .admin-grid{ grid-template-columns:1fr; }
}
.section-title{
  font-size:12px;
  letter-spacing:0.14em;
  text-transform:uppercase;
  color:#6b7280;
  margin:0 0 10px;
}
.hr-dots{border-top:1px dashed #e5e7eb;margin:14px 0;}
.pills{display:flex;gap:8px;flex-wrap:wrap;margin-top:10px;}
.pill{
  display:inline-flex;align-items:center;gap:8px;
  padding:7px 10px;border-radius:999px;
  border:1px solid #e5e7eb;background:#fff;
  font-size:12px;font-weight:800;color:#111827;
}
.pill.ok{background:#dcfce7;border-color:#bbf7d0;color:#166534;}
.pill.warn{background:#fef3c7;border-color:#fde68a;color:#92400e;}
.pill.info{background:#e0f2fe;border-color:#bae6fd;color:#075985;}

.field label{font-weight:800;}
.field small{display:block;color:#6b7280;margin-top:6px;font-size:12px;}
.btn-row{display:flex;gap:10px;flex-wrap:wrap;justify-content:flex-end;align-items:center;}
.btn-row .danger{background:#b91c1c;color:#fff;border-color:transparent;}
.btn-row .secondary{background:#fff;border:1px solid #e5e7eb;}
.btn-row .primary{background:#111827;color:#fff;border-color:transparent;}

select{
  appearance:none;-webkit-appearance:none;-moz-appearance:none;
  padding-right:44px !important;
  background-image:
    linear-gradient(45deg, transparent 50%, #6b7280 50%),
    linear-gradient(135deg, #6b7280 50%, transparent 50%);
  background-position:
    calc(100% - 18px) 50%,
    calc(100% - 12px) 50%;
  background-size:6px 6px, 6px 6px;
  background-repeat:no-repeat;
}

/* Slots list */
.slot-card{
  border:1px solid #e5e7eb;
  border-radius:14px;
  background:#fff;
  padding:12px;
  display:flex;
  align-items:flex-start;
  justify-content:space-between;
  gap:12px;
}
.slot-left{min-width:220px;}
.slot-time{font-size:20px;font-weight:950;letter-spacing:-0.02em;}
.slot-meta{margin-top:6px;color:#6b7280;font-size:12px;font-weight:700;}
.badge{
  display:inline-flex;align-items:center;gap:6px;
  padding:6px 10px;border-radius:999px;
  font-size:12px;font-weight:900;
  border:1px solid #e5e7eb;background:#f9fafb;color:#111827;
}
.badge.full{background:#fee2e2;border-color:#fecaca;color:#991b1b;}
.slot-actions{display:flex;gap:8px;flex-wrap:wrap;justify-content:flex-end;}
.slot-actions button{padding:8px 10px;border-radius:12px;}
.slot-actions .danger{background:#b91c1c;color:#fff;border-color:transparent;}
.slot-actions .secondary{background:#fff;border:1px solid #e5e7eb;}

.split-row{
  display:flex;gap:8px;flex-wrap:wrap;align-items:center;
}
.split-row button{padding:8px 10px;border-radius:12px;}
.split-row input{max-width:110px;}

.placeholder-muted::placeholder{color:#9ca3af;}
</style>

<div class="card">
  <div style="display:flex; justify-content:space-between; align-items:flex-start; gap:12px; flex-wrap:wrap;">
    <div>
      <h2 style="margin:0;"><?= $id > 0 ? "Modifier l’événement" : "Créer un événement" ?></h2>
      <p class="muted" style="margin-top:6px;">
        Enveloppe = période globale. Créneaux = ce que voient les bénévoles.
      </p>

      <div class="pills">
        <span class="pill <?= $useSlots ? 'ok' : 'warn' ?>">
          <?= $useSlots ? "Mode créneaux : actif" : "Mode créneaux : indisponible" ?>
        </span>
        <?php if ($id > 0): ?>
          <span class="pill info">Créneaux : <?= (int)$slotCount ?></span>
          <span class="pill">Inscrits total : <?= (int)$totalRegisteredAll ?></span>
          <?php if ($envLabel): ?><span class="pill">Enveloppe : <?= h($envLabel) ?></span><?php endif; ?>
        <?php endif; ?>
      </div>
    </div>

    <div class="btn-row">
      <a href="<?= $config['base_url'] ?>/admin/events_list.php"><button type="button" class="secondary">← Retour</button></a>
      <?php if ($id > 0): ?>
        <a href="<?= $config['base_url'] ?>/admin/event_registrations.php?id=<?= (int)$id ?>"><button type="button" class="secondary">Inscrits</button></a>

        <!-- ✅ NOUVEAU BOUTON : Dupliquer -->
        <form method="post" style="margin:0;">
          <input type="hidden" name="duplicate_event" value="1">
          <button type="submit" class="secondary" onclick="return confirm('Dupliquer cet événement (et ses créneaux), sans copier les inscriptions ?');">Dupliquer</button>
        </form>

        <form method="post" style="margin:0;">
          <input type="hidden" name="delete_event" value="1">
          <button type="submit" class="danger" onclick="return confirm('Supprimer définitivement cet événement (et ses inscriptions/créneaux) ?');">Supprimer</button>
        </form>
      <?php endif; ?>
    </div>
  </div>

  <?php if ($error): ?>
    <div class="alert danger" style="margin-top:12px;"><?= h($error) ?></div>
  <?php endif; ?>

  <?php if ($success): ?>
    <div class="alert success" style="margin-top:12px;"><?= h($success) ?></div>
  <?php endif; ?>
</div>

<form method="post" class="card" id="eventForm">
  <input type="hidden" name="save_event" value="1">
  <input type="hidden" name="draft_slots_json" id="draftSlotsJson" value="[]">

  <div class="admin-grid">
    <div>
      <div class="section-title">Informations</div>

      <div class="form-grid form-grid--2">
        <div class="field">
          <label for="title" class="field-label">Titre</label>
          <input id="title" name="title" type="text" value="<?= h($event['title'] ?? '') ?>" required>
        </div>

        <div class="field">
          <label for="event_type" class="field-label">Type</label>
          <select id="event_type" name="event_type" required>
            <?php foreach ($eventTypes as $code => $label): ?>
              <option value="<?= h($code) ?>" <?= (($event['event_type'] ?? 'permanence') === $code) ? 'selected' : '' ?>>
                <?= h($label) ?>
              </option>
            <?php endforeach; ?>
          </select>
        </div>
      </div>

      <div class="field" style="margin-top:12px;">
        <label for="description" class="field-label">Description (optionnel)</label>
        <textarea id="description" name="description" rows="5"><?= h($event['description'] ?? '') ?></textarea>
        <small>Champ “humain” : pas d’info technique ici.</small>
      </div>

      <div class="hr-dots"></div>

      <div class="section-title">Bénévoles (par défaut)</div>

      <div class="form-grid form-grid--2">
        <div class="field">
          <label for="min_volunteers" class="field-label">Min</label>
          <input id="min_volunteers" name="min_volunteers" type="number" min="0"
                 value="<?= (int)($event['min_volunteers'] ?? 0) ?>">
        </div>

        <div class="field">
          <label for="max_volunteers" class="field-label">Max (optionnel)</label>
          <input id="max_volunteers" class="placeholder-muted" name="max_volunteers" type="number" min="0"
                 placeholder="Illimité"
                 value="<?= ($event['max_volunteers'] === null) ? '' : (int)$event['max_volunteers'] ?>">
          <small>Laisse vide = illimité.</small>
        </div>
      </div>

      <div class="field" style="margin-top:10px; display:flex; align-items:center; gap:10px;">
        <input type="checkbox" id="is_cancelled" name="is_cancelled" <?= ((int)($event['is_cancelled'] ?? 0) === 1) ? 'checked' : '' ?>>
        <label for="is_cancelled" style="margin:0;">Annuler cet événement</label>
      </div>
    </div>

    <div>
      <div class="section-title">Dates et horaires (enveloppe)</div>

      <div class="form-grid form-grid--2">
        <div class="field">
          <label for="start_datetime" class="field-label">Début</label>
          <input id="start_datetime" name="start_datetime" type="datetime-local"
                 value="<?= h(toDatetimeLocal($event['start_datetime'] ?? '')) ?>" required>
        </div>

        <div class="field">
          <label for="end_datetime" class="field-label">Fin</label>
          <input id="end_datetime" name="end_datetime" type="datetime-local"
                 value="<?= h(toDatetimeLocal($event['end_datetime'] ?? '')) ?>" required>
        </div>
      </div>

      <small style="display:block;margin-top:8px;color:#6b7280;font-weight:700;">
        Astuce : changer le début ajuste la fin si elle est vide/incohérente.
      </small>

      <?php if ($useSlots && $id > 0 && count($slots) > 1): ?>
        <div class="hr-dots"></div>
        <div class="section-title">Autosync enveloppe ↔ créneaux</div>
        <div class="field">
          <label style="font-weight:800;">Quand tu enregistres l’enveloppe :</label>
          <div style="display:flex; flex-direction:column; gap:8px; margin-top:8px;">
            <label style="display:flex;gap:10px;align-items:center;">
              <input type="radio" name="sync_mode" value="extend" checked>
              Étendre les créneaux qui touchent les bords
            </label>
            <label style="display:flex;gap:10px;align-items:center;">
              <input type="radio" name="sync_mode" value="shift">
              Décaler tous les créneaux (delta du début)
            </label>
            <label style="display:flex;gap:10px;align-items:center;">
              <input type="radio" name="sync_mode" value="none">
              Ne rien faire
            </label>
          </div>
        </div>
      <?php else: ?>
        <input type="hidden" name="sync_mode" value="auto">
      <?php endif; ?>

      <div class="btn-row" style="margin-top:14px;">
        <button type="submit" class="primary"><?= $id > 0 ? "Enregistrer" : "Créer" ?></button>
      </div>
    </div>
  </div>
</form>

<?php if ($useSlots): ?>
  <div class="card">
    <div class="section-title" style="margin-bottom:8px;">Créneaux</div>

    <?php if ($id <= 0): ?>
      <p class="muted" style="margin:0 0 10px;">
        Tu peux préparer des créneaux <strong>avant</strong> de créer l’événement (moins de clics).
        Ils seront créés automatiquement au moment de “Créer”.
      </p>

      <div class="split-row" style="margin-bottom:12px;">
        <button type="button" class="secondary" onclick="draftSplitMorningAfternoon()">Découper matin/aprem</button>
        <button type="button" class="secondary" onclick="draftSplitEqual(2)">2 créneaux</button>
        <button type="button" class="secondary" onclick="draftSplitEqual(3)">3 créneaux</button>
        <button type="button" class="secondary" onclick="draftSplitEqual(4)">4 créneaux</button>
        <span class="muted" style="font-weight:700;">ou toutes les</span>
        <input type="number" id="draftMinutes" value="120" min="15" step="15">
        <span class="muted" style="font-weight:700;">minutes</span>
        <button type="button" class="secondary" onclick="draftSplitMinutes()">Découper</button>
      </div>

      <div id="draftList" style="display:flex; flex-direction:column; gap:10px;"></div>

      <script>
        const draft = { slots: [] };

        function getEnvLocal(){
          const s = document.getElementById('start_datetime').value;
          const e = document.getElementById('end_datetime').value;
          return {s,e};
        }
        function toTs(dtLocal){ return dtLocal ? Date.parse(dtLocal) : NaN; }
        function fmtTime(dtLocal){
          const d = new Date(dtLocal);
          const hh = String(d.getHours()).padStart(2,'0');
          const mm = String(d.getMinutes()).padStart(2,'0');
          return `${hh}:${mm}`;
        }
        function renderDraft(){
          const box = document.getElementById('draftList');
          box.innerHTML = '';

          if (draft.slots.length === 0){
            box.innerHTML = `<p class="muted" style="margin:0;">Aucun créneau préparé.</p>`;
          } else {
            draft.slots.forEach((sl, idx) => {
              const time = `${fmtTime(sl.start)} → ${fmtTime(sl.end)}`;
              const maxTxt = (sl.max === '' || sl.max === null) ? 'Illimité' : sl.max;
              const row = document.createElement('div');
              row.className = 'slot-card';
              row.innerHTML = `
                <div class="slot-left">
                  <div class="slot-time">${time}</div>
                  <div class="slot-meta">Min: ${sl.min ?? 0} · Max: ${maxTxt}</div>
                </div>
                <div class="slot-actions">
                  <button type="button" class="secondary" onclick="draftRemove(${idx})">Supprimer</button>
                </div>
              `;
              box.appendChild(row);
            });
          }

          document.getElementById('draftSlotsJson').value = JSON.stringify(draft.slots);
        }
        function draftRemove(i){
          draft.slots.splice(i,1);
          renderDraft();
        }

        function draftSplitEqual(parts){
          const {s,e} = getEnvLocal();
          const sTs = toTs(s), eTs = toTs(e);
          if (!s || !e || !isFinite(sTs) || !isFinite(eTs) || eTs <= sTs) return;

          draft.slots = [];
          const dur = eTs - sTs;
          const step = Math.floor(dur / parts);

          for (let i=0;i<parts;i++){
            const a = sTs + i*step;
            const b = (i===parts-1) ? eTs : (sTs + (i+1)*step);
            draft.slots.push({
              start: new Date(a).toISOString().slice(0,16),
              end: new Date(b).toISOString().slice(0,16),
              min: parseInt(document.getElementById('min_volunteers').value || '0',10),
              max: ''
            });
          }
          renderDraft();
        }

        function draftSplitMinutes(){
          const minutes = parseInt(document.getElementById('draftMinutes').value || '120',10);
          const step = Math.max(15, minutes) * 60 * 1000;

          const {s,e} = getEnvLocal();
          const sTs = toTs(s), eTs = toTs(e);
          if (!s || !e || !isFinite(sTs) || !isFinite(eTs) || eTs <= sTs) return;

          draft.slots = [];
          for (let t=sTs; t<eTs; t+=step){
            const a = t;
            const b = Math.min(eTs, t+step);
            if (b <= a) break;
            draft.slots.push({
              start: new Date(a).toISOString().slice(0,16),
              end: new Date(b).toISOString().slice(0,16),
              min: parseInt(document.getElementById('min_volunteers').value || '0',10),
              max: ''
            });
          }
          renderDraft();
        }

        function draftSplitMorningAfternoon(){
          // défaut 13-14
          const {s,e} = getEnvLocal();
          if (!s || !e) return;
          const sDate = new Date(s);
          const eDate = new Date(e);
          if (eDate <= sDate) return;

          const mid1 = new Date(sDate); mid1.setHours(13,0,0,0);
          const mid2 = new Date(sDate); mid2.setHours(14,0,0,0);

          // fallback 2 égaux si incohérent
          if (mid1 <= sDate || mid2 >= eDate){
            draftSplitEqual(2);
            return;
          }

          draft.slots = [
            { start: s, end: mid1.toISOString().slice(0,16), min: parseInt(document.getElementById('min_volunteers').value||'0',10), max: '' },
            { start: mid2.toISOString().slice(0,16), end: e, min: parseInt(document.getElementById('min_volunteers').value||'0',10), max: '' }
          ];
          renderDraft();
        }

        // auto-ajust fin si incohérente
        (function(){
          const start = document.getElementById('start_datetime');
          const end = document.getElementById('end_datetime');
          start.addEventListener('change', () => {
            const sTs = Date.parse(start.value);
            const eTs = Date.parse(end.value);
            if (!end.value || !isFinite(eTs) || eTs <= sTs){
              // +1h
              const d = new Date(sTs + 60*60*1000);
              end.value = d.toISOString().slice(0,16);
            }
          });
          renderDraft();
        })();
      </script>

    <?php else: ?>

      <!-- Découpage rapide côté event existant -->
      <div class="split-row" style="margin-bottom:12px;">
        <form method="post" style="margin:0;">
          <input type="hidden" name="slot_action" value="split_morning_afternoon">
          <button type="submit" class="secondary" onclick="return confirm('Remplacer tous les créneaux par matin/aprem ? (cela supprime les inscriptions sur créneaux)');">Découper matin/aprem</button>
        </form>

        <form method="post" style="margin:0;">
          <input type="hidden" name="slot_action" value="split_equal">
          <input type="hidden" name="split_parts" value="2">
          <button type="submit" class="secondary" onclick="return confirm('Remplacer tous les créneaux par 2 créneaux égaux ?');">2 créneaux</button>
        </form>

        <form method="post" style="margin:0;">
          <input type="hidden" name="slot_action" value="split_equal">
          <input type="hidden" name="split_parts" value="3">
          <button type="submit" class="secondary" onclick="return confirm('Remplacer tous les créneaux par 3 créneaux égaux ?');">3 créneaux</button>
        </form>

        <form method="post" style="margin:0;">
          <input type="hidden" name="slot_action" value="split_equal">
          <input type="hidden" name="split_parts" value="4">
          <button type="submit" class="secondary" onclick="return confirm('Remplacer tous les créneaux par 4 créneaux égaux ?');">4 créneaux</button>
        </form>

        <form method="post" style="margin:0; display:flex; gap:8px; align-items:center;">
          <input type="hidden" name="slot_action" value="split_minutes">
          <span class="muted" style="font-weight:700;">Toutes les</span>
          <input type="number" name="split_minutes" value="120" min="15" step="15">
          <span class="muted" style="font-weight:700;">minutes</span>
          <button type="submit" class="secondary" onclick="return confirm('Remplacer tous les créneaux par un découpage en minutes ?');">Découper</button>
        </form>
      </div>

      <!-- Actions de masse -->
      <div class="split-row" style="margin-bottom:12px;">
        <form method="post" style="margin:0;">
          <input type="hidden" name="slot_action" value="normalize">
          <button type="submit" class="secondary">Trier / normaliser</button>
        </form>

        <form method="post" style="margin:0;">
          <input type="hidden" name="slot_action" value="delete_all">
          <button type="submit" class="danger" onclick="return confirm('Supprimer tous les créneaux + inscriptions sur créneaux ?');">Supprimer tous les créneaux</button>
        </form>
      </div>

      <!-- Liste des slots -->
      <?php if (empty($slots)): ?>
        <p class="muted" style="margin:0 0 10px;">Aucun créneau.</p>
      <?php else: ?>

        <form method="post" class="slot-card" style="margin-bottom:12px; align-items:center;">
          <input type="hidden" name="slot_action" value="merge_two">
          <div class="slot-left">
            <div style="font-weight:900;">Fusionner deux créneaux consécutifs</div>
            <div class="muted" style="font-weight:700;">Choisis A puis B (A finit quand B commence).</div>
          </div>
          <div style="display:flex; gap:8px; flex-wrap:wrap; align-items:center; justify-content:flex-end;">
            <select name="merge_a" required>
              <option value="">Slot A…</option>
              <?php foreach ($slots as $sl): ?>
                <?php
                  $s = new DateTime($sl['start_datetime']); $e = new DateTime($sl['end_datetime']);
                  $lbl = $s->format('H:i') . ' → ' . $e->format('H:i');
                ?>
                <option value="<?= (int)$sl['id'] ?>"><?= h($lbl) ?></option>
              <?php endforeach; ?>
            </select>
            <select name="merge_b" required>
              <option value="">Slot B…</option>
              <?php foreach ($slots as $sl): ?>
                <?php
                  $s = new DateTime($sl['start_datetime']); $e = new DateTime($sl['end_datetime']);
                  $lbl = $s->format('H:i') . ' → ' . $e->format('H:i');
                ?>
                <option value="<?= (int)$sl['id'] ?>"><?= h($lbl) ?></option>
              <?php endforeach; ?>
            </select>
            <button type="submit" class="secondary" onclick="return confirm('Fusionner ces 2 créneaux ?');">Fusionner</button>
          </div>
        </form>

        <div style="display:flex; flex-direction:column; gap:10px;">
          <?php foreach ($slots as $sl): ?>
            <?php
              $sid = (int)$sl['id'];
              $s = new DateTime($sl['start_datetime']);
              $e = new DateTime($sl['end_datetime']);
              $count = (int)($slotCounts[$sid] ?? 0);
              $minV = (int)($sl['min_volunteers'] ?? 0);
              $maxV = array_key_exists('max_volunteers',$sl) ? $sl['max_volunteers'] : null;
              $maxTxt = ($maxV === null) ? 'Illimité' : (int)$maxV;

              $isFull = ($maxV !== null && $count >= (int)$maxV);
            ?>
            <div class="slot-card">
              <div class="slot-left">
                <div class="slot-time"><?= h($s->format('H:i')) ?> → <?= h($e->format('H:i')) ?></div>
                <div class="slot-meta">
                  Inscrits : <strong><?= (int)$count ?></strong> / <?= h($maxTxt) ?>
                  · Min : <?= (int)$minV ?>
                  · Max : <?= h($maxTxt) ?>
                </div>
                <?php if ($isFull): ?>
                  <div style="margin-top:8px;"><span class="badge full">Complet</span></div>
                <?php endif; ?>
              </div>

              <div class="slot-actions">
                <details>
                  <summary class="secondary" style="cursor:pointer; list-style:none;">
                    <button type="button" class="secondary">Éditer</button>
                  </summary>
                  <div style="margin-top:10px;">
                    <form method="post" style="display:grid; gap:8px; min-width:300px;">
                      <input type="hidden" name="slot_action" value="update_one">
                      <input type="hidden" name="slot_id" value="<?= (int)$sid ?>">
                      <div style="display:grid; grid-template-columns:1fr 1fr; gap:8px;">
                        <div>
                          <label style="font-weight:800;">Début</label>
                          <input type="datetime-local" name="slot_start" value="<?= h(toDatetimeLocal($sl['start_datetime'])) ?>" required>
                        </div>
                        <div>
                          <label style="font-weight:800;">Fin</label>
                          <input type="datetime-local" name="slot_end" value="<?= h(toDatetimeLocal($sl['end_datetime'])) ?>" required>
                        </div>
                      </div>
                      <div style="display:grid; grid-template-columns:1fr 1fr; gap:8px;">
                        <div>
                          <label style="font-weight:800;">Min</label>
                          <input type="number" name="slot_min" min="0" value="<?= (int)$minV ?>">
                        </div>
                        <div>
                          <label style="font-weight:800;">Max</label>
                          <input type="number" name="slot_max" min="0" placeholder="Illimité" value="<?= ($maxV===null)?'':(int)$maxV ?>">
                        </div>
                      </div>
                      <button type="submit" class="secondary">Enregistrer le créneau</button>
                    </form>
                  </div>
                </details>

                <form method="post" style="margin:0;">
                  <input type="hidden" name="slot_action" value="delete_one">
                  <input type="hidden" name="slot_id" value="<?= (int)$sid ?>">
                  <button type="submit" class="danger" onclick="return confirm('Supprimer ce créneau (et ses inscriptions) ?');">Supprimer</button>
                </form>
              </div>
            </div>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>

      <div class="hr-dots"></div>

      <!-- Ajout slot -->
      <div class="section-title">Ajouter un créneau</div>
      <form method="post" class="admin-grid" style="grid-template-columns:1fr 1fr; gap:12px; align-items:end;">
        <input type="hidden" name="slot_action" value="add">
        <input type="hidden" name="auto_extend_envelope" value="1">

        <div class="field">
          <label>Début</label>
          <input type="datetime-local" name="slot_start" value="<?= h(toDatetimeLocal($event['start_datetime'] ?? '')) ?>" required>
        </div>
        <div class="field">
          <label>Fin</label>
          <input type="datetime-local" name="slot_end" value="<?= h(toDatetimeLocal($event['end_datetime'] ?? '')) ?>" required>
        </div>

        <div class="field">
          <label>Min</label>
          <input type="number" name="slot_min" min="0" value="<?= (int)($event['min_volunteers'] ?? 0) ?>">
        </div>
        <div class="field">
          <label>Max</label>
          <input type="number" name="slot_max" min="0" placeholder="Illimité" value="">
          <small>Laisse vide = illimité.</small>
        </div>

        <div class="btn-row" style="grid-column:1 / -1;">
          <button type="submit" class="primary">Ajouter le créneau</button>
        </div>
      </form>

      <script>
        // auto-ajust fin si incohérente (admin)
        (function(){
          const start = document.getElementById('start_datetime');
          const end = document.getElementById('end_datetime');
          start.addEventListener('change', () => {
            const sTs = Date.parse(start.value);
            const eTs = Date.parse(end.value);
            if (!end.value || !isFinite(eTs) || eTs <= sTs){
              const d = new Date(sTs + 60*60*1000);
              end.value = d.toISOString().slice(0,16);
            }
          });
        })();
      </script>

    <?php endif; ?>
  </div>
<?php endif; ?>

<?php
$content = ob_get_clean();
include __DIR__ . '/../includes/layout.php';
