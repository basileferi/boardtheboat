<?php

declare(strict_types=1);

namespace App\Security\Voter;

use App\Entity\Application;
use App\Entity\User;

/**
 * An application is private to its owner, administrators included: it records where somebody
 * is applying and what they wrote, which is none of an administrator's business.
 *
 * @extends OwnedEntityVoter<Application>
 */
final class ApplicationVoter extends OwnedEntityVoter
{
    protected function supportsSubject(mixed $subject): bool
    {
        return $subject instanceof Application;
    }

    protected function ownerOf(object $subject): User
    {
        return $subject->getOwner();
    }
}
