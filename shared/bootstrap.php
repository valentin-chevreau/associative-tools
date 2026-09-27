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

/* --------------------------------------------------------------------------
 * Interrupteur de debug PHP (désactivé par défaut).
 * Pour voir les erreurs PHP à l'écran sur ce serveur, le temps de déboguer :
 *   touch shared/DEBUG_ON
 * Pour les faire disparaître à nouveau (à ne JAMAIS laisser actif en
 * permanence sur un site public — ça peut exposer des chemins serveur,
 * des requêtes SQL, etc.) :
 *   rm shared/DEBUG_ON
 * -------------------------------------------------------------------------- */
if (is_file(__DIR__ . '/DEBUG_ON')) {
    ini_set('display_errors', '1');
    ini_set('display_startup_errors', '1');
    error_reporting(E_ALL);
}

/* --------------------------------------------------------------------------
 * Filet de sécurité : plus jamais de page blanche.
 * Toute exception non rattrapée ou erreur fatale PHP est transformée en une
 * page d'erreur lisible (au lieu d'un écran blanc silencieux), et l'erreur
 * complète est systématiquement écrite dans les logs serveur (error_log),
 * qu'on soit en debug ou non. Le détail technique n'est affiché à l'écran
 * que si shared/DEBUG_ON est présent (voir ci-dessus) — jamais en public.
 * -------------------------------------------------------------------------- */
if (!function_exists('suite_render_error_page')) {
    function suite_render_error_page(string $detail = ''): void {
        if (!headers_sent()) {
            http_response_code(500);
            header('Content-Type: text/html; charset=utf-8');
        }
        while (ob_get_level() > 0) { @ob_end_clean(); }
        $debugOn = is_file(__DIR__ . '/DEBUG_ON');
        echo '<!doctype html><html lang="fr"><head><meta charset="utf-8">'
           . '<title>Erreur — Suite Touraine-Ukraine</title>'
           . '<meta name="viewport" content="width=device-width, initial-scale=1">'
           . '<style>'
           . 'body{font-family:system-ui,-apple-system,sans-serif;background:#fdf8f0;color:#3a2e22;'
           . 'display:flex;align-items:center;justify-content:center;min-height:100vh;margin:0;padding:24px;}'
           . '.box{max-width:600px;background:#fff;border:1.5px solid #eadfce;border-radius:16px;padding:32px;'
           . 'box-shadow:0 8px 24px rgba(0,0,0,.06);}'
           . 'h1{font-size:20px;margin:0 0 8px;}'
           . 'p{font-size:14px;line-height:1.5;color:#6b5d4d;}'
           . 'pre{white-space:pre-wrap;word-break:break-word;background:#faf3e6;border-radius:8px;padding:12px;font-size:12px;overflow:auto;}'
           . 'a{color:#c07a2a;font-weight:600;text-decoration:none;}'
           . '</style></head><body><div class="box">'
           . '<h1>Une erreur est survenue</h1>'
           . '<p>Quelque chose s\'est mal passé de notre côté. Vous pouvez revenir en arrière et réessayer ; '
           . 'si le problème persiste, signalez-le avec l\'heure exacte de l\'erreur.</p>';
        if ($debugOn && $detail !== '') {
            echo '<pre>' . htmlspecialchars($detail, ENT_QUOTES, 'UTF-8') . '</pre>';
        }
        echo '<p><a href="javascript:history.back()">← Revenir en arrière</a></p>'
           . '</div></body></html>';
    }
}

if (!defined('SUITE_ERROR_HANDLERS_INSTALLED')) {
    define('SUITE_ERROR_HANDLERS_INSTALLED', true);

    set_exception_handler(function (Throwable $e) {
        $detail = get_class($e) . ': ' . $e->getMessage() . "\n" . $e->getFile() . ':' . $e->getLine() . "\n" . $e->getTraceAsString();
        error_log('[UNCAUGHT] ' . $detail);
        suite_render_error_page($detail);
        exit(1);
    });

    register_shutdown_function(function () {
        $err = error_get_last();
        if ($err && in_array($err['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
            $detail = $err['message'] . ' in ' . $err['file'] . ' on line ' . $err['line'];
            error_log('[FATAL] ' . $detail);
            suite_render_error_page($detail);
        }
    });
}

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
        return !empty($_SESSION['admin_plus']) || !empty($_SESSION['is_admin_plus']) || !empty($_SESSION['super_admin']);
    }
}

if (!function_exists('is_super_admin')) {
    function is_super_admin(): bool {
        return !empty($_SESSION['super_admin']);
    }
}

if (!function_exists('current_role')) {
    function current_role(): string {
        if (is_super_admin()) return 'super_admin';
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
        if (!in_array($role, ['admin', 'admin_plus', 'super_admin'], true)) return false;

        $_SESSION['is_admin'] = true;
        $_SESSION['admin_authenticated'] = true;
        $_SESSION['admin'] = true;
        $_SESSION['admin_last_active'] = time();
        $_SESSION['volunteer_id'] = (int)$volunteer['id'];
        $_SESSION['volunteer_name'] = trim($volunteer['first_name'] . ' ' . $volunteer['last_name']);

        if ($role === 'admin_plus' || $role === 'super_admin') {
            $_SESSION['admin_plus'] = true;
            $_SESSION['is_admin_plus'] = true;
        }
        if ($role === 'super_admin') {
            $_SESSION['super_admin'] = true;
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
            $_SESSION['super_admin'],
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

/**
 * Gardes d'accès — anciennement fournies par auth_helper.php (fichier disparu).
 * Redirigent vers le login si le rôle requis n'est pas atteint.
 */
if (!function_exists('require_admin')) {
    function require_admin(): void {
        if (!is_admin()) {
            header('Location: ' . suite_login_url());
            exit;
        }
    }
}

if (!function_exists('require_admin_plus')) {
    function require_admin_plus(): void {
        if (!is_admin_plus()) {
            header('Location: ' . suite_login_url());
            exit;
        }
    }
}

if (!function_exists('require_super_admin')) {
    function require_super_admin(): void {
        if (!is_super_admin()) {
            header('Location: ' . suite_login_url());
            exit;
        }
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

/**
 * Formatage lisible d'un numéro de téléphone : groupe les chiffres par 2
 * (ex: "0766512146" -> "07 66 51 21 46"). Ignore tout ce qui n'est pas un
 * chiffre (espaces, points, indicatif "+33" conservé tel quel en tête de
 * groupe s'il est présent) ; ne modifie jamais la valeur stockée en base,
 * uniquement l'affichage.
 */
if (!function_exists('format_phone')) {
    function format_phone(?string $phone): string {
        $phone = trim((string)$phone);
        if ($phone === '') return '';

        $hasPlus = str_starts_with($phone, '+');
        $digits  = preg_replace('/\D+/', '', $phone);
        if ($digits === '') return '';

        $grouped = trim(chunk_split($digits, 2, ' '));
        return $hasPlus ? '+' . $grouped : $grouped;
    }
}

/**
 * Compatibilité — anciennement fournie par auth_helper.php (fichier disparu).
 * Wrapper simplifié autour de audit_log() pour les appels historiques
 * log_action($module, $action, $description).
 */
if (!function_exists('log_action')) {
    function log_action(string $module, string $action, ?string $description = null): void {
        audit_log($module, $action, null, null, null, $description !== null ? ['description' => $description] : null);
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
        '/planning/calendar.ics.php',
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