<?php

declare(strict_types=1);

namespace App\Entity;

use App\Entity\Concerns\TimestampableTrait;
use App\Enum\CareerPageStatus;
use App\Enum\CompanySize;
use App\Enum\CompanyVisibility;
use App\Repository\CompanyRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\IdGenerator\UuidGenerator;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * An employer we may contact, whether it published an offer or not.
 *
 * A company is either global (owner null: imported from a public source or promoted to the
 * catalogue by an admin) or private to the user who typed it in. Deduplication uses, in order,
 * the SIREN, then the website domain, then the normalised name — all three are stored so the
 * matching never has to recompute them at query time.
 */
#[ORM\Entity(repositoryClass: CompanyRepository::class)]
#[ORM\Table(name: 'company')]
#[ORM\Index(name: 'idx_company_owner_visibility', columns: ['owner_id', 'visibility'])]
#[ORM\Index(name: 'idx_company_normalized_name', columns: ['normalized_name'])]
#[ORM\Index(name: 'idx_company_domain', columns: ['domain'])]
#[ORM\Index(name: 'idx_company_career_status', columns: ['career_page_status', 'last_checked_at'])]
#[ORM\UniqueConstraint(name: 'uniq_company_source_external', columns: ['source', 'external_id'])]
#[ORM\HasLifecycleCallbacks]
final class Company
{
    use TimestampableTrait;

    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    #[ORM\GeneratedValue(strategy: 'CUSTOM')]
    #[ORM\CustomIdGenerator(class: UuidGenerator::class)]
    #[Groups(['company:read', 'offer:read'])]
    private ?Uuid $id = null;

    /**
     * Null means the row is global: imported from a public source, or promoted by an admin.
     */
    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'CASCADE')]
    private ?User $owner = null;

    #[ORM\Column(length: 16, enumType: CompanyVisibility::class)]
    #[Groups(['company:read'])]
    private CompanyVisibility $visibility = CompanyVisibility::Catalogue;

    #[ORM\Column(length: 200)]
    #[Assert\NotBlank]
    #[Assert\Length(max: 200)]
    #[Groups(['company:read', 'offer:read'])]
    private string $name;

    /**
     * Lowercase, accent-free, legal-form-free version of the name, used for fuzzy matching.
     */
    #[ORM\Column(length: 200)]
    private string $normalizedName = '';

    #[ORM\Column(length: 9, nullable: true)]
    #[Assert\Regex(pattern: '/^\d{9}$/', message: 'company.siren.invalid')]
    #[Groups(['company:read'])]
    private ?string $siren = null;

    #[ORM\Column(length: 255, nullable: true)]
    #[Assert\Url(requireTld: true)]
    #[Assert\Length(max: 255)]
    #[Groups(['company:read', 'offer:read'])]
    private ?string $website = null;

    /**
     * Registrable host extracted from the website, e.g. "example.com". Deduplication key.
     */
    #[ORM\Column(length: 180, nullable: true)]
    private ?string $domain = null;

    #[ORM\Column(length: 500, nullable: true)]
    #[Assert\Url(requireTld: true)]
    #[Assert\Length(max: 500)]
    #[Groups(['company:read'])]
    private ?string $careerUrl = null;

    #[ORM\Column(length: 120, nullable: true)]
    #[Groups(['company:read', 'offer:read'])]
    private ?string $sector = null;

    /**
     * INSEE activity code, e.g. "62.01Z".
     */
    #[ORM\Column(length: 8, nullable: true)]
    private ?string $nafCode = null;

    #[ORM\Column(length: 16, enumType: CompanySize::class)]
    #[Groups(['company:read'])]
    private CompanySize $size = CompanySize::Unknown;

    #[ORM\Column(length: 120, nullable: true)]
    #[Groups(['company:read', 'offer:read'])]
    private ?string $city = null;

    #[ORM\Column(length: 2, nullable: true)]
    #[Assert\Country(alpha3: false)]
    #[Groups(['company:read'])]
    private ?string $country = 'FR';

    /**
     * Identifier of the adapter that produced the row ("manual" for a user-typed company).
     */
    #[ORM\Column(length: 40)]
    private string $source = 'manual';

    #[ORM\Column(length: 120, nullable: true)]
    private ?string $externalId = null;

    #[ORM\Column(length: 16, enumType: CareerPageStatus::class)]
    private CareerPageStatus $careerPageStatus = CareerPageStatus::Pending;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $lastCheckedAt = null;

    #[ORM\Column(type: 'text', nullable: true)]
    #[Assert\Length(max: 5000)]
    private ?string $notes = null;

    /**
     * The owner's own words on why this company is interesting. Reused as a letter hook.
     */
    #[ORM\Column(type: 'text', nullable: true)]
    #[Assert\Length(max: 2000)]
    private ?string $whyInteresting = null;

    #[ORM\Column(length: 64)]
    private string $fingerprint = '';

    public function __construct(string $name, ?User $owner = null)
    {
        $this->name = $name;
        $this->owner = $owner;
        $this->visibility = null === $owner ? CompanyVisibility::Catalogue : CompanyVisibility::Private;
        $this->initialiseTimestamps();
        $this->refreshDerivedFields();
    }

    public function getId(): ?Uuid
    {
        return $this->id;
    }

    public function getOwner(): ?User
    {
        return $this->owner;
    }

    public function isGlobal(): bool
    {
        return null === $this->owner;
    }

    public function getVisibility(): CompanyVisibility
    {
        return $this->visibility;
    }

    public function setVisibility(CompanyVisibility $visibility): void
    {
        $this->visibility = $visibility;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function setName(string $name): void
    {
        $this->name = $name;
        $this->refreshDerivedFields();
    }

    public function getNormalizedName(): string
    {
        return $this->normalizedName;
    }

    public function getSiren(): ?string
    {
        return $this->siren;
    }

    public function setSiren(?string $siren): void
    {
        $this->siren = $siren;
    }

    public function getWebsite(): ?string
    {
        return $this->website;
    }

    public function setWebsite(?string $website): void
    {
        $this->website = $website;
        $this->refreshDerivedFields();
    }

    public function getDomain(): ?string
    {
        return $this->domain;
    }

    public function getCareerUrl(): ?string
    {
        return $this->careerUrl;
    }

    public function setCareerUrl(?string $careerUrl): void
    {
        $this->careerUrl = $careerUrl;
    }

    public function getSector(): ?string
    {
        return $this->sector;
    }

    public function setSector(?string $sector): void
    {
        $this->sector = $sector;
        $this->refreshDerivedFields();
    }

    public function getNafCode(): ?string
    {
        return $this->nafCode;
    }

    public function setNafCode(?string $nafCode): void
    {
        $this->nafCode = $nafCode;
        $this->refreshDerivedFields();
    }

    public function getSize(): CompanySize
    {
        return $this->size;
    }

    public function setSize(CompanySize $size): void
    {
        $this->size = $size;
        $this->refreshDerivedFields();
    }

    public function getCity(): ?string
    {
        return $this->city;
    }

    public function setCity(?string $city): void
    {
        $this->city = $city;
        $this->refreshDerivedFields();
    }

    public function getCountry(): ?string
    {
        return $this->country;
    }

    public function setCountry(?string $country): void
    {
        $this->country = $country;
        $this->refreshDerivedFields();
    }

    public function getSource(): string
    {
        return $this->source;
    }

    public function getExternalId(): ?string
    {
        return $this->externalId;
    }

    public function setSourceReference(string $source, ?string $externalId): void
    {
        $this->source = $source;
        $this->externalId = $externalId;
    }

    public function getCareerPageStatus(): CareerPageStatus
    {
        return $this->careerPageStatus;
    }

    public function markCareerPageChecked(CareerPageStatus $status): void
    {
        $this->careerPageStatus = $status;
        $this->lastCheckedAt = new \DateTimeImmutable();
    }

    public function getLastCheckedAt(): ?\DateTimeImmutable
    {
        return $this->lastCheckedAt;
    }

    public function getNotes(): ?string
    {
        return $this->notes;
    }

    public function setNotes(?string $notes): void
    {
        $this->notes = $notes;
    }

    public function getWhyInteresting(): ?string
    {
        return $this->whyInteresting;
    }

    public function setWhyInteresting(?string $whyInteresting): void
    {
        $this->whyInteresting = $whyInteresting;
    }

    public function getFingerprint(): string
    {
        return $this->fingerprint;
    }

    /**
     * Keeps the matching keys and the scoring hash in step with the editable fields.
     */
    public function refreshDerivedFields(): void
    {
        $this->normalizedName = self::normalizeName($this->name);
        $this->domain = null === $this->website ? null : self::extractDomain($this->website);
        $this->fingerprint = hash('sha256', json_encode([
            $this->normalizedName,
            $this->sector,
            $this->nafCode,
            $this->size->value,
            $this->city,
            $this->country,
        ], \JSON_THROW_ON_ERROR));
    }

    /**
     * Lowercases, strips accents, punctuation and the usual French legal forms so that
     * "SARL Dupont & Fils" and "dupont et fils" collapse onto the same key.
     */
    public static function normalizeName(string $name): string
    {
        $ascii = transliterator_transliterate('Any-Latin; Latin-ASCII; Lower()', $name);
        $ascii = \is_string($ascii) ? $ascii : mb_strtolower($name);
        $ascii = str_replace('&', ' et ', $ascii);
        $ascii = preg_replace('/[^a-z0-9]+/', ' ', $ascii) ?? '';
        $ascii = preg_replace('/\b(sarl|sas|sasu|sa|eurl|snc|sci|scop|gie|ltd|llc|inc|gmbh|bv|spa|srl|plc)\b/', ' ', $ascii) ?? '';

        return trim(preg_replace('/\s+/', ' ', $ascii) ?? '');
    }

    /**
     * Returns the lowercase host of a URL without a leading "www.", or null when unusable.
     */
    public static function extractDomain(string $website): ?string
    {
        $host = parse_url(str_contains($website, '//') ? $website : 'https://'.$website, \PHP_URL_HOST);
        if (!\is_string($host) || '' === $host) {
            return null;
        }

        $host = mb_strtolower($host);
        $host = str_starts_with($host, 'www.') ? substr($host, 4) : $host;

        // parse_url is lenient: it happily returns a "host" for a sentence. A domain we intend
        // to deduplicate on must look like one, so reject anything without a real TLD.
        if (!\is_string(filter_var($host, \FILTER_VALIDATE_DOMAIN, \FILTER_FLAG_HOSTNAME)) || !str_contains($host, '.')) {
            return null;
        }

        return $host;
    }
}
