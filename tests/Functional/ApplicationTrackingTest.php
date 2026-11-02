<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\Application;
use App\Entity\Company;
use App\Entity\JobOffer;
use App\Entity\User;
use App\Enum\PipelineStatus;
use App\Repository\ApplicationRepository;
use App\Service\Security\AccountCreator;
use App\Tests\Support\ClearsRateLimiters;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Zenstruck\Foundry\Test\ResetDatabase;

/**
 * Saving and tracking, exercised through the rendered forms rather than through crafted
 * requests, so the CSRF tokens the templates emit are part of what is tested.
 *
 * The IDOR cases reuse a token the attacker legitimately owns and aim it at somebody else's
 * row: a valid token must not be enough to touch a resource that is not yours.
 */
final class ApplicationTrackingTest extends WebTestCase
{
    use ClearsRateLimiters;
    use ResetDatabase;

    private const string PASSWORD = 'correct-horse-battery-staple';

    public function testSavingAnOfferCreatesAnApplicationInStatusSaved(): void
    {
        $client = self::createClient();
        $owner = $this->account('owner@example.test');
        $client->loginUser($owner);
        $offer = $this->offer();

        $this->submitSaveButton($client, $offer);

        $application = $this->applications()->findOneForTarget($owner, $offer->getCompany(), $offer);
        self::assertInstanceOf(Application::class, $application);
        self::assertSame(PipelineStatus::Saved, $application->getPipelineStatus());
        self::assertSame($offer->getTitle(), $application->getTargetRole());
    }

    public function testSavingTwiceRemovesTheApplication(): void
    {
        $client = self::createClient();
        $owner = $this->account('toggle@example.test');
        $client->loginUser($owner);
        $offer = $this->offer();

        $this->submitSaveButton($client, $offer);
        $this->submitSaveButton($client, $offer);

        $this->entityManager()->clear();
        self::assertNull($this->applications()->findOneForTarget($owner, $offer->getCompany(), $offer));
    }

    public function testSavingWithAForgedTokenIsRefused(): void
    {
        $client = self::createClient();
        $client->loginUser($this->account('nocsrf@example.test'));
        $offer = $this->offer();

        $client->request('POST', '/fr/offers/'.$offer->getId().'/save', ['_token' => 'forged']);

        self::assertResponseStatusCodeSame(403);
    }

    public function testSavingRequiresAPostRequest(): void
    {
        $client = self::createClient();
        $client->loginUser($this->account('getonly@example.test'));
        $offer = $this->offer();

        $client->request('GET', '/fr/offers/'.$offer->getId().'/save');

        self::assertResponseStatusCodeSame(405);
    }

    public function testMarkingAnApplicationAsAppliedSchedulesAFollowUp(): void
    {
        $client = self::createClient();
        $owner = $this->account('applied@example.test');
        $client->loginUser($owner);
        $application = $this->application($owner, 'Stage back-end');

        $this->submitStatusForm($client, $application, PipelineStatus::Applied);

        $reloaded = $this->reload($application);
        self::assertSame(PipelineStatus::Applied, $reloaded->getPipelineStatus());
        self::assertNotNull($reloaded->getFollowUpAt(), 'applying starts the follow-up clock');
    }

    public function testAValidTokenDoesNotLetAUserChangeSomebodyElsesApplication(): void
    {
        $client = self::createClient();
        $victim = $this->account('victim@example.test');
        $victimApplication = $this->application($victim, 'Cible convoitée');

        $intruder = $this->account('intruder@example.test');
        $ownApplication = $this->application($intruder, 'Ma propre cible');
        $client->loginUser($intruder);

        // A token the intruder legitimately obtained, aimed at the victim's row.
        $token = $this->tokenFromPipeline($client, $ownApplication);
        $client->request('POST', '/fr/applications/'.$victimApplication->getId().'/status', [
            '_token' => $token,
            'status' => PipelineStatus::Rejected->value,
        ]);

        self::assertResponseStatusCodeSame(403);
        self::assertSame(PipelineStatus::Saved, $this->reload($victimApplication)->getPipelineStatus());
    }

    public function testAValidTokenDoesNotLetAUserDeleteSomebodyElsesApplication(): void
    {
        $client = self::createClient();
        $victim = $this->account('victim2@example.test');
        $victimApplication = $this->application($victim, 'À ne pas supprimer');

        $intruder = $this->account('intruder2@example.test');
        $ownApplication = $this->application($intruder, 'La mienne');
        $client->loginUser($intruder);

        $token = $this->tokenFromPipeline($client, $ownApplication);
        $client->request('POST', '/fr/applications/'.$victimApplication->getId().'/delete', ['_token' => $token]);

        self::assertResponseStatusCodeSame(403);
        self::assertNotNull($this->entityManager()->getRepository(Application::class)->find($victimApplication->getId()));
    }

    public function testThePipelineOnlyShowsYourOwnApplications(): void
    {
        $client = self::createClient();
        $owner = $this->account('mine@example.test');
        $other = $this->account('theirs@example.test');
        $this->application($owner, 'Ma cible');
        $this->application($other, 'Leur cible');

        $client->loginUser($owner);
        $crawler = $client->request('GET', '/fr/pipeline');

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Ma cible', $crawler->filter('main')->text());
        self::assertStringNotContainsString('Leur cible', $crawler->filter('main')->text());
    }

    private function submitSaveButton(KernelBrowser $client, JobOffer $offer): void
    {
        $crawler = $client->request('GET', '/fr/offers/'.$offer->getId());
        self::assertResponseIsSuccessful();

        $form = $crawler->filter('#save-offer-'.$offer->getId())->form();
        $client->submit($form);
        self::assertResponseRedirects();
    }

    private function submitStatusForm(KernelBrowser $client, Application $application, PipelineStatus $status): void
    {
        $crawler = $client->request('GET', '/fr/pipeline');
        self::assertResponseIsSuccessful();

        $form = $crawler->filter('form[action*="'.$application->getId().'/status"]')->form();
        $form['status'] = $status->value;
        $client->submit($form);
        self::assertResponseRedirects('/fr/pipeline');
    }

    private function tokenFromPipeline(KernelBrowser $client, Application $own): string
    {
        $crawler = $client->request('GET', '/fr/pipeline');
        self::assertResponseIsSuccessful();

        $token = $crawler->filter('form[action*="'.$own->getId().'/status"] input[name="_token"]')->attr('value');
        self::assertIsString($token);

        return $token;
    }

    private function reload(Application $application): Application
    {
        $this->entityManager()->clear();
        $reloaded = $this->entityManager()->getRepository(Application::class)->find($application->getId());
        self::assertInstanceOf(Application::class, $reloaded);

        return $reloaded;
    }

    private function account(string $email): User
    {
        /** @var AccountCreator $creator */
        $creator = self::getContainer()->get(AccountCreator::class);

        return $creator->register($email, self::PASSWORD, 'fr', [], true);
    }

    private function offer(string $externalId = 'offer-1'): JobOffer
    {
        $entityManager = $this->entityManager();

        $company = new Company('Aquitaine Digital');
        $company->setCity('Bordeaux');
        $offer = new JobOffer($company, 'fixtures', $externalId, 'Stage développeur PHP', 'https://example.test/jobs/1');
        $offer->setDescription('Symfony et Doctrine.');
        $offer->setPublishedAt(new \DateTimeImmutable('-1 day'));

        $entityManager->persist($company);
        $entityManager->persist($offer);
        $entityManager->flush();

        return $offer;
    }

    private function application(User $owner, string $role = 'Stage'): Application
    {
        $entityManager = $this->entityManager();

        $company = new Company('Cible '.$role);
        $application = new Application($owner, $company);
        $application->setTargetRole($role);

        $entityManager->persist($company);
        $entityManager->persist($application);
        $entityManager->flush();

        return $application;
    }

    private function applications(): ApplicationRepository
    {
        /** @var ApplicationRepository $repository */
        $repository = self::getContainer()->get(ApplicationRepository::class);

        return $repository;
    }

    private function entityManager(): EntityManagerInterface
    {
        /** @var EntityManagerInterface $entityManager */
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);

        return $entityManager;
    }
}
