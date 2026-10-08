<?php
/**
 * Fonctions métier pour le module Prospection (démarchage matériel/dons)
 * Touraine-Ukraine
 */

const PROSPECTION_STATUTS = [
    'a_contacter' => 'À contacter',
    'contacte'    => 'Contacté',
    'a_rappeler'  => 'À rappeler',
    'accorde'     => 'Accordé',
    'refuse'      => 'Refusé',
];

const PROSPECTION_STATUT_BADGES = [
    'a_contacter' => 'tu-bdg-ink',
    'contacte'    => 'tu-bdg-blue',
    'a_rappeler'  => 'tu-bdg-amber',
    'accorde'     => 'tu-bdg-green',
    'refuse'      => 'tu-bdg-red',
];

/* ────────────────────────────────────────────────────────────────────────
   Catégories
   ──────────────────────────────────────────────────────────────────────── */

/**
 * Liste des catégories, groupées par famille pour l'affichage (nav, filtres).
 * Retourne un tableau plat trié famille_sort/sort_order — le regroupement
 * visuel se fait côté appelant sur le champ famille_label.
 */
function get_categories_prospection(PDO $pdo, bool $onlyActive = true): array {
    $sql = "SELECT * FROM prospection_categories";
    if ($onlyActive) $sql .= " WHERE is_active = 1";
    $sql .= " ORDER BY famille_sort ASC, sort_order ASC, label ASC";
    return $pdo->query($sql)->fetchAll();
}

/**
 * Libellé d'affichage d'une catégorie : masque un suffixe "- FR" / "– FR" en
 * fin de nom (ex: "Magasins - FR" → "Magasins") — l'info d'origine reste
 * intacte en base (schema.sql, filtres par code), seul l'affichage change.
 */
function prospection_libelle_categorie(string $label): string {
    return trim((string)preg_replace('/\s*[-–]\s*FR\s*$/iu', '', $label));
}

function get_categorie_prospection(PDO $pdo, $id) {
    $stmt = $pdo->prepare("SELECT * FROM prospection_categories WHERE id = ?");
    $stmt->execute([(int)$id]);
    return $stmt->fetch();
}

function get_categorie_prospection_by_code(PDO $pdo, string $code) {
    $stmt = $pdo->prepare("SELECT * FROM prospection_categories WHERE code = ?");
    $stmt->execute([$code]);
    return $stmt->fetch();
}

/* ────────────────────────────────────────────────────────────────────────
   Fiches (contacts)
   ──────────────────────────────────────────────────────────────────────── */

/**
 * Créer une fiche structure. $data attend les clés : nom, commune, adresse,
 * telephone, email, site_web, contact_referent, priorite. $extra est un
 * tableau associatif [colonne_source => valeur] stocké tel quel en JSON.
 */
/**
 * Extrait un nom de commune depuis une adresse complète du type
 * "19 place Jean Jaurès, 37000 Tours" → "Tours". Utilisé en secours quand une
 * fiche n'a qu'une adresse complète et pas de colonne "commune" séparée dans
 * le fichier d'origine (cas du tableau JOUETS_Magasins - FR par exemple).
 * Retourne null si aucun code postal + ville n'est identifiable.
 */
/**
 * Découpe une adresse complète en voie + "code postal ville", pour un
 * affichage sur deux lignes (ex: "19 place Jean Jaurès" / "37000 Tours").
 * Retourne ['voie' => ..., 'cp_ville' => ...] — cp_ville est vide si aucun
 * code postal à 5 chiffres n'est identifiable en fin d'adresse.
 */
function prospection_decouper_adresse(?string $adresse): array {
    $adresse = trim((string)$adresse);
    if ($adresse === '') return ['voie' => '', 'cp_ville' => ''];
    if (preg_match('/^(.*?)[,;]?\s*(\d{5}\s+[A-Za-zÀ-ÖØ-öø-ÿ][A-Za-zÀ-ÖØ-öø-ÿ\'\-\s]*)\s*$/u', $adresse, $m)) {
        return ['voie' => trim($m[1], " ,;\t"), 'cp_ville' => trim($m[2])];
    }
    return ['voie' => $adresse, 'cp_ville' => ''];
}

/**
 * Nom de commune seul (sans le code postal), déduit d'une adresse complète.
 * Voir prospection_decouper_adresse() pour la version qui garde le code postal.
 */
function prospection_extraire_commune(?string $adresse): ?string {
    $cpVille = prospection_decouper_adresse($adresse)['cp_ville'];
    if ($cpVille === '') return null;
    if (preg_match('/^\d{5}\s+(.+)$/u', $cpVille, $m)) return trim($m[1]);
    return null;
}

function creer_contact_prospection(PDO $pdo, int $categorieId, array $data, array $extra = []) {
    $nom = trim((string)($data['nom'] ?? ''));
    if ($nom === '') return false;

    $extraJson = !empty($extra) ? json_encode($extra, JSON_UNESCAPED_UNICODE) : null;

    $sql = "INSERT INTO prospection_contacts
            (categorie_id, nom, commune, adresse, telephone, email, site_web, contact_referent, priorite, extra_json)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
    $stmt = $pdo->prepare($sql);
    $commune  = $data['commune']  ?? null;
    $adresse  = $data['adresse']  ?? null;
    // Pas de commune renseignée séparément mais une adresse complète disponible
    // (ex: "19 place Jean Jaurès, 37000 Tours") : on en déduit la commune.
    if (($commune === null || trim((string)$commune) === '') && $adresse) {
        $commune = prospection_extraire_commune($adresse);
    }
    $tel      = $data['telephone']?? null;
    $email    = $data['email']    ?? null;
    $siteWeb  = $data['site_web'] ?? null;
    $referent = $data['contact_referent'] ?? null;
    $priorite = $data['priorite'] ?? null;

    $stmt->execute([$categorieId, $nom, $commune, $adresse, $tel, $email, $siteWeb, $referent, $priorite, $extraJson]);

    $id = (int)$pdo->lastInsertId();
    audit_log('prospection', 'creation_fiche', 'contact', $id, $nom);
    return $id;
}

function modifier_contact_prospection(PDO $pdo, int $id, array $data): bool {
    $sql = "UPDATE prospection_contacts SET
            nom = ?, commune = ?, adresse = ?, telephone = ?, email = ?,
            site_web = ?, contact_referent = ?, priorite = ?, statut = ?
            WHERE id = ?";
    $stmt = $pdo->prepare($sql);
    $nom      = trim((string)($data['nom'] ?? ''));
    $commune  = $data['commune']  ?? null;
    $adresse  = $data['adresse']  ?? null;
    $tel      = $data['telephone']?? null;
    $email    = $data['email']    ?? null;
    $siteWeb  = $data['site_web'] ?? null;
    $referent = $data['contact_referent'] ?? null;
    $priorite = $data['priorite'] ?? null;
    $statut   = $data['statut'] ?? 'a_contacter';

    $ok = $stmt->execute([$nom, $commune, $adresse, $tel, $email, $siteWeb, $referent, $priorite, $statut, $id]);
    if ($ok) {
        audit_log('prospection', 'modification_fiche', 'contact', $id, $nom);
    }
    return $ok;
}

function get_contact_prospection(PDO $pdo, $id) {
    $stmt = $pdo->prepare("
        SELECT c.*, cat.code AS categorie_code, cat.label AS categorie_label, cat.famille_label
        FROM prospection_contacts c
        JOIN prospection_categories cat ON cat.id = c.categorie_id
        WHERE c.id = ?
    ");
    $stmt->execute([(int)$id]);
    return $stmt->fetch();
}

/**
 * Liste filtrée des fiches. $filters : categorie_id, famille, statut, recherche (texte libre).
 */
/**
 * Colonnes autorisées pour le tri de la liste (clé publique => expression SQL
 * réelle) — whitelist stricte pour ne jamais injecter $_GET dans un ORDER BY.
 */
function prospection_colonnes_tri(): array {
    return [
        'nom'        => 'c.nom',
        'categorie'  => 'cat.famille_sort, cat.sort_order',
        'adresse'    => 'c.adresse',
        'contact'    => 'c.telephone, c.email',
        'priorite'   => 'c.priorite',
        'statut'     => 'c.statut',
    ];
}

/**
 * Construit la clause WHERE (+ paramètres) commune aux requêtes sur
 * prospection_contacts, à partir des mêmes clés $filters que
 * get_liste_contacts_prospection() : categorie_id, famille, statut,
 * recherche, et en plus ici annee_contact / annee_non_contact (voir
 * get_stats_annee_prospection()). Toujours "c.actif = 1" en base.
 */
function prospection_construire_where(array $filters): array {
    $sql = " WHERE c.actif = 1 ";
    $params = [];

    if (!empty($filters['categorie_id'])) {
        $sql .= " AND c.categorie_id = ?"; $params[] = (int)$filters['categorie_id'];
    }
    if (!empty($filters['famille'])) {
        $sql .= " AND cat.famille_label = ?"; $params[] = $filters['famille'];
    }
    if (!empty($filters['statut'])) {
        $sql .= " AND c.statut = ?"; $params[] = $filters['statut'];
    }
    if (!empty($filters['recherche'])) {
        $sql .= " AND (c.nom LIKE ? OR c.commune LIKE ? OR c.adresse LIKE ? OR c.email LIKE ?)";
        $terme = '%' . $filters['recherche'] . '%';
        $params[] = $terme; $params[] = $terme; $params[] = $terme; $params[] = $terme;
    }
    if (!empty($filters['annee_contact'])) {
        $sql .= " AND EXISTS (SELECT 1 FROM prospection_suivi s WHERE s.contact_id = c.id AND s.type = 'contact' AND s.annee = ?)";
        $params[] = (int)$filters['annee_contact'];
    }
    if (!empty($filters['annee_non_contact'])) {
        $sql .= " AND NOT EXISTS (SELECT 1 FROM prospection_suivi s WHERE s.contact_id = c.id AND s.type = 'contact' AND s.annee = ?)";
        $params[] = (int)$filters['annee_non_contact'];
    }

    return [$sql, $params];
}

function get_liste_contacts_prospection(PDO $pdo, array $filters = [], ?string $triCol = null, string $triDir = 'asc') {
    [$whereSql, $params] = prospection_construire_where($filters);
    $sql = "
        SELECT c.*, cat.code AS categorie_code, cat.label AS categorie_label, cat.famille_label
        FROM prospection_contacts c
        JOIN prospection_categories cat ON cat.id = c.categorie_id
        $whereSql
    ";

    $colonnesTri = prospection_colonnes_tri();
    $dir = strtolower($triDir) === 'desc' ? 'DESC' : 'ASC';
    if ($triCol !== null && isset($colonnesTri[$triCol])) {
        $sql .= " ORDER BY " . $colonnesTri[$triCol] . " $dir, c.nom ASC";
    } else {
        $sql .= " ORDER BY cat.famille_sort ASC, cat.sort_order ASC, c.nom ASC";
    }

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll();
}

/**
 * Compte, parmi les fiches correspondant aux $filters (hors annee_contact /
 * annee_non_contact qui n'ont pas de sens ici), combien ont au moins une
 * entrée de suivi type "contact" pour l'année donnée — pour répondre à
 * "combien de fiches n'ont pas été recontactées cette année".
 */
function get_stats_annee_prospection(PDO $pdo, array $filters, int $annee): array {
    $filtersBase = $filters;
    unset($filtersBase['annee_contact'], $filtersBase['annee_non_contact']);
    [$whereSql, $params] = prospection_construire_where($filtersBase);

    $sql = "
        SELECT
          COUNT(*) AS total,
          SUM(CASE WHEN EXISTS (
                SELECT 1 FROM prospection_suivi s
                WHERE s.contact_id = c.id AND s.type = 'contact' AND s.annee = ?
              ) THEN 1 ELSE 0 END) AS contactees
        FROM prospection_contacts c
        JOIN prospection_categories cat ON cat.id = c.categorie_id
        $whereSql
    ";
    array_unshift($params, $annee);

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $row = $stmt->fetch() ?: ['total' => 0, 'contactees' => 0];

    $total = (int)$row['total']; $contactees = (int)$row['contactees'];
    return ['annee' => $annee, 'total' => $total, 'contactees' => $contactees, 'non_contactees' => $total - $contactees];
}

function get_stats_prospection(PDO $pdo, array $filters = []): array {
    $liste = get_liste_contacts_prospection($pdo, $filters);
    $stats = ['total' => 0, 'par_statut' => []];
    foreach (PROSPECTION_STATUTS as $code => $label) $stats['par_statut'][$code] = 0;
    foreach ($liste as $row) {
        $stats['total']++;
        $stats['par_statut'][$row['statut']] = ($stats['par_statut'][$row['statut']] ?? 0) + 1;
    }
    return $stats;
}

function desactiver_contact_prospection(PDO $pdo, int $id): bool {
    $stmt = $pdo->prepare("UPDATE prospection_contacts SET actif = 0 WHERE id = ?");
    $ok = $stmt->execute([$id]);
    if ($ok) {
        audit_log('prospection', 'desactivation_fiche', 'contact', $id);
    }
    return $ok;
}

/**
 * Suppression définitive d'une fiche (et de son historique de suivi, en
 * cascade via la contrainte FK). Réservé admin+ côté appelant. Contrairement
 * à desactiver_contact_prospection(), c'est irréversible — on journalise le
 * nom avant suppression pour garder une trace dans le journal d'audit.
 */
function supprimer_contact_prospection(PDO $pdo, int $id): bool {
    $contact = get_contact_prospection($pdo, $id);
    if (!$contact) return false;

    $stmt = $pdo->prepare("DELETE FROM prospection_contacts WHERE id = ?");
    $ok = $stmt->execute([$id]);
    if ($ok) {
        audit_log('prospection', 'suppression_fiche', 'contact', $id, $contact['nom']);
    }
    return $ok;
}

/**
 * Libellé lisible pour une priorité brute. Le champ reste stocké en texte
 * libre (les échelles diffèrent selon le tableau d'origine), mais quand la
 * valeur est un simple 1/2/3 on l'affiche en clair — 1 = Haute (à contacter
 * en priorité), 3 = Basse. Toute autre valeur (texte déjà explicite, échelle
 * différente...) est affichée telle quelle.
 */
function prospection_libelle_priorite(?string $priorite): string {
    $p = trim((string)$priorite);
    return match ($p) {
        '1' => 'Haute',
        '2' => 'Moyenne',
        '3' => 'Basse',
        default => $p,
    };
}

function modifier_priorite_contact_prospection(PDO $pdo, int $id, string $priorite): bool {
    $stmt = $pdo->prepare("UPDATE prospection_contacts SET priorite = ? WHERE id = ?");
    return $stmt->execute([$priorite, $id]);
}

function modifier_statut_contact_prospection(PDO $pdo, int $id, string $statut): bool {
    if (!array_key_exists($statut, PROSPECTION_STATUTS)) return false;
    $stmt = $pdo->prepare("UPDATE prospection_contacts SET statut = ? WHERE id = ?");
    return $stmt->execute([$statut, $id]);
}

/**
 * Applique une action en masse (changement de priorité, de statut,
 * désactivation ou suppression définitive) à une liste d'ids de fiches.
 * Réutilise les fonctions unitaires ci-dessus (donc journalisées elles aussi
 * une par une) et ajoute une entrée de synthèse dans le journal d'audit.
 * Retourne ['ok' => nb réussies, 'fail' => nb échouées].
 */
function prospection_action_masse(PDO $pdo, string $action, array $ids, array $options = []): array {
    $ids = array_values(array_unique(array_filter(array_map('intval', $ids), fn($id) => $id > 0)));
    $ok = 0; $fail = 0;

    foreach ($ids as $id) {
        $success = match ($action) {
            'desactiver' => desactiver_contact_prospection($pdo, $id),
            'supprimer'  => supprimer_contact_prospection($pdo, $id),
            'priorite'   => modifier_priorite_contact_prospection($pdo, $id, (string)($options['priorite'] ?? '')),
            'statut'     => modifier_statut_contact_prospection($pdo, $id, (string)($options['statut'] ?? '')),
            default      => false,
        };
        if ($success) $ok++; else $fail++;
    }

    audit_log('prospection', 'action_masse_' . $action, 'contact', null, null,
        array_merge(['nb_fiches' => count($ids), 'nb_ok' => $ok, 'nb_echec' => $fail], $options));

    return ['ok' => $ok, 'fail' => $fail];
}

/* ────────────────────────────────────────────────────────────────────────
   Suivi (journal historisé, multi-années)
   ──────────────────────────────────────────────────────────────────────── */

/**
 * Ajoute une entrée de suivi. $dateStr accepte une date déjà au format
 * Y-m-d, ou du texte brut (on tente alors d'en extraire une date, sinon on
 * date l'entrée du jour et on garde le texte original dans le commentaire).
 */
function ajouter_suivi_prospection(PDO $pdo, int $contactId, string $type, string $rawValue, ?string $auteur = null): bool {
    $rawValue = trim($rawValue);
    if ($rawValue === '') return false;

    $date = prospection_extraire_date($rawValue) ?? date('Y-m-d');
    $annee = (int)date('Y', strtotime($date));

    $stmt = $pdo->prepare("
        INSERT INTO prospection_suivi (contact_id, type, date_suivi, annee, auteur, commentaire)
        VALUES (?, ?, ?, ?, ?, ?)
    ");
    $ok = $stmt->execute([$contactId, $type, $date, $annee, $auteur, $rawValue]);

    if ($ok) {
        mettre_a_jour_statut_auto_prospection($pdo, $contactId);
        audit_log('prospection', 'ajout_suivi', 'contact', $contactId, null, ['type' => $type, 'date' => $date]);
    }
    return $ok;
}

/**
 * Supprime une seule entrée de suivi. $contactIdAttendu, si fourni, vérifie
 * que l'entrée appartient bien à cette fiche avant de la supprimer (évite
 * qu'un id bricolé dans le formulaire touche une autre fiche).
 */
function supprimer_suivi_prospection(PDO $pdo, int $suiviId, ?int $contactIdAttendu = null): bool {
    $sql = "SELECT contact_id, type, date_suivi FROM prospection_suivi WHERE id = ?";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$suiviId]);
    $row = $stmt->fetch();
    if (!$row) return false;
    if ($contactIdAttendu !== null && (int)$row['contact_id'] !== $contactIdAttendu) return false;

    $del = $pdo->prepare("DELETE FROM prospection_suivi WHERE id = ?");
    $ok = $del->execute([$suiviId]);

    if ($ok) {
        mettre_a_jour_statut_auto_prospection($pdo, (int)$row['contact_id']);
        audit_log('prospection', 'suppression_suivi', 'contact', (int)$row['contact_id'], null,
            ['type' => $row['type'], 'date' => $row['date_suivi']]);
    }
    return $ok;
}

/**
 * Marque/démarque rapidement une année comme "contactée", sans exiger de
 * commentaire détaillé (utile quand on sait que la structure a été
 * contactée telle année sans avoir le détail de l'historique). Idempotent :
 * un second appel avec $contacte=true n'ajoute pas de doublon.
 *
 * $contacte = true  → ajoute une entrée de suivi minimale si aucune entrée
 *                      "contact" n'existe déjà pour cette année.
 * $contacte = false → supprime les entrées "contact" de cette année ajoutées
 *                      par ce marqueur rapide (repère : commentaire = la
 *                      constante ci-dessous), en laissant intact tout suivi
 *                      détaillé saisi par ailleurs pour la même année.
 */
const PROSPECTION_SUIVI_MARQUEUR_RAPIDE = '(Marqué contactée — sans détail)';

function prospection_marquer_annee_contact(PDO $pdo, int $contactId, int $annee, bool $contacte, ?string $auteur = null): bool {
    if ($contacte) {
        $stmt = $pdo->prepare("
            SELECT COUNT(*) AS n FROM prospection_suivi
            WHERE contact_id = ? AND type = 'contact' AND annee = ?
        ");
        $stmt->execute([$contactId, $annee]);
        $existe = (int)($stmt->fetch()['n'] ?? 0) > 0;
        if ($existe) return true; // déjà marquée contactée, rien à faire

        $anneeCourante = (int)date('Y');
        $date = $annee === $anneeCourante ? date('Y-m-d') : sprintf('%04d-12-31', $annee);

        $ins = $pdo->prepare("
            INSERT INTO prospection_suivi (contact_id, type, date_suivi, annee, auteur, commentaire)
            VALUES (?, 'contact', ?, ?, ?, ?)
        ");
        $commentaire = PROSPECTION_SUIVI_MARQUEUR_RAPIDE;
        $ok = $ins->execute([$contactId, $date, $annee, $auteur, $commentaire]);
        if ($ok) {
            mettre_a_jour_statut_auto_prospection($pdo, $contactId);
            audit_log('prospection', 'marquage_annee_contactee', 'contact', $contactId, null, ['annee' => $annee]);
        }
        return $ok;
    }

    // Démarquer : ne retire que les entrées ajoutées par ce marqueur rapide.
    $del = $pdo->prepare("
        DELETE FROM prospection_suivi
        WHERE contact_id = ? AND type = 'contact' AND annee = ? AND commentaire = ?
    ");
    $commentaire = PROSPECTION_SUIVI_MARQUEUR_RAPIDE;
    $ok = $del->execute([$contactId, $annee, $commentaire]);
    if ($ok) {
        mettre_a_jour_statut_auto_prospection($pdo, $contactId);
        audit_log('prospection', 'demarquage_annee_contactee', 'contact', $contactId, null, ['annee' => $annee]);
    }
    return $ok;
}

/**
 * Tente d'extraire une date d'un texte libre (jj/mm/aaaa, jj/mm/aa, jj-mm-aaaa,
 * aaaa-mm-jj). Retourne null si aucun format connu n'est reconnu.
 */
/**
 * Mois français (abrégés ou complets, avec ou sans accent) → numéro de mois.
 */
function prospection_mois_francais(): array {
    return [
        'janv' => 1, 'janvier' => 1, 'jan' => 1,
        'fevr' => 2, 'fevrier' => 2, 'fev' => 2,
        'mars' => 3, 'mar' => 3,
        'avr' => 4, 'avril' => 4,
        'mai' => 5,
        'juin' => 6,
        'juil' => 7, 'juillet' => 7, 'jul' => 7,
        'aout' => 8,
        'sept' => 9, 'septembre' => 9, 'sep' => 9,
        'oct' => 10, 'octobre' => 10,
        'nov' => 11, 'novembre' => 11,
        'dec' => 12, 'decembre' => 12,
    ];
}

/**
 * Extrait une date d'un texte libre. Gère les formats numériques classiques
 * (2026-01-09, 09/01/2026, 09-01-26) et le format "9-sept" / "9 septembre
 * 2025" fréquent dans les colonnes "Contacté" des tableaux d'origine —
 * lu tel quel sans passer par Excel, ce format ne se reconnaît pas comme une
 * date par les regex numériques et était auparavant silencieusement ignoré
 * (la fiche recevait alors la date du jour de l'IMPORT au lieu de la date
 * réelle du contact).
 *
 * Quand le jour et le mois sont trouvés mais pas l'année (cas "9-sept"), on
 * déduit l'année la plus plausible : cette année si la date n'est pas encore
 * passée cette année-ci sinon... — en pratique on suppose qu'une date de
 * suivi n'est jamais dans le futur, donc si le jour/mois n'est pas encore
 * arrivé cette année on retient l'année précédente, sinon l'année en cours.
 */
function prospection_extraire_date(string $text): ?string {
    if (preg_match('/(\d{4})-(\d{1,2})-(\d{1,2})/', $text, $m)) {
        return sprintf('%04d-%02d-%02d', (int)$m[1], (int)$m[2], (int)$m[3]);
    }
    if (preg_match('/(\d{1,2})[\/\-](\d{1,2})[\/\-](\d{4})/', $text, $m)) {
        return sprintf('%04d-%02d-%02d', (int)$m[3], (int)$m[2], (int)$m[1]);
    }
    if (preg_match('/(\d{1,2})[\/\-](\d{1,2})[\/\-](\d{2})$/', $text, $m)) {
        $year = 2000 + (int)$m[3];
        return sprintf('%04d-%02d-%02d', $year, (int)$m[2], (int)$m[1]);
    }
    // "9-sept", "9 sept.", "9 septembre 2025", "9-sept-25"...
    if (preg_match('/\b(\d{1,2})\s*[\-\/ ]\s*([A-Za-zÀ-ÿ]{3,10})\.?\s*[\-\/ ]?\s*(\d{4}|\d{2})?\b/u', $text, $m)) {
        $jour = (int)$m[1];
        $moisTxt = prospection_normaliser_entete($m[2]);
        $mois = prospection_mois_francais()[$moisTxt] ?? null;
        if ($mois !== null && $jour >= 1 && $jour <= 31) {
            if (!empty($m[3])) {
                $annee = strlen($m[3]) === 2 ? 2000 + (int)$m[3] : (int)$m[3];
            } else {
                $auj = new DateTime();
                $anneeCourante = (int)$auj->format('Y');
                $estDejaPasseeCetteAnnee = ($mois < (int)$auj->format('n')) || ($mois === (int)$auj->format('n') && $jour <= (int)$auj->format('j'));
                $annee = $estDejaPasseeCetteAnnee ? $anneeCourante : $anneeCourante - 1;
            }
            if (checkdate($mois, $jour, $annee)) {
                return sprintf('%04d-%02d-%02d', $annee, $mois, $jour);
            }
        }
    }
    return null;
}

/**
 * Historique groupé par année (le plus récent en premier), pour l'affichage
 * "Année N, N-1, ..." demandé sur la fiche.
 */
function get_historique_suivi_prospection(PDO $pdo, int $contactId): array {
    $stmt = $pdo->prepare("
        SELECT * FROM prospection_suivi
        WHERE contact_id = ?
        ORDER BY date_suivi DESC, id DESC
    ");
    $stmt->execute([$contactId]);

    $parAnnee = [];
    foreach ($stmt->fetchAll() as $row) {
        $parAnnee[(int)$row['annee']][] = $row;
    }
    krsort($parAnnee);
    return $parAnnee;
}

/**
 * Résumé "année → a-t-on contacté cette structure ?" pour une fiche, à
 * partir de son historique de suivi complet (get_historique_suivi_prospection).
 * Couvre les $anneesAffichees demandées (par défaut : année courante et les
 * 4 précédentes), même si aucune entrée n'existe pour certaines d'entre
 * elles (contact === false dans ce cas). Sert à répondre visuellement à
 * "a-t-on contacté cette structure en N-1 ?" sans avoir à relire tout
 * l'historique brut.
 */
function prospection_resume_annees_contact(array $historiqueParAnnee, ?array $anneesAffichees = null): array {
    if ($anneesAffichees === null) {
        $anneeCourante = (int)date('Y');
        $anneesAffichees = range($anneeCourante, $anneeCourante - 4);
    }

    $resume = [];
    foreach ($anneesAffichees as $annee) {
        $entrees = $historiqueParAnnee[$annee] ?? [];
        $contacts = array_values(array_filter($entrees, fn($e) => $e['type'] === 'contact'));
        $rappels  = array_values(array_filter($entrees, fn($e) => $e['type'] === 'rappel'));
        $marqueRapide = array_values(array_filter($contacts, fn($e) => $e['commentaire'] === PROSPECTION_SUIVI_MARQUEUR_RAPIDE));
        $resume[$annee] = [
            'annee'           => $annee,
            'contacte'        => count($contacts) > 0,
            'nb_contacts'     => count($contacts),
            'nb_rappels'      => count($rappels),
            'nb_entrees'      => count($entrees),
            'derniere_date'   => $entrees[0]['date_suivi'] ?? null,
            'peut_demarquer'  => count($marqueRapide) > 0 && count($marqueRapide) === count($contacts),
        ];
    }
    return $resume;
}

/**
 * Déduit un statut "à jour" à partir de la dernière entrée de suivi.
 * N'écrase jamais un statut manuel accorde/refuse.
 */
function mettre_a_jour_statut_auto_prospection(PDO $pdo, int $contactId): void {
    $stmt = $pdo->prepare("SELECT statut FROM prospection_contacts WHERE id = ?");
    $stmt->execute([$contactId]);
    $current = $stmt->fetch();
    if (!$current) return;
    if (in_array($current['statut'], ['accorde', 'refuse'], true)) return; // décisions manuelles, on ne touche pas

    $stmt = $pdo->prepare("SELECT type FROM prospection_suivi WHERE contact_id = ? ORDER BY date_suivi DESC, id DESC LIMIT 1");
    $stmt->execute([$contactId]);
    $last = $stmt->fetch();
    if (!$last) return;

    $nouveauStatut = match ($last['type']) {
        'rappel'  => 'a_rappeler',
        'contact' => 'contacte',
        default   => $current['statut'],
    };
    if ($nouveauStatut !== $current['statut']) {
        $upd = $pdo->prepare("UPDATE prospection_contacts SET statut = ? WHERE id = ?");
        $upd->execute([$nouveauStatut, $contactId]);
    }
}

/* ────────────────────────────────────────────────────────────────────────
   Import CSV — mappage manuel des colonnes
   ──────────────────────────────────────────────────────────────────────── */

/**
 * Champs cibles proposés dans l'écran de mappage manuel des colonnes.
 */
function prospection_import_champs_cibles(): array {
    return [
        'nom'              => 'Nom (obligatoire)',
        'commune'          => 'Commune',
        'adresse'          => 'Adresse',
        'telephone'        => 'Téléphone',
        'email'            => 'Email',
        'site_web'         => 'Site web',
        'contact_referent' => 'Contact / référent',
        'priorite'         => 'Priorité',
        'signal_contact'   => 'Suivi — Contact (date/texte)',
        'signal_rappel'    => 'Suivi — À rappeler (date/texte)',
        'signal_note'      => 'Suivi — Note',
        'ignorer'          => 'Ignorer (conservée en donnée brute)',
    ];
}

/**
 * Calcule une proposition de mappage par défaut [entête_reelle => champ_cible]
 * pour les entêtes détectées dans le fichier, à partir du profil connu de la
 * catégorie (ou d'une reconnaissance par mots-clés à défaut) — l'utilisateur
 * peut ensuite l'ajuster à la main avant de lancer l'import.
 */
function prospection_import_mappage_suggere(array $headers, ?array $profile): array {
    $headerRow = array_combine($headers, $headers);
    $suggestion = [];

    if ($profile) {
        foreach ($profile['fields'] ?? [] as $srcCol => $target) {
            $real = prospection_trouver_colonne($headerRow, $srcCol);
            if ($real !== null) $suggestion[$real] = $target;
        }
        foreach (($profile['contact_signal_cols'] ?? []) as $declared) {
            $real = prospection_trouver_colonne($headerRow, $declared);
            if ($real !== null) $suggestion[$real] = 'signal_contact';
        }
        foreach (($profile['rappel_signal_cols'] ?? []) as $declared) {
            $real = prospection_trouver_colonne($headerRow, $declared);
            if ($real !== null) $suggestion[$real] = 'signal_rappel';
        }
        foreach (($profile['note_signal_cols'] ?? []) as $declared) {
            $real = prospection_trouver_colonne($headerRow, $declared);
            if ($real !== null) $suggestion[$real] = 'signal_note';
        }
        foreach (array_filter([$profile['date_contact_col'] ?? null, $profile['mode_contact_col'] ?? null]) as $declared) {
            $real = prospection_trouver_colonne($headerRow, $declared);
            if ($real !== null) $suggestion[$real] = 'signal_contact';
        }
    }

    // Colonnes non résolues par le profil : reconnaissance par mots-clés.
    $keywords = [
        'nom'              => ['nom', 'entité', 'entite', 'société', 'societe', 'structure', 'enseigne', 'établissement', 'etablissement', 'organisme', 'service', 'agence'],
        'commune'          => ['commune', 'ville'],
        'adresse'          => ['adresse'],
        'telephone'        => ['téléphone', 'telephone', 'tel', 'numéro', 'numero'],
        'email'            => ['email', 'e-mail', 'mail'],
        'site_web'         => ['site web', 'site @', 'site'],
        'contact_referent' => ['contact', 'référent', 'referent', 'gestionnaire'],
        'priorite'         => ['priorité', 'priorite', 'prio', 'filtre'],
        'signal_contact'   => ['contacté', 'contacte'],
        'signal_rappel'    => ['rappeler', 'rappel'],
        'signal_note'      => ['verdict', 'observation', 'commentaire', 'note'],
    ];
    foreach ($headers as $header) {
        if (isset($suggestion[$header])) continue;
        // Les entêtes vides sont libellées "(colonne sans nom)" par
        // prospection_lire_csv — à ne surtout pas passer par la reconnaissance
        // par mots-clés, sinon "nom" matche dans "colonne sans nom" et
        // suggère à tort le champ Nom sur une colonne dont on ne sait rien.
        if ($header === '' || str_starts_with($header, '(colonne sans nom')) { $suggestion[$header] = 'ignorer'; continue; }
        $needle = prospection_normaliser_entete($header);
        if ($needle === '') { $suggestion[$header] = 'ignorer'; continue; }
        $found = 'ignorer';
        foreach ($keywords as $target => $terms) {
            foreach ($terms as $term) {
                if (str_contains($needle, prospection_normaliser_entete($term))) { $found = $target; break 2; }
            }
        }
        $suggestion[$header] = $found;
    }

    return $suggestion;
}

/**
 * Applique une ligne de CSV selon un mappage manuel [entête => champ_cible]
 * choisi par l'utilisateur dans l'écran de mappage (voir import.php).
 * Toute colonne non consommée (cible 'ignorer' ou non mappée) part dans
 * extra_json — rien n'est perdu. Retourne l'id créé, ou 0 si ignorée (pas de nom).
 */
function prospection_import_appliquer_ligne_mapping(PDO $pdo, int $categorieId, array $mapping, array $row): int {
    $data = [];
    $signaux = ['signal_contact' => [], 'signal_rappel' => [], 'signal_note' => []];
    $consumed = [];

    foreach ($mapping as $header => $target) {
        if ($target === 'ignorer' || $target === '' || !array_key_exists($header, $row)) continue;
        $val = trim((string)$row[$header]);
        if ($val === '') continue;
        $consumed[] = $header;

        if (isset($signaux[$target])) {
            $signaux[$target][] = $val;
        } else {
            $data[$target] = $val;
        }
    }

    if (trim((string)($data['nom'] ?? '')) === '') return 0;

    $extra = [];
    foreach ($row as $col => $val) {
        if (in_array($col, $consumed, true)) continue;
        if (trim((string)$val) !== '') $extra[$col] = $val;
    }

    $contactId = creer_contact_prospection($pdo, $categorieId, $data, $extra);
    if (!$contactId) return 0;

    $auteur = 'Import CSV';
    foreach ($signaux['signal_contact'] as $val) ajouter_suivi_prospection($pdo, $contactId, 'contact', $val, $auteur);
    foreach ($signaux['signal_rappel']  as $val) ajouter_suivi_prospection($pdo, $contactId, 'rappel',  $val, $auteur);
    foreach ($signaux['signal_note']   as $val) ajouter_suivi_prospection($pdo, $contactId, 'note',    $val, $auteur);

    return $contactId;
}

/* ────────────────────────────────────────────────────────────────────────
   Import CSV
   ──────────────────────────────────────────────────────────────────────── */

/**
 * Applique une ligne de CSV (déjà lue en tableau associatif [entête => valeur])
 * selon un profil (voir import_profiles.php) : crée la fiche + les entrées de
 * suivi induites par les colonnes "signal", et range le reste dans extra_json.
 * Retourne l'id de la fiche créée, ou 0 si la ligne est ignorée (pas de nom).
 */
/**
 * Normalise un entête de colonne pour un rapprochement tolérant aux petites
 * variations entre le profil attendu et le fichier réellement exporté
 * (espaces multiples/insécables, casse, accents, espace superflu en fin de
 * cellule Excel...). "Entité", " entité ", "ENTITE" matchent tous entre eux.
 */
function prospection_normaliser_entete(string $s): string {
    $s = str_replace("\xC2\xA0", ' ', $s); // espace insécable (fréquent dans les exports Excel)
    $s = trim($s);
    $s = mb_strtolower($s, 'UTF-8');
    $translit = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $s);
    if ($translit !== false && $translit !== '') $s = $translit;
    $s = preg_replace('/\s+/', ' ', $s);
    return trim((string)$s);
}

/**
 * Retourne la clé réelle de $row correspondant à $wantedHeader (rapprochement
 * normalisé), ou null si aucune colonne du fichier ne correspond.
 */
function prospection_trouver_colonne(array $row, string $wantedHeader): ?string {
    $wantedNorm = prospection_normaliser_entete($wantedHeader);
    foreach (array_keys($row) as $col) {
        if (prospection_normaliser_entete((string)$col) === $wantedNorm) return $col;
    }
    return null;
}

function prospection_import_appliquer_ligne(PDO $pdo, int $categorieId, array $profile, array $row): int {
    $data = [];
    $consumed = [];
    foreach ($profile['fields'] as $srcCol => $target) {
        $realCol = prospection_trouver_colonne($row, $srcCol);
        if ($realCol === null) continue;
        $consumed[] = $realCol;
        if (trim((string)$row[$realCol]) !== '') {
            $data[$target] = trim((string)$row[$realCol]);
        }
    }

    if (trim((string)($data['nom'] ?? '')) === '') return 0;

    $signalColsDeclared = array_merge(
        $profile['contact_signal_cols'] ?? [],
        $profile['rappel_signal_cols']  ?? [],
        $profile['note_signal_cols']    ?? [],
        array_filter([$profile['date_contact_col'] ?? null, $profile['mode_contact_col'] ?? null])
    );
    // Résout chaque nom de colonne "signal" déclaré dans le profil vers la
    // vraie clé de $row (mêmes tolérances que pour 'fields' ci-dessus).
    $resolve = fn(?string $declared): ?string => $declared !== null ? prospection_trouver_colonne($row, $declared) : null;

    $signalColsReal = [];
    foreach ($signalColsDeclared as $declared) {
        $real = $resolve($declared);
        if ($real !== null) $signalColsReal[] = $real;
    }

    $extra = [];
    foreach ($row as $col => $val) {
        if (in_array($col, $consumed, true) || in_array($col, $signalColsReal, true)) continue;
        if (trim((string)$val) !== '') $extra[$col] = $val;
    }

    $contactId = creer_contact_prospection($pdo, $categorieId, $data, $extra);
    if (!$contactId) return 0;

    $auteur = 'Import CSV';
    foreach (($profile['contact_signal_cols'] ?? []) as $declared) {
        $real = $resolve($declared);
        if ($real !== null && !empty($row[$real])) ajouter_suivi_prospection($pdo, $contactId, 'contact', (string)$row[$real], $auteur);
    }
    foreach (($profile['rappel_signal_cols'] ?? []) as $declared) {
        $real = $resolve($declared);
        if ($real !== null && !empty($row[$real])) ajouter_suivi_prospection($pdo, $contactId, 'rappel', (string)$row[$real], $auteur);
    }
    foreach (($profile['note_signal_cols'] ?? []) as $declared) {
        $real = $resolve($declared);
        if ($real !== null && !empty($row[$real])) ajouter_suivi_prospection($pdo, $contactId, 'note', (string)$row[$real], $auteur);
    }
    if (!empty($profile['date_contact_col']) || !empty($profile['mode_contact_col'])) {
        $realDate = $resolve($profile['date_contact_col'] ?? null);
        $realMode = $resolve($profile['mode_contact_col'] ?? null);
        $d = trim((string)($realDate !== null ? ($row[$realDate] ?? '') : ''));
        $m = trim((string)($realMode !== null ? ($row[$realMode] ?? '') : ''));
        if ($d !== '' || $m !== '') {
            $note = trim($m . ($d !== '' ? ' — ' . $d : ''));
            ajouter_suivi_prospection($pdo, $contactId, 'contact', $note, $auteur);
        }
    }

    return $contactId;
}
