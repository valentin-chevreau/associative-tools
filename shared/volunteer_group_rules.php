<?php
declare(strict_types=1);

/**
 * shared/volunteer_group_rules.php — Auto-attribution aux groupes d'utilisateurs.
 *
 * Deux règles, configurables directement dans l'interface Groupes (admin/groups.php) :
 *  - "auto_functions" sur un groupe : liste de fonctions (codes MEMBER_FUNCTIONS,
 *    séparés par des virgules) qui, dès qu'un utilisateur les a, l'ajoutent
 *    automatiquement comme membre de ce groupe (ex : la fonction "Trésorier"
 *    ajoute automatiquement au groupe "Bureau").
 *  - "implies_group_id" sur un groupe : être membre de CE groupe ajoute
 *    automatiquement comme membre du groupe désigné (ex : "Bureau" implique
 *    "Conseil d'Administration").
 *
 * Principe important : ces règles n'AJOUTENT jamais que des adhésions —
 * elles n'en retirent jamais automatiquement. Un retrait manuel (décocher
 * quelqu'un d'un groupe) reste toujours possible et n'est jamais annulé par
 * la resynchronisation.
 *
 * Nécessite la migration planning/migrations/002_group_auto_rules.sql.
 * Toutes les fonctions sont silencieuses (no-op) tant qu'elle n'est pas
 * appliquée, pour ne rien casser en attendant.
 */

if (!function_exists('volunteer_groups_schema_ready')) {
    function volunteer_groups_schema_ready(PDO $pdo): bool {
        static $ready = null;
        if ($ready !== null) return $ready;
        try {
            $stmt = $pdo->prepare("
                SELECT COUNT(*) FROM information_schema.columns
                WHERE table_schema = DATABASE() AND table_name = 'volunteer_groups' AND column_name = 'implies_group_id'
            ");
            $stmt->execute();
            $ready = (bool)$stmt->fetchColumn();
        } catch (Throwable) {
            $ready = false;
        }
        return $ready;
    }
}

/**
 * Applique les règles d'auto-attribution pour UN utilisateur :
 * 1) l'ajoute aux groupes dont "auto_functions" contient sa fonction actuelle ;
 * 2) fait remonter la cascade "implies_group_id" sur tous les groupes dont il
 *    est déjà membre (y compris ceux ajoutés à l'étape 1), jusqu'à stabilisation.
 */
if (!function_exists('volunteer_groups_sync_auto_memberships')) {
    function volunteer_groups_sync_auto_memberships(PDO $pdo, int $volunteerId, ?string $memberFunction = null): void {
        if ($volunteerId <= 0 || !volunteer_groups_schema_ready($pdo)) return;

        // 1) Fonction -> groupe
        if ($memberFunction !== null && $memberFunction !== '') {
            $stmt = $pdo->prepare("
                SELECT id FROM volunteer_groups
                WHERE auto_functions IS NOT NULL AND FIND_IN_SET(:fn, auto_functions)
            ");
            $stmt->execute(['fn' => $memberFunction]);
            foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $groupId) {
                $pdo->prepare("INSERT IGNORE INTO volunteer_group_members (group_id, volunteer_id) VALUES (?, ?)")
                    ->execute([(int)$groupId, $volunteerId]);
            }
        }

        // 2) Cascade groupe -> groupe (implies_group_id), jusqu'à stabilisation (max 10 tours,
        //    largement suffisant — évite juste une boucle infinie en cas de cycle mal configuré)
        for ($i = 0; $i < 10; $i++) {
            $stmt = $pdo->prepare("
                SELECT DISTINCT g.implies_group_id
                FROM volunteer_group_members m
                JOIN volunteer_groups g ON g.id = m.group_id
                WHERE m.volunteer_id = :vid AND g.implies_group_id IS NOT NULL
                  AND NOT EXISTS (
                      SELECT 1 FROM volunteer_group_members m2
                      WHERE m2.volunteer_id = :vid AND m2.group_id = g.implies_group_id
                  )
            ");
            $stmt->execute(['vid' => $volunteerId]);
            $toAdd = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
            if (!$toAdd) break;
            $ins = $pdo->prepare("INSERT IGNORE INTO volunteer_group_members (group_id, volunteer_id) VALUES (?, ?)");
            foreach ($toAdd as $gid) $ins->execute([$gid, $volunteerId]);
        }
    }
}

/** Applique la cascade "implies_group_id" pour TOUS les membres actuels d'un groupe donné. */
if (!function_exists('volunteer_groups_sync_cascade_for_group')) {
    function volunteer_groups_sync_cascade_for_group(PDO $pdo, int $groupId): void {
        if (!volunteer_groups_schema_ready($pdo)) return;
        $stmt = $pdo->prepare("SELECT volunteer_id FROM volunteer_group_members WHERE group_id = ?");
        $stmt->execute([$groupId]);
        foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $vid) {
            volunteer_groups_sync_auto_memberships($pdo, (int)$vid);
        }
    }
}

/** Ajoute au groupe donné tous les utilisateurs actifs dont la fonction matche son "auto_functions". */
if (!function_exists('volunteer_groups_apply_group_functions')) {
    function volunteer_groups_apply_group_functions(PDO $pdo, int $groupId): void {
        if (!volunteer_groups_schema_ready($pdo)) return;
        $stmt = $pdo->prepare("SELECT auto_functions FROM volunteer_groups WHERE id = ?");
        $stmt->execute([$groupId]);
        $raw = $stmt->fetchColumn();
        if (!$raw) return;

        $codes = array_values(array_filter(array_map('trim', explode(',', (string)$raw))));
        if (!$codes) return;

        $placeholders = implode(',', array_fill(0, count($codes), '?'));
        $stmt = $pdo->prepare("SELECT id FROM planning_volunteers WHERE is_active = 1 AND member_function IN ($placeholders)");
        $stmt->execute($codes);
        $ins = $pdo->prepare("INSERT IGNORE INTO volunteer_group_members (group_id, volunteer_id) VALUES (?, ?)");
        foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $vid) {
            $ins->execute([$groupId, (int)$vid]);
        }
    }
}

/**
 * Amorce, UNE SEULE FOIS et sans jamais écraser une config existante, les règles
 * par défaut entre un groupe nommé "Bureau" et un groupe dont le nom contient
 * "conseil d'administration" (ou nommé "CA") — détectés par leur NOM, tels
 * qu'ils existent déjà dans l'interface Groupes. Ne fait rien si l'un des deux
 * groupes n'existe pas, ou si les règles ont déjà été définies (implies_group_id
 * / auto_functions non NULL) — dans ce cas, gère-les depuis l'interface.
 *
 * @return int|null l'id du groupe "Bureau" si les règles viennent d'être amorcées, sinon null
 */
if (!function_exists('volunteer_groups_autoseed_default_rules')) {
    function volunteer_groups_autoseed_default_rules(PDO $pdo): ?int {
        if (!volunteer_groups_schema_ready($pdo)) return null;

        $rows = $pdo->query("SELECT id, name, implies_group_id, auto_functions FROM volunteer_groups")->fetchAll(PDO::FETCH_ASSOC);
        $bureau = null;
        $ca     = null;
        foreach ($rows as $r) {
            $n = mb_strtolower(trim((string)$r['name']), 'UTF-8');
            if ($bureau === null && $n === 'bureau') $bureau = $r;
            if ($ca === null && ($n === 'ca' || str_contains($n, "conseil d'administration"))) $ca = $r;
        }
        if (!$bureau || !$ca || (int)$bureau['id'] === (int)$ca['id']) return null;

        $needsImplies = $bureau['implies_group_id'] === null;
        $needsFns     = $bureau['auto_functions'] === null;
        if (!$needsImplies && !$needsFns) return null;

        $officerCodes = defined('MEMBER_FUNCTIONS') ? implode(',', array_keys(MEMBER_FUNCTIONS)) : null;
        $pdo->prepare("
            UPDATE volunteer_groups
            SET implies_group_id = COALESCE(implies_group_id, ?),
                auto_functions   = COALESCE(auto_functions, ?)
            WHERE id = ?
        ")->execute([(int)$ca['id'], $officerCodes, (int)$bureau['id']]);

        return (int)$bureau['id'];
    }
}
