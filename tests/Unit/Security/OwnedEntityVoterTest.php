<?php

declare(strict_types=1);

namespace App\Tests\Unit\Security;

use App\Entity\Application;
use App\Entity\Company;
use App\Entity\DocumentTemplate;
use App\Entity\Profile;
use App\Entity\SavedView;
use App\Entity\User;
use App\Enum\TemplateKind;
use App\Security\Voter\ApplicationVoter;
use App\Security\Voter\CompanyVoter;
use App\Security\Voter\DocumentTemplateVoter;
use App\Security\Voter\OwnedEntityVoter;
use App\Security\Voter\ProfileVoter;
use App\Security\Voter\SavedViewVoter;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\VoterInterface;
use Symfony\Component\Uid\Uuid;

/**
 * The ownership half of the IDOR defence, tested directly so every entity type is covered
 * without a round trip through HTTP. The repository half is covered by the CRUD slices.
 */
final class OwnedEntityVoterTest extends TestCase
{
    public function testAnOwnerMayReadEditAndDeleteTheirApplication(): void
    {
        $owner = $this->persistedUser('owner@example.test');
        $application = new Application($owner, new Company('Acme'));

        foreach ([OwnedEntityVoter::VIEW, OwnedEntityVoter::EDIT, OwnedEntityVoter::DELETE] as $attribute) {
            self::assertSame(
                VoterInterface::ACCESS_GRANTED,
                (new ApplicationVoter())->vote($this->tokenFor($owner), $application, [$attribute]),
            );
        }
    }

    public function testAnotherUserMayNotTouchSomebodyElsesApplication(): void
    {
        $owner = $this->persistedUser('owner@example.test');
        $intruder = $this->persistedUser('intruder@example.test');
        $application = new Application($owner, new Company('Acme'));

        foreach ([OwnedEntityVoter::VIEW, OwnedEntityVoter::EDIT, OwnedEntityVoter::DELETE] as $attribute) {
            self::assertSame(
                VoterInterface::ACCESS_DENIED,
                (new ApplicationVoter())->vote($this->tokenFor($intruder), $application, [$attribute]),
                \sprintf('"%s" must be denied on another account\'s application', $attribute),
            );
        }
    }

    public function testAnAdministratorStillCannotReadSomebodyElsesApplication(): void
    {
        $owner = $this->persistedUser('owner@example.test');
        $admin = $this->persistedUser('admin@example.test', [User::ROLE_ADMIN]);
        $application = new Application($owner, new Company('Acme'));

        self::assertSame(
            VoterInterface::ACCESS_DENIED,
            (new ApplicationVoter())->vote($this->tokenFor($admin), $application, [OwnedEntityVoter::VIEW]),
        );
    }

    public function testACatalogueCompanyIsReadableByAnyoneAndWritableOnlyByAdministrators(): void
    {
        $user = $this->persistedUser('reader@example.test');
        $admin = $this->persistedUser('admin@example.test', [User::ROLE_ADMIN]);
        $catalogue = new Company('Thales');

        $voter = new CompanyVoter();

        self::assertSame(VoterInterface::ACCESS_GRANTED, $voter->vote($this->tokenFor($user), $catalogue, [OwnedEntityVoter::VIEW]));
        self::assertSame(VoterInterface::ACCESS_DENIED, $voter->vote($this->tokenFor($user), $catalogue, [OwnedEntityVoter::EDIT]));
        self::assertSame(VoterInterface::ACCESS_GRANTED, $voter->vote($this->tokenFor($admin), $catalogue, [OwnedEntityVoter::EDIT]));
    }

    public function testAPrivateCompanyIsHiddenFromOtherUsers(): void
    {
        $owner = $this->persistedUser('owner@example.test');
        $intruder = $this->persistedUser('intruder@example.test');
        $private = new Company('Mon petit secret', $owner);

        $voter = new CompanyVoter();

        self::assertSame(VoterInterface::ACCESS_GRANTED, $voter->vote($this->tokenFor($owner), $private, [OwnedEntityVoter::VIEW]));
        self::assertSame(VoterInterface::ACCESS_DENIED, $voter->vote($this->tokenFor($intruder), $private, [OwnedEntityVoter::VIEW]));
    }

    public function testViewsTemplatesAndProfilesAreAllOwnerScoped(): void
    {
        $owner = $this->persistedUser('owner@example.test');
        $intruder = $this->persistedUser('intruder@example.test');

        $cases = [
            [new SavedViewVoter(), new SavedView($owner, 'Alternance')],
            [new DocumentTemplateVoter(), new DocumentTemplate($owner, TemplateKind::Letter, 'Lettre')],
            [new ProfileVoter(), new Profile($owner)],
        ];

        foreach ($cases as [$voter, $subject]) {
            self::assertSame(
                VoterInterface::ACCESS_GRANTED,
                $voter->vote($this->tokenFor($owner), $subject, [OwnedEntityVoter::EDIT]),
                $subject::class.' must be editable by its owner',
            );
            self::assertSame(
                VoterInterface::ACCESS_DENIED,
                $voter->vote($this->tokenFor($intruder), $subject, [OwnedEntityVoter::EDIT]),
                $subject::class.' must not be editable by anybody else',
            );
        }
    }

    public function testAnAnonymousVisitorIsAlwaysDenied(): void
    {
        $owner = $this->persistedUser('owner@example.test');
        $application = new Application($owner, new Company('Acme'));

        $token = $this->createMock(TokenInterface::class);
        $token->method('getUser')->willReturn(null);

        self::assertSame(
            VoterInterface::ACCESS_DENIED,
            (new ApplicationVoter())->vote($token, $application, [OwnedEntityVoter::VIEW]),
        );
    }

    /**
     * Identifiers are assigned by Doctrine on flush; the voter compares them, so the test
     * has to give each user one without going near the database.
     *
     * @param list<string> $roles
     */
    private function persistedUser(string $email, array $roles = []): User
    {
        $user = new User($email);
        $user->setRoles($roles);

        $reflection = new \ReflectionProperty(User::class, 'id');
        $reflection->setValue($user, Uuid::v7());

        return $user;
    }

    private function tokenFor(User $user): TokenInterface
    {
        $token = $this->createMock(TokenInterface::class);
        $token->method('getUser')->willReturn($user);

        return $token;
    }
}
