<?php
declare(strict_types=1);

// donations/includes/env.php
//
// Petit chargeur de variables d'environnement (.env), copié du même
// mécanisme que planning/includes/app.php. Le module "donations" est
// désormais autonome (ne dépend plus de planning — base unifiée), il a
// donc son propre fichier .env (donations/.env, gitignored comme les
// autres .env de la suite — voir donations/.env.example).

if (!function_exists('load_env')) {
    function load_env(string $path): void {
        if (!is_readable($path)) return;

        $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if (!$lines) return;

        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#')) continue;

            $pos = strpos($line, '=');
            if ($pos === false) continue;

            $key = trim(substr($line, 0, $pos));
            $val = trim(substr($line, $pos + 1));

            // enlève guillemets simples/doubles
            if ((str_starts_with($val, '"') && str_ends_with($val, '"')) ||
                (str_starts_with($val, "'") && str_ends_with($val, "'"))) {
                $val = substr($val, 1, -1);
            }

            // ne pas écraser si déjà défini
            if (getenv($key) === false) {
                putenv("$key=$val");
                $_ENV[$key] = $val;
            }
        }
    }
}

if (!function_exists('env')) {
    function env(string $key, mixed $default = null): mixed {
        $v = getenv($key);
        return ($v === false || $v === '') ? $default : $v;
    }
}

// Charge .env depuis la racine du module donations
load_env(__DIR__ . '/../.env');
