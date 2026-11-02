<?php

declare(strict_types=1);

namespace App\Controller;

use App\Dto\CompanyInput;
use App\Entity\Company;
use App\Entity\User;
use App\Form\CompanyType;
use App\Repository\CompanyRepository;
use App\Security\Voter\OwnedEntityVoter;
use App\Service\Company\CompanyManager;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\UX\Turbo\TurboBundle;

/**
 * crud des entreprises ajoutées à la main (squelette make:crud, puis réécrit) :
 * liste, fiche, ajout, modification, suppression en DELETE.
 *
 * le contrôleur reste fin : il valide, demande au voter, et délègue à CompanyManager.
 */
#[Route('/{_locale}/companies', requirements: ['_locale' => 'fr|en'], defaults: ['_locale' => 'fr'])]
#[IsGranted('ROLE_USER')]
final class CompanyController extends AbstractController
{
    public function __construct(private readonly CompanyManager $manager)
    {
    }

    #[Route('', name: 'app_company_index', methods: ['GET'])]
    public function index(Request $request, CompanyRepository $companies): Response
    {
        $query = trim($request->query->getString('q'));
        $mine = $request->query->getBoolean('mine');

        return $this->render('company/index.html.twig', [
            'companies' => $companies->searchVisibleTo($this->currentUser(), '' === $query ? null : $query, $mine),
            'query' => $query,
            'mine' => $mine,
        ]);
    }

    #[Route('/new', name: 'app_company_new', methods: ['GET', 'POST'])]
    public function new(Request $request): Response
    {
        $input = new CompanyInput();
        $form = $this->createForm(CompanyType::class, $input);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $company = $this->manager->create($input, $this->currentUser());
            $this->addFlash('success', 'company.flash.created');

            return $this->redirectToRoute('app_company_show', ['id' => $company->getId()]);
        }

        return $this->render('company/new.html.twig', ['form' => $form]);
    }

    #[Route('/{id}', name: 'app_company_show', requirements: ['id' => '[0-9a-f-]{36}'], methods: ['GET'])]
    public function show(#[MapEntity(mapping: ['id' => 'id'])] Company $company): Response
    {
        $this->denyAccessUnlessGranted(OwnedEntityVoter::VIEW, $company);

        return $this->render('company/show.html.twig', ['company' => $company]);
    }

    #[Route('/{id}/edit', name: 'app_company_edit', requirements: ['id' => '[0-9a-f-]{36}'], methods: ['GET', 'POST'])]
    public function edit(Request $request, #[MapEntity(mapping: ['id' => 'id'])] Company $company): Response
    {
        $this->denyAccessUnlessGranted(OwnedEntityVoter::EDIT, $company);

        $input = CompanyInput::fromCompany($company);
        $form = $this->createForm(CompanyType::class, $input);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->manager->update($company, $input);
            $this->addFlash('success', 'company.flash.updated');

            return $this->redirectToRoute('app_company_show', ['id' => $company->getId()]);
        }

        return $this->render('company/edit.html.twig', ['company' => $company, 'form' => $form]);
    }

    #[Route('/{id}/suggest', name: 'app_company_suggest', requirements: ['id' => '[0-9a-f-]{36}'], methods: ['POST'])]
    public function suggest(Request $request, #[MapEntity(mapping: ['id' => 'id'])] Company $company): Response
    {
        $this->denyAccessUnlessGranted(OwnedEntityVoter::EDIT, $company);
        $this->assertCsrf($request, 'suggest'.$company->getId());

        $this->manager->suggest($company);
        $this->addFlash('success', 'company.flash.suggested');

        return $this->redirectToRoute('app_company_show', ['id' => $company->getId()]);
    }

    /**
     * suppression en vrai DELETE (activité 6) : le formulaire envoie POST + _method=DELETE.
     * avec turbo, on répond un stream qui retire juste la ligne de la liste.
     */
    #[Route('/{id}', name: 'app_company_delete', requirements: ['id' => '[0-9a-f-]{36}'], methods: ['DELETE'])]
    public function delete(Request $request, #[MapEntity(mapping: ['id' => 'id'])] Company $company): Response
    {
        $this->denyAccessUnlessGranted(OwnedEntityVoter::DELETE, $company);
        $this->assertCsrf($request, 'delete'.$company->getId());

        $id = (string) $company->getId();
        $this->manager->delete($company);

        if (TurboBundle::STREAM_FORMAT === $request->getPreferredFormat()) {
            $request->setRequestFormat(TurboBundle::STREAM_FORMAT);

            return $this->render('company/delete.stream.html.twig', ['id' => $id]);
        }

        $this->addFlash('success', 'company.flash.deleted');

        return $this->redirectToRoute('app_company_index', status: Response::HTTP_SEE_OTHER);
    }

    private function assertCsrf(Request $request, string $tokenId): void
    {
        if (!$this->isCsrfTokenValid($tokenId, $request->request->getString('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }
    }

    private function currentUser(): User
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            throw $this->createAccessDeniedException();
        }

        return $user;
    }
}
