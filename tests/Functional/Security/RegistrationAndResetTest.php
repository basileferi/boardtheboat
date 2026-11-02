<?php

declare(strict_types=1);

namespace App\Tests\Functional\Security;

use App\Entity\User;
use App\Repository\UserRepository;
use App\Service\Security\AccountCreator;
use App\Tests\Support\ClearsRateLimiters;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Mime\Email;
use Zenstruck\Foundry\Test\ResetDatabase;

/**
 * Sign-up, e-mail confirmation and the forgotten-password flow, end to end.
 *
 * The mailer is the null transport, so the assertions read the messages Symfony collected
 * rather than anything that left the machine.
 */
final class RegistrationAndResetTest extends WebTestCase
{
    use ClearsRateLimiters;
    use ResetDatabase;

    private const string PASSWORD = 'correct-horse-battery-staple';

    /**
     * Mails collected right after the request that produced them: the mailer's message logger
     * is reset on every request, so following a redirect first would lose them.
     *
     * @var array<int, \Symfony\Component\Mime\RawMessage>
     */
    private array $mailsFromLastSubmission = [];

    public function testSigningUpCreatesAnUnverifiedAccountAndSendsOneEmail(): void
    {
        $client = self::createClient();
        $crawler = $client->request('GET', '/fr/register');
        self::assertResponseIsSuccessful();

        $client->submit($crawler->filter('form')->first()->form([
            'registration_form[email]' => 'newcomer@example.test',
            'registration_form[locale]' => 'fr',
            'registration_form[plainPassword][first]' => self::PASSWORD,
            'registration_form[plainPassword][second]' => self::PASSWORD,
            'registration_form[agreeTerms]' => '1',
        ]));

        self::assertResponseRedirects('/fr/login');
        self::assertEmailCount(1);

        $user = $this->users()->findOneBy(['email' => 'newcomer@example.test']);
        self::assertInstanceOf(User::class, $user);
        self::assertFalse($user->isVerified(), 'a fresh account must confirm its address first');
        self::assertNotNull($user->getProfile(), 'every account gets a profile, otherwise the pipeline ignores it');
        self::assertNotSame(self::PASSWORD, $user->getPassword(), 'the password must be hashed');
    }

    public function testMismatchedPasswordsAreRejected(): void
    {
        $client = self::createClient();
        $crawler = $client->request('GET', '/fr/register');

        $client->submit($crawler->filter('form')->first()->form([
            'registration_form[email]' => 'mismatch@example.test',
            'registration_form[plainPassword][first]' => self::PASSWORD,
            'registration_form[plainPassword][second]' => 'something-else-entirely',
            'registration_form[agreeTerms]' => '1',
        ]));

        self::assertResponseStatusCodeSame(422, 'an invalid form submission is answered with 422 for Turbo');
        self::assertNull($this->users()->findOneBy(['email' => 'mismatch@example.test']));
    }

    public function testAShortPasswordIsRejected(): void
    {
        $client = self::createClient();
        $crawler = $client->request('GET', '/fr/register');

        $client->submit($crawler->filter('form')->first()->form([
            'registration_form[email]' => 'short@example.test',
            'registration_form[plainPassword][first]' => 'short',
            'registration_form[plainPassword][second]' => 'short',
            'registration_form[agreeTerms]' => '1',
        ]));

        self::assertResponseStatusCodeSame(422);
        self::assertNull($this->users()->findOneBy(['email' => 'short@example.test']));

        // the message must be the translated sentence, never the raw key
        $page = (string) $client->getResponse()->getContent();
        self::assertStringContainsString('un peu court', $page);
        self::assertStringNotContainsString('security.register.', $page);
    }

    public function testAnAddressAlreadyInUseGetsAReadableMessage(): void
    {
        $client = self::createClient();
        $this->account('taken@example.test');

        $crawler = $client->request('GET', '/fr/register');
        $client->submit($crawler->filter('form')->first()->form([
            'registration_form[email]' => 'taken@example.test',
            'registration_form[plainPassword][first]' => self::PASSWORD,
            'registration_form[plainPassword][second]' => self::PASSWORD,
            'registration_form[agreeTerms]' => '1',
        ]));

        self::assertResponseStatusCodeSame(422, 'a duplicate must be a form error, not a database crash');
        self::assertStringContainsString('un compte existe déjà', (string) $client->getResponse()->getContent());
    }

    public function testTheConfirmationLinkVerifiesTheAccount(): void
    {
        $client = self::createClient();
        $crawler = $client->request('GET', '/fr/register');
        $client->submit($crawler->filter('form')->first()->form([
            'registration_form[email]' => 'confirm@example.test',
            'registration_form[plainPassword][first]' => self::PASSWORD,
            'registration_form[plainPassword][second]' => self::PASSWORD,
            'registration_form[agreeTerms]' => '1',
        ]));

        $this->mailsFromLastSubmission = $this->getMailerMessages();
        $client->request('GET', $this->firstLinkIn($this->lastEmail(), '/fr/verify-email'));

        self::assertResponseRedirects('/fr/login');
        $user = $this->users()->findOneBy(['email' => 'confirm@example.test']);
        self::assertInstanceOf(User::class, $user);
        self::assertTrue($user->isVerified());
    }

    public function testATamperedConfirmationLinkIsRefused(): void
    {
        $client = self::createClient();
        $victim = $this->account('victim@example.test');
        $attacker = $this->account('attacker@example.test');

        $client->request('GET', \sprintf('/fr/verify-email?id=%s&expires=1&signature=forged&token=forged', $victim->getId()));

        self::assertResponseRedirects('/fr/login');
        self::assertFalse($victim->isVerified());
        self::assertFalse($attacker->isVerified());
    }

    public function testTheResetFormNeverRevealsWhetherAnAccountExists(): void
    {
        $client = self::createClient();
        $this->account('known@example.test', verified: true);

        $bodyForKnown = $this->requestReset($client, 'known@example.test');
        self::assertCount(1, $this->mailsFromLastSubmission, 'a known address gets exactly one mail');

        $bodyForUnknown = $this->requestReset($client, 'nobody@example.test');
        self::assertCount(0, $this->mailsFromLastSubmission, 'no mail may be sent for an address we do not know');

        self::assertSame($bodyForKnown, $bodyForUnknown, 'both answers must be indistinguishable');
    }

    public function testTheResetLinkSetsANewPassword(): void
    {
        $client = self::createClient();
        $this->account('resetme@example.test', verified: true);

        $this->requestReset($client, 'resetme@example.test');
        $resetUrl = $this->firstLinkIn($this->lastEmail(), '/fr/password/reset/');

        $client->request('GET', $resetUrl);
        self::assertResponseRedirects('/fr/password/reset');
        $crawler = $client->followRedirect();

        $client->submit($crawler->filter('form')->first()->form([
            'change_password_form[plainPassword][first]' => 'a-brand-new-passphrase',
            'change_password_form[plainPassword][second]' => 'a-brand-new-passphrase',
        ]));
        self::assertResponseRedirects('/fr/login');

        // The new password works and the link cannot be replayed.
        $login = $client->request('GET', '/fr/login');
        $client->submit($login->filter('form')->first()->form([
            'email' => 'resetme@example.test',
            'password' => 'a-brand-new-passphrase',
        ]));
        self::assertResponseRedirects('/fr/dashboard');

        $client->request('GET', $resetUrl);
        $client->followRedirect();
        self::assertResponseRedirects('/fr/password/forgot');
    }

    private function requestReset(KernelBrowser $client, string $email): string
    {
        $crawler = $client->request('GET', '/fr/password/forgot');
        $client->submit($crawler->filter('form')->first()->form([
            'reset_password_request_form[email]' => $email,
        ]));

        self::assertResponseRedirects('/fr/password/check-email');
        $this->mailsFromLastSubmission = $this->getMailerMessages();

        return $client->followRedirect()->filter('main')->text();
    }

    private function lastEmail(): Email
    {
        $messages = $this->mailsFromLastSubmission;
        self::assertNotEmpty($messages, 'expected an e-mail to have been sent');
        $email = end($messages);
        self::assertInstanceOf(Email::class, $email);

        return $email;
    }

    private function firstLinkIn(Email $email, string $needle): string
    {
        $body = $email->getTextBody() ?? '';
        self::assertIsString($body);

        foreach (preg_split('/\s+/', $body) ?: [] as $word) {
            if (str_contains($word, $needle)) {
                return $word;
            }
        }

        self::fail(\sprintf('No link containing "%s" found in the e-mail.', $needle));
    }

    private function account(string $email, bool $verified = false): User
    {
        /** @var AccountCreator $creator */
        $creator = self::getContainer()->get(AccountCreator::class);

        return $creator->register($email, self::PASSWORD, 'fr', [], $verified);
    }

    private function users(): UserRepository
    {
        /** @var UserRepository $repository */
        $repository = self::getContainer()->get(UserRepository::class);
        self::getContainer()->get('doctrine')->getManager()->clear();

        return $repository;
    }
}
