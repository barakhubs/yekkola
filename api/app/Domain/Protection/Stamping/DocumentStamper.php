<?php

declare(strict_types=1);

namespace App\Domain\Protection\Stamping;

/**
 * Writes a student's identifying mark onto every page of a PDF (PRD-07 FR-12). An interface so the PDF
 * engine can be swapped (e.g. a commercial parser for compressed PDFs) without touching domain code.
 */
interface DocumentStamper
{
    /**
     * Checks a PDF can be stamped (run when a professor uploads it, so students never get an unstamped copy).
     *
     * @return int Page count.
     *
     * @throws DocumentNotStampable
     */
    public function inspect(string $sourcePath): int;

    /**
     * @return string The stamped PDF's bytes.
     *
     * @throws DocumentNotStampable
     */
    public function stamp(string $sourcePath, StampText $text): string;
}
