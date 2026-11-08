<?php
declare(strict_types=1);

/**
 * mailing/includes/automations.php — Messages automatiques du module Mailing.
 *
 * Deux messages : « reminder » (rappel la veille d'un créneau, à partir de 18h) et « welcome » (bienvenue à un
 * nouveau bénévole). Le cron (mailing/cron/send_queue.php) appelle mailing_auto_run() à chaque passage.
 * Un journal (mailing_auto_log) empêche tout double envoi pour le même fait.
 *
 * Variables utilisables dans le sujet et les textes : {prenom} {evenement} {date} {horaires} {planning_url}.
 */

require_once __DIR__ . '/mailing.php';

const MAILING_REMINDER_HOUR = 18;

/** @return array<string,array{label:string,desc:string,channel:string,subject:string,blocks:list<array<string,mixed>>,vars:string}> */
function mailing_auto_definitions(): array {
    return [
        'reminder' => [
            'label'   => "Rappel la veille d'un créneau",
            'desc'    => "À partir de 18h la veille, chaque bénévole inscrit reçoit un rappel avec l'évènement, la date et l'horaire.",
            'channel' => 'planning',
            'subject' => 'Demain : {evenement}',
            'vars'    => '{prenom}, {evenement}, {date}, {horaires}, {planning_url}',
            'blocks'  => [
                ['type' => 'title', 'text' => 'Rendez-vous demain !'],
                ['type' => 'text', 'text' => "Bonjour {prenom},\n\nPetit rappel : vous êtes inscrit(e) à **{evenement}**, {date}, {horaires}.\n\nSi vous ne pouvez finalement pas venir, merci de vous désinscrire du créneau sur le planning pour libérer votre place.\n\nMerci pour votre engagement !"],
                ['type' => 'button', 'label' => 'Ouvrir le planning', 'url' => '{planning_url}'],
            ],
        ],
        'welcome' => [
            'label'   => 'Bienvenue aux nouveaux bénévoles',
            'desc'    => "Dès qu'une personne est ajoutée comme bénévole, elle reçoit un message lui expliquant comment s'inscrire à un créneau.",
            'channel' => 'mailing',
            'subject' => 'Bienvenue chez Touraine-Ukraine',
            'vars'    => '{prenom}, {planning_url}',
            'blocks'  => [
                ['type' => 'title', 'text' => 'Bienvenue {prenom} !'],
                ['type' => 'text', 'text' => "Merci de rejoindre l'équipe des bénévoles de Touraine-Ukraine.\n\nPour participer, ouvrez le planning, choisissez un évènement et inscrivez-vous sur le créneau qui vous convient. Vous recevrez un rappel la veille.\n\nÀ très bientôt !"],
                ['type' => 'button', 'label' => 'Ouvrir le planning', 'url' => '{planning_url}'],
            ],
        ],
    ];
}

function mailing_auto_tables_ready(PDO $pdo): bool {
    try {
        $pdo->query("SELECT 1 FROM mailing_automations LIMIT 1");
        $pdo->query("SELECT 1 FROM mailing_auto_log LIMIT 1");
        return true;
    } catch (Throwable) {
        return false;
    }
}

/**
 * Réglage d'un message automatique : valeurs enregistrées, sinon texte par défaut.
 * @return array{enabled:bool,subject:string,blocks:list<array<string,mixed>>,site_url:string,customized:bool}
 */
function mailing_auto_get(PDO $pdo, string $type): array {
    $def = mailing_auto_definitions()[$type];
    $st = $pdo->prepare("SELECT * FROM mailing_automations WHERE type = ?");
    $st->execute([$type]);
    $r = $st->fetch(PDO::FETCH_ASSOC) ?: [];
    $blocks = isset($r['blocks']) && $r['blocks'] !== null ? mailing_blocks_decode((string)$r['blocks']) : $def['blocks'];
    return [
        'enabled'    => !empty($r['enabled']),
        'subject'    => (string)($r['subject'] ?? '') !== '' ? (string)$r['subject'] : $def['subject'],
        'blocks'     => $blocks,
        'site_url'   => (string)($r['site_url'] ?? ''),
        'customized' => isset($r['blocks']) && $r['blocks'] !== null,
    ];
}

function mailing_auto_save_content(PDO $pdo, string $type, string $subject, array $blocks): void {
    $pdo->prepare("INSERT INTO mailing_automations (type, subject, blocks) VALUES (?, ?, ?)
        ON DUPLICATE KEY UPDATE subject = VALUES(subject), blocks = VALUES(blocks)")
        ->execute([$type, mb_substr($subject, 0, 190), json_encode($blocks, JSON_UNESCAPED_UNICODE)]);
}

function mailing_auto_reset_content(PDO $pdo, string $type): void {
    $pdo->prepare("UPDATE mailing_automations SET subject = NULL, blocks = NULL WHERE type = ?")->execute([$type]);
}

/**
 * Active ou désactive un message. À l'activation, retient l'adresse du site (les liens du cron en dépendent)
 * et, pour « welcome », considère les bénévoles existants comme déjà accueillis : seuls les suivants reçoivent le message.
 */
function mailing_auto_set_enabled(PDO $pdo, string $type, bool $on, string $siteUrl): void {
    if ($on) {
        $pdo->prepare("INSERT INTO mailing_automations (type, enabled, site_url, enabled_at) VALUES (?, 1, ?, NOW())
            ON DUPLICATE KEY UPDATE enabled = 1, site_url = VALUES(site_url), enabled_at = NOW()")->execute([$type, $siteUrl]);
        if ($type === 'welcome') {
            $pdo->exec("INSERT IGNORE INTO mailing_auto_log (type, ref, email, status)
                SELECT 'welcome', CONCAT('user:', id), COALESCE(email, ''), 'skipped' FROM users WHERE is_active = 1 AND deleted_at IS NULL");
        }
    } else {
        $pdo->prepare("UPDATE mailing_automations SET enabled = 0 WHERE type = ?")->execute([$type]);
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
 * Messages à envoyer maintenant pour un type (déjà envoyés exclus).
 * @return list<array{ref:string,email:string,vars:array<string,string>}>
 */
function mailing_auto_due(PDO $pdo, string $type, string $siteUrl, int $limit = 500): array {
    $limit = max(1, $limit);
    $planning = rtrim($siteUrl, '/') . '/planning/events.php';
    $out = [];
    if ($type === 'reminder') {
        $st = $pdo->prepare("SELECT r.id AS reg_id, u.first_name, u.email, e.title,
                COALESCE(s.start_datetime, e.start_datetime) AS st, COALESCE(s.end_datetime, e.end_datetime) AS en
            FROM planning_event_registrations r
            JOIN planning_events e ON e.id = r.event_id AND e.is_cancelled = 0
            JOIN users u ON u.id = r.volunteer_id AND u.is_active = 1 AND u.deleted_at IS NULL
            LEFT JOIN planning_event_slots s ON s.id = r.slot_id
            WHERE r.status = 'present'
              AND DATE(COALESCE(s.start_datetime, e.start_datetime)) = DATE_ADD(CURDATE(), INTERVAL 1 DAY)
              AND NOT EXISTS (SELECT 1 FROM mailing_auto_log l WHERE l.type = 'reminder' AND l.ref = CONCAT('reg:', r.id) AND l.status <> 'retry')
            ORDER BY r.id LIMIT $limit");
        $st->execute();
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $ts = strtotime((string)$r['st']) ?: 0;
            $te = $r['en'] ? (strtotime((string)$r['en']) ?: null) : null;
            [$dow, $dm] = mailing_fr_date($ts);
            $out[] = ['ref' => 'reg:' . $r['reg_id'], 'email' => (string)$r['email'], 'vars' => [
                'prenom' => trim((string)$r['first_name']), 'evenement' => (string)$r['title'],
                'date' => $dow . ' ' . $dm, 'horaires' => mailing_fr_hours($ts, $te), 'planning_url' => $planning]];
        }
    } elseif ($type === 'welcome') {
        $st = $pdo->prepare("SELECT u.id, u.first_name, u.email FROM users u
            WHERE u.is_active = 1 AND u.deleted_at IS NULL
              AND NOT EXISTS (SELECT 1 FROM mailing_auto_log l WHERE l.type = 'welcome' AND l.ref = CONCAT('user:', u.id) AND l.status <> 'retry')
            ORDER BY u.id LIMIT $limit");
        $st->execute();
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $out[] = ['ref' => 'user:' . $r['id'], 'email' => (string)$r['email'], 'vars' => ['prenom' => trim((string)$r['first_name']), 'planning_url' => $planning]];
        }
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

    foreach (mailing_auto_definitions() as $type => $def) {
        if ($limit <= 0) break;
        $cfg = mailing_auto_get($pdo, $type);
        if (!$cfg['enabled'] || $cfg['site_url'] === '' || !suite_mail_ready($def['channel'])) continue;
        if ($type === 'reminder' && (int)date('G') < MAILING_REMINDER_HOUR) continue;

        foreach (mailing_auto_due($pdo, $type, $cfg['site_url'], $limit) as $item) {
            $email = mb_strtolower(trim($item['email']));
            if (!filter_var($email, FILTER_VALIDATE_EMAIL) || isset($suppressed[$email])) {
                $log->execute([$type, $item['ref'], $email, null, 'skipped', 0, null, 'skipped']);
                continue;
            }
            $token = bin2hex(random_bytes(16));
            $m = mailing_auto_build($cfg['subject'], $cfg['blocks'], $item['vars'], $cfg['site_url'], $token);
            $url = mailing_unsubscribe_url($cfg['site_url'], $token);
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

/** @return array{pending:int,sent_month:int} chiffres affichés sur la fiche d'un message */
function mailing_auto_stats(PDO $pdo, string $type, string $siteUrl): array {
    $m = $pdo->prepare("SELECT COUNT(*) FROM mailing_auto_log WHERE type = ? AND status = 'sent' AND sent_at >= DATE_FORMAT(NOW(), '%Y-%m-01')");
    $m->execute([$type]);
    $pending = $type === 'reminder' ? count(mailing_auto_due($pdo, $type, $siteUrl !== '' ? $siteUrl : 'x', 5000)) : 0;
    return ['pending' => $pending, 'sent_month' => (int)$m->fetchColumn()];
}
