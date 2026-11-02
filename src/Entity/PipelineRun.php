<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\PipelineRunStatus;
use App\Repository\PipelineRunRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\IdGenerator\UuidGenerator;
use Symfony\Component\Uid\Uuid;

/**
 * One execution of app:pipeline:run, kept so the status page can answer "did last night work".
 *
 * A run that loses one source is Partial, not Failed: the pipeline is explicitly allowed to
 * finish with holes rather than throw away the whole night's work.
 */
#[ORM\Entity(repositoryClass: PipelineRunRepository::class)]
#[ORM\Table(name: 'pipeline_run')]
#[ORM\Index(name: 'idx_pipeline_run_started', columns: ['started_at'])]
final class PipelineRun
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    #[ORM\GeneratedValue(strategy: 'CUSTOM')]
    #[ORM\CustomIdGenerator(class: UuidGenerator::class)]
    private ?Uuid $id = null;

    #[ORM\Column(length: 16, enumType: PipelineRunStatus::class)]
    private PipelineRunStatus $status = PipelineRunStatus::Running;

    /**
     * What started the run: "cli", "scheduler" or "manual".
     */
    #[ORM\Column(name: 'trigger_source', length: 20)]
    private string $trigger;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $startedAt;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $finishedAt = null;

    /**
     * Counters per step, e.g. {"discovered": 120, "merged": 12, "scored": 98, "enriched": 20}.
     *
     * @var array<string, int>
     */
    #[ORM\Column]
    private array $counts = [];

    /**
     * Human-readable failures, one per entry, already redacted of any credential.
     *
     * @var list<string>
     */
    #[ORM\Column]
    private array $errors = [];

    public function __construct(string $trigger, ?\DateTimeImmutable $startedAt = null)
    {
        $this->trigger = $trigger;
        $this->startedAt = $startedAt ?? new \DateTimeImmutable();
    }

    public function getId(): ?Uuid
    {
        return $this->id;
    }

    public function getStatus(): PipelineRunStatus
    {
        return $this->status;
    }

    public function getTrigger(): string
    {
        return $this->trigger;
    }

    public function getStartedAt(): \DateTimeImmutable
    {
        return $this->startedAt;
    }

    public function getFinishedAt(): ?\DateTimeImmutable
    {
        return $this->finishedAt;
    }

    /** @return array<string, int> */
    public function getCounts(): array
    {
        return $this->counts;
    }

    /** @return list<string> */
    public function getErrors(): array
    {
        return $this->errors;
    }

    public function addCount(string $step, int $delta = 1): void
    {
        $this->counts[$step] = ($this->counts[$step] ?? 0) + $delta;
    }

    public function addError(string $message): void
    {
        $this->errors[] = $message;
    }

    public function finish(?\DateTimeImmutable $finishedAt = null): void
    {
        $this->finishedAt = $finishedAt ?? new \DateTimeImmutable();
        $this->status = [] === $this->errors ? PipelineRunStatus::Success : PipelineRunStatus::Partial;
    }

    public function fail(string $message, ?\DateTimeImmutable $finishedAt = null): void
    {
        $this->addError($message);
        $this->finishedAt = $finishedAt ?? new \DateTimeImmutable();
        $this->status = PipelineRunStatus::Failed;
    }

    public function getDurationSeconds(): ?int
    {
        return null === $this->finishedAt
            ? null
            : $this->finishedAt->getTimestamp() - $this->startedAt->getTimestamp();
    }
}
