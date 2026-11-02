<?php

declare(strict_types=1);

namespace App\Security\Voter;

use App\Entity\User;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Vote;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/**
 * Shared ownership rule for every user-owned entity.
 *
 * Repository queries are already scoped to the owner; this is the second half of the defence,
 * so that a resource loaded any other way (a route parameter resolved by id, a service that
 * forgot the scope) still cannot be read or written by the wrong account.
 *
 * Each decision records a reason, which shows up in the profiler and in access-denied logs.
 *
 * @template T of object
 *
 * @template-extends Voter<string, T>
 */
abstract class OwnedEntityVoter extends Voter
{
    public const string VIEW = 'view';
    public const string EDIT = 'edit';
    public const string DELETE = 'delete';

    protected function supports(string $attribute, mixed $subject): bool
    {
        return \in_array($attribute, [self::VIEW, self::EDIT, self::DELETE], true)
            && $this->supportsSubject($subject);
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token, ?Vote $vote = null): bool
    {
        $current = $token->getUser();
        if (!$current instanceof User || !$this->supportsSubject($subject)) {
            $vote?->addReason('No authenticated application user.');

            return false;
        }

        if ($this->grantsAdminOverride($attribute) && $current->isAdmin()) {
            $vote?->addReason('Administrators curate this kind of row.');

            return true;
        }

        $owner = $this->ownerOf($subject);

        // A row with no owner is shared data: readable by anyone signed in, writable only by
        // administrators, which the override above has already settled.
        if (null === $owner) {
            $vote?->addReason('Shared row: readable by any signed-in user.');

            return self::VIEW === $attribute;
        }

        $ownerId = $owner->getId();
        $currentId = $current->getId();

        if (null === $ownerId || null === $currentId) {
            // At least one side is not persisted yet: object identity is the only honest test.
            return $owner === $current;
        }

        if (!$ownerId->equals($currentId)) {
            $vote?->addReason('The row belongs to another account.');

            return false;
        }

        return true;
    }

    /**
     * @phpstan-assert-if-true T $subject
     */
    abstract protected function supportsSubject(mixed $subject): bool;

    /**
     * @param T $subject
     */
    abstract protected function ownerOf(object $subject): ?User;

    /**
     * Whether an administrator may act on somebody else's row for this attribute. Off by
     * default: an admin has no business reading a user's applications or templates.
     */
    protected function grantsAdminOverride(string $attribute): bool
    {
        return false;
    }
}
