<?php

declare(strict_types=1);

namespace App\Entity;

use App\Entity\Concerns\TimestampableTrait;
use App\Enum\ContactChannel;
use App\Enum\PipelineStatus;
use App\Repository\ApplicationRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\IdGenerator\UuidGenerator;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * One user's pursuit of one target: an offer, or a company approached spontaneously.
 *
 * This is the only "saved" concept in the product — clicking save creates an Application in
 * status Saved. It also carries the follow-up ("relance") schedule, because a reminder only
 * makes sense against something that was actually sent.
 */
#[ORM\Entity(repositoryClass: ApplicationRepository::class)]
#[ORM\Table(name: 'application')]
#[ORM\Index(name: 'idx_application_owner_status', columns: ['owner_id', 'pipeline_status'])]
#[ORM\Index(name: 'idx_application_owner_followup', columns: ['owner_id', 'follow_up_at'])]
#[ORM\Index(name: 'idx_application_company', columns: ['company_id'])]
#[ORM\UniqueConstraint(name: 'uniq_application_owner_target', columns: ['owner_id', 'company_id', 'job_offer_id'])]
#[ORM\HasLifecycleCallbacks]
final class Application
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

    #[ORM\ManyToOne(targetEntity: Company::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Company $company;

    /**
     * Null for a spontaneous application: the company is the target, not a posted offer.
     */
    #[ORM\ManyToOne(targetEntity: JobOffer::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?JobOffer $jobOffer = null;

    #[ORM\Column(length: 20, enumType: PipelineStatus::class)]
    private PipelineStatus $pipelineStatus = PipelineStatus::Saved;

    #[ORM\Column(length: 180, nullable: true)]
    #[Assert\Length(max: 180)]
    private ?string $targetRole = null;

    #[ORM\Column(length: 20, enumType: ContactChannel::class, nullable: true)]
    private ?ContactChannel $contactChannel = null;

    #[ORM\Column(type: 'text', nullable: true)]
    #[Assert\Length(max: 10000)]
    private ?string $notes = null;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $appliedAt = null;

    /**
     * When the next follow-up falls due. Null means no reminder is scheduled.
     */
    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $followUpAt = null;

    #[ORM\Column]
    private int $relanceCount = 0;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $lastRelanceAt = null;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $snoozedUntil = null;

    #[ORM\ManyToOne(targetEntity: DocumentTemplate::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?DocumentTemplate $letterTemplate = null;

    #[ORM\ManyToOne(targetEntity: DocumentTemplate::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?DocumentTemplate $cvTemplate = null;

    public function __construct(User $owner, Company $company, ?JobOffer $jobOffer = null)
    {
        $this->owner = $owner;
        $this->company = $company;
        $this->jobOffer = $jobOffer;
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

    public function getCompany(): Company
    {
        return $this->company;
    }

    public function setCompany(Company $company): void
    {
        $this->company = $company;
    }

    public function getJobOffer(): ?JobOffer
    {
        return $this->jobOffer;
    }

    public function setJobOffer(?JobOffer $jobOffer): void
    {
        $this->jobOffer = $jobOffer;
    }

    public function isSpontaneous(): bool
    {
        return null === $this->jobOffer;
    }

    public function getPipelineStatus(): PipelineStatus
    {
        return $this->pipelineStatus;
    }

    public function getTargetRole(): ?string
    {
        return $this->targetRole;
    }

    public function setTargetRole(?string $targetRole): void
    {
        $this->targetRole = $targetRole;
    }

    public function getContactChannel(): ?ContactChannel
    {
        return $this->contactChannel;
    }

    public function setContactChannel(?ContactChannel $contactChannel): void
    {
        $this->contactChannel = $contactChannel;
    }

    public function getNotes(): ?string
    {
        return $this->notes;
    }

    public function setNotes(?string $notes): void
    {
        $this->notes = $notes;
    }

    public function getAppliedAt(): ?\DateTimeImmutable
    {
        return $this->appliedAt;
    }

    public function getFollowUpAt(): ?\DateTimeImmutable
    {
        return $this->followUpAt;
    }

    public function getRelanceCount(): int
    {
        return $this->relanceCount;
    }

    public function getLastRelanceAt(): ?\DateTimeImmutable
    {
        return $this->lastRelanceAt;
    }

    public function getSnoozedUntil(): ?\DateTimeImmutable
    {
        return $this->snoozedUntil;
    }

    public function getLetterTemplate(): ?DocumentTemplate
    {
        return $this->letterTemplate;
    }

    public function setLetterTemplate(?DocumentTemplate $letterTemplate): void
    {
        $this->letterTemplate = $letterTemplate;
    }

    public function getCvTemplate(): ?DocumentTemplate
    {
        return $this->cvTemplate;
    }

    public function setCvTemplate(?DocumentTemplate $cvTemplate): void
    {
        $this->cvTemplate = $cvTemplate;
    }

    /**
     * Moves the application through the pipeline and keeps the reminder schedule consistent:
     * entering "applied" starts the follow-up clock, and any terminal-ish status stops it.
     *
     * @param int $relanceDelayDays the owner's configured delay, from their Profile
     */
    public function changeStatus(PipelineStatus $status, int $relanceDelayDays, ?\DateTimeImmutable $now = null): void
    {
        $now ??= new \DateTimeImmutable();
        $previous = $this->pipelineStatus;
        $this->pipelineStatus = $status;

        if (PipelineStatus::Applied === $status && PipelineStatus::Applied !== $previous) {
            // The first application date is history and never moves; the reminder, however, is
            // counted from the moment the status was set, so re-opening a dead lead does not
            // schedule a follow-up that is already overdue.
            $this->appliedAt ??= $now;
            $this->followUpAt = $now->modify(\sprintf('+%d days', $relanceDelayDays));
            $this->relanceCount = 0;
            $this->snoozedUntil = null;

            return;
        }

        if ($status->stopsReminders()) {
            $this->followUpAt = null;
            $this->snoozedUntil = null;
        }
    }

    /**
     * Records that a follow-up was sent and schedules the next one, unless the owner's
     * maximum has been reached.
     */
    public function recordRelanceSent(int $relanceDelayDays, int $maxRelances, ?\DateTimeImmutable $now = null): void
    {
        $now ??= new \DateTimeImmutable();
        ++$this->relanceCount;
        $this->lastRelanceAt = $now;
        $this->snoozedUntil = null;
        $this->followUpAt = $this->relanceCount >= $maxRelances
            ? null
            : $now->modify(\sprintf('+%d days', $relanceDelayDays));
    }

    public function snoozeUntil(\DateTimeImmutable $until): void
    {
        $this->snoozedUntil = $until;
        $this->followUpAt = $until;
    }

    /**
     * Stops reminding without changing how the application is classified.
     */
    public function closeFollowUp(): void
    {
        $this->followUpAt = null;
        $this->snoozedUntil = null;
    }

    public function isFollowUpDue(?\DateTimeImmutable $now = null): bool
    {
        if (null === $this->followUpAt || $this->pipelineStatus->stopsReminders()) {
            return false;
        }

        $now ??= new \DateTimeImmutable();

        return $this->followUpAt <= $now && (null === $this->snoozedUntil || $this->snoozedUntil <= $now);
    }
}
