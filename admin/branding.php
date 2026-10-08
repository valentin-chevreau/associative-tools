<?php
// admin/branding.php — Logo de la suite (super admin uniquement)
//
// Le logo téléversé remplace la pastille « TU » de la page de connexion et du menu.
// Il est normalisé en PNG (256 px max) et stocké dans uploads/branding/logo.png.

declare(strict_types=1);

require_once __DIR__ . '/../shared/bootstrap.php';

if (!defined('APP_BASE')) {
    define('APP_BASE', suite_base() . '/admin');
}

if (!is_super_admin()) {
    suite_forbidden("La personnalisation du logo est réservée aux super administrateurs.", "Accès refusé", "users", "Super admin");
}

if (!function_exists('h')) {
    function h(string $s): string { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }
}

const BRANDING_MAX_UPLOAD = 2 * 1024 * 1024; // 2 Mo
const BRANDING_MAX_SIDE   = 256;             // px

// Jeton anti-CSRF simple (formulaire POST réservé au super admin)
if (empty($_SESSION['branding_csrf'])) {
    $_SESSION['branding_csrf'] = bin2hex(random_bytes(16));
}
$csrf = (string)$_SESSION['branding_csrf'];

$errors = [];
$success = null;

/**
 * Normalise une image téléversée en PNG ≤ 256 px (transparence conservée).
 * Retourne le contenu PNG, ou lève une exception avec un message lisible.
 */
function branding_normalize(string $tmpPath): string {
    $info = @getimagesize($tmpPath);
    if (!$info || !in_array($info[2], [IMAGETYPE_PNG, IMAGETYPE_JPEG, IMAGETYPE_GIF, IMAGETYPE_WEBP], true)) {
        throw new RuntimeException("Format non pris en charge : utilisez un PNG, JPEG, GIF ou WebP.");
    }
    [$w, $h] = [(int)$info[0], (int)$info[1]];
    if ($w < 16 || $h < 16) {
        throw new RuntimeException("Image trop petite (16 px minimum).");
    }

    if (!function_exists('imagecreatefromstring')) {
        // Pas de GD : on n'accepte qu'un PNG léger, conservé tel quel.
        if ($info[2] !== IMAGETYPE_PNG || filesize($tmpPath) > 150 * 1024) {
            throw new RuntimeException("Le serveur ne peut pas redimensionner les images : fournissez un PNG de 150 Ko maximum.");
        }
        return (string)file_get_contents($tmpPath);
    }

    $src = @imagecreatefromstring((string)file_get_contents($tmpPath));
    if (!$src) {
        throw new RuntimeException("Impossible de lire cette image.");
    }
    $ratio = min(1, BRANDING_MAX_SIDE / max($w, $h));
    $nw = max(1, (int)round($w * $ratio));
    $nh = max(1, (int)round($h * $ratio));

    $dst = imagecreatetruecolor($nw, $nh);
    imagealphablending($dst, false);
    imagesavealpha($dst, true);
    imagefill($dst, 0, 0, imagecolorallocatealpha($dst, 0, 0, 0, 127));
    imagecopyresampled($dst, $src, 0, 0, 0, 0, $nw, $nh, $w, $h);

    ob_start();
    imagepng($dst, null, 8);
    $png = (string)ob_get_clean();
    imagedestroy($src);
    imagedestroy($dst);
    return $png;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string)($_POST['action'] ?? '');

    if (!hash_equals($csrf, (string)($_POST['csrf'] ?? ''))) {
        $errors[] = "Session expirée, veuillez réessayer.";
    } elseif ($action === 'upload') {
        $f = $_FILES['logo'] ?? null;
        if (!$f || ($f['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
            $errors[] = "Choisissez un fichier.";
        } elseif (($f['error'] ?? 0) !== UPLOAD_ERR_OK || !is_uploaded_file((string)$f['tmp_name'])) {
            $errors[] = "Le téléversement a échoué (code " . (int)($f['error'] ?? -1) . ").";
        } elseif ((int)$f['size'] > BRANDING_MAX_UPLOAD) {
            $errors[] = "Fichier trop volumineux (2 Mo maximum).";
        } else {
            try {
                $png = branding_normalize((string)$f['tmp_name']);
                $dir = dirname(suite_logo_path());
                if (!is_dir($dir) && !@mkdir($dir, 0755, true)) {
                    throw new RuntimeException("Impossible de créer le dossier uploads/branding (droits d'écriture ?).");
                }
                if (@file_put_contents(suite_logo_path(), $png, LOCK_EX) === false) {
                    throw new RuntimeException("Impossible d'enregistrer le logo (droits d'écriture sur uploads/branding ?).");
                }
                audit_log('admin', 'update', 'logo', null, 'Logo de la suite');
                $success = "Logo enregistré.";
            } catch (Throwable $e) {
                $errors[] = $e->getMessage();
            }
        }
    } elseif ($action === 'delete') {
        $path = suite_logo_path();
        if (is_file($path) && !@unlink($path)) {
            $errors[] = "Impossible de supprimer le fichier (droits ?).";
        } else {
            audit_log('admin', 'delete', 'logo', null, 'Logo de la suite');
            $success = "Logo supprimé : la pastille « TU » est de nouveau utilisée.";
        }
    }
}

// Lecture directe (le cache statique de suite_logo_data_uri() peut être périmé après POST)
$logoPath = suite_logo_path();
$logo = (is_file($logoPath) && filesize($logoPath) > 0)
    ? 'data:image/png;base64,' . base64_encode((string)file_get_contents($logoPath))
    : null;
?>
<!doctype html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <title>Logo — Touraine-Ukraine</title>
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <link rel="stylesheet" href="<?= h(suite_base()) ?>/assets/css/suite_nav.css<?= function_exists('suite_css_v') ? suite_css_v() : '' ?>"><?= function_exists('suite_pwa_head') ? suite_pwa_head() : '' ?>
    <style>
      body.tu-v2 { display: block; }
      body.tu-v2 .tu-main { margin-left: var(--tu-sw); padding: 24px; }
      @media (max-width: 900px) {
        body.tu-v2 .tu-main { margin-left: 0; padding: 16px; padding-top: 70px; }
      }
      .brand-prev { display:flex; gap:18px; align-items:center; flex-wrap:wrap; margin-bottom:20px; }
      .brand-tile { width:68px; height:68px; border-radius:16px; display:flex; align-items:center; justify-content:center;
        background:linear-gradient(135deg,#1a1510,#c47328); color:#fff; font-weight:900; font-size:26px; letter-spacing:1px; }
      .brand-tile.img { background:#fff; padding:8px; box-shadow:0 0 0 1px #e8dfd4; }
      .brand-tile img { width:100%; height:100%; object-fit:contain; display:block; }
      .brand-side { width:36px; height:36px; border-radius:10px; background:#1a1510; display:flex; align-items:center; justify-content:center; padding:3px; }
      .brand-side .in { width:100%; height:100%; background:#fff; border-radius:8px; padding:3px; box-sizing:border-box; }
      .brand-side img { width:100%; height:100%; object-fit:contain; display:block; }
    </style>
</head>
<body class="tu-v2">
<?php
require_once dirname(__DIR__) . '/shared/suite_nav.php';
suite_nav_render('users', '');
?>
<div class="tu-main">

<div class="tu-topbar">
  <div class="tu-bc">
    <a href="<?= h(suite_base()) ?>/index.php" style="color:inherit;text-decoration:none;">Accueil</a>
    <span class="tu-bc-sep">›</span>
    <a href="users.php" style="color:inherit;text-decoration:none;">Utilisateurs</a>
    <span class="tu-bc-sep">›</span>
    <span class="tu-bc-cur">Logo</span>
  </div>
  <div class="tu-topbar-acts">
    <a href="users.php" class="tu-btn tu-btn-s tu-btn-sm">Utilisateurs</a>
    <a href="permissions.php" class="tu-btn tu-btn-s tu-btn-sm">Droits</a>
  </div>
</div>

<div class="tu-pg">

  <?php if ($success): ?>
    <div style="background:var(--tu-green-soft);border:1.5px solid rgba(42,125,74,.25);border-radius:12px;padding:12px 16px;margin-bottom:16px;color:var(--tu-green-main);font-size:13px;"><?= h($success) ?></div>
  <?php endif; ?>
  <?php if (!empty($errors)): ?>
    <div style="background:var(--tu-red-soft);border-radius:12px;padding:12px 16px;margin-bottom:16px;color:var(--tu-red-main);font-size:13px;"><ul style="margin:0;padding-left:16px;"><?php foreach ($errors as $e): ?><li><?= h($e) ?></li><?php endforeach; ?></ul></div>
  <?php endif; ?>

  <div class="tu-ph">
    <div>
      <div class="tu-ph-title">Logo de la suite</div>
      <div class="tu-ph-sub">Affiché sur la page de connexion et dans le menu latéral</div>
    </div>
  </div>

  <div class="tu-card" style="padding:22px;max-width:640px;">
    <div style="font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.07em;color:var(--tu-ink-300);margin-bottom:12px;">Aperçu</div>
    <div class="brand-prev">
      <?php if ($logo): ?>
        <div class="brand-tile img"><img src="<?= $logo ?>" alt="Logo"></div>
        <div class="brand-side"><div class="in"><img src="<?= $logo ?>" alt=""></div></div>
      <?php else: ?>
        <div class="brand-tile">TU</div>
        <div style="font-size:13px;color:var(--tu-ink-500);">Aucun logo : la pastille « TU » par défaut est utilisée.</div>
      <?php endif; ?>
    </div>

    <form method="post" enctype="multipart/form-data" style="display:flex;flex-direction:column;gap:12px;">
      <input type="hidden" name="action" value="upload">
      <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
      <div class="tu-form-field">
        <span class="tu-lbl"><?= $logo ? 'Remplacer le logo' : 'Téléverser un logo' ?></span>
        <input class="tu-input" type="file" name="logo" accept="image/png,image/jpeg,image/webp,image/gif" required>
      </div>
      <div style="font-size:12px;color:var(--tu-ink-400);">
        PNG, JPEG, WebP ou GIF, 2 Mo maximum. L'image est redimensionnée (256 px) ; un logo carré, de préférence
        sur fond transparent, rend le mieux.
      </div>
      <div style="display:flex;gap:8px;">
        <button type="submit" class="tu-btn tu-btn-p tu-btn-sm">Enregistrer</button>
      </div>
    </form>

    <?php if ($logo): ?>
      <form method="post" style="margin-top:14px;padding-top:14px;border-top:1px solid var(--tu-ink-100,#eadfce);" onsubmit="return confirm('Supprimer le logo et revenir à la pastille « TU » ?');">
        <input type="hidden" name="action" value="delete">
        <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
        <button type="submit" class="tu-btn tu-btn-d tu-btn-sm">Supprimer le logo</button>
      </form>
    <?php endif; ?>
  </div>

</div>
</div>
</body>
</html>
