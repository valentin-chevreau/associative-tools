<?php
/**
 * shared/db_credentials.php — SOURCE UNIQUE des identifiants de base de données.
 *
 * La base est unifiée : tous les modules se connectent via shared/db.php
 * (suite_pdo()) avec les mêmes identifiants, lus dans un fichier .env (hors git) :
 *
 *   DB_HOST=localhost
 *   DB_NAME=...
 *   DB_USER=...
 *   DB_PASS=...
 *
 * Emplacement du .env : variable d'environnement SUITE_ENV_FILE si définie
 * (obligatoire en production : un fichier HORS du dossier web, passé par
 * fastcgi_param dans nginx), sinon .env à la racine du projet (dev local uniquement).
 * Définit les constantes DB_HOST / DB_NAME / DB_USER / DB_PASS.
 * Le même fichier contient aussi les secrets des autres modules (ex. HelloAsso,
 * identité de l'organisme pour les reçus) : voir donations/includes/env.php.
 * Aucun secret dans ce fichier : il est versionné.
 */
if (!function_exists('suite_load_env_file')) {
    /** Lit un fichier .env (KEY=VALUE, # commentaires, guillemets simples/doubles). */
    function suite_load_env_file(string $path): array {
        $out = [];
        if (!is_file($path) || !is_readable($path)) return $out;
        foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
            $line = trim($line);
            if ($line === '' || $line[0] === '#' || strpos($line, '=') === false) continue;
            [$k, $v] = explode('=', $line, 2);
            $k = trim($k);
            $v = trim($v);
            if ($v !== '' && ($v[0] === '"' || $v[0] === "'") && substr($v, -1) === $v[0]) {
                $v = substr($v, 1, -1);
            } else {
                $v = trim(preg_replace('/\s+#.*$/', '', $v));
            }
            $out[$k] = $v;
        }
        return $out;
    }
}

if (!function_exists('suite_env_path')) {
    /** Chemin du fichier .env de la suite : SUITE_ENV_FILE, sinon .env à la racine du projet. */
    function suite_env_path(): string {
        return getenv('SUITE_ENV_FILE') ?: dirname(__DIR__) . '/.env';
    }
}

if (!defined('DB_HOST')) {
    $__path = suite_env_path();
    $__env  = suite_load_env_file($__path);
    foreach (['DB_HOST', 'DB_NAME', 'DB_USER'] as $__k) {
        if (empty($__env[$__k])) {
            error_log("db_credentials.php: $__k manquant dans $__path");
            if (PHP_SAPI !== 'cli') { http_response_code(500); }
            exit('Configuration base de données manquante (.env : DB_HOST, DB_NAME, DB_USER, DB_PASS).');
        }
    }
    define('DB_HOST', $__env['DB_HOST']);
    define('DB_NAME', $__env['DB_NAME']);
    define('DB_USER', $__env['DB_USER']);
    define('DB_PASS', $__env['DB_PASS'] ?? '');
    unset($__path, $__env, $__k);
}
