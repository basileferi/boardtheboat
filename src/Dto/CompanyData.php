<?php

declare(strict_types=1);

namespace App\Dto;

use App\Enum\CompanySize;

/**
 * One employer as a company source hands it over. Deliberately carries no named person: the
 * registries expose company officers, and we never store them (GDPR baseline).
 */
final readonly class CompanyData
{
    public function __construct(
        public string $source,
        public string $externalId,
        public string $name,
        public ?string $siren = null,
        public ?string $website = null,
        public ?string $city = null,
        public ?string $country = 'FR',
        public ?string $nafCode = null,
        public ?string $sector = null,
        public CompanySize $size = CompanySize::Unknown,
    ) {
    }
}
