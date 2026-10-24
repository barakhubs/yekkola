<?php

declare(strict_types=1);

namespace App\Domain\Protection\Stamping;

use setasign\Fpdi\Fpdi;
use Throwable;

/**
 * Stamps PDFs with FPDI (MIT). The free parser can't read PDFs that use compressed cross-reference streams
 * (common in PDF 1.5+ exports); those are refused at upload by inspect() rather than served unstamped.
 */
final class FpdiDocumentStamper implements DocumentStamper
{
    public function inspect(string $sourcePath): int
    {
        try {
            return (new Fpdi)->setSourceFile($sourcePath);
        } catch (Throwable $e) {
            throw new DocumentNotStampable($e->getMessage(), previous: $e);
        }
    }

    public function stamp(string $sourcePath, StampText $text): string
    {
        try {
            $pdf = new StampingFpdi;
            $pages = $pdf->setSourceFile($sourcePath);

            for ($page = 1; $page <= $pages; $page++) {
                $template = $pdf->importPage($page);
                $size = $pdf->getTemplateSize($template);
                $width = (float) $size['width'];
                $height = (float) $size['height'];

                $pdf->AddPage($width > $height ? 'L' : 'P', [$width, $height]);
                $pdf->useTemplate($template);
                $pdf->stamp($text, $width, $height);
            }

            return $pdf->Output('S');
        } catch (Throwable $e) {
            throw new DocumentNotStampable($e->getMessage(), previous: $e);
        }
    }
}
