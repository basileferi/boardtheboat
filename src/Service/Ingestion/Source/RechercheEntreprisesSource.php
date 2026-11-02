<?php

declare(strict_types=1);

namespace App\Service\Ingestion\Source;

use App\Dto\CompanyData;
use App\Dto\IngestionQuery;
use App\Enum\CompanySize;
use App\Service\Ingestion\CompanySourceInterface;
use App\Service\Ingestion\Payload;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * "API Recherche d'entreprises" (recherche-entreprises.api.gouv.fr): the State's free, keyless
 * company directory built on SIRENE. It supplies real tech employers per département for
 * spontaneous applications, with their NAF code and headcount band — exactly what the
 * RelevanceScorer ranks on.
 *
 * Only SMEs with employees are requested: a spontaneous application is likelier to be read
 * there than at a large group. Company officers ("dirigeants") are in the payload and are
 * never read. Rate limit is 7 requests per second, hence the pause between calls.
 */
final readonly class RechercheEntreprisesSource implements CompanySourceInterface
{
    public const string NAME = 'recherche_entreprises';

    private const string ENDPOINT = 'https://recherche-entreprises.api.gouv.fr/search';

    /** NAF codes of software, IT services, hosting and game publishing. */
    public const array SECTORS = [
        '62.01Z' => 'Programmation informatique',
        '62.02A' => 'Conseil en systèmes et logiciels',
        '62.03Z' => 'Infogérance',
        '62.09Z' => 'Services informatiques',
        '58.29C' => 'Édition de logiciels',
        '58.21Z' => 'Jeu vidéo',
        '63.11Z' => 'Hébergement et données',
        '63.12Z' => 'Portails Internet',
    ];

    /** INSEE headcount bands from 10 to 249 employees. */
    private const string HEADCOUNTS = '11,12,21,22,31';

    private const int PER_PAGE = 25;

    private const int PAGES_PER_DEPARTEMENT = 2;

    public function __construct(
        private HttpClientInterface $httpClient,
        private int $pauseMicroseconds = 200_000,
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
        $count = 0;

        foreach ($query->departements as $departement) {
            for ($page = 1; $page <= self::PAGES_PER_DEPARTEMENT; ++$page) {
                $payload = $this->httpClient->request('GET', self::ENDPOINT, [
                    'query' => [
                        'activite_principale' => implode(',', array_keys(self::SECTORS)),
                        'departement' => $departement,
                        'etat_administratif' => 'A',
                        'categorie_entreprise' => 'PME',
                        'tranche_effectif_salarie' => self::HEADCOUNTS,
                        'per_page' => self::PER_PAGE,
                        'page' => $page,
                    ],
                ])->toArray();

                $rows = Payload::rows($payload, 'results');
                foreach ($rows as $row) {
                    $company = $this->map($row, $departement);
                    if (null !== $company) {
                        yield $company;
                        if (++$count >= $query->limitPerSource * \count($query->departements)) {
                            return;
                        }
                    }
                }

                usleep($this->pauseMicroseconds);

                if (\count($rows) < self::PER_PAGE) {
                    break;
                }
            }
        }
    }

    /**
     * @param array<mixed> $row
     */
    private function map(array $row, string $departement): ?CompanyData
    {
        $siren = Payload::string($row, 'siren');
        $name = Payload::string($row, 'nom_complet');
        if (null === $siren || null === $name || 1 !== preg_match('/^\d{9}$/', $siren)) {
            return null;
        }

        $naf = Payload::string($row, 'activite_principale');

        return new CompanyData(
            source: self::NAME,
            externalId: $siren,
            name: self::displayName($name),
            siren: $siren,
            city: $this->localCity($row, $departement),
            country: 'FR',
            nafCode: $naf,
            sector: self::SECTORS[$naf] ?? null,
            size: self::size(Payload::string($row, 'tranche_effectif_salarie')),
        );
    }

    /**
     * The office in the requested département, not the head office two regions away: that is
     * where an intern would actually go.
     *
     * @param array<mixed> $row
     */
    private function localCity(array $row, string $departement): ?string
    {
        foreach (Payload::rows($row, 'matching_etablissements') as $office) {
            $postcode = Payload::string($office, 'code_postal') ?? '';
            if (str_starts_with($postcode, $departement)) {
                return self::displayName(Payload::string($office, 'libelle_commune') ?? '') ?: null;
            }
        }

        $city = Payload::string($row, 'siege', 'libelle_commune');

        return null === $city ? null : self::displayName($city);
    }

    /**
     * "CRISTAL'ID (CRISTAL'ID)" → "Cristal'id"; registry names are upper-case and repeat
     * their acronym in brackets.
     */
    public static function displayName(string $name): string
    {
        $name = trim(preg_replace('/\s*\(.*$/u', '', $name) ?? $name);

        return mb_convert_case(mb_strtolower($name), \MB_CASE_TITLE);
    }

    public static function size(?string $band): CompanySize
    {
        return match ($band) {
            '01', '02', '03' => CompanySize::Micro,
            '11', '12' => CompanySize::Small,
            '21', '22', '31' => CompanySize::Medium,
            '32', '41', '42', '51' => CompanySize::Large,
            '52', '53' => CompanySize::VeryLarge,
            default => CompanySize::Unknown,
        };
    }
}
