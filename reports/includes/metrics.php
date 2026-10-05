<?php
declare(strict_types=1);

/**
 * reports/includes/metrics.php
 * Indicateurs partagés par les rapports (CRA, bilan bénévoles, bilan dons).
 *
 * Toutes les fonctions sont en lecture seule, tolérantes aux tables/colonnes
 * absentes (valeurs à zéro plutôt qu'une erreur) et "slots-aware" : si
 * planning_event_slots + planning_event_registrations.slot_id existent, les
 * heures et présences sont calculées au niveau du créneau.
 *
 * Période : [$start, $end] bornes de dates, $now = instant de référence
 * (seuls les événements/créneaux terminés avant $now comptent).
 */

if (!function_exists('rep_h')) {
    function rep_h($s): string { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
}

/**
 * Les requêtes des rapports réutilisent plusieurs fois les mêmes paramètres nommés
 * (ex. :start, :end) : cela exige les requêtes préparées émulées (sinon HY093).
 */
function rep_pdo_prepare_mode(PDO $pdo): void {
    try { $pdo->setAttribute(PDO::ATTR_EMULATE_PREPARES, true); } catch (Throwable $e) {}
}

function rep_table_exists(PDO $pdo, string $table): bool {
    static $cache = [];
    if (isset($cache[$table])) return $cache[$table];
    try {
        $st = $pdo->prepare("SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? LIMIT 1");
        $st->execute([$table]);
        return $cache[$table] = (bool)$st->fetchColumn();
    } catch (Throwable $e) {
        return $cache[$table] = false;
    }
}

function rep_column_exists(PDO $pdo, string $table, string $col): bool {
    static $cache = [];
    $k = $table . '.' . $col;
    if (isset($cache[$k])) return $cache[$k];
    try {
        $st = $pdo->prepare("SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ? LIMIT 1");
        $st->execute([$table, $col]);
        return $cache[$k] = (bool)$st->fetchColumn();
    } catch (Throwable $e) {
        return $cache[$k] = false;
    }
}

function rep_use_slots(PDO $pdo): bool {
    return rep_table_exists($pdo, 'planning_event_slots')
        && rep_column_exists($pdo, 'planning_event_registrations', 'slot_id');
}

/** Bornes d'une année : [début, fin, instant de référence]. L'année en cours s'arrête à maintenant. */
function rep_period(int $year): array {
    return [sprintf('%d-01-01 00:00:00', $year), sprintf('%d-12-31 23:59:59', $year), date('Y-m-d H:i:s')];
}

/**
 * Période de l'année précédente comparable : si $year est l'année en cours, on s'arrête
 * au même jour (comparaison "à date") ; sinon année complète.
 * @return array{0:string,1:string,2:string,3:bool} [début, fin, now, à_date]
 */
function rep_prev_period(int $year): array {
    $prev = $year - 1;
    $start = sprintf('%d-01-01 00:00:00', $prev);
    $end = sprintf('%d-12-31 23:59:59', $prev);
    if ($year === (int)date('Y')) {
        $cut = (new DateTime('now'))->modify('-1 year')->format('Y-m-d H:i:s');
        return [$start, $cut, $cut, true];
    }
    return [$start, $end, $end, false];
}

/** Fragments SQL communs aux requêtes de présences. */
function rep_sql_parts(PDO $pdo): array {
    $slots = rep_use_slots($pdo);
    return [
        'slots'   => $slots,
        'regFrom' => $slots
            ? "FROM planning_event_registrations r
               JOIN planning_events e ON e.id = r.event_id
               LEFT JOIN planning_event_slots s ON s.id = r.slot_id"
            : "FROM planning_event_registrations r
               JOIN planning_events e ON e.id = r.event_id",
        'startExpr' => $slots ? "COALESCE(s.start_datetime, e.start_datetime)" : "e.start_datetime",
        'endExpr'   => $slots ? "COALESCE(s.end_datetime, e.end_datetime)"     : "e.end_datetime",
        'evFrom'  => $slots
            ? "FROM planning_events e LEFT JOIN planning_event_slots s ON s.event_id = e.id"
            : "FROM planning_events e",
    ];
}

/** Compare deux valeurs : variation en % (null si impossible). */
function rep_pct_change(float $cur, float $prev): ?float {
    if ($prev <= 0.0) return null;
    return ($cur - $prev) / $prev * 100.0;
}

/** Petit badge de tendance HTML ("▲ +12 %" / "▼ −5 %" / "= stable") à glisser sous une valeur. */
function rep_delta_html(float $cur, ?float $prev, string $prevLabel, string $unit = '', int $dec = 0): string {
    if ($prev === null) return '';
    $title = rep_h($prevLabel . ' : ' . number_format($prev, $dec, ',', ' ') . $unit);
    if ($prev <= 0.0) {
        return $cur > 0
            ? '<span class="cra-delta up" title="' . $title . '">nouveau</span>'
            : '<span class="cra-delta flat" title="' . $title . '">—</span>';
    }
    $pct = rep_pct_change($cur, $prev);
    if ($pct === null) return '';
    if (abs($pct) < 0.5) return '<span class="cra-delta flat" title="' . $title . '">= stable</span>';
    $cls = $pct > 0 ? 'up' : 'down';
    $arrow = $pct > 0 ? '▲' : '▼';
    $txt = ($pct > 0 ? '+' : '−') . number_format(abs($pct), abs($pct) >= 10 ? 0 : 1, ',', ' ') . ' %';
    return '<span class="cra-delta ' . $cls . '" title="' . $title . '">' . $arrow . ' ' . $txt . '</span>';
}

/**
 * Indicateurs globaux d'une période.
 * @return array<string,float|int>
 */
function rep_period_metrics(PDO $pdo, string $start, string $end, string $now): array {
    rep_pdo_prepare_mode($pdo);
    $m = [
        'actions' => 0, 'permanences' => 0, 'logistics' => 0,
        'presences' => 0, 'volunteers' => 0, 'hours' => 0.0,
        'donations_amount' => 0.0, 'donations_count' => 0, 'donors' => 0,
        'cash_sales' => 0.0,
        'members' => 0, 'members_amount' => 0.0,
        'grants_granted' => 0.0, 'grants_count' => 0,
        'convoys' => 0, 'boxes' => 0,
    ];
    $p = rep_sql_parts($pdo);
    $bind = ['start' => $start, 'end' => $end, 'now' => $now];

    // Présences / bénévoles distincts / heures
    try {
        $st = $pdo->prepare("
            SELECT COUNT(*) AS presences,
                   COUNT(DISTINCT r.volunteer_id) AS volunteers,
                   COALESCE(SUM(TIMESTAMPDIFF(MINUTE, {$p['startExpr']}, {$p['endExpr']})), 0) AS minutes
            {$p['regFrom']}
            WHERE r.status = 'present' AND e.is_cancelled = 0
              AND {$p['startExpr']} BETWEEN :start AND :end
              AND {$p['endExpr']} < :now
        ");
        $st->execute($bind);
        $r = $st->fetch(PDO::FETCH_ASSOC) ?: [];
        $m['presences']  = (int)($r['presences'] ?? 0);
        $m['volunteers'] = (int)($r['volunteers'] ?? 0);
        $m['hours']      = round(((float)($r['minutes'] ?? 0)) / 60, 1);
    } catch (Throwable $e) {}

    // Événements : permanences / logistique / actions
    try {
        $hasCat = rep_column_exists($pdo, 'planning_event_types', 'category_label');
        $catExpr = $hasCat ? "COALESCE(et.category_label, '')" : "''";
        $st = $pdo->prepare("
            SELECT e.event_type AS code, {$catExpr} AS cat, COUNT(DISTINCT e.id) AS n
            {$p['evFrom']}
            LEFT JOIN planning_event_types et ON et.code = e.event_type
            WHERE e.is_cancelled = 0
              AND {$p['startExpr']} BETWEEN :start AND :end
              AND {$p['endExpr']} < :now
            GROUP BY e.event_type, cat
        ");
        $st->execute($bind);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $n = (int)$r['n'];
            if ((string)$r['code'] === 'permanence')      $m['permanences'] += $n;
            elseif ((string)$r['cat'] === 'Logistique')   $m['logistics']   += $n;
            else                                          $m['actions']     += $n;
        }
    } catch (Throwable $e) {}

    // Dons
    if (rep_table_exists($pdo, 'donations')) {
        try {
            $st = $pdo->prepare("
                SELECT COUNT(*) AS n, COALESCE(SUM(amount),0) AS a,
                       COUNT(DISTINCT COALESCE(NULLIF(donor_email,''), CONCAT(COALESCE(donor_last_name,''),'|',COALESCE(donor_first_name,''), '|', id))) AS donors
                FROM donations
                WHERE status = 'paid' AND donation_date BETWEEN :start AND :end AND donation_date <= :now
            ");
            $st->execute($bind);
            $r = $st->fetch(PDO::FETCH_ASSOC) ?: [];
            $m['donations_count']  = (int)($r['n'] ?? 0);
            $m['donations_amount'] = (float)($r['a'] ?? 0);
            $m['donors']           = (int)($r['donors'] ?? 0);
        } catch (Throwable $e) {}
    }

    // Recettes caisse liées aux actions
    if (rep_table_exists($pdo, 'caisse_ventes') && rep_table_exists($pdo, 'caisse_evenements')
        && rep_column_exists($pdo, 'caisse_evenements', 'planning_event_id')) {
        try {
            $hasCat = rep_column_exists($pdo, 'planning_event_types', 'category_label');
            $catCond = $hasCat ? "AND COALESCE(et.category_label,'') <> 'Logistique'" : '';
            $st = $pdo->prepare("
                SELECT COALESCE(SUM(v.total),0)
                FROM caisse_ventes v
                JOIN caisse_evenements ce ON ce.id = v.evenement_id
                JOIN planning_events e ON e.id = ce.planning_event_id
                LEFT JOIN planning_event_types et ON et.code = e.event_type
                WHERE e.is_cancelled = 0 AND e.event_type <> 'permanence' {$catCond}
                  AND e.start_datetime BETWEEN :start AND :end AND e.end_datetime < :now
            ");
            $st->execute($bind);
            $m['cash_sales'] = (float)$st->fetchColumn();
        } catch (Throwable $e) {}
    }

    // Adhésions (année civile de la période)
    $y = (int)substr($start, 0, 4);
    if (rep_table_exists($pdo, 'adhesions')) {
        try {
            $st = $pdo->prepare("SELECT COUNT(*), COALESCE(SUM(montant),0) FROM adhesions WHERE annee = :y AND statut = 'valide' AND date_adhesion <= :nowd");
            $st->execute(['y' => $y, 'nowd' => substr($now, 0, 10)]);
            $r = $st->fetch(PDO::FETCH_NUM) ?: [0, 0];
            $m['members'] = (int)$r[0];
            $m['members_amount'] = (float)$r[1];
        } catch (Throwable $e) {}
    }

    // Subventions accordées (année de la demande)
    if (rep_table_exists($pdo, 'subventions')) {
        try {
            $st = $pdo->prepare("SELECT COUNT(*), COALESCE(SUM(montant_accorde),0) FROM subventions WHERE annee = :y AND statut = 'accordee'");
            $st->execute(['y' => $y]);
            $r = $st->fetch(PDO::FETCH_NUM) ?: [0, 0];
            $m['grants_count'] = (int)$r[0];
            $m['grants_granted'] = (float)$r[1];
        } catch (Throwable $e) {}
    }

    // Convois expédiés + colis
    if (rep_table_exists($pdo, 'logistique_convoys')) {
        try {
            $st = $pdo->prepare("SELECT id FROM logistique_convoys WHERE status = 'expedie' AND departure_date BETWEEN :s AND :e AND departure_date <= :nowd");
            $st->execute(['s' => substr($start, 0, 10), 'e' => substr($end, 0, 10), 'nowd' => substr($now, 0, 10)]);
            $ids = array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));
            $m['convoys'] = count($ids);
            if ($ids && rep_table_exists($pdo, 'logistique_boxes')) {
                $in = implode(',', $ids);
                $m['boxes'] = (int)$pdo->query("SELECT COUNT(*) FROM logistique_boxes WHERE convoy_id IN ($in)")->fetchColumn();
            }
        } catch (Throwable $e) {}
    }

    return $m;
}

/**
 * Évolution mensuelle (présences, heures, événements) d'une année.
 * @return array<int,array{presences:int,hours:float,events:int}> clés 1..12
 */
function rep_monthly(PDO $pdo, int $year): array {
    rep_pdo_prepare_mode($pdo);
    $out = [];
    for ($i = 1; $i <= 12; $i++) $out[$i] = ['presences' => 0, 'hours' => 0.0, 'events' => 0];
    [$start, $end, $now] = rep_period($year);
    $p = rep_sql_parts($pdo);
    try {
        $st = $pdo->prepare("
            SELECT MONTH({$p['startExpr']}) AS mo, COUNT(*) AS presences,
                   COALESCE(SUM(TIMESTAMPDIFF(MINUTE, {$p['startExpr']}, {$p['endExpr']})), 0) AS minutes
            {$p['regFrom']}
            WHERE r.status = 'present' AND e.is_cancelled = 0
              AND {$p['startExpr']} BETWEEN :start AND :end AND {$p['endExpr']} < :now
            GROUP BY mo
        ");
        $st->execute(['start' => $start, 'end' => $end, 'now' => $now]);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $mo = (int)$r['mo'];
            if ($mo >= 1 && $mo <= 12) {
                $out[$mo]['presences'] = (int)$r['presences'];
                $out[$mo]['hours'] = round(((float)$r['minutes']) / 60, 1);
            }
        }
        $st = $pdo->prepare("
            SELECT MONTH({$p['startExpr']}) AS mo, COUNT(DISTINCT e.id) AS n
            {$p['evFrom']}
            WHERE e.is_cancelled = 0 AND {$p['startExpr']} BETWEEN :start AND :end AND {$p['endExpr']} < :now
            GROUP BY mo
        ");
        $st->execute(['start' => $start, 'end' => $end, 'now' => $now]);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $mo = (int)$r['mo'];
            if ($mo >= 1 && $mo <= 12) $out[$mo]['events'] = (int)$r['n'];
        }
    } catch (Throwable $e) {}
    return $out;
}

/**
 * Bénévoles de l'année : détail par personne + synthèse fidélisation.
 * @return array{list:array<int,array<string,mixed>>,summary:array<string,mixed>}
 */
function rep_volunteer_stats(PDO $pdo, int $year, int $regularThreshold = 5): array {
    rep_pdo_prepare_mode($pdo);
    $res = ['list' => [], 'summary' => [
        'active' => 0, 'new' => 0, 'returning' => 0, 'lost' => 0, 'prev_active' => 0,
        'regulars' => 0, 'retention' => null, 'top3_share' => null, 'avg_hours' => 0.0,
        'total_hours' => 0.0, 'regular_threshold' => $regularThreshold,
    ]];
    [$start, $end, $now] = rep_period($year);
    $p = rep_sql_parts($pdo);

    try {
        $st = $pdo->prepare("
            SELECT r.volunteer_id AS id, u.first_name, u.last_name,
                   COUNT(*) AS presences,
                   COALESCE(SUM(TIMESTAMPDIFF(MINUTE, {$p['startExpr']}, {$p['endExpr']})), 0) AS minutes,
                   COUNT(DISTINCT e.id) AS events
            {$p['regFrom']}
            JOIN users u ON u.id = r.volunteer_id
            WHERE r.status = 'present' AND e.is_cancelled = 0
              AND {$p['startExpr']} BETWEEN :start AND :end AND {$p['endExpr']} < :now
            GROUP BY r.volunteer_id, u.first_name, u.last_name
            ORDER BY minutes DESC, presences DESC, u.last_name, u.first_name
        ");
        $st->execute(['start' => $start, 'end' => $end, 'now' => $now]);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);

        // Première présence (toutes années confondues) → nouveaux bénévoles
        $first = [];
        $st = $pdo->prepare("
            SELECT r.volunteer_id AS id, MIN({$p['startExpr']}) AS first_dt
            {$p['regFrom']}
            WHERE r.status = 'present' AND e.is_cancelled = 0 AND {$p['endExpr']} < :now
            GROUP BY r.volunteer_id
        ");
        $st->execute(['now' => $now]);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) $first[(int)$r['id']] = (string)$r['first_dt'];

        // Actifs l'année précédente
        [$ps, $pe, $pn] = rep_prev_period($year);
        $prevSet = [];
        $st = $pdo->prepare("
            SELECT DISTINCT r.volunteer_id AS id
            {$p['regFrom']}
            WHERE r.status = 'present' AND e.is_cancelled = 0
              AND {$p['startExpr']} BETWEEN :start AND :end AND {$p['endExpr']} < :now
        ");
        $st->execute(['start' => $ps, 'end' => $pe, 'now' => $pn]);
        foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $id) $prevSet[(int)$id] = true;
        // Pour "perdus" on compare à l'année précédente complète
        $fullPrev = [];
        $st->execute(['start' => sprintf('%d-01-01 00:00:00', $year - 1), 'end' => sprintf('%d-12-31 23:59:59', $year - 1), 'now' => $now]);
        foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $id) $fullPrev[(int)$id] = true;

        $totalMin = 0.0;
        $curSet = [];
        foreach ($rows as $r) {
            $id = (int)$r['id'];
            $curSet[$id] = true;
            $minutes = (float)$r['minutes'];
            $totalMin += $minutes;
            $isNew = !isset($first[$id]) || $first[$id] >= $start;
            $res['list'][] = [
                'id' => $id,
                'first_name' => (string)$r['first_name'],
                'last_name' => (string)$r['last_name'],
                'presences' => (int)$r['presences'],
                'events' => (int)$r['events'],
                'hours' => round($minutes / 60, 1),
                'is_new' => $isNew,
                'returning' => isset($fullPrev[$id]),
                'regular' => ((int)$r['presences'] >= $regularThreshold),
            ];
        }
        $s = &$res['summary'];
        $s['active'] = count($rows);
        $s['new'] = count(array_filter($res['list'], fn($x) => $x['is_new']));
        $s['returning'] = count(array_filter($res['list'], fn($x) => $x['returning']));
        $s['regulars'] = count(array_filter($res['list'], fn($x) => $x['regular']));
        $s['prev_active'] = count($fullPrev);
        $s['lost'] = count(array_diff_key($fullPrev, $curSet));
        $s['retention'] = $fullPrev ? round($s['returning'] / count($fullPrev) * 100, 0) : null;
        $s['total_hours'] = round($totalMin / 60, 1);
        $s['avg_hours'] = $rows ? round($totalMin / 60 / count($rows), 1) : 0.0;
        $top3 = array_slice(array_column($res['list'], 'hours'), 0, 3);
        $s['top3_share'] = $s['total_hours'] > 0 ? round(array_sum($top3) / $s['total_hours'] * 100, 0) : null;
        unset($s);
    } catch (Throwable $e) {
        $res['error'] = $e->getMessage();
    }
    return $res;
}

/**
 * Bilan des dons d'une période.
 * @return array<string,mixed>
 */
function rep_donations_stats(PDO $pdo, string $start, string $end, string $now): array {
    rep_pdo_prepare_mode($pdo);
    $d = [
        'enabled' => false, 'count' => 0, 'amount' => 0.0, 'avg' => 0.0, 'max' => 0.0,
        'eligible_count' => 0, 'eligible_amount' => 0.0, 'donors' => 0,
        'recurring_donors' => 0, 'new_donors' => 0,
        'by_source' => [], 'by_campaign' => [], 'by_method' => [], 'by_month' => array_fill(1, 12, 0.0),
        'buckets' => [], 'top' => [],
        'no_receipt' => 0, 'possible_membership' => ['count' => 0, 'amount' => 0.0], 'error' => null,
    ];
    if (!rep_table_exists($pdo, 'donations')) return $d;
    $d['enabled'] = true;
    $bind = ['start' => $start, 'end' => $end, 'now' => $now];
    $donor = "COALESCE(NULLIF(donor_email,''), CONCAT(COALESCE(donor_last_name,''),'|',COALESCE(donor_first_name,''),'|', id))";
    $where = "status = 'paid' AND donation_date BETWEEN :start AND :end AND donation_date <= :now";

    try {
        $st = $pdo->prepare("SELECT COUNT(*) n, COALESCE(SUM(amount),0) a, COALESCE(MAX(amount),0) mx,
               SUM(CASE WHEN receipt_eligible=1 THEN 1 ELSE 0 END) en,
               COALESCE(SUM(CASE WHEN receipt_eligible=1 THEN amount ELSE 0 END),0) ea,
               COUNT(DISTINCT $donor) donors,
               SUM(CASE WHEN receipt_eligible=1 AND (receipt_number IS NULL OR receipt_number='') THEN 1 ELSE 0 END) norec
            FROM donations WHERE $where");
        $st->execute($bind);
        $r = $st->fetch(PDO::FETCH_ASSOC) ?: [];
        $d['count'] = (int)($r['n'] ?? 0);
        $d['amount'] = (float)($r['a'] ?? 0);
        $d['max'] = (float)($r['mx'] ?? 0);
        $d['avg'] = $d['count'] > 0 ? $d['amount'] / $d['count'] : 0.0;
        $d['eligible_count'] = (int)($r['en'] ?? 0);
        $d['eligible_amount'] = (float)($r['ea'] ?? 0);
        $d['donors'] = (int)($r['donors'] ?? 0);
        $d['no_receipt'] = (int)($r['norec'] ?? 0);

        // Donateurs récurrents (≥ 2 dons sur la période)
        $st = $pdo->prepare("SELECT COUNT(*) FROM (SELECT $donor k FROM donations WHERE $where GROUP BY k HAVING COUNT(*) >= 2) t");
        $st->execute($bind);
        $d['recurring_donors'] = (int)$st->fetchColumn();

        // Nouveaux donateurs (aucun don payé avant le début de la période)
        $st = $pdo->prepare("SELECT COUNT(*) FROM (
                SELECT $donor k FROM donations WHERE $where GROUP BY k
            ) cur WHERE NOT EXISTS (
                SELECT 1 FROM donations b WHERE b.status='paid' AND b.donation_date < :start
                  AND COALESCE(NULLIF(b.donor_email,''), CONCAT(COALESCE(b.donor_last_name,''),'|',COALESCE(b.donor_first_name,''),'|', b.id)) = cur.k
            )");
        $st->execute($bind);
        $d['new_donors'] = (int)$st->fetchColumn();

        foreach (['source' => 'by_source', 'campaign' => 'by_campaign', 'payment_method' => 'by_method'] as $col => $key) {
            $st = $pdo->prepare("SELECT COALESCE(NULLIF($col,''),'(non renseigné)') k, COUNT(*) n, COALESCE(SUM(amount),0) a
                FROM donations WHERE $where GROUP BY k ORDER BY a DESC");
            $st->execute($bind);
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) $d[$key][(string)$r['k']] = ['count' => (int)$r['n'], 'amount' => (float)$r['a']];
        }

        $st = $pdo->prepare("SELECT MONTH(donation_date) mo, COALESCE(SUM(amount),0) a FROM donations WHERE $where GROUP BY mo");
        $st->execute($bind);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) $d['by_month'][(int)$r['mo']] = (float)$r['a'];

        // Tranches de montants
        $st = $pdo->prepare("SELECT
              SUM(amount < 20) b1, SUM(amount >= 20 AND amount < 50) b2,
              SUM(amount >= 50 AND amount < 100) b3, SUM(amount >= 100 AND amount < 500) b4, SUM(amount >= 500) b5
            FROM donations WHERE $where");
        $st->execute($bind);
        $r = $st->fetch(PDO::FETCH_ASSOC) ?: [];
        $d['buckets'] = [
            '< 20 €' => (int)($r['b1'] ?? 0), '20–49 €' => (int)($r['b2'] ?? 0), '50–99 €' => (int)($r['b3'] ?? 0),
            '100–499 €' => (int)($r['b4'] ?? 0), '≥ 500 €' => (int)($r['b5'] ?? 0),
        ];

        // Lignes à vérifier : campagne/référence évoquant une adhésion
        $st = $pdo->prepare("SELECT COUNT(*) n, COALESCE(SUM(amount),0) a FROM donations
            WHERE $where AND (LOWER(COALESCE(campaign,'')) LIKE '%adh%' OR LOWER(COALESCE(source_ref,'')) LIKE '%adh%')");
        $st->execute($bind);
        $r = $st->fetch(PDO::FETCH_ASSOC) ?: [];
        $d['possible_membership'] = ['count' => (int)($r['n'] ?? 0), 'amount' => (float)($r['a'] ?? 0)];
    } catch (Throwable $e) {
        $d['error'] = $e->getMessage();
    }
    return $d;
}

/**
 * Contrôles qualité des données (utile avant une AG).
 * @return array<int,array{label:string,count:int,hint:string,level:string}>
 */
function rep_quality_checks(PDO $pdo, int $year): array {
    rep_pdo_prepare_mode($pdo);
    [$start, $end, $now] = rep_period($year);
    $checks = [];
    $bind = ['start' => $start, 'end' => $end, 'now' => $now];

    try {
        $st = $pdo->prepare("
            SELECT COUNT(*) FROM planning_events e
            WHERE e.is_cancelled = 0 AND e.start_datetime BETWEEN :start AND :end AND e.end_datetime < :now
              AND NOT EXISTS (SELECT 1 FROM planning_event_registrations r WHERE r.event_id = e.id AND r.status = 'present')
        ");
        $st->execute($bind);
        $n = (int)$st->fetchColumn();
        $checks[] = ['label' => 'Événements passés sans aucune présence saisie', 'count' => $n,
            'hint' => 'Les heures de bénévolat de ces événements ne sont pas comptées : pointer les présences.', 'level' => $n > 0 ? 'warn' : 'ok'];
    } catch (Throwable $e) {}

    try {
        $st = $pdo->prepare("
            SELECT COUNT(*) FROM planning_events e
            WHERE e.is_cancelled = 0 AND e.start_datetime BETWEEN :start AND :end AND e.end_datetime < :now
              AND (e.title IS NULL OR TRIM(e.title) = '')
        ");
        $st->execute($bind);
        $n = (int)$st->fetchColumn();
        $checks[] = ['label' => 'Événements passés sans titre', 'count' => $n, 'hint' => 'Titre vide dans le détail du CRA.', 'level' => $n > 0 ? 'warn' : 'ok'];
    } catch (Throwable $e) {}

    if (rep_table_exists($pdo, 'donations')) {
        try {
            $st = $pdo->prepare("SELECT COUNT(*) FROM donations WHERE status='paid' AND receipt_eligible=1
                AND (receipt_number IS NULL OR receipt_number='') AND donation_date BETWEEN :start AND :end");
            $st->execute(['start' => $start, 'end' => $end]);
            $n = (int)$st->fetchColumn();
            $checks[] = ['label' => 'Dons éligibles sans reçu fiscal émis', 'count' => $n, 'hint' => 'Reçus à générer depuis le module Dons.', 'level' => $n > 0 ? 'warn' : 'ok'];

            $st = $pdo->prepare("SELECT COUNT(*) FROM donations WHERE status='paid' AND donation_date BETWEEN :start AND :end
                AND (LOWER(COALESCE(campaign,'')) LIKE '%adh%' OR LOWER(COALESCE(source_ref,'')) LIKE '%adh%')");
            $st->execute(['start' => $start, 'end' => $end]);
            $n = (int)$st->fetchColumn();
            $checks[] = ['label' => 'Dons dont la campagne évoque une adhésion', 'count' => $n, 'hint' => 'Possibles cotisations HelloAsso comptées comme dons.', 'level' => $n > 0 ? 'warn' : 'ok'];
        } catch (Throwable $e) {}
    }

    try {
        $sql = "SELECT COUNT(*) FROM planning_event_registrations r JOIN planning_events e ON e.id = r.event_id
                LEFT JOIN users u ON u.id = r.volunteer_id
                WHERE r.status='present' AND e.start_datetime BETWEEN :start AND :end AND u.id IS NULL";
        $st = $pdo->prepare($sql);
        $st->execute(['start' => $start, 'end' => $end]);
        $n = (int)$st->fetchColumn();
        $checks[] = ['label' => 'Présences rattachées à un bénévole introuvable', 'count' => $n, 'hint' => 'Compte supprimé : la présence est comptée mais anonyme.', 'level' => $n > 0 ? 'warn' : 'ok'];
    } catch (Throwable $e) {}

    return $checks;
}

/** Histogramme HTML/CSS (barres verticales) — $values : 12 valeurs ; $unit affiché dans l'infobulle. */
function rep_bars_html(array $labels, array $values, string $unit = '', string $tone = 'amber', int $height = 110): string {
    $max = max(1.0, (float)max($values ?: [0]));
    $html = '<div class="rep-bars" style="height:' . ($height + 34) . 'px;">';
    foreach ($labels as $i => $lab) {
        $v = (float)($values[$i] ?? 0);
        $pct = $v > 0 ? max(3.0, $v / $max * 100) : 0;
        $txt = rtrim(rtrim(number_format($v, 1, ',', ' '), '0'), ',') . ($unit !== '' ? ' ' . $unit : '');
        $html .= '<div class="rep-bar-col" title="' . rep_h($lab . ' : ' . $txt) . '">'
              . '<div class="rep-bar-val">' . ($v > 0 ? rep_h(rtrim(rtrim(number_format($v, 1, ',', ' '), '0'), ',')) : '') . '</div>'
              . '<div class="rep-bar-track" style="height:' . $height . 'px;"><div class="rep-bar ' . rep_h($tone) . '" style="height:' . $pct . '%;"></div></div>'
              . '<div class="rep-bar-lab">' . rep_h($lab) . '</div></div>';
    }
    return $html . '</div>';
}

/** CSS commun aux rapports (à inclure une fois par page). */
function rep_common_css(): string {
    return <<<'CSS'
<style>
.rep-bars { display:flex; gap:6px; align-items:flex-end; }
.rep-bar-col { flex:1; min-width:0; display:flex; flex-direction:column; align-items:center; gap:3px; }
.rep-bar-track { width:100%; display:flex; align-items:flex-end; }
.rep-bar { width:100%; border-radius:5px 5px 0 0; background:var(--tu-amber-main, #c47328); opacity:.9; }
.rep-bar.teal { background:var(--tu-teal-main, #2f6f68); }
.rep-bar.green { background:var(--tu-green-main, #2a7d4a); }
.rep-bar-val { font-size:10.5px; color:var(--tu-ink-500, #6b5d4d); height:13px; line-height:13px; }
.rep-bar-lab { font-size:10.5px; color:var(--tu-ink-400, #8a7a68); text-transform:uppercase; letter-spacing:.04em; }
.rep-grid2 { display:grid; grid-template-columns:repeat(auto-fit, minmax(340px, 1fr)); gap:14px; margin-bottom:14px; }
.rep-hub { display:grid; grid-template-columns:repeat(auto-fill, minmax(280px, 1fr)); gap:14px; }
.rep-hub a { text-decoration:none; color:inherit; }
.rep-hub .tu-card { padding:18px 20px; height:100%; box-sizing:border-box; }
.rep-hub .tu-card:hover { box-shadow:0 6px 22px rgba(60,40,10,.10); }
.rep-ok { color:var(--tu-green-main, #2a7d4a); font-weight:600; }
.rep-warn { color:var(--tu-amber-main, #c47328); font-weight:600; }
</style>
CSS;
}
