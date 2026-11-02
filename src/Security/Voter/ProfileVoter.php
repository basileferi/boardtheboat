<?php

declare(strict_types=1);

namespace App\Security\Voter;

use App\Entity\Profile;
use App\Entity\User;

/**
 * The profile holds the pasted CV text, so it stays strictly between the account and itself.
 *
 * @extends OwnedEntityVoter<Profile>
 */
final class ProfileVoter extends OwnedEntityVoter
{
    protected function supportsSubject(mixed $subject): bool
    {
        return $subject instanceof Profile;
    }

    protected function ownerOf(object $subject): User
    {
        return $subject->getUser();
    }
}
