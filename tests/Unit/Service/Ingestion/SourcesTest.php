<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Ingestion;

use App\Dto\IngestionQuery;
use App\Enum\CompanySize;
use App\Enum\ContractType;
use App\Enum\WorkMode;
use App\Service\Ingestion\CountryResolver;
use App\Service\Ingestion\Source\ArbeitnowSource;
use App\Service\Ingestion\Source\FranceTravailSource;
use App\Service\Ingestion\Source\HimalayasSource;
use App\Service\Ingestion\Source\RechercheEntreprisesSource;
use App\Service\Ingestion\Source\TheMuseSource;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\JsonMockResponse;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * Each adapter against a recorded-shape payload: mapping, filtering and the requests it sends.
 * No test here touches the network (MockHttpClient refuses anything unplanned).
 */
final class SourcesTest extends TestCase
{
    public function testTheMuseKeepsTechInternshipsAndReadsTheirLocations(): void
    {
        $requests = [];
        $client = new MockHttpClient(static function (string $method, string $url) use (&$requests): JsonMockResponse {
            $requests[] = $url;

            return new JsonMockResponse(['results' => [
                [
                    'id' => 101, 'name' => 'Software Engineering Intern', 'contents' => '<p>Ship code.</p>',
                    'publication_date' => '2026-09-02T19:47:02Z', 'refs' => ['landing_page' => 'https://www.themuse.com/jobs/acme/swe-intern'],
                    'company' => ['name' => 'Acme'], 'locations' => [['name' => 'Flexible / Remote'], ['name' => 'Paris, France']],
                ],
                [
                    'id' => 102, 'name' => 'Brand Strategy Intern', 'contents' => '', 'refs' => ['landing_page' => 'https://www.themuse.com/jobs/acme/brand'],
                    'company' => ['name' => 'Acme'], 'locations' => [['name' => 'Austin, TX']],
                ],
            ]]);
        });

        $offers = iterator_to_array((new TheMuseSource($client, new CountryResolver()))->fetch(new IngestionQuery(limitPerSource: 20)), false);

        self::assertCount(1, $offers);
        $offer = $offers[0];
        self::assertSame('101', $offer->externalId);
        self::assertSame('FR', $offer->country);
        self::assertSame(WorkMode::Hybrid, $offer->workMode);
        self::assertSame(ContractType::Internship, $offer->contractType);
        self::assertSame('Ship code.', $offer->description);
        self::assertStringContainsString('level=Internship', $requests[0]);
        self::assertStringContainsString('category=Software%20Engineering', $requests[0]);
        self::assertStringContainsString('location=Paris%2C%20France', $requests[0], 'French offices are asked for first');
    }

    public function testHimalayasOffersAreRemoteAndHashedOnTheirUrl(): void
    {
        $client = new MockHttpClient([
            new JsonMockResponse(['jobs' => [[
                'guid' => 'https://himalayas.app/companies/kis/jobs/application-developer-intern',
                'title' => 'Application Developer Intern', 'companyName' => 'KIS Solutions', 'employmentType' => 'Intern',
                'locationRestrictions' => ['Germany'], 'categories' => ['Software-Development'], 'parentCategories' => ['Developer'],
                'description' => '<p>Hello</p>', 'pubDate' => 1790195431, 'expiryDate' => 1795379430,
                'minSalary' => 1000, 'maxSalary' => 1500, 'currency' => 'EUR', 'salaryPeriod' => 'month',
            ]]]),
            new JsonMockResponse(['jobs' => []]),
        ]);

        $offers = iterator_to_array((new HimalayasSource($client, new CountryResolver()))->fetch(new IngestionQuery(keywords: ['developer'])), false);

        self::assertCount(1, $offers);
        self::assertSame(WorkMode::Remote, $offers[0]->workMode);
        self::assertSame('DE', $offers[0]->country);
        self::assertSame(32, \strlen($offers[0]->externalId));
        self::assertSame('1000–1500 EUR / month', $offers[0]->salaryText);
    }

    public function testArbeitnowOnlyKeepsInternshipsAndApprenticeships(): void
    {
        $client = new MockHttpClient([new JsonMockResponse([
            'data' => [
                ['slug' => 'a', 'title' => 'Werkstudent Softwareentwicklung (m/w/d)', 'company_name' => 'Ramp', 'url' => 'https://www.arbeitnow.com/jobs/a', 'tags' => [], 'job_types' => ['Working student'], 'location' => 'Hamburg', 'remote' => false, 'created_at' => 1790000000, 'description' => ''],
                ['slug' => 'b', 'title' => 'Senior Backend Developer', 'company_name' => 'Ramp', 'url' => 'https://www.arbeitnow.com/jobs/b', 'tags' => [], 'job_types' => ['Full Time'], 'location' => 'Berlin', 'remote' => true, 'created_at' => 1790000000, 'description' => ''],
                ['slug' => 'c', 'title' => 'Ausbildung Fachinformatiker Anwendungsentwicklung', 'company_name' => 'Lrz', 'url' => 'https://www.arbeitnow.com/jobs/c', 'tags' => [], 'job_types' => [], 'location' => 'Wien', 'remote' => true, 'created_at' => 1790000000, 'description' => ''],
            ],
            'links' => ['next' => null],
        ])]);

        $offers = iterator_to_array((new ArbeitnowSource($client, new CountryResolver()))->fetch(new IngestionQuery()), false);

        self::assertCount(2, $offers);
        self::assertSame(ContractType::Internship, $offers[0]->contractType);
        self::assertSame('DE', $offers[0]->country);
        self::assertSame(ContractType::Apprenticeship, $offers[1]->contractType);
        self::assertSame('AT', $offers[1]->country);
        self::assertSame(WorkMode::Remote, $offers[1]->workMode);
    }

    public function testFranceTravailIsOffWithoutCredentials(): void
    {
        $source = new FranceTravailSource(new MockHttpClient([]), new ArrayAdapter(), '', '');

        self::assertFalse($source->isEnabled());
        self::assertSame([], iterator_to_array($source->fetch(new IngestionQuery()), false));
    }

    public function testFranceTravailAuthenticatesThenMapsAlternancesAndSkipsAnonymousOffers(): void
    {
        $bodies = [];
        $responses = [
            new JsonMockResponse(['access_token' => 'tok', 'expires_in' => 1499]),
            new JsonMockResponse(['resultats' => [
                ['id' => '200ABC', 'intitule' => 'Développeur web en alternance (H/F)', 'alternance' => true, 'entreprise' => ['nom' => 'ACME', 'url' => 'https://acme.example'], 'lieuTravail' => ['libelle' => '33 - BORDEAUX'], 'dateCreation' => '2026-09-20T10:00:00.000Z', 'description' => 'Missions'],
                ['id' => '200DEF', 'intitule' => 'Stage développeur', 'lieuTravail' => ['libelle' => '75 - PARIS']],
            ]], ['http_code' => 206]),
        ];
        // Every other search answers "no content", which this API uses for an empty result.
        $client = new MockHttpClient(static function (string $method, string $url, array $options) use (&$responses, &$bodies): MockResponse {
            $bodies[] = [$method, $url, $options['headers'] ?? []];

            return array_shift($responses) ?? new MockResponse('', ['http_code' => 204]);
        });

        $offers = iterator_to_array((new FranceTravailSource($client, new ArrayAdapter(), 'id', 'secret'))->fetch(new IngestionQuery()), false);

        self::assertCount(1, $offers);
        self::assertSame(ContractType::Apprenticeship, $offers[0]->contractType);
        self::assertSame('Bordeaux (33)', $offers[0]->location);
        self::assertSame('https://candidat.francetravail.fr/offres/recherche/detail/200ABC', $offers[0]->url);
        self::assertSame('POST', $bodies[0][0]);
        self::assertStringContainsString('realm=%2Fpartenaire', $bodies[0][1]);
        self::assertContains('Authorization: Bearer tok', $bodies[1][2]);
    }

    public function testRechercheEntreprisesMapsSmesToTheirLocalOffice(): void
    {
        $client = new MockHttpClient([new JsonMockResponse(['results' => [
            [
                'siren' => '123456789', 'nom_complet' => "CRISTAL'ID (CRISTAL'ID)", 'activite_principale' => '62.01Z', 'tranche_effectif_salarie' => '21',
                'siege' => ['libelle_commune' => "VILLENEUVE D'ASCQ"],
                'matching_etablissements' => [['code_postal' => '33370', 'libelle_commune' => 'ARTIGUES-PRES-BORDEAUX']],
                'dirigeants' => [['nom' => 'Must not be read']],
            ],
            ['siren' => 'not-a-siren', 'nom_complet' => 'BROKEN'],
        ]])]);

        $companies = iterator_to_array((new RechercheEntreprisesSource($client, 0))->fetch(new IngestionQuery(departements: ['33'])), false);

        self::assertCount(1, $companies);
        $company = $companies[0];
        self::assertSame("Cristal'id", $company->name);
        self::assertSame('Artigues-Pres-Bordeaux', $company->city);
        self::assertSame(CompanySize::Medium, $company->size);
        self::assertSame('Programmation informatique', $company->sector);
    }
}
