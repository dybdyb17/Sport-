<?php

namespace App\Controller\Admin;

use App\Entity\FoundingClaim;
use App\Repository\FoundingClaimRepository;
use App\Repository\FoundingOfferRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/admin/fondateurs')]
#[IsGranted('ROLE_ADMIN')]
class AdminFoundingController extends AbstractController
{
    #[Route('', name: 'app_admin_founding_list', methods: ['GET'])]
    public function list(FoundingOfferRepository $offerRepository, FoundingClaimRepository $claimRepository): Response
    {
        $offer = $offerRepository->findCurrent();

        return $this->render('admin/founding/list.html.twig', [
            'offer' => $offer,
            'claims' => $claimRepository->findAllWithUser(),
            'stats' => $claimRepository->getStats($offer),
        ]);
    }

    #[Route('/{id}/bilan', name: 'app_admin_founding_bilan_done', methods: ['POST'])]
    public function markBilanDone(FoundingClaim $claim, Request $request, EntityManagerInterface $em): Response
    {
        if (!$this->isCsrfTokenValid('founding_bilan' . $claim->getId(), (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Token CSRF invalide.');
        }

        $claim->setBilanDone(true);
        $em->flush();
        $this->addFlash('success', 'Bilan fondateur marqué comme effectué.');

        return $this->redirectToRoute('app_admin_founding_list');
    }

    #[Route('/offre/toggle', name: 'app_admin_founding_toggle', methods: ['POST'])]
    public function toggleOffer(Request $request, FoundingOfferRepository $offerRepository, EntityManagerInterface $em): Response
    {
        $offer = $offerRepository->findCurrent();
        if (!$offer) {
            throw $this->createNotFoundException('Offre Founding introuvable.');
        }
        if (!$this->isCsrfTokenValid('founding_toggle' . $offer->getId(), (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Token CSRF invalide.');
        }

        $offer->setIsActive(!$offer->isActive());
        $em->flush();
        $this->addFlash('success', $offer->isActive() ? 'Offre Founding activée.' : 'Offre Founding désactivée.');

        return $this->redirectToRoute('app_admin_founding_list');
    }
}
