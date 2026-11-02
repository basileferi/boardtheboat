<?php

declare(strict_types=1);

namespace App\Service\Company;

use App\Dto\CompanyInput;
use App\Entity\Company;
use App\Entity\User;
use App\Enum\CompanyVisibility;
use App\Message\EnrichCompanyMessage;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * la logique des entreprises ajoutées à la main, pour que le contrôleur reste fin :
 * créer, modifier, proposer au catalogue, supprimer.
 *
 * une entreprise créée par un utilisateur est privée. son site n'est jamais lu pendant la
 * requête : on envoie un message, et le worker va vérifier la page carrières plus tard.
 */
final readonly class CompanyManager
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private MessageBusInterface $bus,
    ) {
    }

    public function create(CompanyInput $input, User $owner): Company
    {
        $company = new Company($input->name, $owner);
        $this->apply($company, $input);

        $this->entityManager->persist($company);
        $this->entityManager->flush();

        $this->checkLater($company);

        return $company;
    }

    public function update(Company $company, CompanyInput $input): void
    {
        $urlsChanged = $company->getWebsite() !== $input->website || $company->getCareerUrl() !== $input->careerUrl;

        $this->apply($company, $input);
        $this->entityManager->flush();

        if ($urlsChanged) {
            $this->checkLater($company);
        }
    }

    /**
     * le propriétaire la propose au catalogue partagé ; un admin validera ensuite.
     */
    public function suggest(Company $company): void
    {
        if (CompanyVisibility::Private === $company->getVisibility()) {
            $company->setVisibility(CompanyVisibility::Suggested);
            $this->entityManager->flush();
        }
    }

    public function delete(Company $company): void
    {
        // les candidatures liées partent avec (ON DELETE CASCADE en base)
        $this->entityManager->remove($company);
        $this->entityManager->flush();
    }

    private function apply(Company $company, CompanyInput $input): void
    {
        $company->setName($input->name);
        $company->setWebsite($input->website);
        $company->setCareerUrl($input->careerUrl);
        $company->setCity($input->city);
        $company->setCountry($input->country);
        $company->setSector($input->sector);
        $company->setSize($input->size);
        $company->setSiren($input->siren);
        $company->setNotes($input->notes);
        $company->setWhyInteresting($input->whyInteresting);
    }

    private function checkLater(Company $company): void
    {
        if (null !== $company->getWebsite() || null !== $company->getCareerUrl()) {
            $this->bus->dispatch(new EnrichCompanyMessage((string) $company->getId()));
        }
    }
}
