<?php

declare(strict_types=1);

namespace App\Entity;

use App\Entity\Concerns\TimestampableTrait;
use App\Enum\Region;
use App\Enum\RemotePreference;
use App\Repository\ProfileRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\IdGenerator\UuidGenerator;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * What the pipeline matches companies and offers against: one per user, filled once
 * and edited from the profile page.
 *
 * The fingerprint is a hash of every field that influences the relevance score. The
 * pipeline recomputes a score only when the profile or the company fingerprint moved,
 * which is what keeps a nightly run cheap.
 */
#[ORM\Entity(repositoryClass: ProfileRepository::class)]
#[ORM\HasLifecycleCallbacks]
final class Profile
{
    use TimestampableTrait;

    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    #[ORM\GeneratedValue(strategy: 'CUSTOM')]
    #[ORM\CustomIdGenerator(class: UuidGenerator::class)]
    private ?Uuid $id = null;

    #[ORM\OneToOne(inversedBy: 'profile', targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false, unique: true, onDelete: 'CASCADE')]
    private User $user;

    /**
     * Pasted CV text. Never sent anywhere without an explicit user action (see privacy policy).
     */
    #[ORM\Column(type: 'text', nullable: true)]
    #[Assert\Length(max: 40000)]
    private ?string $cvText = null;

    /** @var list<string> */
    #[ORM\Column]
    #[Assert\Count(max: 20)]
    private array $targetRoles = [];

    /** @var list<string> */
    #[ORM\Column]
    #[Assert\Count(max: 50)]
    private array $keywords = [];

    /** @var list<string> */
    #[ORM\Column]
    #[Assert\Count(max: 20)]
    private array $sectors = [];

    /** @var list<string> */
    #[ORM\Column]
    #[Assert\Count(max: 50)]
    private array $skills = [];

    /** @var list<string> */
    #[ORM\Column]
    #[Assert\Count(max: 20)]
    private array $preferredLocations = [];

    /** @var list<string> */
    #[ORM\Column]
    #[Assert\Count(max: 10)]
    private array $languages = [];

    #[ORM\Column(length: 16, enumType: RemotePreference::class)]
    private RemotePreference $remotePreference = RemotePreference::Any;

    #[ORM\Column(length: 16, enumType: Region::class)]
    private Region $defaultRegion = Region::France;

    #[ORM\Column(type: 'date_immutable', nullable: true)]
    private ?\DateTimeImmutable $internshipStartDate = null;

    #[ORM\Column(nullable: true)]
    #[Assert\Range(min: 1, max: 104)]
    private ?int $internshipDurationWeeks = null;

    /**
     * Days between an application being marked "applied" and the follow-up falling due.
     */
    #[ORM\Column]
    #[Assert\Range(min: 1, max: 90)]
    private int $relanceDelayDays = 7;

    #[ORM\Column]
    #[Assert\Range(min: 0, max: 10)]
    private int $maxRelances = 2;

    /**
     * How many top-ranked companies the pipeline enriches on each run.
     */
    #[ORM\Column]
    #[Assert\Range(min: 1, max: 100)]
    private int $topN = 20;

    #[ORM\Column(length: 64)]
    private string $fingerprint = '';

    public function __construct(User $user)
    {
        $this->user = $user;
        $user->setProfile($this);
        $this->initialiseTimestamps();
        $this->refreshFingerprint();
    }

    public function getId(): ?Uuid
    {
        return $this->id;
    }

    public function getUser(): User
    {
        return $this->user;
    }

    public function getCvText(): ?string
    {
        return $this->cvText;
    }

    public function setCvText(?string $cvText): void
    {
        $this->cvText = $cvText;
    }

    /** @return list<string> */
    public function getTargetRoles(): array
    {
        return $this->targetRoles;
    }

    /** @param list<string> $targetRoles */
    public function setTargetRoles(array $targetRoles): void
    {
        $this->targetRoles = $targetRoles;
        $this->refreshFingerprint();
    }

    /** @return list<string> */
    public function getKeywords(): array
    {
        return $this->keywords;
    }

    /** @param list<string> $keywords */
    public function setKeywords(array $keywords): void
    {
        $this->keywords = $keywords;
        $this->refreshFingerprint();
    }

    /** @return list<string> */
    public function getSectors(): array
    {
        return $this->sectors;
    }

    /** @param list<string> $sectors */
    public function setSectors(array $sectors): void
    {
        $this->sectors = $sectors;
        $this->refreshFingerprint();
    }

    /** @return list<string> */
    public function getSkills(): array
    {
        return $this->skills;
    }

    /** @param list<string> $skills */
    public function setSkills(array $skills): void
    {
        $this->skills = $skills;
        $this->refreshFingerprint();
    }

    /** @return list<string> */
    public function getPreferredLocations(): array
    {
        return $this->preferredLocations;
    }

    /** @param list<string> $preferredLocations */
    public function setPreferredLocations(array $preferredLocations): void
    {
        $this->preferredLocations = $preferredLocations;
        $this->refreshFingerprint();
    }

    /** @return list<string> */
    public function getLanguages(): array
    {
        return $this->languages;
    }

    /** @param list<string> $languages */
    public function setLanguages(array $languages): void
    {
        $this->languages = $languages;
        $this->refreshFingerprint();
    }

    public function getRemotePreference(): RemotePreference
    {
        return $this->remotePreference;
    }

    public function setRemotePreference(RemotePreference $remotePreference): void
    {
        $this->remotePreference = $remotePreference;
        $this->refreshFingerprint();
    }

    public function getDefaultRegion(): Region
    {
        return $this->defaultRegion;
    }

    public function setDefaultRegion(Region $defaultRegion): void
    {
        $this->defaultRegion = $defaultRegion;
        $this->refreshFingerprint();
    }

    public function getInternshipStartDate(): ?\DateTimeImmutable
    {
        return $this->internshipStartDate;
    }

    public function setInternshipStartDate(?\DateTimeImmutable $internshipStartDate): void
    {
        $this->internshipStartDate = $internshipStartDate;
        $this->refreshFingerprint();
    }

    public function getInternshipDurationWeeks(): ?int
    {
        return $this->internshipDurationWeeks;
    }

    public function setInternshipDurationWeeks(?int $internshipDurationWeeks): void
    {
        $this->internshipDurationWeeks = $internshipDurationWeeks;
        $this->refreshFingerprint();
    }

    public function getRelanceDelayDays(): int
    {
        return $this->relanceDelayDays;
    }

    public function setRelanceDelayDays(int $relanceDelayDays): void
    {
        $this->relanceDelayDays = $relanceDelayDays;
    }

    public function getMaxRelances(): int
    {
        return $this->maxRelances;
    }

    public function setMaxRelances(int $maxRelances): void
    {
        $this->maxRelances = $maxRelances;
    }

    public function getTopN(): int
    {
        return $this->topN;
    }

    public function setTopN(int $topN): void
    {
        $this->topN = $topN;
    }

    public function getFingerprint(): string
    {
        return $this->fingerprint;
    }

    /**
     * Recomputed by every setter that feeds the score. It is NOT a PreUpdate callback:
     * Doctrine ignores property writes made during preUpdate unless they go through the
     * event's changeset, so a callback here would silently store a stale hash.
     */
    public function refreshFingerprint(): void
    {
        $this->fingerprint = hash('sha256', json_encode([
            $this->targetRoles,
            $this->keywords,
            $this->sectors,
            $this->skills,
            $this->preferredLocations,
            $this->languages,
            $this->remotePreference->value,
            $this->defaultRegion->value,
            $this->internshipStartDate?->format('Y-m-d'),
            $this->internshipDurationWeeks,
        ], \JSON_THROW_ON_ERROR));
    }
}
