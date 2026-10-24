<?php

declare(strict_types=1);

namespace App\Domain\Protection\Stamping;

/** What is printed on each page: a footer line and a faint diagonal mark. Never the full phone number. */
final readonly class StampText
{
    public function __construct(
        /** e.g. "Grâce Mbuyi · +243 8•• ••• 678 · 24/10/2026 · Yekkola" */
        public string $footer,
        /** e.g. "Grâce Mbuyi · +243 8•• ••• 678" */
        public string $diagonal,
    ) {}
}
