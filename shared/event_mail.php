<?php
declare(strict_types=1);
/**
 * shared/event_mail.php — E-mails liés aux évènements du planning.
 *
 * Transport : shared/mailer.php (serveur de messagerie local).
 */

require_once __DIR__ . '/mailer.php';
require_once __DIR__ . '/audit.php';

if (!function_exists('suite_notify_event_cancelled')) {
    /**
     * Prévient par e-mail les bénévoles inscrits (statut « present ») qu'un évènement est annulé.
     *
     * @return array{sent:int,failed:int,no_email:int,error:?string}
     */
    function suite_notify_event_cancelled(PDO $pdo, int $eventId, string $reason = ''): array {
        $res = ['sent' => 0, 'failed' => 0, 'no_email' => 0, 'error' => null];
        if (!suite_mail_ready()) {
            $res['error'] = "Adresse d'expédition indéterminée (MAIL_FROM).";
            return $res;
        }

        $ev = $pdo->prepare("SELECT title, start_datetime, end_datetime FROM planning_events WHERE id = ?");
        $ev->execute([$eventId]);
        $event = $ev->fetch(PDO::FETCH_ASSOC);
        if (!$event) {
            $res['error'] = 'Évènement introuvable.';
            return $res;
        }

        $q = $pdo->prepare("
            SELECT DISTINCT u.id, u.first_name, u.email
            FROM planning_event_registrations r
            JOIN users u ON u.id = r.volunteer_id
            WHERE r.event_id = ? AND r.status = 'present'
        ");
        $q->execute([$eventId]);

        $mois  = ['janvier', 'février', 'mars', 'avril', 'mai', 'juin', 'juillet', 'août', 'septembre', 'octobre', 'novembre', 'décembre'];
        $jours = ['dimanche', 'lundi', 'mardi', 'mercredi', 'jeudi', 'vendredi', 'samedi'];
        $start = new DateTimeImmutable((string)$event['start_datetime']);
        $end   = !empty($event['end_datetime']) ? new DateTimeImmutable((string)$event['end_datetime']) : null;
        $date  = $jours[(int)$start->format('w')] . ' ' . (int)$start->format('j') . ' ' . $mois[(int)$start->format('n') - 1] . ' ' . $start->format('Y');
        $heure = $start->format('H:i') . ($end ? ' → ' . $end->format('H:i') : '');
        $title = (string)$event['title'];
        $reason = trim($reason);

        $rows = [['Évènement', $title], ['Date', $date], ['Horaire', $heure]];
        if ($reason !== '') $rows[] = ['Motif', $reason];

        $seen = [];
        foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $v) {
            $email = trim((string)$v['email']);
            if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) { $res['no_email']++; continue; }
            if (isset($seen[strtolower($email)])) continue;
            $seen[strtolower($email)] = true;

            $first = trim((string)$v['first_name']);
            $hello = $first !== '' ? "Bonjour $first," : 'Bonjour,';
            $text  = "$hello\n\nL'évènement « $title » prévu le $date ($heure) est annulé.\n"
                   . ($reason !== '' ? "Motif : $reason\n" : '')
                   . "\nVous n'avez rien à faire : votre inscription n'est plus nécessaire.\n\nMerci de votre engagement,\nTouraine-Ukraine\n";
            $html  = suite_mail_html(
                'Évènement annulé',
                $hello . " l'évènement « $title » auquel vous étiez inscrit(e) est annulé. Vous n'avez rien à faire.",
                $rows,
                null,
                "Vous recevez ce message car vous étiez inscrit(e) à cet évènement du planning.",
                'Annulation'
            );
            $err = suite_mail_send($email, 'Évènement annulé : ' . $title, $text, $html);
            if ($err === null) $res['sent']++; else $res['failed']++;
        }

        audit_log('planning', 'cancel_notify', 'event', $eventId, $title,
            ['envoyes' => $res['sent'], 'echecs' => $res['failed'], 'sans_email' => $res['no_email']]);
        return $res;
    }
}
