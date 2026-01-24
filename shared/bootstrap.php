<?php
declare(strict_types=1);

/**
 * shared/bootstrap.php
 * Point central d'authentification pour la suite tools
 * - Détecte automatiquement si on est en mode "suite" ou "standalone"
 * - Unifie l'authentification pour tous les modules
 */

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

/* ====================================================================
   DÉTECTION DU MODE : SUITE vs STANDALONE
   ==================================================================== */

if (!defined('SUITE_MODE')) {
    // On détecte si on est dans la suite tools en cherchant le fichier bootstrap.php
    $currentFile = __FILE__;
    $isSuiteMode = (strpos($currentFile, '/tools/shared/') !== false || 
                    strpos($currentFile, '/preprod-tools/shared/') !== false);
    
    define('SUITE_MODE', $isSuiteMode);
}

/* ====================================================================
   CONFIGURATION & HELPERS
   ==================================================================== */

if (!function_exists('suite_base')) {
    function suite_base(): string {
        $uri = $_SERVER['REQUEST_URI'] ?? '/';
        $path = parse_url($uri, PHP_URL_PATH) ?: '/';
        
        if (strpos($path, '/preprod-tools/') === 0) return '/preprod-tools';
        if (strpos($path, '/tools/') === 0) return '/tools';
        
        // Fallback standalone
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
            // Mode suite : login global
            return $base . '/admin/login.php?next=' . urlencode($current);
        } else {
            // Mode standalone : login du module
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

/**
 * Vérifie si l'utilisateur est admin (mode suite OU standalone)
 */
if (!function_exists('is_admin')) {
    function is_admin(): bool {
        // Vérification pour tous les flags possibles (suite + modules)
        return !empty($_SESSION['is_admin']) || 
               !empty($_SESSION['admin_authenticated']) || 
               !empty($_SESSION['admin']);
    }
}

/**
 * Vérifie si l'utilisateur est admin+ (uniquement en mode suite)
 */
if (!function_exists('is_admin_plus')) {
    function is_admin_plus(): bool {
        return !empty($_SESSION['admin_plus']) || !empty($_SESSION['is_admin_plus']);
    }
}

/**
 * Rôle actuel de l'utilisateur
 */
if (!function_exists('current_role')) {
    function current_role(): string {
        if (is_admin_plus()) return 'admin_plus';
        if (is_admin()) return 'admin';
        return 'public';
    }
}

/**
 * Login avec code (mode suite uniquement)
 */
if (!function_exists('admin_login_with_code')) {
    function admin_login_with_code(string $code): bool {
        if (!SUITE_MODE) return false;
        
        // Codes admin (à modifier selon vos besoins)
        $codes = [
            '12345678' => 'admin',      // Code admin normal
            '87654321' => 'admin_plus', // Code admin+
        ];
        
        $code = trim($code);
        if (!isset($codes[$code])) return false;
        
        $role = $codes[$code];
        
        // Set tous les flags pour compatibilité avec tous les modules
        $_SESSION['is_admin'] = true;
        $_SESSION['admin_authenticated'] = true;
        $_SESSION['admin'] = true;
        $_SESSION['admin_last_active'] = time();
        
        if ($role === 'admin_plus') {
            $_SESSION['admin_plus'] = true;
            $_SESSION['is_admin_plus'] = true;
        }
        
        return true;
    }
}

/**
 * Déconnexion unifiée
 */
if (!function_exists('admin_logout')) {
    function admin_logout(): void {
        unset(
            $_SESSION['is_admin'],
            $_SESSION['admin_authenticated'],
            $_SESSION['admin'],
            $_SESSION['admin_plus'],
            $_SESSION['is_admin_plus'],
            $_SESSION['admin_last_active']
        );
        session_regenerate_id(true);
    }
}

/**
 * Fonction pour que les modules puissent vérifier l'auth
 * Compatible avec l'ancienne fonction isAdminAuthenticated() du planning
 */
if (!function_exists('isAdminAuthenticated')) {
    function isAdminAuthenticated(): bool {
        return is_admin();
    }
}