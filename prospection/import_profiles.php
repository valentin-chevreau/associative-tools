<?php
declare(strict_types=1);

/**
 * prospection/import_profiles.php
 *
 * Un profil par onglet Excel d'origine (exporté en CSV avant import).
 * 'fields'            : colonne source (nom exact de l'entête) => champ cible harmonisé
 * 'contact_signal_cols' : colonnes dont la présence/valeur crée une entrée de suivi type "contact"
 * 'rappel_signal_cols'  : idem, type "rappel"
 * 'note_signal_cols'    : idem, type "note" (verdict, observations, commentaire libre...)
 * 'date_contact_col' / 'mode_contact_col' : cas EPI, deux colonnes séparées combinées en une note
 *
 * Toute colonne source qui n'est ni dans 'fields' ni dans les colonnes signal ci-dessus
 * est automatiquement conservée dans extra_json — aucune donnée n'est perdue à l'import.
 */

function prospection_import_profiles(): array {
    return [
        'jouets_magasins_fr' => [
            'label' => 'JOUETS_Magasins - FR',
            'fields' => [
                'Entité'              => 'nom',
                'Adresse'             => 'adresse',
                'Téléphone'           => 'telephone',
                'E-mail / contact'    => 'email',
                'Priorité'            => 'priorite',
            ],
            'contact_signal_cols' => ['Contacté'],
            'rappel_signal_cols'  => ['A rappeler'],
            'note_signal_cols'    => ['verdict'],
        ],
        'jouets_grandes_surfaces' => [
            'label' => 'JOUETS_Grandes surfaces',
            'fields' => [
                'Enseigne'             => 'nom',
                'Adresse'              => 'adresse',
                'Téléphone'            => 'telephone',
                'Contact à demander'   => 'contact_referent',
                'Priorité'             => 'priorite',
            ],
            'contact_signal_cols' => ['Contacté'],
            'rappel_signal_cols'  => ['A rappeler'],
            'note_signal_cols'    => ['verdict'],
        ],
        'jouets_ludotheques_ecoles_ville' => [
            'label' => 'JOUETS_ludothèques-écoles-ville',
            'fields' => [
                'Structure'  => 'nom',
                'Adresse'    => 'adresse',
                'Téléphone'  => 'telephone',
                'E-mail'     => 'email',
                'Priorité'   => 'priorite',
            ],
            'contact_signal_cols' => ['Contacté'],
            'rappel_signal_cols'  => ['A rappeler'],
            'note_signal_cols'    => ['verdict'],
        ],
        'epi' => [
            'label' => 'EPI',
            'fields' => [
                'Société'              => 'nom',
                'Prio'                 => 'priorite',
                'Site web'             => 'site_web',
                'Téléphone siège'      => 'telephone',
                'Email siège'          => 'email',
                'Contact RSE / dons'   => 'contact_referent',
            ],
            'date_contact_col' => 'Date du contact',
            'mode_contact_col' => 'Mode de contact',
        ],
        'medic_ephad' => [
            'label' => 'MEDIC_EPHAD',
            'fields' => [
                'Établissement' => 'nom',
                'Commune'       => 'commune',
                'Adresse'       => 'adresse',
                'Téléphone'     => 'telephone',
                'E-mail'        => 'email',
                'Gestionnaire'  => 'contact_referent',
                'Priorité'      => 'priorite',
            ],
            'contact_signal_cols' => ['Contacté'],
        ],
        'medic_ssiad' => [
            'label' => 'MEDIC_SSIAD',
            'fields' => [
                'Organisme'  => 'nom',
                'Commune'    => 'commune',
                'Adresse'    => 'adresse',
                'Téléphone'  => 'telephone',
                'E-mail'     => 'email',
                'Priorité'   => 'priorite',
            ],
            'contact_signal_cols' => ['Contacté'],
        ],
        'medic_fr_materiel' => [
            'label' => 'MEDIC_FR mat. médic',
            'fields' => [
                'Société / agence' => 'nom',
                'Commune'          => 'commune',
                'Adresse'          => 'adresse',
                'Téléphone'        => 'telephone',
                'E-mail'           => 'email',
                'Priorité'         => 'priorite',
            ],
            'contact_signal_cols' => ['Contacté'],
        ],
        'reeduc_salles_sport_muscu' => [
            'label' => 'REEDUC_Salles sport-muscu..',
            'fields' => [
                'Service'    => 'nom',
                'Numéro Tel' => 'telephone',
                'Mail'       => 'email',
                'Site @'     => 'site_web',
                'FILTRE'     => 'priorite',
            ],
            // Deux colonnes de contact par année dans ce fichier — chacune devient
            // une entrée de suivi datée séparément, avec l'année déjà dans son intitulé.
            'contact_signal_cols' => ['Contacté 2024', 'Contact 2026'],
            'note_signal_cols'    => ['Commentaire'],
        ],
        'filets_anti_drones' => [
            'label' => 'FILETS ANTI-DRONES',
            'fields' => [
                'Société / agence' => 'nom',
                'Commune'          => 'commune',
                'Adresse'          => 'adresse',
                'Téléphone'        => 'telephone',
                'E-mail'           => 'email',
            ],
            'contact_signal_cols' => ['Contacté'],
            'note_signal_cols'    => ['Observations'],
        ],
    ];
}

/**
 * Retourne le profil pour un code catégorie donné, ou null si aucun profil
 * n'est prédéfini (dans ce cas l'import utilise un mappage générique par
 * reconnaissance de mots-clés dans les entêtes — voir prospection_guess_profile()).
 */
function prospection_import_profile(string $categorieCode): ?array {
    $profiles = prospection_import_profiles();
    return $profiles[$categorieCode] ?? null;
}

/**
 * Mappage générique de secours pour une catégorie sans profil prédéfini
 * (ex: une catégorie ajoutée après coup) : reconnaît les entêtes usuelles
 * par mots-clés. Toute colonne non reconnue part dans extra_json — rien
 * n'est perdu, mais la répartition sera moins fine qu'un profil dédié.
 */
function prospection_guess_profile(array $headers): array {
    $keywords = [
        'nom'              => ['nom', 'entité', 'entite', 'société', 'societe', 'structure', 'enseigne', 'établissement', 'etablissement', 'organisme', 'service', 'agence'],
        'commune'          => ['commune', 'ville'],
        'adresse'          => ['adresse'],
        'telephone'        => ['téléphone', 'telephone', 'tel', 'numéro', 'numero'],
        'email'            => ['email', 'e-mail', 'mail'],
        'site_web'         => ['site web', 'site @', 'site'],
        'contact_referent' => ['contact', 'référent', 'referent', 'gestionnaire'],
        'priorite'         => ['priorité', 'priorite', 'prio', 'filtre'],
    ];

    $fields = [];
    foreach ($headers as $header) {
        $needle = mb_strtolower(trim((string)$header));
        if ($needle === '') continue;
        foreach ($keywords as $target => $terms) {
            if (isset($fields[$target])) continue; // premier match gagne
            foreach ($terms as $term) {
                if (str_contains($needle, $term)) {
                    $fields[$target] = $header;
                    continue 2;
                }
            }
        }
    }

    // On veut $fields sous la forme [entête_source => champ_cible]
    $mapped = array_flip($fields);

    return [
        'label'  => 'Mappage automatique (générique)',
        'fields' => $mapped,
    ];
}
