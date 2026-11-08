<?php
declare(strict_types=1);

// mailing/upload_image.php — Téléverse une image pour un bloc « Image » (réponse JSON).
// L'image est relue et ré-encodée (JPEG ou PNG, 1200 px de large au plus) : aucun fichier envoyé n'est conservé tel quel.

require_once dirname(__DIR__) . '/shared/bootstrap.php';
require_admin();
require_once __DIR__ . '/includes/mailing.php';

header('Content-Type: application/json; charset=UTF-8');
$fail = static function (string $msg, int $code = 400): never {
    http_response_code($code);
    echo json_encode(['error' => $msg], JSON_UNESCAPED_UNICODE);
    exit;
};

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !mailing_csrf_ok()) $fail('Session expirée : rechargez la page.');
$f = $_FILES['image'] ?? null;
if (!$f || ($f['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_uploaded_file((string)$f['tmp_name'])) $fail("Le téléversement a échoué.");
if ((int)$f['size'] > 10 * 1024 * 1024) $fail("Image trop lourde (10 Mo maximum).");

$info = @getimagesize((string)$f['tmp_name']);
$type = $info[2] ?? 0;
$src = match ($type) {
    IMAGETYPE_JPEG => @imagecreatefromjpeg((string)$f['tmp_name']),
    IMAGETYPE_PNG  => @imagecreatefrompng((string)$f['tmp_name']),
    IMAGETYPE_WEBP => function_exists('imagecreatefromwebp') ? @imagecreatefromwebp((string)$f['tmp_name']) : false,
    default        => false,
};
if (!$src) $fail("Format non pris en charge : utilisez une image JPEG, PNG ou WebP.");

$w = imagesx($src); $h = imagesy($src);
$nw = min($w, 1200); $nh = (int)round($h * $nw / $w);
$dst = imagecreatetruecolor($nw, $nh);
$png = ($type === IMAGETYPE_PNG || $type === IMAGETYPE_WEBP);
if ($png) {
    imagealphablending($dst, false); imagesavealpha($dst, true);
    imagefill($dst, 0, 0, imagecolorallocatealpha($dst, 0, 0, 0, 127));
} else {
    imagefill($dst, 0, 0, imagecolorallocate($dst, 255, 255, 255));
}
imagecopyresampled($dst, $src, 0, 0, 0, 0, $nw, $nh, $w, $h);
imagedestroy($src);

$dir = mailing_image_dir();
if (!is_dir($dir) && !@mkdir($dir, 0755, true)) $fail("Impossible de créer le dossier uploads/mailing (droits d'écriture ?).", 500);
$name = bin2hex(random_bytes(16)) . ($png ? '.png' : '.jpg');
$ok = $png ? @imagepng($dst, $dir . '/' . $name, 6) : @imagejpeg($dst, $dir . '/' . $name, 85);
imagedestroy($dst);
if (!$ok) $fail("Impossible d'enregistrer l'image (droits d'écriture sur uploads/mailing ?).", 500);

echo json_encode(['file' => $name, 'url' => mailing_image_url(suite_base(), $name)], JSON_UNESCAPED_UNICODE);
