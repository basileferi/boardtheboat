<?php

declare(strict_types=1);

namespace App\Dto;

/**
 * The result of scoring one company against one profile: the total, what it is made of, and
 * the translation keys that explain it to the user.
 */
final readonly class ScoreBreakdown
{
    /**
     * @param array<string, int> $criteria
     * @param list<string>       $reasons
     */
    public function __construct(
        public int $score,
        public array $criteria,
        public array $reasons,
    ) {
    }

    /**
     * Rough banding used by the interface to colour a row without re-deriving thresholds.
     */
    public function band(): string
    {
        return match (true) {
            $this->score >= 70 => 'high',
            $this->score >= 40 => 'medium',
            default => 'low',
        };
    }
}
