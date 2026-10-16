<?php
declare(strict_types=1);

/**
 * shared/lib/TuPdf.php — base commune des PDF de la suite (reçus fiscaux, documents, étiquettes).
 *
 * tFPDF (UTF-8) + polices Liberation Sans (« LS ») et Liberation Serif (« LF »), chacune en
 * normal / gras / italique / gras-italique, plus l'opacité (ExtGState) pour les filigranes.
 * Les caches de métriques des polices sont livrés avec la bibliothèque : le serveur n'a
 * aucun fichier à écrire.
 */

if (!defined('FPDF_FONTPATH')) define('FPDF_FONTPATH', __DIR__ . '/tfpdf/font/');
require_once __DIR__ . '/tfpdf/font/unifont/ttfonts.php';
require_once __DIR__ . '/tfpdf/tfpdf.php';

abstract class TuPdf extends tFPDF
{
    /** 1 pt en mm */
    protected const PT = 0.352778;

    /** @var array<int,array{n:int,a:float}> opacités déclarées (ExtGState) */
    private array $extgstates = [];

    public function __construct(string $orientation = 'P', string $unit = 'mm', $size = 'A4')
    {
        parent::__construct($orientation, $unit, $size);
        $this->PDFVersion = '1.4';
        $this->SetCreator('Suite Touraine-Ukraine');
        foreach (['LS' => 'LiberationSans', 'LF' => 'LiberationSerif'] as $family => $file) {
            foreach (['' => 'Regular', 'B' => 'Bold', 'I' => 'Italic', 'BI' => 'BoldItalic'] as $style => $suffix) {
                $this->AddFont($family, $style, $file . '-' . $suffix . '.ttf', true);
            }
        }
    }

    // ---- Opacité (ExtGState) ------------------------------------------------
    protected function setAlpha(float $alpha): void
    {
        $key = (int)round($alpha * 1000);
        if (!isset($this->extgstates[$key])) $this->extgstates[$key] = ['n' => 0, 'a' => $alpha];
        $this->_out(sprintf('/GS%d gs', $key));
    }

    protected function _putextgstates(): void
    {
        foreach ($this->extgstates as $key => $gs) {
            $this->_newobj();
            $this->extgstates[$key]['n'] = $this->n;
            $this->_put('<</Type /ExtGState /ca ' . sprintf('%.3F', $gs['a']) . ' /CA ' . sprintf('%.3F', $gs['a']) . '>>');
            $this->_put('endobj');
        }
    }

    protected function _putresourcedict()
    {
        parent::_putresourcedict();
        $this->_put('/ExtGState <<');
        foreach ($this->extgstates as $key => $gs) {
            $this->_put('/GS' . $key . ' ' . $gs['n'] . ' 0 R');
        }
        $this->_put('>>');
    }

    protected function _putresources()
    {
        $this->_putfonts();
        $this->_putimages();
        $this->_putextgstates();
        $this->_newobj(2);
        $this->_put('<<');
        $this->_putresourcedict();
        $this->_put('>>');
        $this->_put('endobj');
    }
}
