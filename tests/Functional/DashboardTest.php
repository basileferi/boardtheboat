<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\Company;
use App\Entity\JobOffer;
use App\Entity\User;
use App\Message\RefreshSourcesMessage;
use App\Service\Security\AccountCreator;
use App\Tests\Support\ClearsRateLimiters;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;
use Zenstruck\Foundry\Test\ResetDatabase;

/**
 * The cockpit and the admin refresh: real figures on the page, no inline style attribute that
 * the strict CSP would silently drop, and a refresh that is queued, never run in the request.
 */
final class DashboardTest extends WebTestCase
{
    use ClearsRateLimiters;
    use ResetDatabase;

    public function testTheDashboardShowsLiveFigures(): void
    {
        $client = self::createClient();
        $client->loginUser($this->account('pilot@example.test'));
        $this->offers(3);

        $crawler = $client->request('GET', '/fr/dashboard');

        self::assertResponseIsSuccessful();
        self::assertCount(6, $crawler->filter('.kpi'));
        self::assertSame('3', $crawler->filter('.kpi')->first()->filter('[data-controller="countup"]')->attr('data-countup-to-value'));
        self::assertCount(14, $crawler->filter('.spark__bar'), 'two weeks of activity');
        self::assertCount(3, $crawler->filter('.feed__item'));
        self::assertStringNotContainsString(' style="', (string) $client->getResponse()->getContent(), 'the CSP forbids style attributes');
    }

    public function testTheDashboardRequiresASignedInUser(): void
    {
        $client = self::createClient();
        $client->request('GET', '/fr/dashboard');

        self::assertResponseRedirects();
    }

    public function testAnAdminRefreshIsQueuedNotRunInline(): void
    {
        $client = self::createClient();
        $client->loginUser($this->account('admin@example.test', ['ROLE_ADMIN']));

        $crawler = $client->request('GET', '/fr/admin');
        $client->submit($crawler->filter('form[action$="/admin/refresh"]')->form());

        self::assertResponseRedirects('/fr/admin');
        /** @var InMemoryTransport $transport */
        $transport = self::getContainer()->get('messenger.transport.async');
        $sent = $transport->getSent();
        self::assertCount(1, $sent);
        self::assertInstanceOf(RefreshSourcesMessage::class, $sent[0]->getMessage());
    }

    public function testTheRefreshNeedsAValidTokenAndTheAdminRole(): void
    {
        $client = self::createClient();
        $client->loginUser($this->account('user@example.test'));
        $client->request('POST', '/fr/admin/refresh', ['_token' => 'forged']);
        self::assertResponseStatusCodeSame(403);

        $client->loginUser($this->account('admin2@example.test', ['ROLE_ADMIN']));
        $client->request('POST', '/fr/admin/refresh', ['_token' => 'forged']);
        self::assertResponseStatusCodeSame(403);
    }

    /**
     * @param list<string> $roles
     */
    private function account(string $email, array $roles = []): User
    {
        /** @var AccountCreator $creator */
        $creator = self::getContainer()->get(AccountCreator::class);

        return $creator->register($email, 'correct-horse-battery-staple', 'fr', $roles, true);
    }

    private function offers(int $count): void
    {
        /** @var EntityManagerInterface $entityManager */
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $company = new Company('Acme');
        $entityManager->persist($company);

        for ($i = 0; $i < $count; ++$i) {
            $offer = new JobOffer($company, 'themuse', 'id-'.$i, 'Software intern '.$i, 'https://jobs.example/'.$i);
            $offer->setPublishedAt(new \DateTimeImmutable(\sprintf('-%d days', $i)));
            $entityManager->persist($offer);
        }

        $entityManager->flush();
    }
}
