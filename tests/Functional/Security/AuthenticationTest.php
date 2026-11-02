<?php

declare(strict_types=1);

namespace App\Tests\Functional\Security;

use App\Entity\User;
use App\Service\Security\AccountCreator;
use App\Tests\Support\ClearsRateLimiters;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Zenstruck\Foundry\Test\ResetDatabase;

/**
 * The authentication rules, exercised through the real firewall rather than through mocks:
 * an unverified or disabled account cannot get in, protected pages are not reachable
 * anonymously, and the login form itself is throttled.
 */
final class AuthenticationTest extends WebTestCase
{
    use ClearsRateLimiters;
    use ResetDatabase;

    private const string PASSWORD = 'correct-horse-battery-staple';

    public function testTheLoginPageIsPublic(): void
    {
        $client = self::createClient();
        $crawler = $client->request('GET', '/fr/login');

        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter('input[name="_csrf_token"]'));
    }

    public function testAVerifiedAccountReachesTheDashboard(): void
    {
        $client = self::createClient();
        $this->createAccount('ok@example.test', verified: true);

        $this->submitLogin($client, 'ok@example.test', self::PASSWORD);

        self::assertResponseRedirects('/fr/dashboard');
        $client->followRedirect();
        self::assertSelectorTextContains('h1', 'Tableau de bord');
    }

    public function testWrongCredentialsAreRefusedWithoutSayingWhy(): void
    {
        $client = self::createClient();
        $this->createAccount('wrong@example.test', verified: true);

        $this->submitLogin($client, 'wrong@example.test', 'not-the-password');
        $client->followRedirect();

        self::assertSelectorTextContains('[role="alert"]', 'incorrect');
    }

    public function testAnUnverifiedAccountCannotSignIn(): void
    {
        $client = self::createClient();
        $this->createAccount('unverified@example.test', verified: false);

        $this->submitLogin($client, 'unverified@example.test', self::PASSWORD);
        $client->followRedirect();

        self::assertSelectorTextContains('[role="alert"]', 'Confirmez');
    }

    public function testADisabledAccountCannotSignIn(): void
    {
        $client = self::createClient();
        $user = $this->createAccount('disabled@example.test', verified: true);
        $user->setActive(false);
        $this->entityManager()->flush();

        $this->submitLogin($client, 'disabled@example.test', self::PASSWORD);
        $client->followRedirect();

        self::assertSelectorTextContains('[role="alert"]', 'désactivé');
    }

    public function testTheDashboardRedirectsAnonymousVisitorsToTheLoginPage(): void
    {
        $client = self::createClient();
        $client->request('GET', '/fr/dashboard');

        self::assertResponseRedirects();
        self::assertStringContainsString('/login', (string) $client->getResponse()->headers->get('Location'));
    }

    public function testTheAdminAreaIsForbiddenToOrdinaryUsers(): void
    {
        $client = self::createClient();
        $client->loginUser($this->createAccount('plain@example.test', verified: true));

        $client->request('GET', '/fr/admin');

        self::assertResponseStatusCodeSame(403);
    }

    public function testTheAdminAreaIsOpenToAdministrators(): void
    {
        $client = self::createClient();
        $admin = $this->createAccount('admin@example.test', verified: true, roles: [User::ROLE_ADMIN]);
        $client->loginUser($admin);

        $client->request('GET', '/fr/admin');

        self::assertResponseIsSuccessful();
    }

    public function testLoggingOutRequiresAPostRequest(): void
    {
        $client = self::createClient();
        $client->loginUser($this->createAccount('logout@example.test', verified: true));

        $client->request('GET', '/fr/logout');

        self::assertResponseStatusCodeSame(405);
    }

    public function testRepeatedFailuresAreThrottled(): void
    {
        $client = self::createClient();
        $this->createAccount('throttled@example.test', verified: true);

        for ($attempt = 1; $attempt <= 6; ++$attempt) {
            $this->submitLogin($client, 'throttled@example.test', 'still-not-the-password');
            $client->followRedirect();
        }

        self::assertSelectorTextContains('[role="alert"]', 'Trop de tentatives');
    }

    private function submitLogin(KernelBrowser $client, string $email, string $password): void
    {
        $crawler = $client->request('GET', '/fr/login');
        $client->submit($crawler->filter('form')->first()->form([
            'email' => $email,
            'password' => $password,
        ]));
    }

    /**
     * @param list<string> $roles
     */
    private function createAccount(string $email, bool $verified, array $roles = []): User
    {
        /** @var AccountCreator $creator */
        $creator = self::getContainer()->get(AccountCreator::class);

        return $creator->register($email, self::PASSWORD, 'fr', $roles, $verified);
    }

    private function entityManager(): EntityManagerInterface
    {
        /** @var EntityManagerInterface $entityManager */
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);

        return $entityManager;
    }
}
