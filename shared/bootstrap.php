<?php
declare(strict_types=1);

/**
 * SUITE - Bootstrap canonique (SAFE)
 * - session
 * - .env (loader minimal)
 * - auth globale par codes (admin / admin_plus) via $_SESSION['role']
 * - helpers
 * IMPORTANT: toutes les fonctions sont protégées par function_exists()
 */

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

/* -----------------------------
   .env minimal loader
----------------------------- */
if (!function_exists('env_load')) {
    function env_load(string $path): void {
        if (!is_file($path) || !is_readable($path)) return;

        $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if (!$lines) return;

        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#')) continue;

            $pos = strpos($line, '=');
            if ($pos === false) continue;

            $k = trim(substr($line, 0, $pos));
            $v = trim(substr($line, $pos + 1));

            if (
                (str_starts_with($v, '"') && str_ends_with($v, '"')) ||
                (str_starts_with($v, "'") && str_ends_with($v, "'"))
            ) {
                $v = substr($v, 1, -1);
            }

            if ($k !== '') $_ENV[$k] = $v;
        }
    }
}

/**
 * Charge .env suite: /preprod-fusion/.env
 * OK même si des modules legacy ont leur propre loader.
 */
env_load(dirname(__DIR__) . '/.env');

if (!function_exists('env')) {
    function env(string $key, ?string $default = null): ?string {
        return $_ENV[$key] ?? $default;
    }
}

if (!function_exists('h')) {
    function h(string $s): string {
        return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
    }
}

if (!function_exists('suite_base')) {
    function suite_base(): string {
        return env('SUITE_BASE', '/');
    }
}

if (!function_exists('app_env')) {
    function app_env(): string {
        return env('APP_ENV', 'prod');
    }
}

/* -----------------------------
   Auth globale (indépendante Planning)
----------------------------- */
if (!function_exists('csv_codes')) {
    function csv_codes(?string $s): array {
        if ($s === null) return [];
        $parts = array_map('trim', explode(',', $s));
        return array_values(array_filter($parts, fn($x) => $x !== ''));
    }
}

if (!function_exists('current_role')) {
    function current_role(): string {
        $r = (string)($_SESSION['role'] ?? 'public');
        return in_array($r, ['public', 'admin', 'admin_plus'], true) ? $r : 'public';
    }
}

if (!function_exists('is_admin')) {
    function is_admin(): bool {
        return in_array(current_role(), ['admin', 'admin_plus'], true);
    }
}

if (!function_exists('is_admin_plus')) {
    function is_admin_plus(): bool {
        return current_role() === 'admin_plus';
    }
}

if (!function_exists('admin_login_with_code')) {
    function admin_login_with_code(string $code): bool {
        $code = trim($code);
        if ($code === '') return false;

        $plus = csv_codes(env('ADMIN_PLUS_CODES', ''));
        if ($plus && in_array($code, $plus, true)) {
            $_SESSION['role'] = 'admin_plus';
            return true;
        }

        $admins = csv_codes(env('ADMIN_CODES', ''));
        if ($admins && in_array($code, $admins, true)) {
            $_SESSION['role'] = 'admin';
            return true;
        }

        return false;
    }
}

if (!function_exists('admin_logout')) {
    function admin_logout(): void {
        unset($_SESSION['role']);
    }
}

if (!function_exists('require_role')) {
    function require_role(string $minRole): void {
        $rank = ['public'=>1, 'admin'=>2, 'admin_plus'=>3];
        $cur = current_role();
        if (($rank[$cur] ?? 1) < ($rank[$minRole] ?? 1)) {
            http_response_code(403);
            echo "<div style='padding:12px;border:1px solid #f5c2c7;background:#f8d7da;color:#842029;border-radius:10px;font-family:system-ui'>
                    Accès interdit (niveau requis: ".h($minRole).").
                  </div>";
            exit;
        }
    }
}

if (!function_exists('suite_login_url')) {
    function suite_login_url(): string {
        return suite_base() . '/admin/login.php';
    }
}

if (!function_exists('suite_logout_url')) {
    function suite_logout_url(): string {
        return suite_base() . '/admin/logout.php';
    }
}
