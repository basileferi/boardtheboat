<?php

declare(strict_types=1);

namespace App\Security\Voter;

use App\Entity\SavedView;
use App\Entity\User;

/**
 * @extends OwnedEntityVoter<SavedView>
 */
final class SavedViewVoter extends OwnedEntityVoter
{
    protected function supportsSubject(mixed $subject): bool
    {
        return $subject instanceof SavedView;
    }

    protected function ownerOf(object $subject): User
    {
        return $subject->getOwner();
    }
}
