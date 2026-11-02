<?php

declare(strict_types=1);

namespace App\Service\Ingestion\Source;

use App\Dto\IngestionQuery;
use App\Dto\OfferData;
use App\Enum\ContractType;
use App\Enum\WorkMode;
use App\Service\Ingestion\CountryResolver;
use App\Service\Ingestion\JobSourceInterface;
use App\Service\Ingestion\Payload;
use App\Service\Ingestion\PlainText;
use App\Service\Ingestion\TechFilter;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * The Muse public jobs API: free, no key needed below 500 requests an hour, filterable by
 * experience level, which is what makes it the richest keyless source of real internships.
 *
 * Terms: https://www.themuse.com/developers/api/v2 — attribution by linking to the offer page.
 */
final readonly class TheMuseSource implements JobSourceInterface
{
    public const string NAME = 'themuse';

    private const string ENDPOINT = 'https://www.themuse.com/api/public/jobs';

    private const array CATEGORIES = ['Software Engineering', 'Data and Analytics', 'Computer and IT', 'Design and UX'];

    private const array FRENCH_LOCATIONS = ['Paris, France', 'Lyon, France', 'Bordeaux, France', 'Toulouse, France', 'Nantes, France', 'Lille, France', 'Marseille, France', 'Montpellier, France'];

    private const int PAGE_SIZE = 20;

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
        $seen = [];
        // Most internships in these categories are not dev roles: read ahead to fill the limit.
        $pages = max(1, intdiv($query->limitPerSource, self::PAGE_SIZE)) * 2;

        // French offices first, so a short limit still favours what the France view shows.
        foreach ([self::FRENCH_LOCATIONS, []] as $locations) {
            for ($page = 0; $page < $pages; ++$page) {
                $rows = $this->page($locations, $page);
                foreach ($rows as $row) {
                    $offer = $this->map($row);
                    if (null !== $offer && !isset($seen[$offer->externalId])) {
                        $seen[$offer->externalId] = true;
                        yield $offer;
                    }
                }

                if (\count($rows) < self::PAGE_SIZE || \count($seen) >= $query->limitPerSource) {
                    break;
                }
            }
        }
    }

    /**
     * @param list<string> $locations
     *
     * @return list<array<mixed>>
     */
    private function page(array $locations, int $page): array
    {
        // The API wants repeated keys (category=A&category=B), not PHP's category[0]=A.
        $pairs = ['level=Internship', 'page='.$page];
        foreach (self::CATEGORIES as $category) {
            $pairs[] = 'category='.rawurlencode($category);
        }
        foreach ($locations as $location) {
            $pairs[] = 'location='.rawurlencode($location);
        }

        $payload = $this->httpClient->request('GET', self::ENDPOINT.'?'.implode('&', $pairs))->toArray();

        return Payload::rows($payload, 'results');
    }

    /**
     * @param array<mixed> $row
     */
    private function map(array $row): ?OfferData
    {
        $id = Payload::string($row, 'id');
        $title = Payload::string($row, 'name');
        $url = Payload::string($row, 'refs', 'landing_page');
        $company = Payload::string($row, 'company', 'name');
        if (null === $id || null === $title || null === $url || null === $company) {
            return null;
        }

        // The Muse categories are broad ("Data and Analytics" holds strategy roles), so only
        // the title decides.
        if (!TechFilter::isTech($title)) {
            return null;
        }

        $places = array_values(array_filter(array_map(
            static fn (array $location): ?string => Payload::string($location, 'name'),
            Payload::rows($row, 'locations'),
        )));
        $cities = array_values(array_filter($places, fn (string $place): bool => !$this->countries->isRemote($place)));
        $remote = \count($cities) !== \count($places);

        return new OfferData(
            source: self::NAME,
            externalId: $id,
            title: $title,
            url: $url,
            companyName: $company,
            description: PlainText::fromHtml(Payload::string($row, 'contents')),
            location: [] === $places ? null : mb_substr(implode(' · ', $places), 0, 180),
            country: [] === $cities ? null : $this->countries->resolve($cities[0]),
            workMode: match (true) {
                $remote && [] === $cities => WorkMode::Remote,
                $remote => WorkMode::Hybrid,
                [] === $cities => WorkMode::Unknown,
                default => WorkMode::Onsite,
            },
            contractType: TechFilter::contractType($title) ?? ContractType::Internship,
            publishedAt: Payload::date($row, 'publication_date'),
        );
    }
}
