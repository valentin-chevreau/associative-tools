<?php
/**
 * Configuration de la base de données
 * À adapter selon votre configuration existante
 */

// Paramètres de connexion
define('DB_HOST', 'localhost');
define('DB_NAME', '');
define('DB_USER', '');
define('DB_PASS', '');
define('DB_CHARSET', 'utf8mb4');

// Connexion à la base de données
function get_db_connection() {
    static $conn = null;
    
    if ($conn === null) {
        $conn = mysqli_connect(DB_HOST, DB_USER, DB_PASS, DB_NAME);
        
        if (!$conn) {
            die("Erreur de connexion : " . mysqli_connect_error());
        }
        
        mysqli_set_charset($conn, DB_CHARSET);
    }
    
    return $conn;
}

// Initialisation de la connexion
$conn = get_db_connection();
?>
