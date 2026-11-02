<?php

declare(strict_types=1);

namespace App\Service\Ingestion\Source;

use App\Dto\IngestionQuery;
use App\Dto\OfferData;
use App\Enum\WorkMode;
use App\Service\Ingestion\CountryResolver;
use App\Service\Ingestion\JobSourceInterface;
use App\Service\Ingestion\Payload;
use App\Service\Ingestion\PlainText;
use App\Service\Ingestion\TechFilter;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Arbeitnow job-board API: free, keyless, European (mostly Germany), refreshed hourly. It has no
 * level filter, so internships, working-student and apprenticeship roles are picked out of the
 * full feed by title and job type.
 *
 * Terms (from the payload itself): free public API, do not abuse, link back to arbeitnow.com.
 */
final readonly class ArbeitnowSource implements JobSourceInterface
{
    public const string NAME = 'arbeitnow';

    private const string ENDPOINT = 'https://www.arbeitnow.com/api/job-board-api';

    /** A page holds a few hundred offers; three pages cover roughly the last day. */
    private const int MAX_PAGES = 3;

    public function __construct(
        private HttpClientInterface $httpClient,
        private CountryResolver $countries,
    ) {
    }

    public function getName(): string
    {
        return self::NAME;
    }

    public function isEnabled(): bool
    {
        return true;
    }

    public function fetch(IngestionQuery $query): iterable
    {
        $kept = 0;

        for ($page = 1; $page <= self::MAX_PAGES; ++$page) {
            $payload = $this->httpClient->request('GET', self::ENDPOINT, ['query' => ['page' => $page]])->toArray();
            $rows = Payload::rows($payload, 'data');

            foreach ($rows as $row) {
                $offer = $this->map($row);
                if (null !== $offer) {
                    yield $offer;
                    if (++$kept >= $query->limitPerSource) {
                        return;
                    }
                }
            }

            if ([] === $rows || null === Payload::string($payload, 'links', 'next')) {
                return;
            }
        }
    }

    /**
     * @param array<mixed> $row
     */
    private function map(array $row): ?OfferData
    {
        $slug = Payload::string($row, 'slug');
        $title = Payload::string($row, 'title');
        $url = Payload::string($row, 'url');
        $company = Payload::string($row, 'company_name');
        if (null === $slug || null === $title || null === $url || null === $company) {
            return null;
        }

        $types = Payload::strings($row, 'job_types');
        $tags = Payload::strings($row, 'tags');
        $contract = TechFilter::contractType($title, $types);
        if (null === $contract || !TechFilter::isTech($title, $tags)) {
            return null;
        }

        $location = Payload::string($row, 'location');

        return new OfferData(
            source: self::NAME,
            externalId: mb_strlen($slug) <= 120 ? $slug : hash('xxh128', $slug),
            title: $title,
            url: $url,
            companyName: $company,
            description: PlainText::fromHtml(Payload::string($row, 'description')),
            location: null === $location ? null : mb_substr($location, 0, 180),
            country: $this->countries->resolve($location) ?? 'DE',
            workMode: Payload::bool($row, 'remote') ? WorkMode::Remote : WorkMode::Onsite,
            contractType: $contract,
            publishedAt: Payload::date($row, 'created_at'),
        );
    }
}
