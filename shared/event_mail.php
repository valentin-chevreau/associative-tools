<?php
declare(strict_types=1);
/**
 * shared/event_mail.php — E-mails liés aux évènements du planning.
 *
 * Transport : shared/mailer.php (serveur de messagerie local).
 * Destinataires : bénévoles inscrits (statut « present ») ayant une adresse e-mail valide, une fois par adresse.
 */

require_once __DIR__ . '/mailer.php';
require_once __DIR__ . '/audit.php';

if (!function_exists('suite_event_date_fr')) {
    function suite_event_date_fr(DateTimeInterface $d): string {
        $mois  = ['janvier', 'février', 'mars', 'avril', 'mai', 'juin', 'juillet', 'août', 'septembre', 'octobre', 'novembre', 'décembre'];
        $jours = ['dimanche', 'lundi', 'mardi', 'mercredi', 'jeudi', 'vendredi', 'samedi'];
        return $jours[(int)$d->format('w')] . ' ' . (int)$d->format('j') . ' ' . $mois[(int)$d->format('n') - 1] . ' ' . $d->format('Y');
    }
}

if (!function_exists('suite_event_when')) {
    /** @return array{0:string,1:string} [date, horaire] */
    function suite_event_when(?string $start, ?string $end): array {
        if (!$start) return ['—', '—'];
        $s = new DateTimeImmutable($start);
        $e = $end ? new DateTimeImmutable($end) : null;
        return [suite_event_date_fr($s), $s->format('H:i') . ($e ? ' → ' . $e->format('H:i') : '')];
    }
}

if (!function_exists('suite_event_send')) {
    /**
     * @param callable(string $firstName):array{0:string,1:string,2:string} $build  retourne [sujet, texte, html]
     * @return array{sent:int,failed:int,no_email:int,error:?string}
     */
    function suite_event_send(PDO $pdo, int $eventId, string $auditAction, string $auditLabel, callable $build): array {
        $res = ['sent' => 0, 'failed' => 0, 'no_email' => 0, 'error' => null];
        if (!suite_mail_ready()) {
            $res['error'] = "Adresse d'expédition indéterminée (MAIL_FROM).";
            return $res;
        }
        $q = $pdo->prepare("
            SELECT DISTINCT u.id, u.first_name, u.email
            FROM planning_event_registrations r
            JOIN users u ON u.id = r.volunteer_id
            WHERE r.event_id = ? AND r.status = 'present'
        ");
        $q->execute([$eventId]);

        $seen = [];
        foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $v) {
            $email = trim((string)$v['email']);
            if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) { $res['no_email']++; continue; }
            if (isset($seen[strtolower($email)])) continue;
            $seen[strtolower($email)] = true;
            [$subject, $text, $html] = $build(trim((string)$v['first_name']));
            $err = suite_mail_send($email, $subject, $text, $html);
            if ($err === null) $res['sent']++; else $res['failed']++;
        }
        audit_log('planning', $auditAction, 'event', $eventId, $auditLabel,
            ['envoyes' => $res['sent'], 'echecs' => $res['failed'], 'sans_email' => $res['no_email']]);
        return $res;
    }
}

if (!function_exists('suite_notify_event_cancelled')) {
    /** Prévient les inscrits qu'un évènement est annulé. */
    function suite_notify_event_cancelled(PDO $pdo, int $eventId, string $reason = ''): array {
        $ev = $pdo->prepare("SELECT title, start_datetime, end_datetime FROM planning_events WHERE id = ?");
        $ev->execute([$eventId]);
        $event = $ev->fetch(PDO::FETCH_ASSOC);
        if (!$event) return ['sent' => 0, 'failed' => 0, 'no_email' => 0, 'error' => 'Évènement introuvable.'];

        $title  = (string)$event['title'];
        $reason = trim($reason);
        [$date, $heure] = suite_event_when($event['start_datetime'], $event['end_datetime']);
        $rows = [['Évènement', $title], ['Date', $date], ['Horaire', $heure]];
        if ($reason !== '') $rows[] = ['Motif', $reason];

        return suite_event_send($pdo, $eventId, 'cancel_notify', $title, static function (string $first) use ($title, $date, $heure, $reason, $rows): array {
            $hello = $first !== '' ? "Bonjour $first," : 'Bonjour,';
            $text  = "$hello\n\nL'évènement « $title » prévu le $date ($heure) est annulé.\n"
                   . ($reason !== '' ? "Motif : $reason\n" : '')
                   . "\nVous n'avez rien à faire : votre inscription n'est plus nécessaire.\n\nMerci de votre engagement,\nTouraine-Ukraine\n";
            $html  = suite_mail_html(
                'Évènement annulé',
                "$hello l'évènement « $title » auquel vous étiez inscrit(e) est annulé. Vous n'avez rien à faire.",
                $rows,
                ['Voir le planning', suite_mail_abs_url('/planning/events.php')],
                'Vous recevez ce message car vous étiez inscrit(e) à cet évènement du planning.',
                'Annulation',
                "« $title » du $date est annulé."
            );
            return ['Évènement annulé : ' . $title, $text, $html];
        });
    }
}

if (!function_exists('suite_notify_event_changed')) {
    /**
     * Prévient les inscrits que le titre, la date ou l'horaire d'un évènement a changé.
     * @param array{title:string,start_datetime:?string,end_datetime:?string} $before
     */
    function suite_notify_event_changed(PDO $pdo, int $eventId, array $before): array {
        $ev = $pdo->prepare("SELECT title, start_datetime, end_datetime FROM planning_events WHERE id = ?");
        $ev->execute([$eventId]);
        $after = $ev->fetch(PDO::FETCH_ASSOC);
        if (!$after) return ['sent' => 0, 'failed' => 0, 'no_email' => 0, 'error' => 'Évènement introuvable.'];

        $title = (string)$after['title'];
        [$dAfter, $hAfter]   = suite_event_when($after['start_datetime'], $after['end_datetime']);
        [$dBefore, $hBefore] = suite_event_when($before['start_datetime'] ?? null, $before['end_datetime'] ?? null);

        $rows = [['Évènement', $title]];
        if ((string)($before['title'] ?? '') !== $title) $rows[0] = ['Évènement', $title . ' (avant : ' . (string)$before['title'] . ')'];
        $rows[] = ['Date', $dAfter . ($dBefore !== $dAfter ? ' (avant : ' . $dBefore . ')' : '')];
        $rows[] = ['Horaire', $hAfter . ($hBefore !== $hAfter ? ' (avant : ' . $hBefore . ')' : '')];

        return suite_event_send($pdo, $eventId, 'change_notify', $title, static function (string $first) use ($title, $dAfter, $hAfter, $rows): array {
            $hello = $first !== '' ? "Bonjour $first," : 'Bonjour,';
            $text  = "$hello\n\nL'évènement « $title » auquel vous êtes inscrit(e) a changé.\n\n";
            foreach ($rows as [$k, $v]) $text .= "$k : $v\n";
            $text .= "\nSi ce nouvel horaire ne vous convient plus, vous pouvez vous désinscrire depuis le planning.\n\nMerci de votre engagement,\nTouraine-Ukraine\n";
            $html  = suite_mail_html(
                'Évènement modifié',
                "$hello l'évènement « $title » auquel vous êtes inscrit(e) a changé. Vérifiez les nouvelles informations ci-dessous.",
                $rows,
                ['Voir le planning', suite_mail_abs_url('/planning/events.php')],
                'Vous recevez ce message car vous êtes inscrit(e) à cet évènement du planning. Si le nouvel horaire ne vous convient plus, désinscrivez-vous depuis le planning.',
                'Modification',
                "« $title » : maintenant le $dAfter, $hAfter."
            );
            return ['Évènement modifié : ' . $title, $text, $html];
        });
    }
}
