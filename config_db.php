<?php
/**
 * config_db.php — connexion MySQLi héritée (adhésions, subventions, prospection…).
 *
 * Ne contient AUCUN identifiant : elle réutilise la source unique
 * shared/db_credentials.php, la même que le reste de la suite (PDO).
 * Fournit : get_db_connection() et la variable $conn.
 */
require_once __DIR__ . '/shared/db_credentials.php';

if (!function_exists('get_db_connection')) {
    function get_db_connection(): mysqli {
        if (!defined('DB_HOST')) {
            http_response_code(500);
            exit('Configuration base de données introuvable (voir shared/db_credentials.php).');
        }
        $c = mysqli_connect(DB_HOST, DB_USER, DB_PASS, DB_NAME);
        mysqli_set_charset($c, 'utf8mb4');
        return $c;
    }
}

$conn = get_db_connection();
