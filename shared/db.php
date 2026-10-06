<?php
/**
 * shared/db.php — UNIQUE point de connexion PDO de toute la suite.
 *
 * Tous les modules (planning, caisse, logistique, adhésions, subventions, …)
 * obtiennent leur connexion via suite_pdo(). Les identifiants viennent du .env
 * (voir shared/db_credentials.php). Aucune autre connexion PDO ne doit être créée.
 */
require_once __DIR__ . '/db_credentials.php';

if (!function_exists('suite_pdo')) {
    function suite_pdo(): PDO {
        static $pdo = null;
        if ($pdo instanceof PDO) return $pdo;

        $pdo = new PDO(
            'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4',
            DB_USER,
            DB_PASS,
            [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                // Préparation émulée : autorise un même paramètre nommé plusieurs fois dans une requête
                PDO::ATTR_EMULATE_PREPARES   => true,
            ]
        );
        return $pdo;
    }
}
