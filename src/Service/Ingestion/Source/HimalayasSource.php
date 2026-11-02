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
 * Himalayas remote-jobs search API: free, keyless, with an employment-type filter that isolates
 * internships. Every offer is remote by construction; the country is the hiring restriction.
 *
 * Terms: https://himalayas.app/api — link back to the offer, do not rebrand the listing.
 */
final readonly class HimalayasSource implements JobSourceInterface
{
    public const string NAME = 'himalayas';

    private const string ENDPOINT = 'https://himalayas.app/jobs/api/search';

    private const int MAX_PAGES_PER_KEYWORD = 2;

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

        foreach ($query->keywords as $keyword) {
            for ($page = 1; $page <= self::MAX_PAGES_PER_KEYWORD; ++$page) {
                $payload = $this->httpClient->request('GET', self::ENDPOINT, [
                    'query' => ['q' => $keyword, 'employment_type' => 'Intern', 'page' => $page],
                ])->toArray();

                $rows = Payload::rows($payload, 'jobs');
                foreach ($rows as $row) {
                    $offer = $this->map($row);
                    if (null !== $offer && !isset($seen[$offer->externalId])) {
                        $seen[$offer->externalId] = true;
                        yield $offer;
                    }
                }

                if ([] === $rows || \count($seen) >= $query->limitPerSource) {
                    break;
                }
            }

            if (\count($seen) >= $query->limitPerSource) {
                return;
            }
        }
    }

    /**
     * @param array<mixed> $row
     */
    private function map(array $row): ?OfferData
    {
        $guid = Payload::string($row, 'guid');
        $title = Payload::string($row, 'title');
        $company = Payload::string($row, 'companyName');
        if (null === $guid || null === $title || null === $company) {
            return null;
        }

        $tags = [...Payload::strings($row, 'categories'), ...Payload::strings($row, 'parentCategories')];
        if (!TechFilter::isTech($title, $tags)) {
            return null;
        }

        $countries = Payload::strings($row, 'locationRestrictions');
        $salary = $this->salary($row);

        return new OfferData(
            source: self::NAME,
            // Offer URLs outgrow the column; their hash is just as stable.
            externalId: hash('xxh128', $guid),
            title: $title,
            url: Payload::string($row, 'applicationLink') ?? $guid,
            companyName: $company,
            description: PlainText::fromHtml(Payload::string($row, 'description') ?? Payload::string($row, 'excerpt')),
            location: [] === $countries ? 'Remote' : mb_substr('Remote · '.implode(', ', \array_slice($countries, 0, 4)), 0, 180),
            country: 1 === \count($countries) ? $this->countries->resolve($countries[0]) : null,
            workMode: WorkMode::Remote,
            contractType: TechFilter::contractType($title) ?? ContractType::Internship,
            publishedAt: Payload::date($row, 'pubDate'),
            expiresAt: Payload::date($row, 'expiryDate'),
            salaryText: $salary,
        );
    }

    /**
     * @param array<mixed> $row
     */
    private function salary(array $row): ?string
    {
        $min = Payload::string($row, 'minSalary');
        $currency = Payload::string($row, 'currency');
        if (null === $min || null === $currency) {
            return null;
        }

        $max = Payload::string($row, 'maxSalary');
        $period = Payload::string($row, 'salaryPeriod') ?? 'annual';

        return \sprintf('%s%s %s / %s', $min, null === $max ? '' : '–'.$max, $currency, $period);
    }
}
