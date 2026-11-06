<?php

declare(strict_types=1);

namespace MaikSchneider\AnalyticsMatomo\Dashboard;

final readonly class BreakdownRow
{
    /**
     * @param float $share share of all visits, 0..1
     * @param float|null $shareChange change of the share against the previous period; null without visits before
     */
    public function __construct(
        public string $label,
        public int $visits,
        public float $share,
        public ?float $shareChange,
        public bool $isOther = false,
    ) {
    }
}
