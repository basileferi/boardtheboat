<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\Company;
use App\Entity\User;
use App\Enum\CompanyVisibility;
use App\Message\EnrichCompanyMessage;
use App\Service\Security\AccountCreator;
use App\Tests\Support\ClearsRateLimiters;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;
use Zenstruck\Foundry\Test\ResetDatabase;

/**
 * le crud entreprise : parcours normal, erreurs de validation (dont notre contrainte SafeUrl),
 * csrf, et surtout l'idor : un autre compte ne voit ni ne touche une entreprise privée.
 */
final class CompanyCrudTest extends WebTestCase
{
    use ClearsRateLimiters;
    use ResetDatabase;

    public function testAUserAddsAPrivateCompanyAndItsSiteIsCheckedLater(): void
    {
        $client = self::createClient();
        $owner = $this->account('owner@example.test');
        $client->loginUser($owner);

        $crawler = $client->request('GET', '/fr/companies/new');
        $client->submit($crawler->filter('form[name="company"]')->form([
            'company[name]' => 'Petite Boîte',
            'company[website]' => 'https://petiteboite.example',
            'company[city]' => 'Bordeaux',
        ]));

        self::assertResponseRedirects();
        $company = $this->entityManager()->getRepository(Company::class)->findOneBy(['name' => 'Petite Boîte']);
        self::assertInstanceOf(Company::class, $company);
        self::assertSame(CompanyVisibility::Private, $company->getVisibility());
        self::assertSame($owner->getId()?->toRfc4122(), $company->getOwner()?->getId()?->toRfc4122());

        /** @var InMemoryTransport $async */
        $async = self::getContainer()->get('messenger.transport.async');
        $messages = array_map(static fn ($envelope) => $envelope->getMessage(), $async->getSent());
        self::assertCount(1, array_filter($messages, static fn ($m) => $m instanceof EnrichCompanyMessage), 'the site is never fetched during the request');
    }

    public function testAnEmptyNameShowsAReadableError(): void
    {
        $client = self::createClient();
        $client->loginUser($this->account('empty@example.test'));

        $crawler = $client->request('GET', '/fr/companies/new');
        $client->submit($crawler->filter('form[name="company"]')->form(['company[name]' => '']));

        self::assertResponseStatusCodeSame(422);
        self::assertStringContainsString('il faut au moins un nom', (string) $client->getResponse()->getContent());
    }

    public function testOurSafeUrlConstraintRefusesAPrivateAddress(): void
    {
        $client = self::createClient();
        $client->loginUser($this->account('ssrf@example.test'));

        $crawler = $client->request('GET', '/fr/companies/new');
        $client->submit($crawler->filter('form[name="company"]')->form([
            'company[name]' => 'Piège',
            'company[website]' => 'http://169.254.169.254/latest/meta-data/',
        ]));

        self::assertResponseStatusCodeSame(422);
        self::assertStringContainsString('lien refusé', (string) $client->getResponse()->getContent());
        self::assertNull($this->entityManager()->getRepository(Company::class)->findOneBy(['name' => 'Piège']));
    }

    public function testTheOwnerEditsThenDeletesWithARealDeleteRequest(): void
    {
        $client = self::createClient();
        $owner = $this->account('editor@example.test');
        $company = $this->privateCompany($owner, 'Avant');
        $client->loginUser($owner);

        $crawler = $client->request('GET', '/fr/companies/'.$company->getId().'/edit');
        $client->submit($crawler->filter('form[name="company"]')->form(['company[name]' => 'Après']));
        self::assertResponseRedirects('/fr/companies/'.$company->getId());

        $crawler = $client->request('GET', '/fr/companies/'.$company->getId());
        $deleteForm = $crawler->filter('form[action$="/companies/'.$company->getId().'"]')->form();
        // le navigateur envoie un POST, le champ caché _method le transforme en DELETE côté symfony
        self::assertSame('DELETE', $deleteForm->getValues()['_method'] ?? null);
        $client->submit($deleteForm);

        self::assertResponseStatusCodeSame(303);
        $this->entityManager()->clear();
        self::assertNull($this->entityManager()->getRepository(Company::class)->find($company->getId()));
    }

    public function testDeletingWithAForgedTokenIsRefused(): void
    {
        $client = self::createClient();
        $owner = $this->account('csrf@example.test');
        $company = $this->privateCompany($owner, 'Protégée');
        $client->loginUser($owner);

        $client->request('DELETE', '/fr/companies/'.$company->getId(), ['_token' => 'forged']);

        self::assertResponseStatusCodeSame(403);
        self::assertNotNull($this->entityManager()->getRepository(Company::class)->find($company->getId()));
    }

    public function testAnotherUserCannotSeeEditOrDeleteAPrivateCompany(): void
    {
        $client = self::createClient();
        $company = $this->privateCompany($this->account('victim@example.test'), 'Secrète');
        $client->loginUser($this->account('intruder@example.test'));

        $client->request('GET', '/fr/companies/'.$company->getId());
        self::assertResponseStatusCodeSame(403);

        $client->request('GET', '/fr/companies/'.$company->getId().'/edit');
        self::assertResponseStatusCodeSame(403);

        $client->request('DELETE', '/fr/companies/'.$company->getId(), ['_token' => 'whatever']);
        self::assertResponseStatusCodeSame(403);

        $crawler = $client->request('GET', '/fr/companies');
        self::assertStringNotContainsString('Secrète', $crawler->filter('main')->text(), 'private rows never appear in somebody else\'s list');
    }

    public function testACatalogueCompanyIsReadableButNotEditableByAUser(): void
    {
        $client = self::createClient();
        $catalogue = new Company('Catalogue SA');
        $this->entityManager()->persist($catalogue);
        $this->entityManager()->flush();
        $client->loginUser($this->account('reader@example.test'));

        $client->request('GET', '/fr/companies/'.$catalogue->getId());
        self::assertResponseIsSuccessful();

        $client->request('GET', '/fr/companies/'.$catalogue->getId().'/edit');
        self::assertResponseStatusCodeSame(403);
    }

    private function privateCompany(User $owner, string $name): Company
    {
        $company = new Company($name, $owner);
        $this->entityManager()->persist($company);
        $this->entityManager()->flush();

        return $company;
    }

    private function account(string $email): User
    {
        /** @var AccountCreator $creator */
        $creator = self::getContainer()->get(AccountCreator::class);

        return $creator->register($email, 'correct-horse-battery-staple', 'fr', [], true);
    }

    private function entityManager(): EntityManagerInterface
    {
        /** @var EntityManagerInterface $entityManager */
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);

        return $entityManager;
    }
}
