<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Entity\Application;
use App\Entity\Company;
use App\Entity\DocumentTemplate;
use App\Entity\JobOffer;
use App\Entity\Profile;
use App\Entity\SavedView;
use App\Entity\User;
use App\Enum\ContractType;
use App\Enum\PipelineStatus;
use App\Enum\TemplateKind;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Uid\UuidV7;
use Zenstruck\Foundry\Test\ResetDatabase;

/**
 * Proves the mapping actually works against MariaDB, and that deleting an account really
 * removes everything it owns — the GDPR deletion feature relies on the database cascade,
 * not on application code walking the graph.
 */
final class SchemaPersistenceTest extends KernelTestCase
{
    use ResetDatabase;

    private EntityManagerInterface $entityManager;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->entityManager = self::getContainer()->get(EntityManagerInterface::class);
    }

    public function testIdentifiersAreTimeOrderedUuidV7(): void
    {
        $user = new User('uuid@example.test');
        $this->entityManager->persist($user);
        $this->entityManager->flush();

        $id = $user->getId();
        self::assertInstanceOf(Uuid::class, $id);
        self::assertInstanceOf(UuidV7::class, $id, 'public identifiers must stay sortable by creation time');
    }

    public function testTheWholeGraphPersistsAndReloads(): void
    {
        $user = $this->persistFullGraph('graph@example.test');
        $this->entityManager->clear();

        $reloaded = $this->entityManager->getRepository(User::class)->findOneBy(['email' => 'graph@example.test']);
        self::assertInstanceOf(User::class, $reloaded);
        self::assertNotNull($reloaded->getProfile());
        self::assertSame(20, $reloaded->getProfile()->getTopN());
        self::assertNotSame('', $reloaded->getProfile()->getFingerprint());

        $applications = $this->entityManager->getRepository(Application::class)->findBy(['owner' => $reloaded]);
        self::assertCount(1, $applications);
        self::assertSame(PipelineStatus::Applied, $applications[0]->getPipelineStatus());
        self::assertNotNull($applications[0]->getFollowUpAt());

        self::assertNotSame($user->getId(), null);
    }

    public function testDeletingAnAccountCascadesToEverythingItOwns(): void
    {
        $user = $this->persistFullGraph('cascade@example.test');
        $userId = $user->getId();
        self::assertNotNull($userId);

        $this->entityManager->remove($user);
        $this->entityManager->flush();
        $this->entityManager->clear();

        self::assertCount(0, $this->entityManager->getRepository(Application::class)->findAll());
        self::assertCount(0, $this->entityManager->getRepository(SavedView::class)->findAll());
        self::assertCount(0, $this->entityManager->getRepository(DocumentTemplate::class)->findAll());
        self::assertCount(0, $this->entityManager->getRepository(Profile::class)->findAll());

        self::assertCount(
            1,
            $this->entityManager->getRepository(Company::class)->findAll(),
            'catalogue companies are shared data and survive an account deletion',
        );
    }

    public function testTheFullTextIndexAnswersAKeywordQuery(): void
    {
        $this->persistFullGraph('fulltext@example.test');
        $this->entityManager->clear();

        $matches = $this->entityManager
            ->createQuery('SELECT o FROM App\Entity\JobOffer o WHERE MATCH_AGAINST(o.title, o.description, :terms) > 0')
            ->setParameter('terms', 'alternance développeur')
            ->getResult();

        self::assertCount(1, $matches, 'the FULLTEXT index must be queryable through the MATCH_AGAINST DQL function');
    }

    private function persistFullGraph(string $email): User
    {
        $user = new User($email);
        $profile = new Profile($user);
        $profile->setKeywords(['symfony', 'php']);

        $company = new Company('Acme Industrie');
        $company->setWebsite('https://www.acme-industrie.test');

        $offer = new JobOffer($company, 'fixtures', 'offer-'.$email, 'Stage développeur PHP', 'https://acme-industrie.test/jobs/1');
        $offer->setDescription('Stage ou alternance développeur back-end Symfony à Bordeaux.');
        $offer->setContractType(ContractType::Internship);
        $offer->setPublishedAt(new \DateTimeImmutable('-3 days'));

        $application = new Application($user, $company, $offer);
        $application->changeStatus(PipelineStatus::Applied, 7);

        $view = new SavedView($user, 'Alternance Bordeaux', ['keyword' => 'symfony'], 'relevance');
        $template = new DocumentTemplate($user, TemplateKind::Letter, 'Lettre type');
        $template->setContent('Bonjour {company},');

        foreach ([$user, $profile, $company, $offer, $application, $view, $template] as $entity) {
            $this->entityManager->persist($entity);
        }

        $this->entityManager->flush();

        return $user;
    }
}
