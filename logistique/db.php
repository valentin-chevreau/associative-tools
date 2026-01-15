<?php
require_once __DIR__ . '/config.php';

try {
    $dsn = "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4";
    $pdo = new PDO($dsn, DB_USER, DB_PASS, [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ]);
} catch (PDOException $e) {
    http_response_code(500);
    echo "<div style='font-family:system-ui, -apple-system, Segoe UI, Roboto, Arial; padding:16px'>";
    echo "<h2>Erreur de connexion à la base</h2>";
    echo "<p>Vérifie les paramètres dans <code>config.php</code>.</p>";
    echo "</div>";
    exit;
}
