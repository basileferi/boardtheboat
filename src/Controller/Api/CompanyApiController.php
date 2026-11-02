<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Dto\CompanyInput;
use App\Entity\Company;
use App\Entity\User;
use App\Repository\CompanyRepository;
use App\Security\Voter\OwnedEntityVoter;
use App\Service\Company\CompanyManager;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * l'api json des entreprises (cours 9 / activité 7) : liste, détail, création, modification,
 * suppression. on s'authentifie avec un jeton jwt (cours 15), pas avec la session.
 *
 * - en sortie, $this->json() sérialise avec le groupe "company:read" : seuls les champs marqués
 *   #[Groups] partent, jamais le propriétaire ni les notes privées ;
 * - en entrée, MapRequestPayload désérialise le json dans un CompanyInput et le valide
 *   (mêmes contraintes que le formulaire, SafeUrl compris) : 422 avec les erreurs sinon.
 */
#[Route('/api/companies')]
#[IsGranted('ROLE_USER')]
final class CompanyApiController extends AbstractController
{
    private const array READ = ['groups' => ['company:read']];

    public function __construct(private readonly CompanyManager $manager)
    {
    }

    #[Route('', name: 'api_company_index', methods: ['GET'])]
    public function index(Request $request, CompanyRepository $companies): JsonResponse
    {
        $query = trim($request->query->getString('q'));

        return $this->json(
            $companies->searchVisibleTo($this->currentUser(), '' === $query ? null : $query, $request->query->getBoolean('mine')),
            context: self::READ,
        );
    }

    #[Route('/{id}', name: 'api_company_show', requirements: ['id' => '[0-9a-f-]{36}'], methods: ['GET'])]
    public function show(#[MapEntity(mapping: ['id' => 'id'])] Company $company): JsonResponse
    {
        $this->denyAccessUnlessGranted(OwnedEntityVoter::VIEW, $company);

        return $this->json($company, context: self::READ);
    }

    #[Route('', name: 'api_company_create', methods: ['POST'])]
    public function create(#[MapRequestPayload] CompanyInput $input, UrlGeneratorInterface $urls): JsonResponse
    {
        $company = $this->manager->create($input, $this->currentUser());

        return $this->json($company, Response::HTTP_CREATED, [
            'Location' => $urls->generate('api_company_show', ['id' => $company->getId()], UrlGeneratorInterface::ABSOLUTE_URL),
        ], self::READ);
    }

    #[Route('/{id}', name: 'api_company_update', requirements: ['id' => '[0-9a-f-]{36}'], methods: ['PUT'])]
    public function update(#[MapEntity(mapping: ['id' => 'id'])] Company $company, #[MapRequestPayload] CompanyInput $input): JsonResponse
    {
        $this->denyAccessUnlessGranted(OwnedEntityVoter::EDIT, $company);
        $this->manager->update($company, $input);

        return $this->json($company, context: self::READ);
    }

    #[Route('/{id}', name: 'api_company_delete', requirements: ['id' => '[0-9a-f-]{36}'], methods: ['DELETE'])]
    public function delete(#[MapEntity(mapping: ['id' => 'id'])] Company $company): Response
    {
        $this->denyAccessUnlessGranted(OwnedEntityVoter::DELETE, $company);
        $this->manager->delete($company);

        return new Response(status: Response::HTTP_NO_CONTENT);
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
