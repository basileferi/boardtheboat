<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\Company;
use App\Entity\JobOffer;
use App\Entity\User;
use App\Enum\ContractType;
use App\Enum\WorkMode;
use App\Service\Security\AccountCreator;
use App\Tests\Support\ClearsRateLimiters;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Zenstruck\Foundry\Test\ResetDatabase;

/**
 * The offer list: who may see it, that the filters reach the database, and that the split view
 * degrades to ordinary pages when Turbo is not there to swap frames.
 */
final class OfferSearchTest extends WebTestCase
{
    use ClearsRateLimiters;
    use ResetDatabase;

    public function testTheListRequiresAnAccount(): void
    {
        $client = self::createClient();
        $client->request('GET', '/fr/offers');

        self::assertResponseRedirects();
        self::assertStringContainsString('/login', (string) $client->getResponse()->headers->get('Location'));
    }

    public function testTheListShowsOpenFrenchOffers(): void
    {
        $client = $this->signedIn();
        $this->seedOffers();

        $crawler = $client->request('GET', '/fr/offers');

        self::assertResponseIsSuccessful();
        self::assertCount(3, $crawler->filter('.offer'), 'closed and non-French offers stay out by default');

        // With no offer picked, the detail pane opens on the first row of the list.
        self::assertSame(
            $crawler->filter('.offer__title')->first()->text(),
            $crawler->filter('turbo-frame#job-detail h2')->text(),
        );
    }

    public function testTheKeywordFilterUsesTheFullTextIndex(): void
    {
        $client = $this->signedIn();
        $this->seedOffers();

        $crawler = $client->request('GET', '/fr/offers?keyword=kubernetes');

        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter('.offer'));
        self::assertSelectorTextContains('.offer__title', 'Stage plateforme');
    }

    public function testTheContractFilterNarrowsTheList(): void
    {
        $client = $this->signedIn();
        $this->seedOffers();

        $crawler = $client->request('GET', '/fr/offers?contractTypes[]=apprenticeship');

        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter('.offer'));
    }

    public function testTheRegionSwitchSeparatesFranceFromTheRest(): void
    {
        $client = $this->signedIn();
        $this->seedOffers();

        $crawler = $client->request('GET', '/fr/offers?region=international');

        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter('.offer'));
        self::assertSelectorTextContains('.offer__title', 'Backend engineer');
    }

    public function testClosedOffersAreHiddenUnlessAskedFor(): void
    {
        $client = $this->signedIn();
        $this->seedOffers();

        $crawler = $client->request('GET', '/fr/offers?status=closed');

        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter('.offer'));
        self::assertSelectorTextContains('.offer__title', 'Stage terminé');
    }

    public function testOpeningAnOfferRendersBothFramesSoItWorksWithoutTurbo(): void
    {
        $client = $this->signedIn();
        $offers = $this->seedOffers();

        $crawler = $client->request('GET', '/fr/offers/'.$offers[1]->getId());

        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter('turbo-frame#job-list'), 'the list must still be rendered');
        self::assertSelectorTextContains('turbo-frame#job-detail h2', $offers[1]->getTitle());
    }

    public function testThePageIsKeysetPaginated(): void
    {
        $client = $this->signedIn();
        $this->seedManyOffers(30);

        $crawler = $client->request('GET', '/fr/offers');
        self::assertCount(25, $crawler->filter('.offer'));

        $nextLink = $crawler->filter('a[href*="cursor="]');
        self::assertCount(1, $nextLink, 'a next-page link is expected');

        $crawler = $client->request('GET', (string) $nextLink->attr('href'));
        self::assertResponseIsSuccessful();
        self::assertCount(5, $crawler->filter('.offer'), 'the second page holds the remaining offers');
        self::assertCount(0, $crawler->filter('a[href*="cursor="]'), 'the last page offers no further cursor');
    }

    /**
     * @return list<JobOffer>
     */
    private function seedOffers(): array
    {
        $entityManager = $this->entityManager();

        $french = new Company('Aquitaine Digital');
        $french->setCity('Bordeaux');
        $french->setCountry('FR');
        $french->setWebsite('https://aquitaine-digital.example');

        $abroad = new Company('Remote Nomads');
        $abroad->setCity('Lisbonne');
        $abroad->setCountry('PT');
        $abroad->setSourceReference('fixtures', 'abroad-1');

        $offers = [
            $this->offer($french, 'php', 'Stage développeur PHP', 'Symfony, Doctrine et tests automatisés.', ContractType::Internship),
            $this->offer($french, 'k8s', 'Stage plateforme', 'Docker, kubernetes et intégration continue.', ContractType::Internship),
            $this->offer($french, 'alt', 'Alternance data', 'SQL, Python et tableaux de bord.', ContractType::Apprenticeship),
            $this->offer($abroad, 'int', 'Backend engineer', 'Go, PostgreSQL, distributed systems.', ContractType::Internship),
            $this->offer($french, 'old', 'Stage terminé', 'Cette offre est fermée.', ContractType::Internship),
        ];

        $offers[3]->setCountry('PT');
        $offers[4]->setStatus(\App\Enum\OfferStatus::Closed);

        foreach ([$french, $abroad, ...$offers] as $entity) {
            $entityManager->persist($entity);
        }
        $entityManager->flush();

        return [$offers[0], $offers[1], $offers[2]];
    }

    private function seedManyOffers(int $count): void
    {
        $entityManager = $this->entityManager();
        $company = new Company('Volume Corp');
        $company->setCity('Nantes');
        $entityManager->persist($company);

        for ($index = 0; $index < $count; ++$index) {
            $offer = $this->offer($company, 'bulk-'.$index, 'Stage numéro '.$index, 'Description générique.', ContractType::Internship);
            $offer->setPublishedAt(new \DateTimeImmutable(\sprintf('-%d hours', $index)));
            $entityManager->persist($offer);
        }

        $entityManager->flush();
    }

    private function offer(Company $company, string $externalId, string $title, string $description, ContractType $type): JobOffer
    {
        $offer = new JobOffer($company, 'fixtures', $externalId, $title, 'https://example.test/jobs/'.$externalId);
        $offer->setDescription($description);
        $offer->setContractType($type);
        $offer->setWorkMode(WorkMode::Hybrid);
        $offer->setLocation((string) $company->getCity());
        $offer->setCountry($company->getCountry());
        $offer->setPublishedAt(new \DateTimeImmutable('-1 day'));

        return $offer;
    }

    private function signedIn(): KernelBrowser
    {
        $client = self::createClient();

        /** @var AccountCreator $creator */
        $creator = self::getContainer()->get(AccountCreator::class);
        $user = $creator->register('searcher@example.test', 'correct-horse-battery-staple', 'fr', [], true);
        self::assertInstanceOf(User::class, $user);
        $client->loginUser($user);

        return $client;
    }

    private function entityManager(): EntityManagerInterface
    {
        /** @var EntityManagerInterface $entityManager */
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);

        return $entityManager;
    }
}
