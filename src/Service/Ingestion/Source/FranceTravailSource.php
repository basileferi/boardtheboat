<?php

declare(strict_types=1);

namespace App\Service\Ingestion\Source;

use App\Dto\IngestionQuery;
use App\Dto\OfferData;
use App\Enum\ContractType;
use App\Enum\WorkMode;
use App\Service\Ingestion\JobSourceInterface;
use App\Service\Ingestion\Payload;
use App\Service\Ingestion\PlainText;
use App\Service\Ingestion\TechFilter;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * France Travail "Offres d'emploi v2": the official French job-offer API. Free, but it needs an
 * application registered on francetravail.io (OAuth2 client credentials), so the source turns
 * itself off until FRANCE_TRAVAIL_CLIENT_ID and FRANCE_TRAVAIL_CLIENT_SECRET are set.
 *
 * It is the only source of French stages and alternances in volume, which is why it exists.
 */
final readonly class FranceTravailSource implements JobSourceInterface
{
    public const string NAME = 'francetravail';

    private const string TOKEN_URL = 'https://entreprise.francetravail.fr/connexion/oauth2/access_token?realm=%2Fpartenaire';

    private const string SEARCH_URL = 'https://api.francetravail.io/partenaire/offresdemploi/v2/offres/search';

    private const string DETAIL_URL = 'https://candidat.francetravail.fr/offres/recherche/detail/';

    /** E2 = contrat d'apprentissage, FS = contrat de professionnalisation. */
    private const array SEARCHES = [
        ['motsCles' => 'stage,développeur'],
        ['motsCles' => 'stage,informatique'],
        ['motsCles' => 'stage,data'],
        ['motsCles' => 'développeur', 'natureContrat' => 'E2'],
        ['motsCles' => 'informatique', 'natureContrat' => 'E2'],
        ['motsCles' => 'développeur', 'natureContrat' => 'FS'],
    ];

    public function __construct(
        private HttpClientInterface $httpClient,
        private CacheInterface $cache,
        #[Autowire(env: 'FRANCE_TRAVAIL_CLIENT_ID')]
        private string $clientId,
        #[Autowire(env: 'FRANCE_TRAVAIL_CLIENT_SECRET')]
        private string $clientSecret,
    ) {
    }

    public function getName(): string
    {
        return self::NAME;
    }

    public function isEnabled(): bool
    {
        return '' !== $this->clientId && '' !== $this->clientSecret;
    }

    public function fetch(IngestionQuery $query): iterable
    {
        if (!$this->isEnabled()) {
            return;
        }

        $token = $this->token();
        $seen = [];

        foreach (self::SEARCHES as $search) {
            $response = $this->httpClient->request('GET', self::SEARCH_URL, [
                'auth_bearer' => $token,
                'query' => $search + ['range' => '0-149'],
            ]);

            // 204 means "no result" for this API, not an error.
            if (204 === $response->getStatusCode()) {
                continue;
            }

            foreach (Payload::rows($response->toArray(), 'resultats') as $row) {
                $offer = $this->map($row);
                if (null !== $offer && !isset($seen[$offer->externalId])) {
                    $seen[$offer->externalId] = true;
                    yield $offer;
                }
            }

            if (\count($seen) >= $query->limitPerSource) {
                return;
            }
        }
    }

    private function token(): string
    {
        return $this->cache->get('france_travail.token', function (ItemInterface $item): string {
            $payload = $this->httpClient->request('POST', self::TOKEN_URL, [
                'body' => [
                    'grant_type' => 'client_credentials',
                    'client_id' => $this->clientId,
                    'client_secret' => $this->clientSecret,
                    'scope' => 'api_offresdemploiv2 o2dsoffre',
                ],
            ])->toArray();

            $token = Payload::string($payload, 'access_token');
            if (null === $token) {
                throw new \RuntimeException('France Travail did not return an access token.');
            }

            $item->expiresAfter(max(60, (int) (Payload::string($payload, 'expires_in') ?? 1500) - 60));

            return $token;
        });
    }

    /**
     * @param array<mixed> $row
     */
    private function map(array $row): ?OfferData
    {
        $id = Payload::string($row, 'id');
        $title = Payload::string($row, 'intitule');
        // Anonymous offers cannot be followed up with a spontaneous application: skip them.
        $company = Payload::string($row, 'entreprise', 'nom');
        if (null === $id || null === $title || null === $company) {
            return null;
        }

        $nature = Payload::string($row, 'natureContrat') ?? '';
        $contract = match (true) {
            Payload::bool($row, 'alternance'), str_contains(mb_strtolower($nature), 'apprentissage'), str_contains(mb_strtolower($nature), 'professionnalisation') => ContractType::Apprenticeship,
            default => TechFilter::contractType($title),
        };
        if (null === $contract || !TechFilter::isTech($title, array_filter([Payload::string($row, 'romeLibelle')]))) {
            return null;
        }

        // "33 - BORDEAUX" reads better as "Bordeaux (33)".
        $place = Payload::string($row, 'lieuTravail', 'libelle');
        if (null !== $place && 1 === preg_match('/^(\w{2,3})\s*-\s*(.+)$/u', $place, $match)) {
            $place = mb_convert_case(mb_strtolower($match[2]), \MB_CASE_TITLE).' ('.$match[1].')';
        }

        return new OfferData(
            source: self::NAME,
            externalId: $id,
            title: $title,
            url: Payload::string($row, 'origineOffre', 'urlOrigine') ?? self::DETAIL_URL.rawurlencode($id),
            companyName: $company,
            description: PlainText::fromHtml(Payload::string($row, 'description')),
            companyWebsite: Payload::string($row, 'entreprise', 'url'),
            location: $place,
            country: 'FR',
            workMode: WorkMode::Onsite,
            contractType: $contract,
            publishedAt: Payload::date($row, 'dateCreation'),
            salaryText: Payload::string($row, 'salaire', 'libelle'),
        );
    }
}
