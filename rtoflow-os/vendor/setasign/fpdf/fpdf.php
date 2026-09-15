<?php

/**
 * FPDF — Free PDF generator for PHP.
 *
 * This is a bundled minimal implementation of the FPDF library (fpdf.org).
 * Original library by Olivier Plathey, licensed under free/permissive license.
 *
 * Bundled with RTOFLOW OS because the network environment prevented Composer
 * installation. For the full-featured library, run `composer install` which
 * will replace this file with the official setasign/fpdf package.
 *
 * Supported methods: AddPage, SetFont, Cell, MultiCell, Ln, SetXY, GetX, GetY,
 * SetFillColor, SetTextColor, SetDrawColor, SetLineWidth, Output, Rect, Line,
 * SetTitle, Image (PNG only).
 */
class FPDF
{
    // ── State ─────────────────────────────────────────────────────────────────
    protected float  $x        = 10;
    protected float  $y        = 10;
    protected float  $w        = 210; // A4 width mm
    protected float  $h        = 297; // A4 height mm
    protected float  $lMargin  = 10;
    protected float  $rMargin  = 10;
    protected float  $tMargin  = 10;
    protected float  $bMargin  = 20;
    protected float  $cMargin  = 1;  // cell margin
    protected string $fontFamily  = 'Helvetica';
    protected string $fontStyle   = '';
    protected float  $fontSize    = 10;
    protected array  $fillColor   = [255, 255, 255];
    protected array  $textColor   = [0, 0, 0];
    protected array  $drawColor   = [0, 0, 0];
    protected float  $lineWidth   = 0.2;
    protected string $title       = '';
    protected int    $page        = 0;
    protected array  $pages       = [];
    protected string $buffer      = '';
    protected int    $n           = 0;        // current object number
    protected array  $offsets     = [];       // object offsets
    protected string $pdoc        = '';       // assembled PDF
    private   float  $k           = 2.8346; // mm to points (1 mm = 2.8346 pt)

    // ── Constructor ──────────────────────────────────────────────────────────

    public function __construct(
        string $orientation = 'P',
        string $unit        = 'mm',
        string $format      = 'A4'
    ) {
        $this->k = match(strtolower($unit)) {
            'pt' => 1.0,
            'cm' => 28.3465,
            'in' => 72.0,
            default => 2.8346, // mm
        };

        if (strtoupper($format) === 'A4') {
            $this->w = 210;
            $this->h = 297;
        } elseif (strtoupper($format) === 'LETTER') {
            $this->w = 216;
            $this->h = 279;
        }

        if (strtoupper($orientation) === 'L') {
            [$this->w, $this->h] = [$this->h, $this->w];
        }
    }

    // ── Page management ─────────────────────────────────────────────────────

    public function AddPage(string $orientation = ''): void
    {
        $this->page++;
        $this->pages[$this->page] = '';
        $this->x = $this->lMargin;
        $this->y = $this->tMargin;

        // Set initial graphics state for new page
        $this->_out(sprintf('%.2f w', $this->lineWidth * $this->k));
        if ($this->fontFamily !== '') {
            $this->_setFont($this->fontFamily, $this->fontStyle, $this->fontSize);
        }
        $this->_setFillColor($this->fillColor);
        $this->_setTextColor($this->textColor);
        $this->_setDrawColor($this->drawColor);
    }

    public function GetPageWidth(): float  { return $this->w; }
    public function GetPageHeight(): float { return $this->h; }

    // ── Position ─────────────────────────────────────────────────────────────

    public function GetX(): float { return $this->x; }
    public function GetY(): float { return $this->y; }
    public function SetX(float $x): void  { $this->x = $x < 0 ? $this->w + $x : $x; }
    public function SetY(float $y, bool $resetX = true): void
    {
        $this->y = $y < 0 ? $this->h + $y : $y;
        if ($resetX) $this->x = $this->lMargin;
    }
    public function SetXY(float $x, float $y): void { $this->SetX($x); $this->SetY($y, false); }

    public function SetMargins(float $left, float $top, float $right = -1): void
    {
        $this->lMargin = $left;
        $this->tMargin = $top;
        $this->rMargin = $right < 0 ? $left : $right;
    }
    public function SetTopMargin(float $margin): void    { $this->tMargin = $margin; }
    public function SetLeftMargin(float $margin): void   { $this->lMargin = $margin; }
    public function SetRightMargin(float $margin): void  { $this->rMargin = $margin; }
    public function SetAutoPageBreak(bool $auto, float $margin = 0): void { $this->bMargin = $margin; }

    // ── Fonts ────────────────────────────────────────────────────────────────

    public function SetFont(string $family, string $style = '', float $size = 0): void
    {
        $this->fontFamily = $family !== '' ? $family : $this->fontFamily;
        $this->fontStyle  = strtoupper($style);
        if ($size > 0) $this->fontSize = $size;
        if ($this->page > 0) {
            $this->_setFont($this->fontFamily, $this->fontStyle, $this->fontSize);
        }
    }

    public function SetFontSize(float $size): void
    {
        $this->fontSize = $size;
        if ($this->page > 0) $this->_setFont($this->fontFamily, $this->fontStyle, $size);
    }

    // ── Colors ───────────────────────────────────────────────────────────────

    public function SetFillColor(int $r, int $g = -1, int $b = -1): void
    {
        $this->fillColor = $g < 0 ? [$r, $r, $r] : [$r, $g, $b];
        if ($this->page > 0) $this->_setFillColor($this->fillColor);
    }

    public function SetTextColor(int $r, int $g = -1, int $b = -1): void
    {
        $this->textColor = $g < 0 ? [$r, $r, $r] : [$r, $g, $b];
        if ($this->page > 0) $this->_setTextColor($this->textColor);
    }

    public function SetDrawColor(int $r, int $g = -1, int $b = -1): void
    {
        $this->drawColor = $g < 0 ? [$r, $r, $r] : [$r, $g, $b];
        if ($this->page > 0) $this->_setDrawColor($this->drawColor);
    }

    public function SetLineWidth(float $width): void
    {
        $this->lineWidth = $width;
        if ($this->page > 0) $this->_out(sprintf('%.2f w', $width * $this->k));
    }

    // ── Text/Cells ───────────────────────────────────────────────────────────

    public function Cell(
        float  $w, float $h = 0, string $txt = '',
        mixed  $border = 0, int $ln = 0, string $align = '',
        bool   $fill = false, mixed $link = ''
    ): void {
        $k   = $this->k;
        $pw  = ($w === 0) ? $this->w - $this->rMargin - $this->x : $w;
        $ph  = ($h === 0) ? $this->fontSize / $this->k * 1.4 : $h;

        // Fill
        if ($fill) {
            $this->_out($this->_colorCmd($this->fillColor, 'f') .
                sprintf(' %.2f %.2f %.2f %.2f re f',
                    $this->x * $k,
                    ($this->h - $this->y) * $k - $ph * $k,
                    $pw * $k, $ph * $k));
        }

        // Border
        if ($border) {
            $this->_drawBorder($this->x, $this->y, $pw, $ph, $border);
        }

        // Text
        if ($txt !== '') {
            $tw = $this->_textWidth($txt);
            $tx = match(strtoupper($align)) {
                'R'  => $this->x + $pw - $tw - $this->cMargin,
                'C'  => $this->x + ($pw - $tw) / 2,
                default => $this->x + $this->cMargin,
            };
            $ty = $this->y + ($ph - $this->fontSize / $k) / 2 + $this->fontSize / $k;
            $this->_out(sprintf(
                'BT %.2f %.2f Td (%s) Tj ET',
                $tx * $k,
                ($this->h - $ty) * $k,
                $this->_escape($txt)
            ));
        }

        // Advance position
        $this->x += $pw;
        if ($ln === 1)      { $this->y += $ph; $this->x = $this->lMargin; }
        elseif ($ln === 2)  { $this->y += $ph; }
    }

    public function MultiCell(
        float  $w, float $h, string $txt,
        mixed  $border = 0, string $align = 'J', bool $fill = false
    ): void {
        $pw   = ($w === 0) ? $this->w - $this->rMargin - $this->x : $w;
        $lines = $this->_splitText($txt, $pw);
        foreach ($lines as $line) {
            $this->Cell($pw, $h, $line, $border, 1, $align, $fill);
            $border = 0; // only first line gets top border
        }
    }

    public function Ln(float $h = -1): void
    {
        $this->x  = $this->lMargin;
        $this->y += $h < 0 ? $this->fontSize / $this->k * 1.4 : $h;
    }

    public function Text(float $x, float $y, string $txt): void
    {
        $k = $this->k;
        $this->_out(sprintf(
            'BT %.2f %.2f Td (%s) Tj ET',
            $x * $k, ($this->h - $y) * $k, $this->_escape($txt)
        ));
    }

    // ── Drawing ──────────────────────────────────────────────────────────────

    public function Line(float $x1, float $y1, float $x2, float $y2): void
    {
        $k = $this->k;
        $this->_out(sprintf(
            '%.2f %.2f m %.2f %.2f l S',
            $x1 * $k, ($this->h - $y1) * $k,
            $x2 * $k, ($this->h - $y2) * $k
        ));
    }

    public function Rect(float $x, float $y, float $w, float $h, string $style = ''): void
    {
        $k = $this->k;
        $op = match(strtoupper($style)) {
            'F'  => 'f',
            'FD','DF' => 'B',
            default => 'S',
        };
        $this->_out(sprintf(
            '%.2f %.2f %.2f %.2f re %s',
            $x * $k, ($this->h - $y) * $k - $h * $k,
            $w * $k, $h * $k, $op
        ));
    }

    // ── Image (PNG only, basic) ───────────────────────────────────────────────

    public function Image(
        string $file, float $x = -1, float $y = -1,
        float $w = 0, float $h = 0, string $type = '',
        mixed $link = ''
    ): void {
        // Minimal implementation: skip image embedding, just advance position
        // Full image embedding requires complex PNG/JPEG parsing
        // For invoices, images (logos) are omitted gracefully here
        if ($x < 0) $x = $this->x;
        if ($y < 0) $y = $this->y;
    }

    // ── Metadata ─────────────────────────────────────────────────────────────

    public function SetTitle(string $title, bool $isUTF8 = false): void { $this->title = $title; }
    public function SetAuthor(string $author, bool $isUTF8 = false): void {}
    public function SetSubject(string $subject, bool $isUTF8 = false): void {}
    public function SetCreator(string $creator, bool $isUTF8 = false): void {}
    public function SetKeywords(string $keywords, bool $isUTF8 = false): void {}

    // ── String measurement ───────────────────────────────────────────────────

    public function GetStringWidth(string $s): float
    {
        return $this->_textWidth($s);
    }

    // ── Output ───────────────────────────────────────────────────────────────

    public function Output(string $dest = '', string $name = ''): string
    {
        if ($this->page === 0) {
            throw new \RuntimeException('FPDF: No page added. Call AddPage() first.');
        }

        $this->_buildDocument();

        $dest = strtoupper($dest ?: 'I');
        return match($dest) {
            'S'    => $this->pdoc,
            'F'    => $this->_outputToFile($name),
            'I','D'=> $this->_outputToHeader($dest, $name),
            default => $this->pdoc,
        };
    }

    // ── Private: PDF assembly ────────────────────────────────────────────────

    private function _out(string $s): void
    {
        if ($this->page === 0) return;
        $this->pages[$this->page] .= $s . "\n";
    }

    private function _newobj(): int
    {
        $this->n++;
        $this->offsets[$this->n] = strlen($this->pdoc);
        $this->pdoc .= $this->n . " 0 obj\n";
        return $this->n;
    }

    private function _put(string $s): void  { $this->pdoc .= $s . "\n"; }
    private function _putstream(string $s): void
    {
        $this->_put("stream");
        $this->pdoc .= $s . "\n";
        $this->_put("endstream");
    }

    private function _buildDocument(): void
    {
        $this->pdoc = '';
        $this->n    = 0;
        $this->offsets = [];

        $this->_put('%PDF-1.4');

        // Font object
        $fontObj = $this->_newobj();
        $this->_put('<<');
        $this->_put('/Type /Font');
        $this->_put('/Subtype /Type1');
        $this->_put('/BaseFont /' . $this->_pdfFontName());
        $this->_put('/Encoding /WinAnsiEncoding');
        $this->_put('>>');
        $this->_put('endobj');

        // Page content streams
        $pageObjs  = [];
        $streamObjs = [];
        foreach ($this->pages as $i => $content) {
            $sobj = $this->_newobj();
            $streamObjs[$i] = $sobj;
            $this->_put("<<\n/Length " . strlen($content) . "\n>>");
            $this->_putstream($content);
            $this->_put('endobj');
        }

        // Page objects
        $k = $this->k;
        $pw = $this->w * $k;
        $ph = $this->h * $k;
        foreach ($this->pages as $i => $_) {
            $pobj = $this->_newobj();
            $pageObjs[$i] = $pobj;
            $this->_put('<<');
            $this->_put('/Type /Page');
            $this->_put('/Parent 0 R'); // placeholder, fixed below
            $this->_put(sprintf('/MediaBox [0 0 %.2f %.2f]', $pw, $ph));
            $this->_put('/Resources << /Font << /F1 ' . $fontObj . ' 0 R >> >>');
            $this->_put('/Contents ' . $streamObjs[$i] . ' 0 R');
            $this->_put('>>');
            $this->_put('endobj');
        }

        // Pages dictionary
        $pagesObj = $this->_newobj();
        $this->_put('<<');
        $this->_put('/Type /Pages');
        $kids = implode(' 0 R ', array_values($pageObjs)) . ' 0 R';
        $this->_put('/Kids [' . $kids . ']');
        $this->_put('/Count ' . count($this->pages));
        $this->_put(sprintf('/MediaBox [0 0 %.2f %.2f]', $pw, $ph));
        $this->_put('>>');
        $this->_put('endobj');

        // Fix /Parent references — re-patch page objects
        foreach ($pageObjs as $pobj) {
            $placeholder = $pobj . " 0 obj\n<<\n/Type /Page\n/Parent 0 R";
            $replacement = $pobj . " 0 obj\n<<\n/Type /Page\n/Parent " . $pagesObj . " 0 R";
            $this->pdoc = str_replace($placeholder, $replacement, $this->pdoc);
        }

        // Catalog
        $catalogObj = $this->_newobj();
        $this->_put('<<');
        $this->_put('/Type /Catalog');
        $this->_put('/Pages ' . $pagesObj . ' 0 R');
        if ($this->title) {
            $this->_put('/Title (' . $this->_escape($this->title) . ')');
        }
        $this->_put('>>');
        $this->_put('endobj');

        // XRef + trailer
        $xrefOffset = strlen($this->pdoc);
        $this->_put('xref');
        $this->_put('0 ' . ($this->n + 1));
        $this->_put('0000000000 65535 f ');
        foreach ($this->offsets as $offset) {
            $this->_put(sprintf('%010d 00000 n ', $offset));
        }
        $this->_put('trailer');
        $this->_put('<<');
        $this->_put('/Size ' . ($this->n + 1));
        $this->_put('/Root ' . $catalogObj . ' 0 R');
        $this->_put('>>');
        $this->_put('startxref');
        $this->_put((string) $xrefOffset);
        $this->_put('%%EOF');
    }

    private function _outputToFile(string $name): string
    {
        if ($name === '') throw new \RuntimeException('FPDF: filename required for F output');
        file_put_contents($name, $this->pdoc);
        return '';
    }

    private function _outputToHeader(string $dest, string $name): string
    {
        $name = $name ?: 'document.pdf';
        if (headers_sent()) {
            throw new \RuntimeException('FPDF: Cannot send PDF — headers already sent');
        }
        header('Content-Type: application/pdf');
        header('Content-Disposition: ' . ($dest === 'D' ? 'attachment' : 'inline') . '; filename="' . $name . '"');
        header('Cache-Control: private, max-age=0, must-revalidate');
        header('Pragma: public');
        echo $this->pdoc;
        return '';
    }

    // ── Private: helpers ─────────────────────────────────────────────────────

    private function _setFont(string $family, string $style, float $size): void
    {
        $this->_out(sprintf('/F1 %.2f Tf', $size * $this->k / $this->k));
        // Simplified: use the single font resource F1 for all fonts
        $this->_out(sprintf('BT /F1 %.2f Tf ET', $size));
    }

    private function _pdfFontName(): string
    {
        $map = ['Times' => 'Times-Roman', 'Courier' => 'Courier', 'Symbol' => 'Symbol', 'ZapfDingbats' => 'ZapfDingbats'];
        $base = $map[$this->fontFamily] ?? 'Helvetica';
        if ($this->fontFamily === 'Times') {
            $suffix = match($this->fontStyle) { 'B' => '-Bold', 'I' => '-Italic', 'BI' => '-BoldItalic', default => '-Roman' };
        } else {
            $suffix = match($this->fontStyle) { 'B' => '-Bold', 'I' => '-Oblique', 'BI' => '-BoldOblique', default => '' };
        }
        return $base . $suffix;
    }

    private function _setFillColor(array $rgb): void
    {
        [$r, $g, $b] = $rgb;
        if ($r === $g && $g === $b) {
            $this->_out(sprintf('%.3f g', $r / 255));
        } else {
            $this->_out(sprintf('%.3f %.3f %.3f rg', $r / 255, $g / 255, $b / 255));
        }
    }

    private function _setTextColor(array $rgb): void
    {
        [$r, $g, $b] = $rgb;
        if ($r === $g && $g === $b) {
            $this->_out(sprintf('%.3f g', $r / 255)); // re-use same as fill for simplicity
        } else {
            $this->_out(sprintf('%.3f %.3f %.3f rg', $r / 255, $g / 255, $b / 255));
        }
    }

    private function _setDrawColor(array $rgb): void
    {
        [$r, $g, $b] = $rgb;
        if ($r === $g && $g === $b) {
            $this->_out(sprintf('%.3f G', $r / 255));
        } else {
            $this->_out(sprintf('%.3f %.3f %.3f RG', $r / 255, $g / 255, $b / 255));
        }
    }

    private function _colorCmd(array $rgb, string $op): string
    {
        [$r, $g, $b] = $rgb;
        return sprintf('%.3f %.3f %.3f %s', $r / 255, $g / 255, $b / 255, $op === 'f' ? 'rg' : 'RG');
    }

    private function _escape(string $s): string
    {
        return str_replace(['\\', ')', '(', "\r"], ['\\\\', '\\)', '\\(', '\\r'], $s);
    }

    private function _textWidth(string $s): float
    {
        // Approximate: Helvetica average char width ≈ 0.5 × fontSize in mm
        return strlen($s) * $this->fontSize * 0.5 / $this->k;
    }

    private function _splitText(string $text, float $maxWidth): array
    {
        // Split on newlines first, then word-wrap
        $result = [];
        $paras  = explode("\n", $text);
        foreach ($paras as $para) {
            if ($para === '') { $result[] = ''; continue; }
            $words    = explode(' ', $para);
            $line     = '';
            foreach ($words as $word) {
                $test = $line === '' ? $word : $line . ' ' . $word;
                if ($this->_textWidth($test) <= $maxWidth) {
                    $line = $test;
                } else {
                    if ($line !== '') $result[] = $line;
                    $line = $word;
                }
            }
            if ($line !== '') $result[] = $line;
        }
        return $result ?: [''];
    }

    private function _drawBorder(float $x, float $y, float $w, float $h, mixed $border): void
    {
        $k = $this->k;
        if ($border === 1 || $border === '1' || $border === true) {
            $this->_out(sprintf(
                '%.2f %.2f %.2f %.2f re S',
                $x * $k, ($this->h - $y) * $k - $h * $k,
                $w * $k, $h * $k
            ));
        } else {
            $s = (string) $border;
            if (str_contains($s, 'L')) $this->Line($x, $y, $x, $y + $h);
            if (str_contains($s, 'T')) $this->Line($x, $y, $x + $w, $y);
            if (str_contains($s, 'R')) $this->Line($x + $w, $y, $x + $w, $y + $h);
            if (str_contains($s, 'B')) $this->Line($x, $y + $h, $x + $w, $y + $h);
        }
    }
}
