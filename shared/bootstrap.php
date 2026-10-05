<?php
// shared/bootstrap.php

declare(strict_types=1);

/**
 * shared/bootstrap.php
 * Point central d'authentification pour la suite tools
 * - Codes d'accès gérés en base (users.access_code), plus de codes en dur
 * - Unifie l'authentification pour tous les modules (mode suite uniquement —
 *   le mode "standalone" historique n'existe plus : les anciens dossiers
 *   autonomes caisse/, logistique/, planning/, etc. ont été retirés du
 *   serveur, seuls /tools/ et /preprod-tools/ subsistent)
 * - Protège l'accès à /tools/ avec exceptions intelligentes
 * - Cookie de session partagé avec les sous-domaines (*.touraine-ukraine.fr) pour
 *   que l'espace bureau du site public reconnaisse la connexion à la suite
 * - Session distincte pour la préprod (/preprod-tools/) : se connecter en préprod
 *   ne connecte plus à la prod, et inversement
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
        // Détail technique affiché si le mode debug est activé (shared/DEBUG_ON),
        // ou automatiquement pour un super_admin connecté (pratique pour déboguer
        // en prod sans activer le mode debug pour tout le monde) — jamais pour un
        // simple admin/admin_plus, ni pour un visiteur non connecté.
        $debugOn = is_file(__DIR__ . '/DEBUG_ON')
            || (function_exists('is_super_admin') && is_super_admin());
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

// En CLI (scripts cron), il n'y a ni cookie ni session HTTP qui tienne : on
// n'essaie pas de démarrer une session (ça ne sert à rien et génère un
// warning), et surtout on saute la protection globale plus bas (elle tente
// une redirection HTTP via header(), qui n'a aucun sens hors requête web et
// coupe le script en plein milieu). Ça permet aux scripts CLI d'inclure
// bootstrap.php (via _bootstrap_get_pdo() notamment) sans dupliquer les
// identifiants de connexion ailleurs.
$suiteIsCli = (PHP_SAPI === 'cli');

/* --------------------------------------------------------------------------
 * Cookie de session partagé avec les sous-domaines.
 * Sur touraine-ukraine.fr (et ses sous-domaines), le cookie est posé pour
 * « .touraine-ukraine.fr » : une connexion faite dans /tools/ est reconnue par
 * l'espace bureau du site (new.touraine-ukraine.fr, puis touraine-ukraine.fr).
 * Ailleurs (local, autre domaine), comportement PHP par défaut.
 * Doit rester identique à auth.suite.cookie_domain dans site/config.php.
 *
 * La préprod (/preprod-tools/) utilise son propre nom de session : prod et
 * préprod sont sur le même domaine et partageaient jusqu'ici la même session
 * (un login en préprod ouvrait la prod avec un id de la base de préprod).
 * Doit rester identique à auth.suite.session_name dans site/config.php.
 * -------------------------------------------------------------------------- */
if (!defined('SUITE_COOKIE_DOMAIN')) {
    define('SUITE_COOKIE_DOMAIN', '.touraine-ukraine.fr');
}
if (!defined('SUITE_PREPROD_SESSION_NAME')) {
    define('SUITE_PREPROD_SESSION_NAME', 'TU_PREPROD');
}

/* Beaucoup de pages (admin/*, planning/*, logistique/*…) appelaient session_start()
 * AVANT de charger ce fichier : la session démarrait alors avec le nom par défaut
 * (PHPSESSID) et sans les paramètres de cookie ci-dessous — sur /preprod-tools/,
 * ces pages lisaient donc une AUTRE session que celle du login (ex. un ancien
 * « super admin » restant dans le cookie PHPSESSID). On referme cette session
 * prématurée et on repart avec le bon nom. */
if (!$suiteIsCli && session_status() === PHP_SESSION_ACTIVE) {
    $suiteExpectedName = (strpos((string)(parse_url((string)($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH) ?: '/'), '/preprod-tools/') === 0)
        ? SUITE_PREPROD_SESSION_NAME : 'PHPSESSID';
    if (session_name() !== $suiteExpectedName) {
        session_write_close();
        // Ne pas réutiliser l'identifiant de la session prématurée (ce serait
        // récupérer ses données, ex. un ancien super admin) : on prend le cookie
        // du bon nom s'il existe, sinon un nouvel identifiant sera généré.
        $suiteOwnId = (string)($_COOKIE[$suiteExpectedName] ?? '');
        session_id(preg_match('/^[A-Za-z0-9,-]{22,128}$/', $suiteOwnId) ? $suiteOwnId : '');
    }
}

if (!$suiteIsCli && session_status() === PHP_SESSION_NONE) {
    // Détection préprod basée sur l'URL demandée (comme suite_base() plus bas),
    // et non sur __FILE__ : si shared/ est partagé entre /tools et /preprod-tools
    // (lien symbolique, ou tout déploiement où le fichier physique est commun),
    // __FILE__ peut être résolu vers le chemin réel et ne plus contenir
    // "/preprod-tools/", ce qui faisait silencieusement échouer cette détection
    // et faisait retomber la préprod sur le même nom de cookie que la prod.
    $suiteReqPath = parse_url((string)($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH) ?: '/';
    if (strpos($suiteReqPath, '/preprod-tools/') === 0) {
        session_name(SUITE_PREPROD_SESSION_NAME);
    }
    $suiteHost = strtolower((string) preg_replace('/:\d+$/', '', (string) ($_SERVER['HTTP_HOST'] ?? '')));
    $suiteHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'domain'   => str_ends_with('.' . $suiteHost, SUITE_COOKIE_DOMAIN) ? SUITE_COOKIE_DOMAIN : '',
        'secure'   => $suiteHttps,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
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

        // Seuls /tools/ (prod) et /preprod-tools/ (préprod) existent désormais —
        // les anciens dossiers autonomes ont été supprimés du serveur.
        return (strpos($path, '/preprod-tools/') === 0) ? '/preprod-tools' : '/tools';
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
        return $base . '/admin/login.php?next=' . urlencode($current);
    }
}

if (!function_exists('suite_logout_url')) {
    function suite_logout_url(): string {
        return suite_base() . '/admin/logout.php';
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

/* ====================================================================
   DROITS PAR MODULE
   --------------------------------------------------------------------
   Résolution de l'accès d'un utilisateur à un module :
     super_admin                     -> toujours autorisé
     surcharge utilisateur (table)   -> prioritaire
     défaut du rôle (table)          -> sinon
     défaut intégré (ci-dessous)     -> si les tables n'existent pas encore
   Gérés dans admin/permissions.php (super admin).
   ==================================================================== */

if (!function_exists('suite_modules')) {
    /** @return array<string,string> clé => libellé */
    function suite_modules(): array {
        return [
            'planning'        => 'Planning',
            'caisse'          => 'Caisse',
            'logistique'      => 'Convois & stock local',
            'annuaire'        => 'Annuaire (familles)',
            'adhesions'       => 'Adhésions',
            'subventions'     => 'Subventions',
            'donations'       => 'Dons',
            'prospection'     => 'Prospection',
            'documents'       => 'Documents',
            'site_backoffice' => 'Backoffice du site internet',
        ];
    }
}

if (!function_exists('suite_module_default_access')) {
    /** Accès par défaut d'un rôle (reproduit la sidebar historique). */
    function suite_module_default_access(string $role, string $module): bool {
        if ($role === 'super_admin') return true;
        if ($role === 'admin_plus') return $module !== 'site_backoffice';
        if ($role === 'admin') {
            return in_array($module, ['planning', 'caisse', 'logistique', 'annuaire', 'adhesions', 'subventions', 'documents'], true);
        }
        return false;
    }
}

if (!function_exists('suite_permissions_load')) {
    /** @return array{roles:array<string,array<string,bool>>, users:array<int,array<string,bool>>} */
    function suite_permissions_load(bool $reset = false): array {
        static $cache = null;
        if ($reset) $cache = null;
        if ($cache !== null) return $cache;

        $cache = ['roles' => [], 'users' => []];
        $pdo = _bootstrap_get_pdo();
        if (!($pdo instanceof PDO)) return $cache;

        try {
            foreach ($pdo->query("SELECT role, module, allowed FROM suite_role_permissions") as $r) {
                $cache['roles'][(string)$r['role']][(string)$r['module']] = ((int)$r['allowed'] === 1);
            }
            foreach ($pdo->query("SELECT user_id, module, allowed FROM suite_user_permissions") as $r) {
                $cache['users'][(int)$r['user_id']][(string)$r['module']] = ((int)$r['allowed'] === 1);
            }
        } catch (Throwable $e) {
            // Tables absentes (migration pas encore exécutée) : défauts intégrés.
            $cache = ['roles' => [], 'users' => []];
        }
        return $cache;
    }
}

if (!function_exists('module_access_for')) {
    function module_access_for(string $role, ?int $userId, string $module): bool {
        if ($role === 'super_admin') return true;
        if (!in_array($role, ['admin', 'admin_plus'], true)) return false;

        $perm = suite_permissions_load();
        if ($userId !== null && isset($perm['users'][$userId][$module])) {
            return $perm['users'][$userId][$module];
        }
        if (isset($perm['roles'][$role][$module])) {
            return $perm['roles'][$role][$module];
        }
        return suite_module_default_access($role, $module);
    }
}

if (!function_exists('module_access')) {
    /** L'utilisateur connecté peut-il accéder à ce module ? */
    function module_access(string $module): bool {
        return module_access_for(current_role(), current_volunteer_id(), $module);
    }
}

if (!function_exists('suite_module_from_path')) {
    /** Module concerné par un chemin de requête (null = hors modules : admin, accueil…). */
    function suite_module_from_path(string $path): ?string {
        $path = preg_replace('#^/(preprod-tools|tools)#', '', $path) ?? $path;
        $segs = explode('/', ltrim($path, '/'));
        $first = $segs[0] ?? '';
        if ($first === 'logistique' && ($segs[1] ?? '') === 'families') return 'annuaire';
        $map = [
            'planning' => 'planning', 'caisse' => 'caisse', 'logistique' => 'logistique',
            'adhesions' => 'adhesions', 'subventions' => 'subventions', 'donations' => 'donations',
            'prospection' => 'prospection', 'documents' => 'documents',
        ];
        return $map[$first] ?? null;
    }
}

if (!defined('SITE_BACKOFFICE_URL')) {
    // Adresse du backoffice du site internet grand public (lien de la sidebar).
    define('SITE_BACKOFFICE_URL', 'https://touraine-ukraine.fr/admin/site');
}

/**
 * Login avec code — recherche en base sur users.
 * Récupère $pdo s'il existe déjà, sinon se connecte lui-même (voir _bootstrap_get_pdo).
 */
if (!function_exists('admin_login_with_code')) {
    function admin_login_with_code(string $code): bool {
        $pdo = _bootstrap_get_pdo();
        if (!($pdo instanceof PDO)) return false;

        $code = trim($code);
        if ($code === '' || !ctype_digit($code) || strlen($code) !== 8) return false;

        $stmt = $pdo->prepare("
            SELECT id, first_name, last_name, role
            FROM users
            WHERE access_code = ? AND role IS NOT NULL AND is_active = 1
            LIMIT 1
        ");
        $stmt->execute([$code]);
        $volunteer = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$volunteer) return false;

        $role = (string)$volunteer['role'];
        if (!in_array($role, ['admin', 'admin_plus', 'super_admin'], true)) return false;

        // Repart d'une session propre : sans cela, les drapeaux de rôle d'une
        // connexion précédente (super_admin, admin_plus…) survivaient à un
        // nouveau login avec le code d'un autre utilisateur.
        foreach (['is_admin', 'admin_authenticated', 'admin', 'admin_plus', 'is_admin_plus',
                  'super_admin', 'admin_last_active', 'volunteer_id', 'volunteer_name'] as $k) {
            unset($_SESSION[$k]);
        }
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_regenerate_id(true);
        }

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

        $pdo->prepare("UPDATE users SET last_login_at = NOW() WHERE id = ?")
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
   LOGO DE LA SUITE (téléversé par le super admin, admin/branding.php)
   Stocké en PNG dans uploads/branding/logo.png (dossier hors dépôt) et
   inséré en data: URI : aucune URL publique à exposer, donc utilisable
   sur la page de connexion sans ouvrir l'accès au dossier uploads/.
   ==================================================================== */
if (!function_exists('suite_logo_path')) {
    function suite_logo_path(): string {
        return dirname(__DIR__) . '/uploads/branding/logo.png';
    }
}
if (!function_exists('suite_logo_data_uri')) {
    function suite_logo_data_uri(): ?string {
        static $cache = false;
        if ($cache !== false) return $cache;
        $path = suite_logo_path();
        $cache = null;
        if (is_file($path) && filesize($path) > 0 && filesize($path) <= 400 * 1024) {
            $raw = @file_get_contents($path);
            if ($raw !== false) $cache = 'data:image/png;base64,' . base64_encode($raw);
        }
        return $cache;
    }
}

/* ====================================================================
   PAGE « ACCÈS REFUSÉ » (stylée, avec le menu de la suite)
   ==================================================================== */
if (!function_exists('suite_forbidden')) {
    /**
     * Affiche une page « Accès refusé » (403) dans l'habillage de la suite.
     * $requiredLabel : rôle nécessaire (ex. « Super admin »), affiché à côté du rôle actuel.
     */
    function suite_forbidden(string $message = "Vous n'avez pas accès à cette page.", string $title = 'Accès refusé', string $activeModule = '', string $requiredLabel = ''): void {
        http_response_code(403);
        $base = suite_base();
        $e = static fn(string $v): string => htmlspecialchars($v, ENT_QUOTES, 'UTF-8');
        $isAdm = is_admin();
        $roleLabel = match (current_role()) {
            'super_admin' => 'Super admin',
            'admin_plus'  => 'Admin+',
            'admin'       => 'Admin',
            default       => 'Non connecté',
        };
        $who = $isAdm ? current_volunteer_name() : '';
        ?><!DOCTYPE html>
<html lang="fr">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?= $e($title) ?> — Touraine-Ukraine</title>
  <link rel="stylesheet" href="<?= $e($base) ?>/assets/css/suite_nav.css">
</head>
<body class="tu-v2">
<?php
        if ($isAdm) {
            require_once __DIR__ . '/suite_nav.php';
            suite_nav_render($activeModule, '');
        }
?>
<div class="<?= $isAdm ? 'tu-main' : '' ?>">
  <div class="tu-topbar">
    <div class="tu-bc">
      <a href="<?= $e($base . '/index.php') ?>" style="color:inherit;text-decoration:none;">Accueil</a>
      <span class="tu-bc-sep">›</span>
      <span class="tu-bc-cur"><?= $e($title) ?></span>
    </div>
  </div>
  <div class="tu-pg" style="display:flex;justify-content:center;align-items:flex-start;padding-top:56px;">
    <div class="tu-card" style="max-width:460px;width:100%;height:auto;align-self:flex-start;padding:32px 30px 28px;text-align:center;">
      <div style="width:52px;height:52px;margin:0 auto 16px;border-radius:50%;background:var(--tu-red-soft,#fbeae6);color:var(--tu-red-main,#c0432a);display:flex;align-items:center;justify-content:center;">
        <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="4" y="11" width="16" height="10" rx="2"/><path d="M8 11V7a4 4 0 0 1 8 0v4"/></svg>
      </div>
      <div style="font-size:19px;font-weight:700;margin-bottom:6px;"><?= $e($title) ?></div>
      <p style="font-size:14px;line-height:1.55;color:var(--tu-ink-500,#6b5d4d);margin:0;"><?= $e($message) ?></p>
      <?php if ($isAdm): ?>
      <div style="display:flex;justify-content:center;gap:10px;flex-wrap:wrap;margin-top:20px;padding-top:18px;border-top:1px solid var(--tu-ink-100,#eadfce);font-size:12.5px;color:var(--tu-ink-500,#6b5d4d);">
        <span>Connecté : <strong style="color:var(--tu-ink-900,#2b2118);"><?= $e($who) ?></strong> · <?= $e($roleLabel) ?></span>
        <?php if ($requiredLabel !== ''): ?><span>Rôle requis : <strong style="color:var(--tu-ink-900,#2b2118);"><?= $e($requiredLabel) ?></strong></span><?php endif; ?>
      </div>
      <?php else: ?>
      <a href="<?= $e(suite_login_url()) ?>" class="tu-btn tu-btn-p" style="display:inline-flex;margin-top:20px;">Se connecter</a>
      <?php endif; ?>
    </div>
  </div>
</div>
</body>
</html>
<?php
        exit;
    }
}

/* ====================================================================
   PROTECTION GLOBALE DE /TOOLS/ (AVEC EXCEPTIONS INTELLIGENTES)
   ==================================================================== */

if (!$suiteIsCli) {

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

    // Droits par module : un admin connecté doit aussi avoir accès au module
    // demandé (voir "DROITS PAR MODULE" plus haut, gérés par le super admin).
    if (!$is_public_page && is_admin()) {
        $reqPath = parse_url((string)($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH) ?: '/';
        $reqModule = suite_module_from_path($reqPath);
        if ($reqModule !== null && !module_access($reqModule)) {
            suite_forbidden("Vous n'avez pas accès à ce module. Si vous en avez besoin, demandez-le à un super administrateur.");
        }
    }
}
