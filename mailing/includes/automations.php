<?php
declare(strict_types=1);

/**
 * mailing/includes/automations.php — Messages automatiques du module Mailing (règles personnalisables).
 *
 * Une règle = un déclencheur (avant un créneau, après un créneau, à l'inscription, nouveau bénévole)
 * + un délai + une heure d'envoi + des filtres (types d'évènement, groupes) + un message.
 * Le cron (mailing/cron/send_queue.php) appelle mailing_auto_run() à chaque passage.
 * Un journal (mailing_auto_log, type « r<id> ») empêche tout double envoi pour le même fait.
 *
 * Variables utilisables dans le sujet et les textes : {prenom} {evenement} {date} {horaires} {planning_url}.
 * Toutes les heures sont calculées dans le fuseau de l'association (MAILING_TZ), quel que soit celui du serveur.
 */

require_once __DIR__ . '/mailing.php';

const MAILING_TZ = 'Europe/Paris';

function mailing_now(): DateTimeImmutable {
    return new DateTimeImmutable('now', new DateTimeZone(MAILING_TZ));
}

/** @return array<string,array<string,mixed>> */
function mailing_trigger_defs(): array {
    $planningBtn = ['type' => 'button', 'label' => 'Ouvrir le planning', 'url' => '{planning_url}'];
    return [
        'before_slot' => [
            'label' => 'Avant un créneau', 'channel' => 'planning', 'delay' => true, 'hour' => true, 'filters' => true,
            'desc' => "Un rappel envoyé aux bénévoles inscrits, quelques jours (ou la veille) avant leur créneau.",
            'name' => 'Rappel avant un créneau', 'delay_default' => 1, 'hour_default' => 18,
            'vars' => '{prenom}, {evenement}, {date}, {horaires}, {planning_url}',
            'subject' => 'Demain : {evenement}',
            'blocks' => [
                ['type' => 'title', 'text' => 'Rendez-vous bientôt !'],
                ['type' => 'text', 'text' => "Bonjour {prenom},\n\nPetit rappel : vous êtes inscrit(e) à **{evenement}**, {date}, {horaires}.\n\nSi vous ne pouvez finalement pas venir, merci de vous désinscrire du créneau sur le planning pour libérer votre place.\n\nMerci pour votre engagement !"],
                $planningBtn,
            ],
        ],
        'after_slot' => [
            'label' => 'Après un créneau', 'channel' => 'planning', 'delay' => true, 'hour' => true, 'filters' => true,
            'desc' => "Un message de remerciement (ou de suivi) envoyé aux bénévoles après leur participation.",
            'name' => 'Remerciement après un créneau', 'delay_default' => 1, 'hour_default' => 10,
            'vars' => '{prenom}, {evenement}, {date}, {horaires}, {planning_url}',
            'subject' => 'Merci pour {evenement}',
            'blocks' => [
                ['type' => 'title', 'text' => 'Merci {prenom} !'],
                ['type' => 'text', 'text' => "Merci d'avoir participé à **{evenement}** ({date}).\n\nGrâce à vous, l'association peut continuer à aider. Si vous souhaitez vous inscrire à un prochain créneau, le planning est ouvert."],
                $planningBtn,
            ],
        ],
        'on_registration' => [
            'label' => "À l'inscription", 'channel' => 'planning', 'delay' => false, 'hour' => false, 'filters' => true,
            'desc' => "Une confirmation envoyée tout de suite quand un bénévole s'inscrit à un créneau.",
            'name' => "Confirmation d'inscription", 'delay_default' => 0, 'hour_default' => 0,
            'vars' => '{prenom}, {evenement}, {date}, {horaires}, {planning_url}',
            'subject' => 'Inscription confirmée : {evenement}',
            'blocks' => [
                ['type' => 'title', 'text' => 'Votre inscription est confirmée'],
                ['type' => 'text', 'text' => "Bonjour {prenom},\n\nVous êtes bien inscrit(e) à **{evenement}**, {date}, {horaires}.\n\nSi vous avez un empêchement, merci de vous désinscrire depuis le planning."],
                $planningBtn,
            ],
        ],
        'new_volunteer' => [
            'label' => 'Nouveau bénévole', 'channel' => 'mailing', 'delay' => false, 'hour' => false, 'filters' => false,
            'desc' => "Un message de bienvenue envoyé dès qu'une personne est ajoutée comme bénévole.",
            'name' => 'Bienvenue aux nouveaux bénévoles', 'delay_default' => 0, 'hour_default' => 0,
            'vars' => '{prenom}, {planning_url}',
            'subject' => 'Bienvenue chez Touraine-Ukraine',
            'blocks' => [
                ['type' => 'title', 'text' => 'Bienvenue {prenom} !'],
                ['type' => 'text', 'text' => "Merci de rejoindre l'équipe des bénévoles de Touraine-Ukraine.\n\nPour participer, ouvrez le planning, choisissez un évènement et inscrivez-vous sur le créneau qui vous convient.\n\nÀ très bientôt !"],
                $planningBtn,
            ],
        ],
    ];
}

function mailing_auto_tables_ready(PDO $pdo): bool {
    try {
        $pdo->query("SELECT 1 FROM mailing_rules LIMIT 1");
        $pdo->query("SELECT 1 FROM mailing_auto_log LIMIT 1");
        return true;
    } catch (Throwable) {
        return false;
    }
}

/** Valeurs d'exemple pour un message de test. @return array<string,string> */
function mailing_trigger_sample_vars(string $trigger, string $firstName): array {
    $site = suite_mail_abs_url('');
    [$dow, $dm] = mailing_fr_date(strtotime('+1 day 09:00'));
    $v = ['prenom' => $firstName, 'planning_url' => $site . '/planning/events.php'];
    if (mailing_trigger_defs()[$trigger]['filters'] ?? false) {
        $v += ['evenement' => 'Chargement du convoi', 'date' => $dow . ' ' . $dm, 'horaires' => 'de 9h à 12h'];
    }
    return $v;
}

/* ---------- Règles ---------- */

/**
 * @param array<string,mixed> $r ligne de mailing_rules
 * @return array{id:int,name:string,trigger:string,delay:int,hour:int,types:list<string>,groups:list<int>,enabled:bool,subject:string,blocks:list<array<string,mixed>>,site_url:string,customized:bool,enabled_at:?string}
 */
function mailing_rule_normalize(array $r): array {
    $defs = mailing_trigger_defs();
    $trigger = isset($defs[(string)$r['trigger_type']]) ? (string)$r['trigger_type'] : 'before_slot';
    $d = $defs[$trigger];
    $f = json_decode((string)($r['filters'] ?? ''), true);
    $f = is_array($f) ? $f : [];
    $blocks = $r['blocks'] !== null && $r['blocks'] !== '' ? mailing_blocks_decode((string)$r['blocks']) : $d['blocks'];
    return [
        'id'         => (int)$r['id'],
        'name'       => (string)$r['name'],
        'trigger'    => $trigger,
        'delay'      => (int)$r['delay_days'],
        'hour'       => (int)$r['send_hour'],
        'types'      => array_values(array_filter(array_map('strval', (array)($f['types'] ?? [])), static fn(string $t): bool => $t !== '')),
        'groups'     => array_values(array_filter(array_map('intval', (array)($f['groups'] ?? [])), static fn(int $g): bool => $g > 0)),
        'enabled'    => !empty($r['enabled']),
        'subject'    => (string)($r['subject'] ?? '') !== '' ? (string)$r['subject'] : $d['subject'],
        'blocks'     => $blocks,
        'site_url'   => (string)($r['site_url'] ?? ''),
        'customized' => $r['blocks'] !== null && $r['blocks'] !== '',
        'enabled_at' => $r['enabled_at'] !== null ? (string)$r['enabled_at'] : null,
    ];
}

/** @return list<array<string,mixed>> */
function mailing_rules_list(PDO $pdo): array {
    return array_map('mailing_rule_normalize', $pdo->query("SELECT * FROM mailing_rules ORDER BY id")->fetchAll(PDO::FETCH_ASSOC));
}

function mailing_rule_get(PDO $pdo, int $id): ?array {
    $st = $pdo->prepare("SELECT * FROM mailing_rules WHERE id = ?");
    $st->execute([$id]);
    $r = $st->fetch(PDO::FETCH_ASSOC);
    return $r ? mailing_rule_normalize($r) : null;
}

/** Crée (id null) ou met à jour une règle. Le déclencheur ne change plus après la création. Renvoie l'id. */
function mailing_rule_save(PDO $pdo, ?int $id, array $d): int {
    $filters = json_encode(['types' => array_values($d['types']), 'groups' => array_values($d['groups'])], JSON_UNESCAPED_UNICODE);
    $blocks = json_encode($d['blocks'], JSON_UNESCAPED_UNICODE);
    if ($id === null) {
        $pdo->prepare("INSERT INTO mailing_rules (name, trigger_type, delay_days, send_hour, filters, subject, blocks) VALUES (?, ?, ?, ?, ?, ?, ?)")
            ->execute([mb_substr($d['name'], 0, 80), $d['trigger'], $d['delay'], $d['hour'], $filters, mb_substr($d['subject'], 0, 190), $blocks]);
        return (int)$pdo->lastInsertId();
    }
    $pdo->prepare("UPDATE mailing_rules SET name = ?, delay_days = ?, send_hour = ?, filters = ?, subject = ?, blocks = ? WHERE id = ?")
        ->execute([mb_substr($d['name'], 0, 80), $d['delay'], $d['hour'], $filters, mb_substr($d['subject'], 0, 190), $blocks, $id]);
    return $id;
}

function mailing_rule_reset_content(PDO $pdo, int $id): void {
    $pdo->prepare("UPDATE mailing_rules SET subject = NULL, blocks = NULL WHERE id = ?")->execute([$id]);
}

function mailing_rule_delete(PDO $pdo, int $id): void {
    $pdo->prepare("DELETE FROM mailing_rules WHERE id = ?")->execute([$id]);
}

/** Copie désactivée d'une règle. */
function mailing_rule_duplicate(PDO $pdo, int $id): ?int {
    $r = mailing_rule_get($pdo, $id);
    if (!$r) return null;
    return mailing_rule_save($pdo, null, [
        'name' => mb_substr($r['name'], 0, 70) . ' (copie)', 'trigger' => $r['trigger'], 'delay' => $r['delay'], 'hour' => $r['hour'],
        'types' => $r['types'], 'groups' => $r['groups'], 'subject' => $r['subject'], 'blocks' => $r['blocks'],
    ]);
}

/**
 * Active ou désactive une règle. À l'activation, retient l'adresse du site (les liens du cron en dépendent) et, pour
 * « Nouveau bénévole », considère les bénévoles existants comme déjà accueillis : seuls les suivants reçoivent le message.
 */
function mailing_rule_set_enabled(PDO $pdo, int $id, bool $on, string $siteUrl): void {
    if (!$on) {
        $pdo->prepare("UPDATE mailing_rules SET enabled = 0 WHERE id = ?")->execute([$id]);
        return;
    }
    $pdo->prepare("UPDATE mailing_rules SET enabled = 1, site_url = ?, enabled_at = NOW() WHERE id = ?")->execute([$siteUrl, $id]);
    $st = $pdo->prepare("SELECT trigger_type FROM mailing_rules WHERE id = ?");
    $st->execute([$id]);
    if ($st->fetchColumn() === 'new_volunteer') {
        $pdo->prepare("INSERT IGNORE INTO mailing_auto_log (type, ref, email, status)
            SELECT ?, CONCAT('user:', id), COALESCE(email, ''), 'skipped' FROM users WHERE is_active = 1 AND deleted_at IS NULL")->execute(['r' . $id]);
    }
}

/**
 * Phrase décrivant quand part le message.
 * @param array<string,string> $typeLabels code => libellé des types d'évènement
 * @param array<int,string> $groupLabels id => nom des groupes
 */
function mailing_rule_summary(array $r, array $typeLabels = [], array $groupLabels = []): string {
    $h = $r['hour'] . 'h';
    $n = $r['delay'];
    switch ($r['trigger']) {
        case 'before_slot':
            $s = $n === 0 ? "Le jour même, à partir de $h" : ($n === 1 ? "La veille, à partir de $h" : "$n jours avant, à partir de $h");
            $s .= ", pour chaque bénévole inscrit";
            break;
        case 'after_slot':
            $s = $n === 0 ? "Le jour même, à partir de $h, une fois le créneau terminé" : ($n === 1 ? "Le lendemain, à partir de $h" : "$n jours après, à partir de $h");
            $s .= ", pour chaque bénévole qui était inscrit";
            break;
        case 'on_registration':
            $s = "Tout de suite, dès qu'un bénévole s'inscrit à un créneau";
            break;
        default:
            $s = "Tout de suite, dès qu'une personne est ajoutée comme bénévole";
    }
    if ($r['types']) $s .= ' · Évènements : ' . implode(', ', array_map(static fn(string $c): string => $typeLabels[$c] ?? $c, $r['types']));
    if ($r['groups']) $s .= ' · Groupes : ' . implode(', ', array_map(static fn(int $g): string => $groupLabels[$g] ?? ('#' . $g), $r['groups']));
    return $s;
}

/** @return array<string,string> code => libellé (vide si la table n'existe pas) */
function mailing_event_type_labels(PDO $pdo): array {
    try {
        $out = [];
        foreach ($pdo->query("SELECT code, label FROM planning_event_types WHERE is_active = 1 ORDER BY sort_order, label")->fetchAll(PDO::FETCH_ASSOC) as $r) $out[(string)$r['code']] = (string)$r['label'];
        return $out;
    } catch (Throwable) {
        return [];
    }
}

/** @return array<int,string> id => nom (vide si la table n'existe pas) */
function mailing_group_labels(PDO $pdo): array {
    try {
        $out = [];
        foreach ($pdo->query("SELECT id, name FROM volunteer_groups ORDER BY sort_order, name")->fetchAll(PDO::FETCH_ASSOC) as $r) $out[(int)$r['id']] = (string)$r['name'];
        return $out;
    } catch (Throwable) {
        return [];
    }
}

function mailing_auto_fill(string $text, array $vars): string {
    $map = [];
    foreach ($vars as $k => $v) $map['{' . $k . '}'] = (string)$v;
    return strtr($text, $map);
}

/** @param mixed $node */
function mailing_auto_fill_deep($node, array $vars) {
    if (is_string($node)) return mailing_auto_fill($node, $vars);
    if (is_array($node)) return array_map(static fn($n) => mailing_auto_fill_deep($n, $vars), $node);
    return $node;
}

/** Message prêt à envoyer. @return array{subject:string,text:string,html:string} */
function mailing_auto_build(string $subject, array $blocks, array $vars, string $siteUrl, string $token): array {
    $filled = mailing_auto_fill_deep($blocks, $vars);
    $c = ['subject' => mailing_auto_fill($subject, $vars), 'preheader' => '', 'blocks' => json_encode($filled, JSON_UNESCAPED_UNICODE)];
    $url = mailing_unsubscribe_url($siteUrl, $token);
    return ['subject' => $c['subject'], 'text' => mailing_render_text($c, $url), 'html' => mailing_render_html($c, $url, $siteUrl)];
}

/**
 * Messages à envoyer maintenant pour une règle (déjà envoyés exclus). $ignoreHour : pour compter ce qui est en attente
 * sans tenir compte de l'heure d'envoi.
 * @return list<array{ref:string,email:string,vars:array<string,string>}>
 */
function mailing_rule_due(PDO $pdo, array $rule, string $siteUrl, int $limit = 500, bool $ignoreHour = false): array {
    $limit = max(1, $limit);
    $now = mailing_now();
    $trigger = $rule['trigger'];
    $planning = rtrim($siteUrl, '/') . '/planning/events.php';
    $logType = 'r' . $rule['id'];
    $out = [];

    if ($trigger === 'new_volunteer') {
        $st = $pdo->prepare("SELECT u.id, u.first_name, u.email FROM users u
            WHERE u.is_active = 1 AND u.deleted_at IS NULL
              AND NOT EXISTS (SELECT 1 FROM mailing_auto_log l WHERE l.type = ? AND l.ref = CONCAT('user:', u.id) AND l.status <> 'retry')
            ORDER BY u.id LIMIT $limit");
        $st->execute([$logType]);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $out[] = ['ref' => 'user:' . $r['id'], 'email' => (string)$r['email'], 'vars' => ['prenom' => trim((string)$r['first_name']), 'planning_url' => $planning]];
        }
        return $out;
    }

    if (!$ignoreHour && in_array($trigger, ['before_slot', 'after_slot'], true) && (int)$now->format('G') < $rule['hour']) return [];

    $when = 'COALESCE(s.start_datetime, e.start_datetime)';
    $whenEnd = 'COALESCE(s.end_datetime, s.start_datetime, e.end_datetime, e.start_datetime)';
    $args = [$logType];
    $cond = '';
    if ($trigger === 'before_slot') {
        $cond = "DATE($when) = ? AND $when > ?";
        $args[] = $now->modify('+' . $rule['delay'] . ' day')->format('Y-m-d');
        $args[] = $now->format('Y-m-d H:i:s');
    } elseif ($trigger === 'after_slot') {
        $cond = "DATE($whenEnd) = ? AND $whenEnd < ?";
        $args[] = $now->modify('-' . $rule['delay'] . ' day')->format('Y-m-d');
        $args[] = $now->format('Y-m-d H:i:s');
    } else { // on_registration
        $cond = "r.created_at >= ? AND $whenEnd > ?";
        $args[] = (string)($rule['enabled_at'] ?? '1970-01-01 00:00:00');
        $args[] = $now->format('Y-m-d H:i:s');
    }
    $filters = '';
    if ($rule['types']) {
        $filters .= ' AND e.event_type IN (' . implode(',', array_fill(0, count($rule['types']), '?')) . ')';
        array_push($args, ...$rule['types']);
    }
    if ($rule['groups']) {
        $filters .= ' AND EXISTS (SELECT 1 FROM volunteer_group_members gm WHERE gm.volunteer_id = u.id AND gm.group_id IN (' . implode(',', array_fill(0, count($rule['groups']), '?')) . '))';
        array_push($args, ...$rule['groups']);
    }
    $refSql = $trigger === 'on_registration' ? "CONCAT('slot:', r.event_id, ':', COALESCE(r.slot_id, 0), ':', r.volunteer_id)" : "CONCAT('reg:', r.id)";
    // L'argument du journal passe en premier dans la requête (NOT EXISTS), puis les conditions de date et les filtres.
    $sql = "SELECT r.id AS reg_id, $refSql AS ref, u.first_name, u.email, e.title, $when AS st, COALESCE(s.end_datetime, e.end_datetime) AS en
        FROM planning_event_registrations r
        JOIN planning_events e ON e.id = r.event_id AND e.is_cancelled = 0
        JOIN users u ON u.id = r.volunteer_id AND u.is_active = 1 AND u.deleted_at IS NULL
        LEFT JOIN planning_event_slots s ON s.id = r.slot_id
        WHERE r.status = 'present' AND $cond $filters
          AND NOT EXISTS (SELECT 1 FROM mailing_auto_log l WHERE l.type = ? AND l.ref = $refSql AND l.status <> 'retry')
        ORDER BY r.id LIMIT $limit";
    // Ordre réel des « ? » : conditions de date, filtres, puis type du journal.
    $logArg = array_shift($args);
    $args[] = $logArg;
    $st = $pdo->prepare($sql);
    $st->execute($args);
    $seen = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
        if (isset($seen[$r['ref']])) continue; // même personne, même créneau inscrite deux fois
        $seen[$r['ref']] = true;
        $ts = strtotime((string)$r['st']) ?: 0;
        $te = $r['en'] ? (strtotime((string)$r['en']) ?: null) : null;
        [$dow, $dm] = mailing_fr_date($ts);
        $out[] = ['ref' => (string)$r['ref'], 'email' => (string)$r['email'], 'vars' => [
            'prenom' => trim((string)$r['first_name']), 'evenement' => (string)$r['title'],
            'date' => $dow . ' ' . $dm, 'horaires' => mailing_fr_hours($ts, $te), 'planning_url' => $planning]];
    }
    return $out;
}

/**
 * Envoie au plus $limit messages automatiques en attente. Renvoie le nombre de messages traités.
 * @return array{sent:int,failed:int}
 */
function mailing_auto_run(PDO $pdo, int $limit): array {
    $res = ['sent' => 0, 'failed' => 0];
    $suppressed = mailing_suppressed($pdo);
    $log = $pdo->prepare("INSERT INTO mailing_auto_log (type, ref, email, token, status, attempts, error, sent_at)
        VALUES (?, ?, ?, ?, ?, ?, ?, IF(? = 'sent', NOW(), NULL))
        ON DUPLICATE KEY UPDATE email = VALUES(email), token = VALUES(token), status = VALUES(status),
            attempts = attempts + 1, error = VALUES(error), sent_at = VALUES(sent_at)");
    $defs = mailing_trigger_defs();

    foreach (mailing_rules_list($pdo) as $rule) {
        if ($limit <= 0) break;
        $def = $defs[$rule['trigger']];
        if (!$rule['enabled'] || $rule['site_url'] === '' || !suite_mail_ready($def['channel'])) continue;
        $type = 'r' . $rule['id'];

        foreach (mailing_rule_due($pdo, $rule, $rule['site_url'], $limit) as $item) {
            $email = mb_strtolower(trim($item['email']));
            if (!filter_var($email, FILTER_VALIDATE_EMAIL) || isset($suppressed[$email])) {
                $log->execute([$type, $item['ref'], $email, null, 'skipped', 0, null, 'skipped']);
                continue;
            }
            $token = bin2hex(random_bytes(16));
            $m = mailing_auto_build($rule['subject'], $rule['blocks'], $item['vars'], $rule['site_url'], $token);
            $url = mailing_unsubscribe_url($rule['site_url'], $token);
            $err = suite_mail_send($email, $m['subject'], $m['text'], $m['html'], [], $def['channel'],
                ['List-Unsubscribe' => '<' . $url . '>', 'List-Unsubscribe-Post' => 'List-Unsubscribe=One-Click']);
            $limit--;
            if ($err === null) {
                $log->execute([$type, $item['ref'], $email, $token, 'sent', 1, null, 'sent']);
                $res['sent']++;
            } else {
                $prev = $pdo->prepare("SELECT attempts FROM mailing_auto_log WHERE type = ? AND ref = ?");
                $prev->execute([$type, $item['ref']]);
                $status = ((int)$prev->fetchColumn() + 1) >= MAILING_MAX_ATTEMPTS ? 'failed' : 'retry';
                $log->execute([$type, $item['ref'], $email, null, $status, 1, mb_substr($err, 0, 255), $status]);
                $res['failed']++;
            }
            if ($limit <= 0) break;
        }
    }
    return $res;
}

/** @return array{pending:int,sent_month:int} chiffres affichés sur la carte d'une règle */
function mailing_rule_stats(PDO $pdo, array $rule): array {
    $m = $pdo->prepare("SELECT COUNT(*) FROM mailing_auto_log WHERE type = ? AND status = 'sent' AND sent_at >= ?");
    $m->execute(['r' . $rule['id'], mailing_now()->format('Y-m-01 00:00:00')]);
    $pending = $rule['enabled'] && $rule['site_url'] !== '' ? count(mailing_rule_due($pdo, $rule, $rule['site_url'], 5000, true)) : 0;
    return ['pending' => $pending, 'sent_month' => (int)$m->fetchColumn()];
}
