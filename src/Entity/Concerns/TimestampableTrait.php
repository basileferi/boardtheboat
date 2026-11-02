<?php

declare(strict_types=1);

namespace App\Entity\Concerns;

use Doctrine\ORM\Mapping as ORM;

/**
 * Creation and update stamps.
 *
 * Both are initialised from the entity constructor (so a freshly built object is already
 * readable, in tests as well as in the app) and the update stamp is refreshed by Doctrine.
 * The using entity must carry #[ORM\HasLifecycleCallbacks], otherwise updatedAt would
 * silently stop moving.
 */
trait TimestampableTrait
{
    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $updatedAt;

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }

    #[ORM\PreUpdate]
    public function refreshUpdatedAt(): void
    {
        $this->updatedAt = new \DateTimeImmutable();
    }

    private function initialiseTimestamps(): void
    {
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = $this->createdAt;
    }
}
