<?php
declare(strict_types=1);

/**
 * shared/member_functions.php — Liste des fonctions de bureau des utilisateurs.
 * Centralisé pour rester identique entre admin/users.php et admin/groups.php
 * (la liste sert aussi de base aux règles d'auto-attribution de groupes —
 * voir shared/volunteer_group_rules.php).
 */

if (!defined('MEMBER_FUNCTIONS')) {
    define('MEMBER_FUNCTIONS', [
        'president'       => 'Président',
        'vice_president'  => 'Vice-Président',
        'secretaire'      => 'Secrétaire',
        'vice_secretaire' => 'Vice-Secrétaire',
        'tresorier'       => 'Trésorier',
        'vice_tresorier'  => 'Vice-Trésorier',
    ]);
}

if (!function_exists('member_function_label')) {
    function member_function_label(?string $f): string {
        if ($f === null || $f === '') return '—';
        // Texte libre si la valeur ne fait plus partie de la liste (compat anciennes valeurs
        // comme "membre_ca" / "membre_bureau" / "membre_actif", désormais gérées via les groupes).
        return MEMBER_FUNCTIONS[$f] ?? $f;
    }
}