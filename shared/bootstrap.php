<?php
// shared/bootstrap.php

declare(strict_types=1);

/**
 * shared/bootstrap.php
 * Point central d'authentification pour la suite tools
 * - Codes d'accès gérés en base (planning_volunteers.access_code), plus de codes en dur
 * - Détecte automatiquement si on est en mode "suite" ou "standalone"
 * - Unifie l'authentification pour tous les modules
 * - Protège l'accès à /tools/ avec exceptions intelligentes
 */

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

/* ====================================================================
   DÉTECTION DU MODE : SUITE vs STANDALONE
   ==================================================================== */

if (!defined('SUITE_MODE')) {
    $currentFile = __FILE__;
    $isSuiteMode = (strpos($currentFile, '/tools/shared/') !== false ||
                    strpos($currentFile, '/preprod-tools/shared/') !== false);

    define('SUITE_MODE', $isSuiteMode);
}

/* ====================================================================
   CONNEXION DB AUTONOME (utilisée par bootstrap.php si $pdo pas encore défini)
   ==================================================================== */

if (!function_exists('_bootstrap_get_pdo')) {
    function _bootstrap_get_pdo(): ?PDO {
        global $pdo;
        if ($pdo instanceof PDO) return $pdo;

        // $pdo n'existe pas encore dans la portée appelante (ex: login.php
        // qui inclut bootstrap.php avant tout db.php) -> on se connecte nous-mêmes
        // en réutilisant le même config.php que les modules (logistique/config.php),
        // qui définit DB_HOST / DB_NAME / DB_USER / DB_PASS.
        static $localPdo = null;
        if ($localPdo instanceof PDO) return $localPdo;

        if (!defined('DB_HOST')) {
            $candidates = [
                __DIR__ . '/../logistique/config.php',
                __DIR__ . '/../planning/config.php',
                __DIR__ . '/../caisse/config.php',
                __DIR__ . '/config_db.php',
            ];
            foreach ($candidates as $cfg) {
                if (file_exists($cfg)) { require_once $cfg; break; }
            }
        }

        if (!defined('DB_HOST')) return null;

        try {
            $dsn = "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4";
            $localPdo = new PDO($dsn, DB_USER, DB_PASS, [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
            ]);
            $pdo = $localPdo; // expose aussi globalement pour le reste de la requête
            return $localPdo;
        } catch (PDOException $e) {
            error_log('bootstrap.php: connexion DB impossible: ' . $e->getMessage());
            return null;
        }
    }
}

/* ====================================================================
   CONFIGURATION & HELPERS
   ==================================================================== */

if (!function_exists('suite_base')) {
    function suite_base(): string {
        $uri = $_SERVER['REQUEST_URI'] ?? '/';
        $path = parse_url($uri, PHP_URL_PATH) ?: '/';

        if (strpos($path, '/tools/') === 0) return '/tools';
        if (strpos($path, '/preprod-tools/') === 0) return '/preprod-tools';

        if (strpos($path, '/preprod-planning') === 0) return '/preprod-planning';
        if (strpos($path, '/planning') === 0) return '/planning';
        if (strpos($path, '/preprod-logistique') === 0) return '/preprod-logistique';
        if (strpos($path, '/logistique') === 0) return '/logistique';
        if (strpos($path, '/preprod-caisse') === 0) return '/preprod-caisse';
        if (strpos($path, '/caisse') === 0) return '/caisse';

        return '';
    }
}

if (!function_exists('h')) {
    function h(string $s): string {
        return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
    }
}

if (!function_exists('suite_login_url')) {
    function suite_login_url(): string {
        $base = suite_base();
        $current = $_SERVER['REQUEST_URI'] ?? '';

        if (SUITE_MODE) {
            return $base . '/admin/login.php?next=' . urlencode($current);
        } else {
            return 'login_admin.php?redirect=' . urlencode($current);
        }
    }
}

if (!function_exists('suite_logout_url')) {
    function suite_logout_url(): string {
        $base = suite_base();
        return SUITE_MODE ? ($base . '/admin/logout.php') : 'logout_admin.php';
    }
}

/* ====================================================================
   AUTHENTIFICATION UNIFIÉE
   ==================================================================== */

if (!function_exists('is_admin')) {
    function is_admin(): bool {
        return !empty($_SESSION['is_admin']) ||
               !empty($_SESSION['admin_authenticated']) ||
               !empty($_SESSION['admin']);
    }
}

if (!function_exists('is_admin_plus')) {
    function is_admin_plus(): bool {
        return !empty($_SESSION['admin_plus']) || !empty($_SESSION['is_admin_plus']);
    }
}

if (!function_exists('current_role')) {
    function current_role(): string {
        if (is_admin_plus()) return 'admin_plus';
        if (is_admin()) return 'admin';
        return 'public';
    }
}

if (!function_exists('current_volunteer_id')) {
    function current_volunteer_id(): ?int {
        return isset($_SESSION['volunteer_id']) ? (int)$_SESSION['volunteer_id'] : null;
    }
}
if (!function_exists('current_volunteer_name')) {
    function current_volunteer_name(): string {
        return (string)($_SESSION['volunteer_name'] ?? 'Inconnu');
    }
}

/**
 * Login avec code (mode suite uniquement) — recherche en base sur planning_volunteers.
 * Récupère $pdo s'il existe déjà, sinon se connecte lui-même (voir _bootstrap_get_pdo).
 */
if (!function_exists('admin_login_with_code')) {
    function admin_login_with_code(string $code): bool {
        if (!SUITE_MODE) return false;

        $pdo = _bootstrap_get_pdo();
        if (!($pdo instanceof PDO)) return false;

        $code = trim($code);
        if ($code === '' || !ctype_digit($code) || strlen($code) !== 8) return false;

        $stmt = $pdo->prepare("
            SELECT id, first_name, last_name, role
            FROM planning_volunteers
            WHERE access_code = ? AND role IS NOT NULL AND is_active = 1
            LIMIT 1
        ");
        $stmt->execute([$code]);
        $volunteer = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$volunteer) return false;

        $role = (string)$volunteer['role'];
        if (!in_array($role, ['admin', 'admin_plus'], true)) return false;

        $_SESSION['is_admin'] = true;
        $_SESSION['admin_authenticated'] = true;
        $_SESSION['admin'] = true;
        $_SESSION['admin_last_active'] = time();
        $_SESSION['volunteer_id'] = (int)$volunteer['id'];
        $_SESSION['volunteer_name'] = trim($volunteer['first_name'] . ' ' . $volunteer['last_name']);

        if ($role === 'admin_plus') {
            $_SESSION['admin_plus'] = true;
            $_SESSION['is_admin_plus'] = true;
        }

        $pdo->prepare("UPDATE planning_volunteers SET last_login_at = NOW() WHERE id = ?")
            ->execute([(int)$volunteer['id']]);

        if (function_exists('audit_log')) {
            audit_log('auth', 'login', 'volunteer', (int)$volunteer['id'], $_SESSION['volunteer_name']);
        }

        return true;
    }
}

if (!function_exists('admin_logout')) {
    function admin_logout(): void {
        if (function_exists('audit_log') && current_volunteer_id() !== null) {
            audit_log('auth', 'logout', 'volunteer', current_volunteer_id(), current_volunteer_name());
        }
        unset(
            $_SESSION['is_admin'],
            $_SESSION['admin_authenticated'],
            $_SESSION['admin'],
            $_SESSION['admin_plus'],
            $_SESSION['is_admin_plus'],
            $_SESSION['admin_last_active'],
            $_SESSION['volunteer_id'],
            $_SESSION['volunteer_name']
        );
        session_regenerate_id(true);
    }
}

if (!function_exists('isAdminAuthenticated')) {
    function isAdminAuthenticated(): bool {
        return is_admin();
    }
}

/* ====================================================================
   JOURNAL D'AUDIT — utilisable par tous les modules
   ==================================================================== */

if (!function_exists('audit_log')) {
    function audit_log(
        string $module,
        string $action,
        ?string $entityType = null,
        ?int $entityId = null,
        ?string $entityLabel = null,
        ?array $details = null
    ): void {
        $pdo = _bootstrap_get_pdo();
        if (!($pdo instanceof PDO)) return;

        try {
            $volunteerId = current_volunteer_id();
            $actorName   = $volunteerId !== null ? current_volunteer_name() : 'Système';
            $actorRole   = current_role();
            $ip          = $_SERVER['REMOTE_ADDR'] ?? null;

            $pdo->prepare("
                INSERT INTO suite_audit_log
                    (volunteer_id, actor_name, actor_role, module, action, entity_type, entity_id, entity_label, details_json, ip_address)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ")->execute([
                $volunteerId,
                $actorName,
                $actorRole,
                $module,
                $action,
                $entityType,
                $entityId,
                $entityLabel,
                $details !== null ? json_encode($details, JSON_UNESCAPED_UNICODE) : null,
                $ip,
            ]);
        } catch (Exception $e) {
            error_log('audit_log failed: ' . $e->getMessage());
        }
    }
}

/* ====================================================================
   PROTECTION GLOBALE DE /TOOLS/ (AVEC EXCEPTIONS INTELLIGENTES)
   ==================================================================== */

if (defined('SUITE_MODE') && SUITE_MODE) {

    $current_page = $_SERVER['PHP_SELF'] ?? '';

    $public_patterns = [
        '/admin/login.php',
        '/planning/index.php',
        '/planning/events.php',
        '/planning/toggle_registration.php',
        '/logistique/stops/validate.php',
    ];

    $is_public_page = false;
    foreach ($public_patterns as $pattern) {
        if (strpos($current_page, $pattern) !== false) {
            $is_public_page = true;
            break;
        }
    }

    if (!$is_public_page && !is_admin()) {
        $requested_url = $_SERVER['REQUEST_URI'] ?? '';
        header('Location: ' . suite_base() . '/admin/login.php?next=' . urlencode($requested_url));
        exit;
    }
}
