<?php
/**
 * shared/audit.php — Journal d'audit de toute la suite.
 *
 * Fichier autonome : utilisable par n'importe quel module, y compris les
 * endpoints qui n'incluent pas shared/bootstrap.php (ex. logistique/boxes/api_create.php).
 * Il suffit de :  require_once __DIR__ . '/../shared/audit.php';
 *
 * Écrit dans la table suite_audit_log (voir admin/migrations/001_suite_audit_log.sql).
 *
 * Format de details_json :
 *   {
 *     "changes": { "champ": {"from": <avant>, "to": <après>}, ... },   // modifications
 *     "<clé>":   <valeur>,                                              // contexte libre
 *     "_page":   "/tools/logistique/boxes/api_create.php"               // page à l'origine (auto)
 *   }
 *
 * Aucune exception n'est jamais propagée : un souci de journal ne doit pas
 * empêcher l'action métier (l'erreur part dans error_log).
 */

require_once __DIR__ . '/db.php';

/* ====================================================================
   ÉCRITURE
   ==================================================================== */

if (!function_exists('audit_actor')) {
    /** @return array{id:?int,name:string,role:string} acteur courant d'après la session */
    function audit_actor(): array {
        $s = $_SESSION ?? [];
        $id = isset($s['volunteer_id']) ? (int)$s['volunteer_id'] : null;
        $role = !empty($s['super_admin']) ? 'super_admin'
              : ((!empty($s['admin_plus']) || !empty($s['is_admin_plus'])) ? 'admin_plus'
              : ((!empty($s['is_admin']) || !empty($s['admin_authenticated']) || !empty($s['admin'])) ? 'admin' : 'public'));
        $name = $id !== null ? trim((string)($s['volunteer_name'] ?? '')) : '';
        if ($name === '' && $id !== null) {
            // Session de bénévole simple (planning) : le nom n'est pas en session, on le retrouve en base.
            static $cache = [];
            if (!isset($cache[$id])) {
                try {
                    $q = suite_pdo()->prepare("SELECT first_name, last_name FROM users WHERE id = ?");
                    $q->execute([$id]);
                    $u = $q->fetch(PDO::FETCH_ASSOC);
                    $cache[$id] = $u ? trim($u['first_name'] . ' ' . $u['last_name']) : '';
                } catch (Throwable) {
                    $cache[$id] = '';
                }
            }
            $name = $cache[$id];
        }
        if ($id === null && $role === 'public' && PHP_SAPI === 'cli') {
            return ['id' => null, 'name' => 'Système (tâche planifiée)', 'role' => 'system'];
        }
        if ($name === '') $name = $id !== null ? 'Utilisateur #' . $id : ($role === 'public' ? 'Visiteur non connecté' : 'Inconnu');
        return ['id' => $id, 'name' => $name, 'role' => $role];
    }
}

if (!function_exists('audit_log')) {
    function audit_log(
        string $module,
        string $action,
        ?string $entityType = null,
        ?int $entityId = null,
        ?string $entityLabel = null,
        ?array $details = null
    ): void {
        try {
            $pdo   = suite_pdo();
            $actor = audit_actor();

            $details = $details ?? [];
            $page = parse_url((string)($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH);
            if (is_string($page) && $page !== '') $details['_page'] = $page;

            $pdo->prepare("
                INSERT INTO suite_audit_log
                    (volunteer_id, actor_name, actor_role, module, action, entity_type, entity_id, entity_label, details_json, ip_address)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ")->execute([
                $actor['id'],
                $actor['name'],
                $actor['role'],
                $module,
                $action,
                $entityType,
                $entityId,
                $entityLabel !== null ? mb_substr($entityLabel, 0, 255) : null,
                $details ? json_encode($details, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE) : null,
                $_SERVER['REMOTE_ADDR'] ?? null,
            ]);
        } catch (Throwable $e) {
            error_log('audit_log failed: ' . $e->getMessage());
        }
    }
}

if (!function_exists('audit_diff')) {
    /**
     * Compare deux états d'une ligne et renvoie uniquement les champs modifiés.
     *
     * @param array<string,mixed> $before  état avant (ligne SQL)
     * @param array<string,mixed> $after   état après (mêmes clés, valeurs saisies)
     * @param string[]|null       $fields  champs à comparer (par défaut : clés de $after)
     * @return array<string,array{from:mixed,to:mixed}>
     */
    function audit_diff(array $before, array $after, ?array $fields = null): array {
        $out = [];
        foreach ($fields ?? array_keys($after) as $f) {
            $a = $before[$f] ?? null;
            $b = $after[$f] ?? null;
            if (audit_norm($a) === audit_norm($b)) continue;
            $out[$f] = ['from' => $a, 'to' => $b];
        }
        return $out;
    }
}

if (!function_exists('audit_norm')) {
    /** Normalisation pour comparer : null et '' équivalents, 1/'1'/true équivalents. */
    function audit_norm(mixed $v): string {
        if ($v === null) return '';
        if (is_bool($v)) return $v ? '1' : '0';
        if (is_float($v) || (is_string($v) && is_numeric($v) && !preg_match('/^0\d/', $v))) {
            return rtrim(rtrim(number_format((float)$v, 6, '.', ''), '0'), '.');
        }
        return trim((string)$v);
    }
}

if (!function_exists('audit_update')) {
    /**
     * Journalise une modification avec avant/après. N'écrit rien si rien n'a changé.
     *
     * @param array<string,mixed> $before
     * @param array<string,mixed> $after
     * @param string[]|null       $fields
     * @param array<string,mixed> $context  infos libres supplémentaires
     * @param string[]            $redact   champs personnels dont la valeur n'est pas conservée
     */
    function audit_update(
        string $module,
        string $entityType,
        ?int $entityId,
        ?string $entityLabel,
        array $before,
        array $after,
        ?array $fields = null,
        array $context = [],
        array $redact = []
    ): void {
        $changes = audit_diff($before, $after, $fields);
        if (!$changes) return;
        // Données personnelles : on garde l'info « ce champ a changé » sans conserver les valeurs.
        foreach ($redact as $f) {
            if (isset($changes[$f])) $changes[$f] = ['redacted' => true];
        }
        audit_log($module, 'update', $entityType, $entityId, $entityLabel, ['changes' => $changes] + $context);
    }
}

/* ====================================================================
   LIBELLÉS (utilisés par l'affichage du journal)
   ==================================================================== */

if (!function_exists('audit_module_label')) {
    function audit_module_label(string $m): string {
        return [
            'auth'        => 'Connexion',
            'admin'       => 'Administration',
            'logistique'  => 'Logistique',
            'planning'    => 'Planning',
            'caisse'      => 'Caisse',
            'adhesions'   => 'Adhésions',
            'subventions' => 'Subventions',
            'donations'   => 'Dons',
            'prospection' => 'Prospection',
            'documents'   => 'Documents',
        ][$m] ?? ucfirst($m);
    }
}

if (!function_exists('audit_action_label')) {
    function audit_action_label(string $a): string {
        return [
            'create'           => 'Création',
            'update'           => 'Modification',
            'delete'           => 'Suppression',
            'login'            => 'Connexion',
            'logout'           => 'Déconnexion',
            'login_failed'     => 'Échec de connexion',
            'access_denied'    => 'Accès refusé',
            'stock_add'        => 'Ajout au stock',
            'stock_remove'     => 'Retrait du stock',
            'cancel'           => 'Annulation',
            'close'            => 'Clôture',
            'reopen'           => 'Réouverture',
            'status_change'    => 'Changement de statut',
            'toggle'           => 'Activation / désactivation',
            'register'         => 'Inscription',
            'unregister'       => 'Désinscription',
            'import'           => 'Import',
            'export'           => 'Export',
            'generate'         => 'Génération',
            'sync'             => 'Synchronisation',
            'migration_apply'  => 'Migration appliquée',
            'migration_mark'   => 'Migration marquée appliquée',
            'migration_fail'   => 'Migration en échec',
            'access_grant'     => 'Accès attribué',
            'access_revoke'    => 'Accès révoqué',
            'role_change'      => 'Changement de rôle',
            'code_regenerate'  => 'Code d\'accès régénéré',
            // Actions historiques de la prospection (entrées antérieures au journal harmonisé)
            'creation_fiche'              => 'Création de fiche',
            'modification_fiche'          => 'Modification de fiche',
            'desactivation_fiche'         => 'Désactivation de fiche',
            'suppression_fiche'           => 'Suppression de fiche',
            'ajout_suivi'                 => 'Ajout de suivi',
            'suppression_suivi'           => 'Suppression de suivi',
            'marquage_annee_contactee'    => 'Année marquée contactée',
            'demarquage_annee_contactee'  => 'Année démarquée',
            'import_csv'                  => 'Import CSV',
        ][$a] ?? ucfirst(str_replace('_', ' ', $a));
    }
}

if (!function_exists('audit_action_kind')) {
    /** Famille visuelle d'une action : create | update | delete | info | alert */
    function audit_action_kind(string $a): string {
        return match (true) {
            in_array($a, ['create', 'stock_add', 'register', 'import', 'import_csv', 'creation_fiche', 'ajout_suivi', 'access_grant'], true) => 'create',
            in_array($a, ['delete', 'stock_remove', 'unregister', 'cancel', 'suppression_fiche', 'suppression_suivi', 'desactivation_fiche', 'access_revoke'], true) => 'delete',
            in_array($a, ['login_failed', 'access_denied', 'migration_fail'], true) => 'alert',
            in_array($a, ['login', 'logout', 'export', 'sync'], true) => 'info',
            default => 'update',
        };
    }
}

if (!function_exists('audit_entity_label')) {
    function audit_entity_label(string $t): string {
        return [
            'volunteer'       => 'Utilisateur',
            'user'            => 'Utilisateur',
            'permissions'     => 'Droits',
            'group'           => 'Groupe',
            'volunteer_group' => 'Groupe de bénévoles',
            'logo'            => 'Logo',
            'unit'            => 'Unité de comptage',
            'caisse_withdrawal' => 'Retrait de caisse',
            'caisse_volunteer' => 'Bénévole de caisse',
            'sale_line'       => 'Ligne de vente',
            'migration'       => 'Migration',
            'convoy'          => 'Convoi',
            'category'        => 'Catégorie',
            'box'             => 'Carton',
            'pallet'          => 'Palette',
            'stop'            => 'Arrêt',
            'family'          => 'Famille',
            'sale'            => 'Vente',
            'caisse_event'    => 'Événement caisse',
            'caisse_product'  => 'Produit',
            'event'           => 'Événement',
            'event_type'      => 'Type d\'événement',
            'slot'            => 'Créneau',
            'registration'    => 'Inscription',
            'subvention'      => 'Subvention',
            'subvention_doc'  => 'Document de subvention',
            'donation'        => 'Don',
            'adherent'        => 'Adhérent',
            'adhesion'        => 'Adhésion',
            'contact'         => 'Contact',
            'document'        => 'Document',
        ][$t] ?? ucfirst(str_replace('_', ' ', $t));
    }
}

if (!function_exists('audit_field_label')) {
    function audit_field_label(string $f): string {
        return [
            'label'        => 'Libellé FR',
            'label_ua'     => 'Libellé UA',
            'label_en'     => 'Libellé EN',
            'unit'         => 'Unité',
            'parent_id'    => 'Catégorie parente',
            'is_active'    => 'Actif',
            'name'         => 'Nom',
            'title'        => 'Titre',
            'status'       => 'Statut',
            'date'         => 'Date',
            'start_at'     => 'Début',
            'end_at'       => 'Fin',
            'location'     => 'Lieu',
            'description'  => 'Description',
            'notes'        => 'Notes',
            'price'        => 'Prix',
            'quantity'     => 'Quantité',
            'qty'          => 'Quantité',
            'amount'       => 'Montant',
            'email'        => 'E-mail',
            'phone'        => 'Téléphone',
            'role'         => 'Rôle',
            'first_name'   => 'Prénom',
            'last_name'    => 'Nom',
            'presence_status' => 'Présence',
            'categorie'    => 'Catégorie', 'quantite' => 'Quantité', 'unite' => 'Unité', 'total_categorie' => 'Total de la catégorie',
            'convoi'       => 'Convoi', 'evenement' => 'Évènement', 'benevole' => 'Bénévole', 'creneau' => 'Créneau',
            'annee'        => 'Année', 'debut' => 'Début', 'fin' => 'Fin', 'ordre' => 'Ordre', 'numero' => 'Numéro',
            'origine'      => 'Origine', 'prix' => 'Prix', 'taille' => 'Taille', 'mode' => 'Mode', 'type' => 'Type',
            'montant'      => 'Montant', 'montant_accorde' => 'Montant accordé', 'montant_demande' => 'Montant demandé',
            'statut'       => 'Statut', 'nom' => 'Nom', 'note' => 'Note', 'periode' => 'Période', 'duree_ms' => 'Durée (ms)',
            'mode_paiement' => 'Mode de paiement', 'fond_caisse' => 'Fond de caisse', 'fond_initial' => 'Fond initial',
            'fond_reel'    => 'Fond réel', 'fond_theorique' => 'Fond théorique', 'ecart' => 'Écart',
            'cartons_supprimes' => 'Cartons supprimés', 'ventes_supprimees' => 'Ventes supprimées',
            'adhesions_supprimees' => 'Adhésions supprimées', 'inscriptions_supprimees' => 'Inscriptions supprimées',
            'creneaux_ajoutes' => 'Créneaux ajoutés', 'permanences_creees' => 'Permanences créées', 'semaines' => 'Semaines',
            'checksum'     => 'Empreinte', 'erreur' => 'Erreur', 'filtres' => 'Filtres', 'acteur' => 'Acteur',
            'recherche'    => 'Recherche', 'seulement' => 'Seulement', 'entite' => 'Élément', 'module' => 'Module',
            'produit'      => 'Produit', 'stock' => 'Stock', 'stock_remis' => 'Stock remis', 'retraits_caisse' => 'Retraits de caisse',
            'sort_order'   => 'Ordre', 'min_volunteers' => 'Bénévoles min.', 'max_volunteers' => 'Bénévoles max.',
            'start_datetime' => 'Début', 'end_datetime' => 'Fin', 'expires_at' => 'Expire le', 'expire_le' => 'Expire le',
            'donation_date' => 'Date du don', 'payment_method' => 'Mode de paiement', 'is_cancelled' => 'Annulé',
        ][$f] ?? ucfirst(str_replace('_', ' ', $f));
    }
}

if (!function_exists('audit_format_value')) {
    /** Affichage court d'une valeur d'audit (null → « — », booléens, tronquage). */
    function audit_format_value(mixed $v): string {
        if ($v === null || $v === '') return '—';
        if (is_bool($v)) return $v ? 'oui' : 'non';
        if (is_array($v)) {
            if (array_key_exists('from', $v) && array_key_exists('to', $v) && count($v) === 2) {
                return audit_format_value($v['from']) . ' → ' . audit_format_value($v['to']);
            }
            $parts = [];
            foreach ($v as $k => $item) {
                $parts[] = (is_int($k) ? '' : audit_field_label((string)$k) . ' : ') . audit_format_value($item);
            }
            return implode(', ', $parts);
        }
        $s = (string)$v;
        return mb_strlen($s) > 160 ? mb_substr($s, 0, 157) . '…' : $s;
    }
}
