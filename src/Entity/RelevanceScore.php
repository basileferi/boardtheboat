<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\RelevanceScoreRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\IdGenerator\UuidGenerator;
use Symfony\Component\Uid\Uuid;

/**
 * How well one company matches one user's profile, with the breakdown that explains it.
 *
 * The two fingerprints record what the score was computed from: the pipeline rescores a pair
 * only when the profile or the company actually changed, which is what keeps a nightly run cheap.
 */
#[ORM\Entity(repositoryClass: RelevanceScoreRepository::class)]
#[ORM\Table(name: 'relevance_score')]
#[ORM\UniqueConstraint(name: 'uniq_relevance_user_company', columns: ['user_id', 'company_id'])]
#[ORM\Index(name: 'idx_relevance_user_score', columns: ['user_id', 'score'])]
final class RelevanceScore
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    #[ORM\GeneratedValue(strategy: 'CUSTOM')]
    #[ORM\CustomIdGenerator(class: UuidGenerator::class)]
    private ?Uuid $id = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private User $user;

    #[ORM\ManyToOne(targetEntity: Company::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Company $company;

    /**
     * Normalised 0-100 score, stored as an integer so ordering is exact and index-friendly.
     */
    #[ORM\Column]
    private int $score;

    /**
     * Per-criterion contributions, e.g. {"keywords": 30, "sector": 15, "location": 0}.
     *
     * @var array<string, int>
     */
    #[ORM\Column]
    private array $breakdown;

    /**
     * Translation keys explaining the score, shown as "why this company".
     *
     * @var list<string>
     */
    #[ORM\Column]
    private array $reasons;

    #[ORM\Column(length: 64)]
    private string $profileFingerprint;

    #[ORM\Column(length: 64)]
    private string $companyFingerprint;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $computedAt;

    /**
     * @param array<string, int> $breakdown
     * @param list<string>       $reasons
     */
    public function __construct(
        User $user,
        Company $company,
        int $score,
        array $breakdown,
        array $reasons,
        string $profileFingerprint,
        string $companyFingerprint,
        ?\DateTimeImmutable $computedAt = null,
    ) {
        $this->user = $user;
        $this->company = $company;
        $this->score = $score;
        $this->breakdown = $breakdown;
        $this->reasons = $reasons;
        $this->profileFingerprint = $profileFingerprint;
        $this->companyFingerprint = $companyFingerprint;
        $this->computedAt = $computedAt ?? new \DateTimeImmutable();
    }

    public function getId(): ?Uuid
    {
        return $this->id;
    }

    public function getUser(): User
    {
        return $this->user;
    }

    public function getCompany(): Company
    {
        return $this->company;
    }

    public function getScore(): int
    {
        return $this->score;
    }

    /** @return array<string, int> */
    public function getBreakdown(): array
    {
        return $this->breakdown;
    }

    /** @return list<string> */
    public function getReasons(): array
    {
        return $this->reasons;
    }

    public function getProfileFingerprint(): string
    {
        return $this->profileFingerprint;
    }

    public function getCompanyFingerprint(): string
    {
        return $this->companyFingerprint;
    }

    public function getComputedAt(): \DateTimeImmutable
    {
        return $this->computedAt;
    }

    public function isStaleFor(string $profileFingerprint, string $companyFingerprint): bool
    {
        return $this->profileFingerprint !== $profileFingerprint
            || $this->companyFingerprint !== $companyFingerprint;
    }

    /**
     * @param array<string, int> $breakdown
     * @param list<string>       $reasons
     */
    public function update(
        int $score,
        array $breakdown,
        array $reasons,
        string $profileFingerprint,
        string $companyFingerprint,
        ?\DateTimeImmutable $computedAt = null,
    ): void {
        $this->score = $score;
        $this->breakdown = $breakdown;
        $this->reasons = $reasons;
        $this->profileFingerprint = $profileFingerprint;
        $this->companyFingerprint = $companyFingerprint;
        $this->computedAt = $computedAt ?? new \DateTimeImmutable();
    }
}
