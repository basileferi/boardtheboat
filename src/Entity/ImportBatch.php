<?php

declare(strict_types=1);

namespace App\Entity;

use App\Entity\Concerns\TimestampableTrait;
use App\Enum\ImportBatchStatus;
use App\Repository\ImportBatchRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\IdGenerator\UuidGenerator;
use Symfony\Component\Uid\Uuid;

/**
 * One admin import of company career pages (a CSV upload or a pasted URL list).
 *
 * Rows are validated asynchronously, so the batch records progress rather than a final verdict:
 * the admin screen reads counts and errors while handlers are still running.
 */
#[ORM\Entity(repositoryClass: ImportBatchRepository::class)]
#[ORM\Table(name: 'import_batch')]
#[ORM\Index(name: 'idx_import_batch_status', columns: ['status', 'created_at'])]
#[ORM\HasLifecycleCallbacks]
final class ImportBatch
{
    use TimestampableTrait;

    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    #[ORM\GeneratedValue(strategy: 'CUSTOM')]
    #[ORM\CustomIdGenerator(class: UuidGenerator::class)]
    private ?Uuid $id = null;

    /**
     * Nullable so deleting an admin account never deletes the audit trail of their imports.
     */
    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?User $createdBy;

    #[ORM\Column(length: 40)]
    private string $source;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $filename = null;

    #[ORM\Column(length: 16, enumType: ImportBatchStatus::class)]
    private ImportBatchStatus $status = ImportBatchStatus::Pending;

    /**
     * @var array<string, int> e.g. {"rows": 40, "created": 31, "merged": 7, "rejected": 2}
     */
    #[ORM\Column]
    private array $counts = [];

    /** @var list<string> */
    #[ORM\Column]
    private array $errors = [];

    public function __construct(string $source, ?User $createdBy = null, ?string $filename = null)
    {
        $this->source = $source;
        $this->createdBy = $createdBy;
        $this->filename = $filename;
        $this->initialiseTimestamps();
    }

    public function getId(): ?Uuid
    {
        return $this->id;
    }

    public function getCreatedBy(): ?User
    {
        return $this->createdBy;
    }

    public function getSource(): string
    {
        return $this->source;
    }

    public function getFilename(): ?string
    {
        return $this->filename;
    }

    public function getStatus(): ImportBatchStatus
    {
        return $this->status;
    }

    public function setStatus(ImportBatchStatus $status): void
    {
        $this->status = $status;
    }

    /** @return array<string, int> */
    public function getCounts(): array
    {
        return $this->counts;
    }

    public function addCount(string $key, int $delta = 1): void
    {
        $this->counts[$key] = ($this->counts[$key] ?? 0) + $delta;
    }

    /** @return list<string> */
    public function getErrors(): array
    {
        return $this->errors;
    }

    public function addError(string $message): void
    {
        $this->errors[] = $message;
    }
}
