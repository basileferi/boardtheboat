<?php

declare(strict_types=1);

namespace App\Dto;

/**
 * What a refresh asks the sources for. Each source reads the part it understands: the job
 * boards use the keywords, the company registry uses the départements.
 */
final readonly class IngestionQuery
{
    /** Tech internship searches used when no profile narrows them down. */
    public const array DEFAULT_KEYWORDS = ['software', 'developer', 'data', 'devops', 'web'];

    /** Main French tech hubs: Paris, Lyon, Bordeaux, Toulouse, Nantes, Lille, Montpellier. */
    public const array DEFAULT_DEPARTEMENTS = ['75', '69', '33', '31', '44', '59', '34'];

    /**
     * @param list<string> $keywords
     * @param list<string> $departements INSEE département codes ("33", "2A")
     */
    public function __construct(
        public array $keywords = self::DEFAULT_KEYWORDS,
        public array $departements = self::DEFAULT_DEPARTEMENTS,
        public int $limitPerSource = 150,
    ) {
    }
}
