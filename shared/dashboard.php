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

        // Cartons médicaux (catégorie ou racine marquée médicale)
        $m = $pdo->prepare("
            SELECT COUNT(*)
            FROM logistique_boxes b
            JOIN logistique_categories c ON c.id = b.category_id
            LEFT JOIN logistique_categories r ON r.id = COALESCE(c.root_id, c.parent_id)
            WHERE b.convoy_id = ? AND (c.is_medical = 1 OR COALESCE(r.is_medical, 0) = 1)
        ");
        $m->execute([(int)$c['id']]);
        $c['medical'] = (int)$m->fetchColumn();

        // Répartition par catégorie racine (les 4 plus remplies)
        $r = $pdo->prepare("
            SELECT rc.label, COUNT(*) AS n
            FROM logistique_boxes b
            JOIN logistique_categories rc ON rc.id = b.root_category_id
            WHERE b.convoy_id = ?
            GROUP BY rc.id, rc.label
            ORDER BY n DESC, rc.label ASC
            LIMIT 4
        ");
        $r->execute([(int)$c['id']]);
        $c['top'] = $r->fetchAll(PDO::FETCH_ASSOC);

        $c['days'] = null;
        if (!empty($c['departure_date'])) {
            $today = new DateTimeImmutable('today');
            $dep   = new DateTimeImmutable((string)$c['departure_date']);
            $c['days'] = (int)$today->diff($dep)->format('%r%a');
        }
        return $c;
    }
}

if (!function_exists('dash_evenements')) {
    /** Prochains évènements du planning avec le nombre de bénévoles présents (inscrits « present »). */
    function dash_evenements(PDO $pdo, int $limit = 5): ?array {
        if (!module_access('planning')) return null;
        $q = $pdo->prepare("
            SELECT e.id, e.title, e.start_datetime, e.end_datetime, e.min_volunteers,
                   (SELECT COUNT(DISTINCT r.volunteer_id) FROM planning_event_registrations r
                     WHERE r.event_id = e.id AND r.status = 'present') AS present
            FROM planning_events e
            WHERE e.is_cancelled = 0 AND e.start_datetime >= NOW()
            ORDER BY e.start_datetime ASC
            LIMIT " . (int)$limit);
        $q->execute();
        return $q->fetchAll(PDO::FETCH_ASSOC);
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
            $n = (int)$pdo->query("SELECT COUNT(*) FROM donations WHERE source = 'manual' AND receipt_eligible = 1 AND (receipt_number IS NULL OR receipt_number = '')")->fetchColumn();
            if ($n > 0) {
                $out[] = [
                    'label' => $n . ($n > 1 ? ' dons manuels sans reçu fiscal' : ' don manuel sans reçu fiscal'),
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
