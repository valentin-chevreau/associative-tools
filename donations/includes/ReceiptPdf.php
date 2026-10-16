<?php
declare(strict_types=1);

// donations/includes/ReceiptPdf.php — PDF du reçu fiscal (A4, tFPDF + Liberation Sans, UTF-8).

require_once dirname(__DIR__, 2) . '/shared/lib/TuPdf.php';

final class ReceiptPdf extends TuPdf
{
    private const LABEL_W = 30.0;

    /** @var array<string,mixed> */
    private array $d = [];

    public function __construct()
    {
        parent::__construct('P', 'mm', 'A4');
        $this->SetMargins(20, 20, 20);
        $this->SetAutoPageBreak(false);
    }

    /** @param array<string,mixed> $d données de receipt_build() */
    public static function render(array $d): string
    {
        $pdf = new self();
        $pdf->d = $d;
        $pdf->SetTitle('Reçu fiscal ' . $d['number'], true);
        $pdf->SetAuthor((string)$d['org_name'], true);
        $pdf->AddPage();
        $pdf->body();
        return $pdf->Output('S');
    }

    public function Footer(): void
    {
        $parts = array_filter([
            (string)$this->d['org_name'],
            $this->d['org_rna'] !== '' ? 'RNA ' . $this->d['org_rna'] : '',
            $this->d['org_siret'] !== '' ? 'SIRET ' . $this->d['org_siret'] : '',
        ]);
        $this->SetDrawColor(190, 190, 190);
        $this->Line(20, 280, 190, 280);
        $this->SetFont('LS', '', 8);
        $this->SetTextColor(110, 110, 110);
        $this->SetXY(20, 282);
        $this->Cell(170, 4, implode('  ·  ', $parts), 0, 0, 'C');
    }

    private function section(string $title, float $x, float $w): void
    {
        $this->SetFont('LS', 'B', 10);
        $this->SetTextColor(0, 0, 0);
        $this->SetXY($x, $this->GetY());
        $this->Cell($w, 5, $title, 0, 1);
        $this->SetDrawColor(150, 150, 150);
        $this->Line($x, $this->GetY(), $x + $w, $this->GetY());
        $this->SetY($this->GetY() + 3);
    }

    /** @param string|string[] $value */
    private function row(float $x, float $w, string $label, $value, float $size = 9.5, bool $bold = false): void
    {
        $lines = is_array($value) ? $value : [$value];
        $lines = array_values(array_filter($lines, fn($v) => trim((string)$v) !== ''));
        if (!$lines) return;
        $y = $this->GetY();
        $this->SetFont('LS', 'B', 9);
        $this->SetTextColor(0, 0, 0);
        $this->SetXY($x, $y);
        $this->Cell(self::LABEL_W, 5, $label, 0, 0);
        $this->SetFont('LS', $bold ? 'B' : '', $size);
        $this->SetXY($x + self::LABEL_W, $y);
        $this->MultiCell($w - self::LABEL_W, 5, implode("\n", $lines), 0, 'L');
        $this->SetY($this->GetY() + 1.2);
    }

    private function body(): void
    {
        $d = $this->d;

        // En-tête
        $this->SetFont('LS', 'B', 18);
        $this->SetXY(20, 22);
        $this->Cell(170, 9, 'REÇU FISCAL', 0, 1, 'C');
        $this->SetFont('LS', '', 10);
        $this->Cell(170, 5.5, 'Référence : ' . $d['number'], 0, 1, 'C');
        $this->Cell(170, 5.5, 'Date du reçu : ' . $d['receipt_date'], 0, 1, 'C');
        $this->SetY($this->GetY() + 3);
        $this->SetDrawColor(110, 110, 110);
        $this->Line(20, $this->GetY(), 190, $this->GetY());
        $this->SetY($this->GetY() + 8);

        // Organisme | Donateur
        $top = $this->GetY();
        $colW = 80.0;
        $x2 = 110.0;

        $this->SetY($top);
        $this->section('ORGANISME', 20, $colW);
        $this->row(20, $colW, 'Organisme :', (string)$d['org_name']);
        $this->row(20, $colW, 'Adresse :', $d['org_lines']);
        $this->row(20, $colW, 'RNA :', (string)$d['org_rna']);
        $this->row(20, $colW, 'SIRET :', (string)$d['org_siret']);
        $yOrg = $this->GetY();

        $this->SetY($top);
        $this->section('DONATEUR', $x2, $colW);
        $this->row($x2, $colW, 'Donateur :', $d['donor_name'] !== '' ? (string)$d['donor_name'] : '—');
        $this->row($x2, $colW, 'Email :', (string)$d['donor_email'], 9);
        $this->row($x2, $colW, 'Adresse :', $d['donor_lines']);
        $yDon = $this->GetY();

        $this->SetY(max($yOrg, $yDon) + 4);

        // Don
        $this->section('DON', 20, 170);
        $this->row(20, 170, 'Date du don :', (string)$d['donation_date']);
        $this->row(20, 170, 'Nature :', 'Don en numéraire');
        $this->row(20, 170, 'Montant :', (string)$d['amount_fr'], 12, true);
        $this->SetY($this->GetY() + 5);

        // Mentions légales
        $this->section('MENTIONS LÉGALES', 20, 170);
        $this->SetFont('LS', '', 9);
        $this->SetTextColor(40, 40, 40);
        $this->SetX(20);
        $this->MultiCell(170, 4.7,
            "Le présent reçu atteste que l'organisme ci-dessus a reçu un don en numéraire du donateur mentionné. "
            . "Ce don ouvre droit à une réduction d'impôt au titre de l'article 200 du Code général des impôts "
            . "(et/ou article 238 bis pour les entreprises, selon la situation du donateur), sous réserve de la réglementation en vigueur.", 0, 'J');
        $this->SetY($this->GetY() + 2.5);
        $this->SetX(20);
        $this->MultiCell(170, 4.7, "L'organisme certifie sur l'honneur remplir les conditions prévues par la loi pour bénéficier des dispositions fiscales relatives aux dons.", 0, 'J');
        $this->SetY($this->GetY() + 8);

        // Date + signature
        $this->SetTextColor(0, 0, 0);
        $this->SetX(20);
        $doneAt = $d['done_at'] !== '' ? $d['done_at'] : '________________';
        $this->Cell(170, 5, 'Fait à ' . $doneAt . ', le ' . $d['receipt_date'], 0, 1);
        $this->SetY($this->GetY() + 2);
        $this->SetX(20);
        $this->Cell(170, 5, "Pour l'association,", 0, 1);

        $sig = $d['signatory'];
        $this->SetX(20);
        if ($sig) {
            // « Prénom NOM, fonction » puis la signature dessous
            $this->SetFont('LS', 'B', 9.5);
            $this->Cell(170, 5, trim((string)$sig['name'] . ', ' . (string)$sig['role'], ' ,'), 0, 1);
            [$iw, $ih] = getimagesize($sig['path']) ?: [1, 1];
            $scale = min(40.0 / $iw, 17.0 / $ih);
            $this->Image($sig['path'], 20, $this->GetY() + 1, $iw * $scale, $ih * $scale);
        } else {
            $this->SetFont('LS', '', 9);
            $this->Cell(170, 5, 'Le Trésorier', 0, 1);
        }
    }
}
