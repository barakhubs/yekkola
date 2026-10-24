<?php

declare(strict_types=1);

namespace App\Domain\Protection\Stamping;

use setasign\Fpdi\Fpdi;

/** FPDI with the drawing the stamp needs: a footer line and rotated, light grey text. */
final class StampingFpdi extends Fpdi
{
    public function stamp(StampText $text, float $width, float $height): void
    {
        $this->SetAutoPageBreak(false);

        // Diagonal mark across the page centre.
        $this->SetFont('Helvetica', 'B', 22);
        $this->SetTextColor(200, 200, 200);
        $diagonal = self::latin1($text->diagonal);
        $angle = rad2deg(atan2($height, $width));
        $this->rotatedText($width / 2 - $this->GetStringWidth($diagonal) / 2 * cos(deg2rad($angle)), $height / 2 + $this->GetStringWidth($diagonal) / 2 * sin(deg2rad($angle)), $diagonal, $angle);

        // Footer line on a white band so it stays readable over page content.
        $this->SetFont('Helvetica', '', 7);
        $this->SetTextColor(90, 90, 90);
        $this->SetFillColor(255, 255, 255);
        $this->SetXY(6, $height - 7);
        $this->Cell($width - 12, 4, self::latin1($text->footer), 0, 0, 'C', true);
    }

    private function rotatedText(float $x, float $y, string $text, float $angle): void
    {
        $radians = deg2rad($angle);
        $cos = cos($radians);
        $sin = sin($radians);
        $cx = $x * $this->k;
        $cy = ($this->h - $y) * $this->k;

        $this->_out(sprintf('q %.5F %.5F %.5F %.5F %.2F %.2F cm 1 0 0 1 %.2F %.2F cm', $cos, $sin, -$sin, $cos, $cx, $cy, -$cx, -$cy));
        $this->Text($x, $y, $text);
        $this->_out('Q');
    }

    /** FPDF core fonts are Windows-1252: accents and the "•" mask survive, anything else becomes "?". */
    private static function latin1(string $text): string
    {
        $converted = @iconv('UTF-8', 'Windows-1252//TRANSLIT', $text);

        return $converted === false ? (string) preg_replace('/[^ -~]/', '?', $text) : $converted;
    }
}
