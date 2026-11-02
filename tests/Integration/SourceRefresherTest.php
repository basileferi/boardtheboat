<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Dto\CompanyData;
use App\Dto\IngestionQuery;
use App\Dto\OfferData;
use App\Entity\Company;
use App\Entity\JobOffer;
use App\Entity\User;
use App\Enum\CompanySize;
use App\Enum\OfferStatus;
use App\Enum\PipelineRunStatus;
use App\Repository\CompanyRepository;
use App\Repository\JobOfferRepository;
use App\Service\Ingestion\CompanySourceInterface;
use App\Service\Ingestion\Ingestor;
use App\Service\Ingestion\JobSourceInterface;
use App\Service\Ingestion\SourceRefresher;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpClient\Exception\TransportException;
use Zenstruck\Foundry\Test\ResetDatabase;

/**
 * The refresh against the real database: idempotent upserts, company deduplication across
 * sources, isolation of private companies, and a failing provider that does not stop the run.
 */
final class SourceRefresherTest extends KernelTestCase
{
    use ResetDatabase;

    private EntityManagerInterface $entityManager;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->entityManager = self::getContainer()->get(EntityManagerInterface::class);
    }

    public function testReplayingARunUpdatesInsteadOfDuplicating(): void
    {
        $refresher = $this->refresher([$this->jobSource([$this->offer('1'), $this->offer('2'), $this->offer('1')])]);

        $first = $refresher->refresh(new IngestionQuery(), 'test');
        $second = $refresher->refresh(new IngestionQuery(), 'test');

        self::assertSame(2, $first->getCounts()['offers_created'] ?? 0, 'a feed repeating an offer in one run is read once');
        self::assertSame(2, $second->getCounts()['offers_updated'] ?? 0);
        self::assertSame(2, $this->entityManager->getRepository(JobOffer::class)->count([]));
        self::assertSame(1, $this->entityManager->getRepository(Company::class)->count([]), 'both offers share one employer');
        self::assertSame(PipelineRunStatus::Success, $second->getStatus());
    }

    public function testTheRegistryEnrichesACompanyAJobBoardCreated(): void
    {
        $refresher = $this->refresher(
            [$this->jobSource([$this->offer('1', 'ACME SAS')])],
            [$this->companySource([new CompanyData('registry', '123456789', 'Acme', siren: '123456789', city: 'Bordeaux', nafCode: '62.01Z', sector: 'Programmation informatique', size: CompanySize::Small)])],
        );

        $refresher->refresh(new IngestionQuery(), 'test');

        $companies = $this->entityManager->getRepository(Company::class)->findAll();
        self::assertCount(1, $companies, '"ACME SAS" and "Acme" are the same employer');
        self::assertSame('123456789', $companies[0]->getSiren());
        self::assertSame(CompanySize::Small, $companies[0]->getSize());
    }

    public function testAPrivateCompanyIsNeverMergedIntoByAnImport(): void
    {
        $owner = new User('owner@example.test');
        $owner->setPassword('x');
        $private = new Company('Acme', $owner);
        $this->entityManager->persist($owner);
        $this->entityManager->persist($private);
        $this->entityManager->flush();

        $this->refresher([$this->jobSource([$this->offer('1', 'Acme')])])->refresh(new IngestionQuery(), 'test');

        $offer = $this->entityManager->getRepository(JobOffer::class)->findOneBy([]);
        self::assertInstanceOf(JobOffer::class, $offer);
        self::assertNotSame($private->getId()?->toRfc4122(), $offer->getCompany()->getId()?->toRfc4122());
        self::assertTrue($offer->getCompany()->isGlobal());
    }

    public function testAFailingSourceIsRecordedAndTheOthersStillRun(): void
    {
        $broken = new class implements JobSourceInterface {
            public function getName(): string
            {
                return 'broken';
            }

            public function isEnabled(): bool
            {
                return true;
            }

            public function fetch(IngestionQuery $query): iterable
            {
                throw new TransportException('timeout');
            }
        };

        $run = $this->refresher([$broken, $this->jobSource([$this->offer('9')])])->refresh(new IngestionQuery(), 'test');

        self::assertSame(PipelineRunStatus::Partial, $run->getStatus());
        self::assertSame(['broken: timeout'], $run->getErrors());
        self::assertSame(1, $run->getCounts()['offers_created'] ?? 0);
    }

    public function testOldOffersASourceStoppedListingAreExpired(): void
    {
        $refresher = $this->refresher([$this->jobSource([$this->offer('old', published: '-90 days'), $this->offer('new')])]);
        $refresher->refresh(new IngestionQuery(), 'test');

        $this->refresher([$this->jobSource([$this->offer('new')])])->refresh(new IngestionQuery(), 'test');

        /** @var JobOfferRepository $offers */
        $offers = self::getContainer()->get(JobOfferRepository::class);
        $this->entityManager->clear();
        self::assertSame(OfferStatus::Expired, $offers->findOneBySource('fake', 'old')?->getStatus());
        self::assertSame(OfferStatus::Open, $offers->findOneBySource('fake', 'new')?->getStatus());
    }

    /**
     * @param list<JobSourceInterface>     $jobSources
     * @param list<CompanySourceInterface> $companySources
     */
    private function refresher(array $jobSources, array $companySources = []): SourceRefresher
    {
        $container = self::getContainer();

        return new SourceRefresher(
            $jobSources,
            $companySources,
            new Ingestor($this->entityManager, $container->get(CompanyRepository::class), $container->get(JobOfferRepository::class)),
            $container->get(JobOfferRepository::class),
            $this->entityManager,
            new NullLogger(),
        );
    }

    private function offer(string $id, string $company = 'Acme', string $published = '-1 day'): OfferData
    {
        return new OfferData('fake', $id, 'Stage développeur '.$id, 'https://jobs.example/'.$id, $company, publishedAt: new \DateTimeImmutable($published), country: 'FR');
    }

    /**
     * @param list<OfferData> $offers
     */
    private function jobSource(array $offers): JobSourceInterface
    {
        return new class($offers) implements JobSourceInterface {
            /**
             * @param list<OfferData> $offers
             */
            public function __construct(private readonly array $offers)
            {
            }

            public function getName(): string
            {
                return 'fake';
            }

            public function isEnabled(): bool
            {
                return true;
            }

            public function fetch(IngestionQuery $query): iterable
            {
                yield from $this->offers;
            }
        };
    }

    /**
     * @param list<CompanyData> $companies
     */
    private function companySource(array $companies): CompanySourceInterface
    {
        return new class($companies) implements CompanySourceInterface {
            /**
             * @param list<CompanyData> $companies
             */
            public function __construct(private readonly array $companies)
            {
            }

            public function getName(): string
            {
                return 'registry';
            }

            public function isEnabled(): bool
            {
                return true;
            }

            public function fetch(IngestionQuery $query): iterable
            {
                yield from $this->companies;
            }
        };
    }
}
