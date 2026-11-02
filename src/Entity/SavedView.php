<?php

declare(strict_types=1);

namespace App\Entity;

use App\Entity\Concerns\TimestampableTrait;
use App\Repository\SavedViewRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\IdGenerator\UuidGenerator;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * A named set of search filters the user can reapply in one click.
 *
 * Filters are stored as JSON but never trusted on read: they are re-hydrated into the search
 * DTO and revalidated, so an edited row cannot smuggle an unexpected sort column into SQL.
 */
#[ORM\Entity(repositoryClass: SavedViewRepository::class)]
#[ORM\Table(name: 'saved_view')]
#[ORM\UniqueConstraint(name: 'uniq_saved_view_owner_name', columns: ['owner_id', 'name'])]
#[ORM\Index(name: 'idx_saved_view_owner_default', columns: ['owner_id', 'is_default'])]
#[ORM\HasLifecycleCallbacks]
final class SavedView
{
    use TimestampableTrait;

    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    #[ORM\GeneratedValue(strategy: 'CUSTOM')]
    #[ORM\CustomIdGenerator(class: UuidGenerator::class)]
    private ?Uuid $id = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private User $owner;

    #[ORM\Column(length: 80)]
    #[Assert\NotBlank]
    #[Assert\Length(max: 80)]
    private string $name;

    /** @var array<string, mixed> */
    #[ORM\Column]
    private array $filters = [];

    #[ORM\Column(length: 40)]
    private string $sort = 'newest';

    #[ORM\Column(name: 'is_default')]
    private bool $default = false;

    /**
     * @param array<string, mixed> $filters
     */
    public function __construct(User $owner, string $name, array $filters = [], string $sort = 'newest')
    {
        $this->owner = $owner;
        $this->name = $name;
        $this->filters = $filters;
        $this->sort = $sort;
        $this->initialiseTimestamps();
    }

    public function getId(): ?Uuid
    {
        return $this->id;
    }

    public function getOwner(): User
    {
        return $this->owner;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function setName(string $name): void
    {
        $this->name = $name;
    }

    /** @return array<string, mixed> */
    public function getFilters(): array
    {
        return $this->filters;
    }

    /** @param array<string, mixed> $filters */
    public function setFilters(array $filters): void
    {
        $this->filters = $filters;
    }

    public function getSort(): string
    {
        return $this->sort;
    }

    public function setSort(string $sort): void
    {
        $this->sort = $sort;
    }

    public function isDefault(): bool
    {
        return $this->default;
    }

    public function setDefault(bool $default): void
    {
        $this->default = $default;
    }
}
