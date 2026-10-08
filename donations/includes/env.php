<?php
declare(strict_types=1);

// donations/includes/env.php
//
// Le module "donations" n'a plus de .env propre : ses variables (ORG_*,
// HELLOASSO_*) vivent dans le fichier .env UNIQUE de la suite, celui qui
// contient déjà les identifiants de base de données (voir
// shared/db_credentials.php : variable SUITE_ENV_FILE, fichier hors du
// dossier web en production). Même fichier, même chargeur, pour les pages
// web comme pour le script en ligne de commande.

require_once __DIR__ . '/../../shared/db_credentials.php';

if (!function_exists('env')) {
    function env(string $key, mixed $default = null): mixed {
        $v = getenv($key);
        return ($v === false || $v === '') ? $default : $v;
    }
}

// Expose les variables du .env de la suite (sans écraser une valeur déjà définie).
foreach (suite_load_env_file(suite_env_path()) as $__k => $__v) {
    if (getenv($__k) === false) {
        putenv("$__k=$__v");
        $_ENV[$__k] = $__v;
    }
}
unset($__k, $__v);
