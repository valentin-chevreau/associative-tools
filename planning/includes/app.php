<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Mini loader .env (sans lib)
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

// Charge .env depuis la racine projet
load_env(__DIR__ . '/../.env');
// Fallback: charge .env de la suite (racine) si présent
load_env(__DIR__ . '/../../.env');

// Helpers
function env(string $key, $default = null) {
    $v = getenv($key);
    return ($v === false || $v === '') ? $default : $v;
}

require_once __DIR__ . '/db.php';

function getCurrentVolunteerId(): ?int {
    return isset($_SESSION['volunteer_id']) ? (int)$_SESSION['volunteer_id'] : null;
}

function setCurrentVolunteerId(?int $id): void {
    if ($id === null) {
        unset($_SESSION['volunteer_id']);
    } else {
        $_SESSION['volunteer_id'] = $id;
    }
}

function getCurrentVolunteer(): ?array {
    $id = getCurrentVolunteerId();
    if (!$id) return null;
    static $vol = null;
    if ($vol !== null && $vol['id'] == $id) return $vol;

    global $pdo;
    $stmt = $pdo->prepare("SELECT * FROM volunteers WHERE id = :id AND is_active = 1");
    $stmt->execute(['id' => $id]);
    $vol = $stmt->fetch();
    if (!$vol) {
        setCurrentVolunteerId(null);
        return null;
    }
    return $vol;
}

function isAdminAuthenticated(): bool {
    if (empty($_SESSION['is_admin'])) {
        return false;
    }
    $maxIdle = 600; // 10 minutes
    $now = time();

    if (!isset($_SESSION['admin_last_active'])) {
        $_SESSION['admin_last_active'] = $now;
        return true;
    }

    // Session expirée ?
    if ($now - $_SESSION['admin_last_active'] > $maxIdle) {
        unset($_SESSION['is_admin'], $_SESSION['admin_last_active']);
        return false;
    }

    // Mise à jour timestamp activité
    $_SESSION['admin_last_active'] = $now;
    return true;
}

function requireAdmin(array $config): void {
    if (isAdminAuthenticated()) {
        return;
    }
    $loginUrl = $config['base_url'] . '/admin/login.php';
    header('Location: ' . $loginUrl);
    exit;
}
