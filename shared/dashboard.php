<?php
declare(strict_types=1);
/**
 * shared/dashboard.php — Données du tableau de bord d'accueil (admins).
 *
 * Chaque fonction ne lit que ce que l'utilisateur a le droit de voir (module_access) et renvoie null
 * quand le bloc n'a pas lieu d'être affiché.
 */

require_once __DIR__ . '/bootstrap.php';

if (!function_exists('dash_jour_fr')) {
    function dash_jour_fr(DateTimeInterface $d, bool $long = false): string {
        $jours = ['dim', 'lun', 'mar', 'mer', 'jeu', 'ven', 'sam'];
        $joursL = ['dimanche', 'lundi', 'mardi', 'mercredi', 'jeudi', 'vendredi', 'samedi'];
        $mois = ['janvier', 'février', 'mars', 'avril', 'mai', 'juin', 'juillet', 'août', 'septembre', 'octobre', 'novembre', 'décembre'];
        $n = (int)$d->format('w');
        if ($long) return $joursL[$n] . ' ' . (int)$d->format('j') . ' ' . $mois[(int)$d->format('n') - 1];
        return $jours[$n];
    }
}

if (!function_exists('dash_convoi')) {
    /** Prochain convoi non expédié (le plus proche par date de départ). */
    function dash_convoi(PDO $pdo): ?array {
        if (!module_access('logistique')) return null;
        $c = $pdo->query("
            SELECT id, name, destination, departure_date, status
            FROM logistique_convoys
            WHERE status <> 'expedie'
            ORDER BY (departure_date IS NULL), departure_date ASC, id DESC
            LIMIT 1
        ")->fetch(PDO::FETCH_ASSOC);
        if (!$c) return null;

        $s = $pdo->prepare("SELECT COUNT(*) AS boxes, COUNT(DISTINCT category_id) AS cats FROM logistique_boxes WHERE convoy_id = ?");
        $s->execute([(int)$c['id']]);
        $t = $s->fetch(PDO::FETCH_ASSOC) ?: ['boxes' => 0, 'cats' => 0];
        $c['boxes'] = (int)$t['boxes'];
        $c['cats']  = (int)$t['cats'];

        $c['days'] = null;
        if (!empty($c['departure_date'])) {
            $today = new DateTimeImmutable('today');
            $dep   = new DateTimeImmutable((string)$c['departure_date']);
            $c['days'] = (int)$today->diff($dep)->format('%r%a');
        }
        return $c;
    }
}

if (!function_exists('dash_creneaux')) {
    /** Créneaux à venir où il manque des inscrits (inscrits « present » < minimum). */
    function dash_creneaux(PDO $pdo, int $limit = 4): ?array {
        if (!module_access('planning')) return null;
        $q = $pdo->prepare("
            SELECT s.id, s.start_datetime, s.end_datetime, s.min_volunteers, e.title,
                   (SELECT COUNT(*) FROM planning_event_registrations r WHERE r.slot_id = s.id AND r.status = 'present') AS registered
            FROM planning_event_slots s
            JOIN planning_events e ON e.id = s.event_id
            WHERE s.start_datetime >= NOW()
              AND s.min_volunteers > 0
              AND (SELECT COUNT(*) FROM planning_event_registrations r WHERE r.slot_id = s.id AND r.status = 'present') < s.min_volunteers
            ORDER BY s.start_datetime ASC
            LIMIT " . (int)$limit);
        $q->execute();
        $rows = $q->fetchAll(PDO::FETCH_ASSOC);

        $tot = $pdo->query("
            SELECT COALESCE(SUM(s.min_volunteers - (SELECT COUNT(*) FROM planning_event_registrations r WHERE r.slot_id = s.id AND r.status = 'present')), 0)
            FROM planning_event_slots s
            WHERE s.start_datetime >= NOW() AND s.min_volunteers > 0
              AND (SELECT COUNT(*) FROM planning_event_registrations r WHERE r.slot_id = s.id AND r.status = 'present') < s.min_volunteers
        ")->fetchColumn();

        return ['rows' => $rows, 'missing' => (int)$tot];
    }
}

if (!function_exists('dash_caisse')) {
    /** Évènement de caisse en cours, sinon le dernier clôturé. */
    function dash_caisse(PDO $pdo): ?array {
        if (!module_access('caisse')) return null;
        $e = $pdo->query("
            SELECT id, nom, actif, date_fin, ecart_caisse
            FROM caisse_evenements
            ORDER BY actif DESC, COALESCE(date_fin, NOW()) DESC, id DESC
            LIMIT 1
        ")->fetch(PDO::FETCH_ASSOC);
        if (!$e) return null;

        $s = $pdo->prepare("SELECT COUNT(*) AS n, COALESCE(SUM(total), 0) AS total FROM caisse_ventes WHERE evenement_id = ?");
        $s->execute([(int)$e['id']]);
        $t = $s->fetch(PDO::FETCH_ASSOC) ?: ['n' => 0, 'total' => 0];
        $e['sales']     = (int)$t['n'];
        $e['total']     = (float)$t['total'];
        $e['is_open']   = (int)$e['actif'] === 1 && empty($e['date_fin']);
        $e['ecart']     = $e['ecart_caisse'] !== null ? (float)$e['ecart_caisse'] : null;
        return $e;
    }
}

if (!function_exists('dash_a_traiter')) {
    /** @return array<int,array{label:string,hint:string,href:string}> */
    function dash_a_traiter(PDO $pdo, string $base): array {
        $out = [];

        if (module_access('donations')) {
            $n = (int)$pdo->query("SELECT COUNT(*) FROM donations WHERE receipt_eligible = 1 AND (receipt_number IS NULL OR receipt_number = '')")->fetchColumn();
            if ($n > 0) {
                $out[] = [
                    'label' => $n . ($n > 1 ? ' dons éligibles sans reçu fiscal' : ' don éligible sans reçu fiscal'),
                    'hint'  => 'Dons',
                    'href'  => $base . '/donations/index.php',
                ];
            }
        }

        if (module_access('subventions')) {
            $n = (int)$pdo->query("SELECT COUNT(*) FROM subventions WHERE statut = 'brouillon'")->fetchColumn();
            if ($n > 0) {
                $out[] = [
                    'label' => $n . ($n > 1 ? ' dossiers de subvention en brouillon' : ' dossier de subvention en brouillon'),
                    'hint'  => 'Subventions',
                    'href'  => $base . '/subventions/index.php?statut=brouillon',
                ];
            }
        }

        return $out;
    }
}
