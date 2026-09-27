<?php
declare(strict_types=1);

/**
 * shared/simplepdf.php — Générateur PDF minimal, sans dépendance externe.
 *
 * Remplace TCPDF (jamais installé : pas de composer.json, pas de vendor/)
 * pour les documents PDF de la Suite (adhésions : reçu, attestation, carte
 * de membre). Construit directement le flux d'octets PDF (objets,
 * table xref, trailer) — aucune bibliothèque tierce, rien à installer côté
 * serveur, rien à passer par composer.
 *
 * Fonctionnalités : texte positionné (Helvetica / Helvetica-Bold, taille et
 * couleur libres, alignement gauche/centre/droite), texte multi-lignes avec
 * retour à la ligne automatique, rectangles pleins ou juste contour, lignes,
 * pages multiples et formats de page personnalisés (mm). Encodage
 * WinAnsiEncoding (CP1252) pour les accents français.
 */

class SimplePdf
{
    private const MM2PT = 2.834645669;
    private const DEFAULT_WIDTH = 556;

    /** @var array<int, array{w: float, h: float, content: string}> */
    private array $pages = [];
    private int $curPage = -1;

    private array $fillColor = [0, 0, 0];
    private array $textColor = [0, 0, 0];
    private array $drawColor = [0, 0, 0];
    private float $lineWidth = 0.2;

    private static ?array $widthsRegular = null;
    private static ?array $widthsBold = null;

    public function __construct(float $width = 210, float $height = 297)
    {
        $this->addPage($width, $height);
    }

    public function addPage(float $width = 210, float $height = 297): void
    {
        $this->pages[] = ['w' => $width, 'h' => $height, 'content' => ''];
        $this->curPage = count($this->pages) - 1;
    }

    public function setFillColor(int $r, int $g, int $b): void { $this->fillColor = [$r, $g, $b]; }
    public function setTextColor(int $r, int $g, int $b): void { $this->textColor = [$r, $g, $b]; }
    public function setDrawColor(int $r, int $g, int $b): void { $this->drawColor = [$r, $g, $b]; }
    public function setLineWidth(float $w): void { $this->lineWidth = $w; }

    private function col(array $c): string
    {
        return sprintf('%.3F %.3F %.3F', $c[0] / 255, $c[1] / 255, $c[2] / 255);
    }

    private function append(string $s): void
    {
        $this->pages[$this->curPage]['content'] .= $s;
    }

    /** Rectangle. $x/$y/$w/$h en mm depuis le coin haut-gauche. $style : 'F' plein, 'D' contour, 'FD' les deux. */
    public function rect(float $x, float $y, float $w, float $h, string $style = 'D'): void
    {
        $pageH = $this->pages[$this->curPage]['h'];
        $px = $x * self::MM2PT;
        $py = ($pageH - $y - $h) * self::MM2PT;
        $pw = $w * self::MM2PT;
        $ph = $h * self::MM2PT;
        $op = $style === 'F' ? 'f' : ($style === 'FD' ? 'B' : 'S');
        $this->append(sprintf(
            "q %s rg %s RG %.2F w %.2F %.2F %.2F %.2F re %s Q\n",
            $this->col($this->fillColor), $this->col($this->drawColor), $this->lineWidth * self::MM2PT,
            $px, $py, $pw, $ph, $op
        ));
    }

    public function line(float $x1, float $y1, float $x2, float $y2): void
    {
        $pageH = $this->pages[$this->curPage]['h'];
        $this->append(sprintf(
            "q %s RG %.2F w %.2F %.2F m %.2F %.2F l S Q\n",
            $this->col($this->drawColor), $this->lineWidth * self::MM2PT,
            $x1 * self::MM2PT, ($pageH - $y1) * self::MM2PT,
            $x2 * self::MM2PT, ($pageH - $y2) * self::MM2PT
        ));
    }

    /** Encode une chaîne UTF-8 en CP1252 (WinAnsiEncoding) et échappe les caractères spéciaux PDF. */
    private function encode(string $txt): string
    {
        $converted = @iconv('UTF-8', 'CP1252//TRANSLIT//IGNORE', $txt);
        if ($converted === false || $converted === null) $converted = $txt;
        return str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], $converted);
    }

    private function widthTable(bool $bold): array
    {
        if ($bold) {
            if (self::$widthsBold === null) self::$widthsBold = self::buildWidths(true);
            return self::$widthsBold;
        }
        if (self::$widthsRegular === null) self::$widthsRegular = self::buildWidths(false);
        return self::$widthsRegular;
    }

    private static function buildWidths(bool $bold): array
    {
        // Métriques Helvetica standard (ASCII 32-126), en 1/1000 d'em.
        $ascii = [
            32=>278,33=>278,34=>355,35=>556,36=>556,37=>889,38=>667,39=>191,40=>333,41=>333,
            42=>389,43=>584,44=>278,45=>333,46=>278,47=>278,48=>556,49=>556,50=>556,51=>556,
            52=>556,53=>556,54=>556,55=>556,56=>556,57=>556,58=>278,59=>278,60=>584,61=>584,
            62=>584,63=>556,64=>1015,65=>667,66=>667,67=>722,68=>722,69=>667,70=>611,71=>778,
            72=>722,73=>278,74=>500,75=>667,76=>556,77=>833,78=>722,79=>778,80=>667,81=>778,
            82=>722,83=>667,84=>611,85=>722,86=>667,87=>944,88=>667,89=>667,90=>611,91=>278,
            92=>278,93=>278,94=>469,95=>556,96=>333,97=>556,98=>556,99=>500,100=>556,101=>556,
            102=>278,103=>556,104=>556,105=>222,106=>222,107=>500,108=>222,109=>833,110=>556,
            111=>556,112=>556,113=>556,114=>333,115=>500,116=>278,117=>556,118=>500,119=>722,
            120=>500,121=>500,122=>500,123=>334,124=>260,125=>334,126=>584,
        ];
        if ($bold) {
            // Helvetica-Bold : majoration approximative, suffisante pour le calcul
            // du retour à la ligne (pas de rendu métrique exact requis ici).
            foreach ($ascii as $k => $v) $ascii[$k] = (int)round($v * 1.08);
        }
        // Lettres accentuées françaises (WinAnsiEncoding / CP1252) : largeur
        // alignée sur celle de la lettre de base correspondante.
        $accents = [
            192=>'A',193=>'A',194=>'A',195=>'A',196=>'A',197=>'A',
            199=>'C',
            200=>'E',201=>'E',202=>'E',203=>'E',
            204=>'I',205=>'I',206=>'I',207=>'I',
            209=>'N',
            210=>'O',211=>'O',212=>'O',213=>'O',214=>'O',
            217=>'U',218=>'U',219=>'U',220=>'U',
            221=>'Y',
            224=>'a',225=>'a',226=>'a',227=>'a',228=>'a',229=>'a',
            231=>'c',
            232=>'e',233=>'e',234=>'e',235=>'e',
            236=>'i',237=>'i',238=>'i',239=>'i',
            241=>'n',
            242=>'o',243=>'o',244=>'o',245=>'o',246=>'o',
            249=>'u',250=>'u',251=>'u',252=>'u',
            253=>'y',255=>'y',
        ];
        $table = $ascii;
        foreach ($accents as $code => $letter) {
            $baseCode = ord($letter);
            $table[$code] = $ascii[$baseCode] ?? self::DEFAULT_WIDTH;
        }
        return $table;
    }

    private function widthOf(string $cp1252Text, float $size, bool $bold): float
    {
        $table = $this->widthTable($bold);
        $w = 0;
        $len = strlen($cp1252Text);
        for ($i = 0; $i < $len; $i++) {
            $code = ord($cp1252Text[$i]);
            $w += $table[$code] ?? self::DEFAULT_WIDTH;
        }
        return $w / 1000 * $size;
    }

    public function textWidth(string $txt, float $size, bool $bold = false): float
    {
        return $this->widthOf($this->encode($txt), $size, $bold) / self::MM2PT;
    }

    /**
     * Écrit une ligne de texte. $x/$y en mm depuis le coin haut-gauche de la
     * page, $y désignant la ligne de base du texte (convention type TCPDF).
     */
    public function text(float $x, float $y, string $txt, float $size = 11, bool $bold = false, string $align = 'L'): void
    {
        $pageH = $this->pages[$this->curPage]['h'];
        $encoded = $this->encode($txt);
        $wPt = $this->widthOf($encoded, $size, $bold);
        $px = $x;
        if ($align === 'C') $px = $x - ($wPt / self::MM2PT) / 2;
        elseif ($align === 'R') $px = $x - ($wPt / self::MM2PT);
        $font = $bold ? 'F2' : 'F1';
        $this->append(sprintf(
            "q %s rg BT /%s %.2F Tf %.2F %.2F Td (%s) Tj ET Q\n",
            $this->col($this->textColor), $font, $size,
            $px * self::MM2PT, ($pageH - $y) * self::MM2PT, $encoded
        ));
    }

    /**
     * Texte multi-lignes, retour à la ligne automatique sur la largeur $w (mm).
     * Retourne la position Y (mm) juste après la dernière ligne écrite.
     */
    public function multiText(float $x, float $y, float $w, float $lineHeight, string $txt, float $size = 11, bool $bold = false, string $align = 'L'): float
    {
        $curY = $y;
        foreach ($this->wrapText($txt, $w, $size, $bold) as $line) {
            $this->text($x, $curY, $line, $size, $bold, $align);
            $curY += $lineHeight;
        }
        return $curY;
    }

    /** @return string[] */
    private function wrapText(string $txt, float $wMm, float $size, bool $bold): array
    {
        $maxWidthPt = $wMm * self::MM2PT;
        $lines = [];
        foreach (preg_split("/\r\n|\r|\n/", $txt) as $paragraph) {
            $words = preg_split('/\s+/', trim($paragraph));
            $current = '';
            foreach ($words as $word) {
                if ($word === '') continue;
                $candidate = $current === '' ? $word : $current . ' ' . $word;
                $cw = $this->widthOf($this->encode($candidate), $size, $bold);
                if ($cw > $maxWidthPt && $current !== '') {
                    $lines[] = $current;
                    $current = $word;
                } else {
                    $current = $candidate;
                }
            }
            $lines[] = $current;
        }
        return $lines;
    }

    /**
     * Génère le PDF final.
     * $dest : 'D' = téléchargement (envoie les en-têtes HTTP),
     *         'S' = retourne le contenu binaire du PDF (string),
     *         'F' = écrit le PDF dans le fichier $filename et retourne true.
     */
    public function output(string $filename = 'document.pdf', string $dest = 'D')
    {
        $pdf = $this->build();
        if ($dest === 'S') return $pdf;
        if ($dest === 'F') { file_put_contents($filename, $pdf); return true; }

        if (!headers_sent()) {
            header('Content-Type: application/pdf');
            header('Content-Disposition: attachment; filename="' . str_replace('"', '', $filename) . '"');
            header('Content-Length: ' . strlen($pdf));
            header('Cache-Control: private, max-age=0, must-revalidate');
            header('Pragma: public');
        }
        echo $pdf;
        return true;
    }

    private function build(): string
    {
        $objects = [];
        $n = 1;

        $catalogNum = $n++;
        $pagesNum = $n++;
        $fontRegNum = $n++;
        $fontBoldNum = $n++;

        $pageNums = [];
        $contentNums = [];
        foreach ($this->pages as $p) {
            $pageNums[] = $n++;
            $contentNums[] = $n++;
        }

        $objects[$catalogNum] = "<< /Type /Catalog /Pages $pagesNum 0 R >>";

        $kids = implode(' ', array_map(fn($pn) => "$pn 0 R", $pageNums));
        $objects[$pagesNum] = "<< /Type /Pages /Kids [$kids] /Count " . count($pageNums) . " >>";

        $objects[$fontRegNum] = "<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>";
        $objects[$fontBoldNum] = "<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold /Encoding /WinAnsiEncoding >>";

        foreach ($this->pages as $i => $p) {
            $wPt = $p['w'] * self::MM2PT;
            $hPt = $p['h'] * self::MM2PT;
            $pageNum = $pageNums[$i];
            $contentNum = $contentNums[$i];
            $objects[$pageNum] = "<< /Type /Page /Parent $pagesNum 0 R /MediaBox [0 0 " . sprintf('%.2F %.2F', $wPt, $hPt) . "] "
                . "/Resources << /Font << /F1 $fontRegNum 0 R /F2 $fontBoldNum 0 R >> >> /Contents $contentNum 0 R >>";
            $stream = $p['content'];
            $objects[$contentNum] = "<< /Length " . strlen($stream) . " >>\nstream\n$stream" . "endstream";
        }

        $out = "%PDF-1.4\n";
        $offsets = [0 => 0];
        ksort($objects);
        foreach ($objects as $num => $body) {
            $offsets[$num] = strlen($out);
            $out .= "$num 0 obj\n$body\nendobj\n";
        }
        $xrefStart = strlen($out);
        $count = count($objects) + 1;
        $out .= "xref\n0 $count\n";
        $out .= "0000000000 65535 f \n";
        for ($i = 1; $i < $count; $i++) {
            $off = $offsets[$i] ?? 0;
            $out .= sprintf("%010d 00000 n \n", $off);
        }
        $out .= "trailer\n<< /Size $count /Root $catalogNum 0 R >>\nstartxref\n$xrefStart\n%%EOF";
        return $out;
    }
}
