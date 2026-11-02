<?php

declare(strict_types=1);

namespace App\Entity;

use App\Entity\Concerns\TimestampableTrait;
use App\Enum\AppLocale;
use App\Enum\TemplateKind;
use App\Repository\DocumentTemplateRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\IdGenerator\UuidGenerator;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * A reusable letter, CV variant or follow-up email owned by one user.
 *
 * Content is plain text with {placeholders} resolved at render time ({company}, {role},
 * {appliedAt}); it is never rendered as HTML, so a template cannot inject markup.
 */
#[ORM\Entity(repositoryClass: DocumentTemplateRepository::class)]
#[ORM\Table(name: 'document_template')]
#[ORM\UniqueConstraint(name: 'uniq_template_owner_kind_name', columns: ['owner_id', 'kind', 'name'])]
#[ORM\Index(name: 'idx_template_owner_kind', columns: ['owner_id', 'kind'])]
#[ORM\HasLifecycleCallbacks]
final class DocumentTemplate
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

    #[ORM\Column(length: 20, enumType: TemplateKind::class)]
    private TemplateKind $kind;

    #[ORM\Column(length: 120)]
    #[Assert\NotBlank]
    #[Assert\Length(max: 120)]
    private string $name;

    #[ORM\Column(length: 5, enumType: AppLocale::class)]
    private AppLocale $language = AppLocale::French;

    #[ORM\Column(type: 'text')]
    #[Assert\NotBlank]
    #[Assert\Length(max: 20000)]
    private string $content = '';

    public function __construct(User $owner, TemplateKind $kind, string $name)
    {
        $this->owner = $owner;
        $this->kind = $kind;
        $this->name = $name;
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

    public function getKind(): TemplateKind
    {
        return $this->kind;
    }

    public function setKind(TemplateKind $kind): void
    {
        $this->kind = $kind;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function setName(string $name): void
    {
        $this->name = $name;
    }

    public function getLanguage(): AppLocale
    {
        return $this->language;
    }

    public function setLanguage(AppLocale $language): void
    {
        $this->language = $language;
    }

    public function getContent(): string
    {
        return $this->content;
    }

    public function setContent(string $content): void
    {
        $this->content = $content;
    }
}
