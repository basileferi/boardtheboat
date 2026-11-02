<?php

declare(strict_types=1);

namespace App\Security\Voter;

use App\Entity\DocumentTemplate;
use App\Entity\User;

/**
 * @extends OwnedEntityVoter<DocumentTemplate>
 */
final class DocumentTemplateVoter extends OwnedEntityVoter
{
    protected function supportsSubject(mixed $subject): bool
    {
        return $subject instanceof DocumentTemplate;
    }

    protected function ownerOf(object $subject): User
    {
        return $subject->getOwner();
    }
}
