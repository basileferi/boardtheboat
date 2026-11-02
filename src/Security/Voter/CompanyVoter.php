<?php

declare(strict_types=1);

namespace App\Security\Voter;

use App\Entity\Company;
use App\Entity\User;

/**
 * Companies are the one shared entity a user can also own privately.
 *
 * A catalogue row (owner null) is visible to everyone and editable by administrators, who
 * also validate the rows users suggest. A private row belongs to its author alone.
 *
 * @extends OwnedEntityVoter<Company>
 */
final class CompanyVoter extends OwnedEntityVoter
{
    protected function supportsSubject(mixed $subject): bool
    {
        return $subject instanceof Company;
    }

    protected function ownerOf(object $subject): ?User
    {
        return $subject->getOwner();
    }

    protected function grantsAdminOverride(string $attribute): bool
    {
        // Admins curate the catalogue, so they may edit and remove any company row.
        return true;
    }
}
