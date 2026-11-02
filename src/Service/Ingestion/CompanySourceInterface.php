<?php

declare(strict_types=1);

namespace App\Service\Ingestion;

use App\Dto\CompanyData;
use App\Dto\IngestionQuery;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * A free registry of employers, used for spontaneous applications: companies worth writing to
 * even when they have not published an offer.
 */
#[AutoconfigureTag('app.company_source')]
interface CompanySourceInterface
{
    public function getName(): string;

    public function isEnabled(): bool;

    /**
     * @return iterable<CompanyData>
     */
    public function fetch(IngestionQuery $query): iterable;
}
