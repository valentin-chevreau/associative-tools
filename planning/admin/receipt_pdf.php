<?php
// admin/receipt_pdf.php
// PDF officiel & élégant — version fiscale SAFE (v4)
// Ajout explicite de la reconnaissance d'intérêt général (CGI art. 200)
// admin/receipt_pdf.php

// PDF "léger" sans dépendance — officiel & élégant
// v3 (ajustements demandés):
// - En-tête simplifié : "REÇU FISCAL" + référence (petit) + date (petit), sans bande grise
// - Accents corrigés (WinAnsi/Windows-1252)
// - Normalisation : VILLES en MAJUSCULES ; NOMS en MAJUSCULES ; prénoms en "Nom Propre"
// - Adresse donateur : suppression doublons (CP/Ville déjà présents dans la ligne rue)
// - Adresses "carrées" (rue / CP VILLE / pays)

require_once __DIR__ . '/../includes/app.php';
$config = require __DIR__ . '/../config/config.php';

if (session_status() === PHP_SESSION_NONE) session_start();
if (empty($_SESSION['is_admin']) && empty($_SESSION['admin_authenticated'])) {
    header("Location: {$config['base_url']}/admin/login.php");
    exit;
}

global $pdo;

$id = (int)($_GET['id'] ?? 0);
if ($id <= 0) { http_response_code(400); echo "Reçu invalide."; exit; }

$stmt = $pdo->prepare("SELECT * FROM donations WHERE id=:id LIMIT 1");
$stmt->execute(['id'=>$id]);
$don = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$don) { http_response_code(404); echo "Don introuvable."; exit; }
if ((string)($don['source'] ?? '') !== 'manual') { http_response_code(403); echo "Accès refusé."; exit; }
if (empty($don['receipt_number'])) { http_response_code(400); echo "Reçu non généré (receipt_number manquant)."; exit; }

// ---------- Helpers UTF-8 (before WinAnsi conversion)
function u_trim(string $s): string { return trim(str_replace(["\xC2\xA0", "\xE2\x80\xAF"], ' ', $s)); } // nbsp + narrow nbsp

function u_upper(string $s): string {
    $s = u_trim($s);
    if ($s === '') return '';
    if (function_exists('mb_strtoupper')) return mb_strtoupper($s, 'UTF-8');
    return strtoupper($s);
}

function u_title(string $s): string {
    $s = u_trim($s);
    if ($s === '') return '';
    // Title case (handles accents); keep separators - and '
    if (function_exists('mb_convert_case')) {
        $s = mb_convert_case($s, MB_CASE_TITLE, 'UTF-8');
    } else {
        $s = ucwords(strtolower($s));
    }
    // Normalise small particles if you want (optional) – left as-is.
    return $s;
}

function normalize_unicode(string $s): string {
    $s = str_replace(["\xC2\xA0", "\xE2\x80\xAF"], ' ', $s);
    // quotes & dashes to simpler equivalents
    $s = str_replace(["\xE2\x80\x98", "\xE2\x80\x99", "\xE2\x80\x9C", "\xE2\x80\x9D"], ["'", "'", '"', '"'], $s);
    $s = str_replace(["\xE2\x80\x93", "\xE2\x80\x94"], '-', $s);
    $s = str_replace(["\xE2\x80\xA6"], '...', $s);
    return $s;
}

// ---------- PDF helpers (WinAnsi)
function pdf_escape(string $s): string {
    $s = normalize_unicode($s);
    $s = iconv('UTF-8', 'Windows-1252//TRANSLIT//IGNORE', $s);
    $s = str_replace(["\\", "(", ")"], ["\\\\", "\\(", "\\)"], $s);
    return $s;
}
function approx_text_width(string $s, float $fontSize): float {
    $len = mb_strlen($s);
    return $len * $fontSize * 0.52; // approximation Helvetica
}
function wrap_text(string $text, float $maxWidthPt, float $fontSize): array {
    $text = trim($text);
    if ($text === '') return [''];
    $words = preg_split('/\s+/', $text);
    $lines = [];
    $cur = '';
    foreach ($words as $w) {
        $try = ($cur === '') ? $w : ($cur . ' ' . $w);
        if (approx_text_width($try, $fontSize) <= $maxWidthPt) {
            $cur = $try;
        } else {
            if ($cur !== '') $lines[] = $cur;
            $cur = $w;
        }
    }
    if ($cur !== '') $lines[] = $cur;
    return $lines ?: [''];
}
function text_at(&$stream, float $x, float $y, string $txt, int $size=11, bool $bold=false){
    $font = $bold ? "F2" : "F1";
    $stream .= "BT\n";
    $stream .= "/{$font} {$size} Tf\n";
    $stream .= sprintf("%.2f %.2f Td\n", $x, $y);
    $stream .= "(" . pdf_escape($txt) . ") Tj\n";
    $stream .= "ET\n";
}
function hline(&$stream, float $x1, float $x2, float $y){
    $stream .= sprintf("%.2f %.2f m\n%.2f %.2f l\nS\n", $x1, $y, $x2, $y);
}
function set_stroke_gray(&$stream, float $g){ $stream .= sprintf("%.3f %.3f %.3f RG\n", $g, $g, $g); }

// ---------- Address formatting / de-duplication
function strip_trailing_zip_city(string $street, string $zip, string $city): string {
    $s = u_trim($street);
    if ($s === '') return '';
    $zip = u_trim($zip);
    $city = u_trim($city);

    // Remove explicit zip anywhere
    if ($zip !== '') {
        $s = preg_replace('/\b' . preg_quote($zip, '/') . '\b/u', '', $s);
    }

    // Remove city anywhere (case-insensitive, best effort)
    if ($city !== '') {
        $s = preg_replace('/\b' . preg_quote($city, '/') . '\b/iu', '', $s);
    }

    // Cleanup extra spaces/punctuation
    $s = preg_replace('/\s{2,}/', ' ', $s);
    $s = trim($s, " \t\n\r\0\x0B,;-");
    return $s;
}

function join_lines(array $parts): string {
    $parts = array_values(array_filter(array_map(fn($v) => u_trim((string)$v), $parts), fn($v) => $v !== ''));
    return implode("\n", $parts);
}

function extract_city_name(string $s): string {
    $s = u_trim($s);
    if ($s === '') return '';
    $s = preg_replace('/^\d{4,5}\s+/u', '', $s);
    return u_trim($s);
}

// ---------- Data
$orgName    = u_trim((string)($config['org_name'] ?? 'ASSOCIATION'));
$orgRna     = u_trim((string)($config['org_rna'] ?? ''));
$orgSiret   = u_trim((string)($config['org_siret'] ?? ''));
$orgAddress = u_trim((string)($config['org_address'] ?? ''));
$orgCity    = u_trim((string)($config['org_city'] ?? ''));

$receiptNumber = (string)$don['receipt_number'];
$receiptDate   = (string)($don['receipt_date'] ?? '');
$donationDate  = (string)($don['donation_date'] ?? '');

$receiptDateFr  = $receiptDate ? date('d/m/Y', strtotime($receiptDate)) : date('d/m/Y');
$donationDateFr = $donationDate ? date('d/m/Y H:i', strtotime($donationDate)) : '—';

$first = u_title((string)($don['donor_first_name'] ?? ''));
$last  = u_upper((string)($don['donor_last_name'] ?? ''));
$donorName = u_trim(trim($first . ' ' . $last));

$donorEmail = u_trim((string)($don['donor_email'] ?? ''));
$donorAddr  = u_trim((string)($don['donor_address'] ?? ''));
$donorZip   = u_trim((string)($don['donor_postal_code'] ?? ''));
$donorCity  = u_upper((string)($don['donor_city'] ?? ''));     // ville en MAJUSCULES
$donorCountry = u_title((string)($don['donor_country'] ?? '')); // pays en Nom Propre

// Clean donor street line if it already embeds zip/city (case typical from api-adresse)
$donorStreet = strip_trailing_zip_city($donorAddr, $donorZip, $donorCity);
if ($donorStreet === '') $donorStreet = $donorAddr;

// Org address block: keep as provided but "city line" in uppercase when possible
$orgCityPretty = $orgCity;
$orgCityOnly = extract_city_name($orgCity);
if ($orgCityOnly !== '') {
    // if orgCity is "37300 JOUÉ-LÈS-TOURS" already, keep; else uppercase the city name
    $orgCityPretty = preg_replace('/^\d{4,5}\s+/u', '$0' . u_upper($orgCityOnly), $orgCity);
    if ($orgCityPretty === $orgCity) {
        $orgCityPretty = u_upper($orgCityOnly);
    }
}
$orgAddrValue = join_lines([
    $orgAddress,
    $orgCityPretty !== '' ? $orgCityPretty : ''
]);

$donorAddrValue = join_lines([
    $donorStreet,
    trim($donorZip . ' ' . $donorCity),
    $donorCountry
]);

$amount = (float)($don['amount'] ?? 0);
$amountFr = number_format($amount, 2, ',', ' ') . " EUR";

// "Fait à"
$doneAt = u_upper(u_trim((string)($config['org_receipt_city'] ?? '')));
if ($doneAt === '') $doneAt = u_upper(extract_city_name($orgCity));
if ($doneAt === '') $doneAt = '________________';
$doneOn = $receiptDateFr;

// ---------- Page constants (A4)
$page_w = 595; $page_h = 842;
$ml = 54; $mr = 54; $mt = 54; $mb = 54;
$usable_w = $page_w - $ml - $mr;

$x = $ml;
$y = $page_h - $mt;

$stream = "";
$stream .= "1 w\n";
set_stroke_gray($stream, 0.15);

// ---------- Header (simplified, no band)
$textCenterX = $x + ($usable_w / 2.0);

// Centered title (approx centering by width estimate)
$title = "REÇU FISCAL";
$refLine = "Référence : {$receiptNumber}";
$dateLine = "Date du reçu : {$receiptDateFr}";

function text_center(&$stream, float $centerX, float $y, string $txt, int $size, bool $bold=false){
    $w = approx_text_width($txt, $size);
    $x = $centerX - ($w / 2.0);
    text_at($stream, $x, $y, $txt, $size, $bold);
}

text_center($stream, $textCenterX, $y, $title, 16, true);
$y -= 18;
text_center($stream, $textCenterX, $y, $refLine, 10, false);
$y -= 14;
text_center($stream, $textCenterX, $y, $dateLine, 10, false);

$y -= 18;
set_stroke_gray($stream, 0.35);
hline($stream, $x, $page_w-$mr, $y);
set_stroke_gray($stream, 0.15);
$y -= 18;

// ---------- Two columns tables
$gap = 18;
$col_w = ($usable_w - $gap) / 2.0;
$c1 = $x;
$c2 = $x + $col_w + $gap;
$titleSize = 10;
$labelSize = 9;
$valSize   = 9;

text_at($stream, $c1, $y, "ORGANISME", $titleSize, true);
text_at($stream, $c2, $y, "DONATEUR", $titleSize, true);
$y -= 10;
set_stroke_gray($stream, 0.60);
hline($stream, $c1, $c1+$col_w, $y);
hline($stream, $c2, $c2+$col_w, $y);
set_stroke_gray($stream, 0.15);
$y -= 16;

function table_row_multiline(&$stream, float $x, float &$y, float $w, string $label, string $value, int $labelSize=9, int $valSize=9){
    $value = (string)$value;
    if (trim($value) === '') return;

    $labelW = 92;
    text_at($stream, $x, $y, $label, $labelSize, true);

    $valX = $x + $labelW;
    $valW = $w - $labelW;

    $parts = explode("\n", $value);
    foreach ($parts as $p) {
        $p = trim($p);
        if ($p === '') continue;
        $lines = wrap_text($p, $valW, $valSize);
        foreach ($lines as $ln) {
            text_at($stream, $valX, $y, $ln, $valSize, false);
            $y -= 13;
        }
        $y -= 1;
    }
    $y -= 2;
}

$y1 = $y; $y2 = $y;

table_row_multiline($stream, $c1, $y1, $col_w, "Organisme :", $orgName, $labelSize, $valSize);
table_row_multiline($stream, $c1, $y1, $col_w, "Adresse :", $orgAddrValue, $labelSize, $valSize);
table_row_multiline($stream, $c1, $y1, $col_w, "RNA :", $orgRna, $labelSize, $valSize);
table_row_multiline($stream, $c1, $y1, $col_w, "SIRET :", $orgSiret, $labelSize, $valSize);

table_row_multiline($stream, $c2, $y2, $col_w, "Donateur :", ($donorName !== '' ? $donorName : '—'), $labelSize, $valSize);
table_row_multiline($stream, $c2, $y2, $col_w, "Email :", $donorEmail, $labelSize, $valSize);
table_row_multiline($stream, $c2, $y2, $col_w, "Adresse :", $donorAddrValue, $labelSize, $valSize);

$y = min($y1, $y2) - 8;

// Separator
set_stroke_gray($stream, 0.60);
hline($stream, $x, $page_w-$mr, $y);
set_stroke_gray($stream, 0.15);
$y -= 18;

// ---------- DON section
text_at($stream, $x, $y, "DON", $titleSize, true);
$y -= 10;
set_stroke_gray($stream, 0.60);
hline($stream, $x, $page_w-$mr, $y);
set_stroke_gray($stream, 0.15);
$y -= 16;

$w = $usable_w;
table_row_multiline($stream, $x, $y, $w, "Date du don :", $donationDateFr, $labelSize, $valSize);
table_row_multiline($stream, $x, $y, $w, "Nature :", "Don en numéraire", $labelSize, $valSize);

text_at($stream, $x, $y, "Montant :", $labelSize, true);
text_at($stream, $x + 92, $y, $amountFr, 12, true);
$y -= 20;

// Separator
set_stroke_gray($stream, 0.60);
hline($stream, $x, $page_w-$mr, $y);
set_stroke_gray($stream, 0.15);
$y -= 18;

// ---------- Legal (with accents)
text_at($stream, $x, $y, "MENTIONS LÉGALES", $titleSize, true);
$y -= 12;

$legal = "Le présent reçu atteste que l'organisme ci-dessus a reçu un don en numéraire du donateur mentionné. "
       . "Ce don ouvre droit à une réduction d'impôt au titre de l'article 200 du Code général des impôts "
       . "(et/ou article 238 bis pour les entreprises, selon la situation du donateur), sous réserve de la réglementation en vigueur.\n"
       . "L'organisme certifie sur l'honneur remplir les conditions prévues par la loi pour bénéficier des dispositions fiscales relatives aux dons.";

$paraLines = [];
foreach (explode("\n", $legal) as $p) {
    $p = trim($p);
    if ($p === '') { $paraLines[] = ''; continue; }
    $paraLines = array_merge($paraLines, wrap_text($p, $usable_w, 9));
    $paraLines[] = '';
}

foreach ($paraLines as $ln) {
    if ($ln === '') { $y -= 8; continue; }
    text_at($stream, $x, $y, $ln, 9, false);
    $y -= 13;
}

$y -= 6;
text_at($stream, $x, $y, "Fait à {$doneAt}, le {$doneOn}", 9, false);
$y -= 20;
text_at($stream, $x, $y, "Pour l'association,", 9, false);
$y -= 18;
text_at($stream, $x, $y, "Le Président / Le Trésorier", 9, false);

// Footer
set_stroke_gray($stream, 0.75);
hline($stream, $x, $page_w-$mr, $mb-22);
set_stroke_gray($stream, 0.35);
text_at($stream, $x, $mb-36, "Reçu fiscal (document interne) — outil planning bénévoles", 8, false);
set_stroke_gray($stream, 0.15);

// ---------- Build PDF objects (2 fonts, WinAnsi)
$len = strlen($stream);

$objs = [];
$objs[1] = "<< /Type /Catalog /Pages 2 0 R >>";
$objs[2] = "<< /Type /Pages /Kids [3 0 R] /Count 1 >>";
$objs[3] = "<< /Type /Page /Parent 2 0 R /MediaBox [0 0 {$page_w} {$page_h}] /Resources << /Font << /F1 4 0 R /F2 5 0 R >> >> /Contents 6 0 R >>";
$objs[4] = "<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>";
$objs[5] = "<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold /Encoding /WinAnsiEncoding >>";
$objs[6] = "<< /Length {$len} >>\nstream\n{$stream}\nendstream";

$pdf = "%PDF-1.4\n";
$offs = [0];
for ($i=1; $i<=6; $i++) {
    $offs[$i] = strlen($pdf);
    $pdf .= "{$i} 0 obj\n{$objs[$i]}\nendobj\n";
}
$xref = strlen($pdf);
$pdf .= "xref\n0 7\n0000000000 65535 f \n";
for ($i=1; $i<=6; $i++) $pdf .= sprintf("%010d 00000 n \n", $offs[$i]);
$pdf .= "trailer\n<< /Size 7 /Root 1 0 R >>\nstartxref\n{$xref}\n%%EOF";

$fname = "recu_fiscal_" . preg_replace('/[^A-Za-z0-9\-]/','_', $receiptNumber) . ".pdf";
header('Content-Type: application/pdf');
header('Content-Disposition: inline; filename="'.$fname.'"');
header('Content-Length: ' . strlen($pdf));
echo $pdf;
exit;

?>