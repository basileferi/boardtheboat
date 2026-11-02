<?php

declare(strict_types=1);

namespace App\Entity;

use App\Entity\Concerns\TimestampableTrait;
use App\Enum\ContractType;
use App\Enum\OfferStatus;
use App\Enum\WorkMode;
use App\Repository\JobOfferRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\IdGenerator\UuidGenerator;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * A published position attached to a company.
 *
 * Rows are upserted on (source, externalId), which is what makes the ingestion handlers
 * idempotent: replaying a batch updates rows instead of duplicating them.
 *
 * The full-text index on (title, description) is the one the keyword search uses through
 * the MATCH_AGAINST DQL function; it only exists on MySQL/MariaDB, which is the only
 * engine this project supports (see docs/adr/0002).
 */
#[ORM\Entity(repositoryClass: JobOfferRepository::class)]
#[ORM\Table(name: 'job_offer')]
#[ORM\Index(name: 'idx_offer_status_published', columns: ['status', 'published_at'])]
#[ORM\Index(name: 'idx_offer_company', columns: ['company_id'])]
#[ORM\Index(name: 'idx_offer_country_contract', columns: ['country', 'contract_type'])]
#[ORM\Index(name: 'ft_offer_title_description', columns: ['title', 'description'], flags: ['fulltext'])]
#[ORM\UniqueConstraint(name: 'uniq_offer_source_external', columns: ['source', 'external_id'])]
#[ORM\HasLifecycleCallbacks]
final class JobOffer
{
    use TimestampableTrait;

    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    #[ORM\GeneratedValue(strategy: 'CUSTOM')]
    #[ORM\CustomIdGenerator(class: UuidGenerator::class)]
    #[Groups(['offer:read'])]
    private ?Uuid $id = null;

    #[ORM\ManyToOne(targetEntity: Company::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    #[Groups(['offer:read'])]
    private Company $company;

    #[ORM\Column(length: 255)]
    #[Assert\NotBlank]
    #[Assert\Length(max: 255)]
    #[Groups(['offer:read'])]
    private string $title;

    /**
     * Sanitised excerpt of the source description: third-party HTML never reaches storage raw.
     */
    #[ORM\Column(type: 'text')]
    private string $description = '';

    #[ORM\Column(length: 180, nullable: true)]
    #[Groups(['offer:read'])]
    private ?string $location = null;

    #[ORM\Column(length: 2, nullable: true)]
    #[Assert\Country(alpha3: false)]
    #[Groups(['offer:read'])]
    private ?string $country = 'FR';

    #[ORM\Column(length: 16, enumType: WorkMode::class)]
    #[Groups(['offer:read'])]
    private WorkMode $workMode = WorkMode::Unknown;

    #[ORM\Column(length: 20, enumType: ContractType::class)]
    #[Groups(['offer:read'])]
    private ContractType $contractType = ContractType::Other;

    #[ORM\Column(length: 16, enumType: OfferStatus::class)]
    #[Groups(['offer:read'])]
    private OfferStatus $status = OfferStatus::Open;

    #[ORM\Column(length: 40)]
    #[Groups(['offer:read'])]
    private string $source;

    #[ORM\Column(length: 120)]
    private string $externalId;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    #[Groups(['offer:read'])]
    private ?\DateTimeImmutable $publishedAt = null;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $expiresAt = null;

    #[ORM\Column(length: 1000)]
    #[Assert\Url(requireTld: true)]
    #[Groups(['offer:read'])]
    private string $url;

    #[ORM\Column(length: 180, nullable: true)]
    private ?string $salaryText = null;

    public function __construct(Company $company, string $source, string $externalId, string $title, string $url)
    {
        $this->company = $company;
        $this->source = $source;
        $this->externalId = $externalId;
        $this->title = $title;
        $this->url = $url;
        $this->initialiseTimestamps();
    }

    public function getId(): ?Uuid
    {
        return $this->id;
    }

    public function getCompany(): Company
    {
        return $this->company;
    }

    public function setCompany(Company $company): void
    {
        $this->company = $company;
    }

    public function getTitle(): string
    {
        return $this->title;
    }

    public function setTitle(string $title): void
    {
        $this->title = $title;
    }

    public function getDescription(): string
    {
        return $this->description;
    }

    public function setDescription(string $description): void
    {
        $this->description = $description;
    }

    public function getLocation(): ?string
    {
        return $this->location;
    }

    public function setLocation(?string $location): void
    {
        $this->location = $location;
    }

    public function getCountry(): ?string
    {
        return $this->country;
    }

    public function setCountry(?string $country): void
    {
        $this->country = $country;
    }

    public function getWorkMode(): WorkMode
    {
        return $this->workMode;
    }

    public function setWorkMode(WorkMode $workMode): void
    {
        $this->workMode = $workMode;
    }

    public function getContractType(): ContractType
    {
        return $this->contractType;
    }

    public function setContractType(ContractType $contractType): void
    {
        $this->contractType = $contractType;
    }

    public function getStatus(): OfferStatus
    {
        return $this->status;
    }

    public function setStatus(OfferStatus $status): void
    {
        $this->status = $status;
    }

    public function getSource(): string
    {
        return $this->source;
    }

    public function getExternalId(): string
    {
        return $this->externalId;
    }

    public function getPublishedAt(): ?\DateTimeImmutable
    {
        return $this->publishedAt;
    }

    public function setPublishedAt(?\DateTimeImmutable $publishedAt): void
    {
        $this->publishedAt = $publishedAt;
    }

    public function getExpiresAt(): ?\DateTimeImmutable
    {
        return $this->expiresAt;
    }

    public function setExpiresAt(?\DateTimeImmutable $expiresAt): void
    {
        $this->expiresAt = $expiresAt;
    }

    public function getUrl(): string
    {
        return $this->url;
    }

    public function setUrl(string $url): void
    {
        $this->url = $url;
    }

    public function getSalaryText(): ?string
    {
        return $this->salaryText;
    }

    public function setSalaryText(?string $salaryText): void
    {
        $this->salaryText = $salaryText;
    }
}
