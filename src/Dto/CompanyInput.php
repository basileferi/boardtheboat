<?php

declare(strict_types=1);

namespace App\Dto;

use App\Entity\Company;
use App\Enum\CompanySize;
use App\Validator\SafeUrl;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * ce que le formulaire "ajouter une entreprise" a le droit de remplir. le formulaire écrit
 * dans cet objet, puis CompanyManager recopie dans l'entité : l'utilisateur ne peut donc jamais
 * toucher au propriétaire, à la visibilité ou à la source.
 */
final class CompanyInput
{
    #[Assert\NotBlank(message: 'company.name.required')]
    #[Assert\Length(max: 200)]
    public string $name = '';

    #[Assert\Url(message: 'company.url.invalid', requireTld: true)]
    #[SafeUrl]
    #[Assert\Length(max: 255)]
    public ?string $website = null;

    #[Assert\Url(message: 'company.url.invalid', requireTld: true)]
    #[SafeUrl]
    #[Assert\Length(max: 500)]
    public ?string $careerUrl = null;

    #[Assert\Length(max: 120)]
    public ?string $city = null;

    #[Assert\Country]
    public ?string $country = 'FR';

    #[Assert\Length(max: 120)]
    public ?string $sector = null;

    public CompanySize $size = CompanySize::Unknown;

    #[Assert\Regex(pattern: '/^\d{9}$/', message: 'company.siren.invalid')]
    public ?string $siren = null;

    #[Assert\Length(max: 5000)]
    public ?string $notes = null;

    #[Assert\Length(max: 2000)]
    public ?string $whyInteresting = null;

    public static function fromCompany(Company $company): self
    {
        $input = new self();
        $input->name = $company->getName();
        $input->website = $company->getWebsite();
        $input->careerUrl = $company->getCareerUrl();
        $input->city = $company->getCity();
        $input->country = $company->getCountry();
        $input->sector = $company->getSector();
        $input->size = $company->getSize();
        $input->siren = $company->getSiren();
        $input->notes = $company->getNotes();
        $input->whyInteresting = $company->getWhyInteresting();

        return $input;
    }
}
