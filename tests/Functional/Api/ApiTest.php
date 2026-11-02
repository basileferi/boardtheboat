<?php

declare(strict_types=1);

namespace App\Tests\Functional\Api;

use App\Entity\Company;
use App\Entity\JobOffer;
use App\Entity\User;
use App\Service\Security\AccountCreator;
use App\Tests\Support\ClearsRateLimiters;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Zenstruck\Foundry\Test\ResetDatabase;

/**
 * l'api json, comme on la testerait avec postman : connexion jwt, puis chaque route,
 * avec et sans erreur.
 */
final class ApiTest extends WebTestCase
{
    use ClearsRateLimiters;
    use ResetDatabase;

    private const string PASSWORD = 'correct-horse-battery-staple';

    public function testWithoutATokenTheApiAnswers401(): void
    {
        $client = self::createClient();
        $client->request('GET', '/api/companies');

        self::assertResponseStatusCodeSame(401);
    }

    public function testAWrongPasswordGetsNoToken(): void
    {
        $client = self::createClient();
        $this->account('api@example.test');

        $this->login($client, 'api@example.test', 'not-the-password');

        self::assertResponseStatusCodeSame(401);
    }

    public function testTheListNeverLeaksPrivateFields(): void
    {
        $client = self::createClient();
        $owner = $this->account('lister@example.test');
        $mine = new Company('Ma Boîte', $owner);
        $mine->setNotes('note très privée');
        $this->entityManager()->persist($mine);
        $this->entityManager()->flush();

        $token = $this->tokenFor($client, 'lister@example.test');
        $client->request('GET', '/api/companies', server: ['HTTP_AUTHORIZATION' => 'Bearer '.$token]);

        self::assertResponseIsSuccessful();
        $body = (string) $client->getResponse()->getContent();
        self::assertStringContainsString('Ma Bo', $body);
        self::assertStringNotContainsString('note très privée', $body, 'notes are not in the company:read group');
        self::assertStringNotContainsString('owner', $body);
    }

    public function testCreatingWithInvalidDataReturns422(): void
    {
        $client = self::createClient();
        $this->account('invalid@example.test');
        $token = $this->tokenFor($client, 'invalid@example.test');

        $this->json($client, 'POST', '/api/companies', $token, ['name' => '', 'website' => 'http://127.0.0.1/']);

        self::assertResponseStatusCodeSame(422);
    }

    public function testCreatingReturns201WithALocationHeader(): void
    {
        $client = self::createClient();
        $this->account('creator@example.test');
        $token = $this->tokenFor($client, 'creator@example.test');

        $this->json($client, 'POST', '/api/companies', $token, ['name' => 'Créée par api', 'city' => 'Lyon']);

        self::assertResponseStatusCodeSame(201);
        self::assertStringContainsString('/api/companies/', (string) $client->getResponse()->headers->get('Location'));
    }

    public function testUpdatingSomebodyElsesCompanyIsForbidden(): void
    {
        $client = self::createClient();
        $victimCompany = new Company('Pas à toi', $this->account('victim@example.test'));
        $this->entityManager()->persist($victimCompany);
        $this->entityManager()->flush();

        $this->account('intruder@example.test');
        $token = $this->tokenFor($client, 'intruder@example.test');
        $this->json($client, 'PUT', '/api/companies/'.$victimCompany->getId(), $token, ['name' => 'Volée']);

        self::assertResponseStatusCodeSame(403);
    }

    public function testDeletingYourOwnCompanyReturns204(): void
    {
        $client = self::createClient();
        $owner = $this->account('deleter@example.test');
        $company = new Company('À supprimer', $owner);
        $this->entityManager()->persist($company);
        $this->entityManager()->flush();
        $id = $company->getId();

        $token = $this->tokenFor($client, 'deleter@example.test');
        $client->request('DELETE', '/api/companies/'.$id, server: ['HTTP_AUTHORIZATION' => 'Bearer '.$token]);

        self::assertResponseStatusCodeSame(204);
        $this->entityManager()->clear();
        self::assertNull($this->entityManager()->getRepository(Company::class)->find($id));
    }

    public function testRecentOffersOnlyReturnsTheLastDays(): void
    {
        $client = self::createClient();
        $this->account('reader@example.test');

        $company = new Company('Offreur');
        $fresh = new JobOffer($company, 'fixtures', 'fresh', 'Offre fraîche', 'https://example.test/1');
        $fresh->setPublishedAt(new \DateTimeImmutable('-2 days'));
        $old = new JobOffer($company, 'fixtures', 'old', 'Vieille offre', 'https://example.test/2');
        $old->setPublishedAt(new \DateTimeImmutable('-40 days'));
        foreach ([$company, $fresh, $old] as $entity) {
            $this->entityManager()->persist($entity);
        }
        $this->entityManager()->flush();

        $token = $this->tokenFor($client, 'reader@example.test');
        $client->request('GET', '/api/offers?days=7', server: ['HTTP_AUTHORIZATION' => 'Bearer '.$token]);

        self::assertResponseIsSuccessful();
        $body = (string) $client->getResponse()->getContent();
        self::assertStringContainsString('Offre fra', $body);
        self::assertStringNotContainsString('Vieille offre', $body);
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function json(KernelBrowser $client, string $method, string $uri, string $token, array $payload): void
    {
        $client->request($method, $uri, server: [
            'HTTP_AUTHORIZATION' => 'Bearer '.$token,
            'CONTENT_TYPE' => 'application/json',
        ], content: json_encode($payload, \JSON_THROW_ON_ERROR));
    }

    private function login(KernelBrowser $client, string $email, string $password): void
    {
        $client->request('POST', '/api/login', server: ['CONTENT_TYPE' => 'application/json'], content: json_encode([
            'username' => $email,
            'password' => $password,
        ], \JSON_THROW_ON_ERROR));
    }

    private function tokenFor(KernelBrowser $client, string $email): string
    {
        $this->login($client, $email, self::PASSWORD);
        self::assertResponseIsSuccessful();

        /** @var array{token?: string} $data */
        $data = json_decode((string) $client->getResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR);
        self::assertArrayHasKey('token', $data);

        return $data['token'];
    }

    private function account(string $email): User
    {
        /** @var AccountCreator $creator */
        $creator = self::getContainer()->get(AccountCreator::class);

        return $creator->register($email, self::PASSWORD, 'fr', [], true);
    }

    private function entityManager(): EntityManagerInterface
    {
        /** @var EntityManagerInterface $entityManager */
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);

        return $entityManager;
    }
}
