<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Profile;
use App\Entity\User;
use App\Form\ProfileType;
use App\Security\Voter\OwnedEntityVoter;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * The profile the whole automatic mode is matched against.
 *
 * An account always has one (AccountCreator makes it), so this is an edit screen rather than
 * a create-or-edit one. The voter is checked even though the profile is read from the token:
 * the rule lives in one place and stays true if this ever takes an id.
 */
#[Route('/{_locale}/profile', requirements: ['_locale' => 'fr|en'], defaults: ['_locale' => 'fr'])]
#[IsGranted('ROLE_USER')]
final class ProfileController extends AbstractController
{
    #[Route('', name: 'app_profile', methods: ['GET', 'POST'])]
    public function edit(Request $request, EntityManagerInterface $entityManager): Response
    {
        $profile = $this->currentProfile($entityManager);
        $this->denyAccessUnlessGranted(OwnedEntityVoter::EDIT, $profile);

        $form = $this->createForm(ProfileType::class, $profile);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $entityManager->flush();
            $this->addFlash('success', 'profile.saved');

            // The scores stored for this user are now stale; the recommendations page
            // notices through the fingerprint and recomputes what it needs.
            return $this->redirectToRoute('app_recommendations');
        }

        return $this->render('profile/edit.html.twig', ['form' => $form]);
    }

    private function currentProfile(EntityManagerInterface $entityManager): Profile
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            throw $this->createAccessDeniedException();
        }

        $profile = $user->getProfile();
        if (null === $profile) {
            // Defensive: an account created before profiles existed, or repaired by hand.
            $profile = new Profile($user);
            $entityManager->persist($profile);
            $entityManager->flush();
        }

        return $profile;
    }
}
