<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Entity\Company;
use App\Entity\CompanyBrief;
use App\Entity\RelevanceScore;
use App\Enum\BriefStatus;
use App\Enum\CareerPageStatus;
use App\Message\EnrichCompanyMessage;
use App\MessageHandler\EnrichCompanyHandler;
use App\Service\Pipeline\PipelineRunner;
use App\Service\Security\AccountCreator;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;
use Zenstruck\Foundry\Test\ResetDatabase;

/**
 * le pipeline complet sur une petite base, sans réseau (les réponses http sont simulées).
 */
final class PipelineRunnerTest extends KernelTestCase
{
    use ResetDatabase;

    public function testARunScoresProfilesAndQueuesBriefsForTheTopCompanies(): void
    {
        self::bootKernel();
        $this->seedUserAndCompanies();

        $run = $this->runner()->run('test', discover: false);

        self::assertSame(1, $run->getCounts()['profiles'] ?? 0);
        self::assertSame(3, $run->getCounts()['scored'] ?? 0, 'the three companies get a score');
        self::assertSame(3, $run->getCounts()['briefs_queued'] ?? 0);
        self::assertSame([], $run->getErrors());

        /** @var InMemoryTransport $async */
        $async = self::getContainer()->get('messenger.transport.async');
        $messages = array_map(static fn ($envelope) => $envelope->getMessage(), $async->getSent());
        self::assertContainsOnlyInstancesOf(EnrichCompanyMessage::class, $messages);
        self::assertCount(3, $messages);
    }

    public function testASecondRunRescoresNothingThatDidNotChange(): void
    {
        self::bootKernel();
        $this->seedUserAndCompanies();

        $this->runner()->run('test', discover: false);
        $second = $this->runner()->run('test', discover: false);

        self::assertSame(0, $second->getCounts()['scored'] ?? 0, 'fingerprints unchanged: nothing to recompute');
        self::assertSame(3, $this->entityManager()->getRepository(RelevanceScore::class)->count([]));
    }

    public function testTheEnrichHandlerPreparesAPendingBriefAndChecksTheCareerPage(): void
    {
        self::bootKernel();
        $company = new Company('Acme');
        $company->setWebsite('https://acme.example');
        $this->entityManager()->persist($company);
        $this->entityManager()->flush();

        /** @var EnrichCompanyHandler $handler */
        $handler = self::getContainer()->get(EnrichCompanyHandler::class);
        $handler(new EnrichCompanyMessage((string) $company->getId()));
        // rejoué : ne doit pas créer une deuxième fiche
        $handler(new EnrichCompanyMessage((string) $company->getId()));

        $briefs = $this->entityManager()->getRepository(CompanyBrief::class)->findAll();
        self::assertCount(1, $briefs);
        self::assertSame(BriefStatus::Pending, $briefs[0]->getStatus());
        self::assertSame(['https://acme.example'], $briefs[0]->getSourceUrls());

        // la réponse simulée est du json vide, pas une page carrières
        self::assertSame(CareerPageStatus::Invalid, $briefs[0]->getCompany()->getCareerPageStatus());
    }

    private function seedUserAndCompanies(): void
    {
        /** @var AccountCreator $creator */
        $creator = self::getContainer()->get(AccountCreator::class);
        $creator->register('pipeline@example.test', 'correct-horse-battery-staple', 'fr', [], true);

        foreach (['Alpha', 'Bravo', 'Charlie'] as $name) {
            $company = new Company($name);
            $company->setCity('Bordeaux');
            $this->entityManager()->persist($company);
        }
        $this->entityManager()->flush();
    }

    private function runner(): PipelineRunner
    {
        /** @var PipelineRunner $runner */
        $runner = self::getContainer()->get(PipelineRunner::class);

        return $runner;
    }

    private function entityManager(): EntityManagerInterface
    {
        /** @var EntityManagerInterface $entityManager */
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);

        return $entityManager;
    }
}
