<?php

namespace App\Controller\Admin;

use App\Repository\FoundingClaimRepository;
use App\Repository\FoundingOfferRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/admin')]
#[IsGranted('ROLE_ADMIN')]
class AdminFoundingController extends AbstractController
{
    #[Route('/fondateurs', name: 'app_admin_founding_list', methods: ['GET'])]
    public function list(
        FoundingClaimRepository $claimRepo,
        FoundingOfferRepository $offerRepo,
    ): Response {
        $offer  = $offerRepo->findCurrent() ?? $offerRepo->findOneBy([], ['createdAt' => 'DESC']);
        $claims = $claimRepo->findAllWithUser();
        $stats  = $offer !== null ? $claimRepo->getStats($offer) : null;

        return $this->render('admin/founding/list.html.twig', [
            'offer'  => $offer,
            'claims' => $claims,
            'stats'  => $stats,
        ]);
    }

    #[Route('/fondateurs/{id}/bilan-done', name: 'app_admin_founding_bilan_done', methods: ['POST'])]
    public function markBilanDone(
        int $id,
        Request $request,
        FoundingClaimRepository $claimRepo,
        EntityManagerInterface $em,
        CsrfTokenManagerInterface $csrf,
    ): Response {
        $token = new CsrfToken('bilan_done_' . $id, (string) $request->request->get('_token'));
        if (!$csrf->isTokenValid($token)) {
            throw $this->createAccessDeniedException('Token CSRF invalide.');
        }

        $claim = $claimRepo->find($id);
        if ($claim === null) {
            throw $this->createNotFoundException('Claim introuvable.');
        }

        if (!$claim->isBilanDone()) {
            $claim->setBilanDoneAt(new \DateTimeImmutable());
            $em->flush();
            $this->addFlash('success', sprintf('Bilan marqué comme effectué pour %s #%02d.', $claim->getUser()->getNomComplet(), $claim->getClaimNumber()));
        }

        return $this->redirectToRoute('app_admin_founding_list');
    }

    #[Route('/fondateurs/toggle-offer', name: 'app_admin_founding_toggle', methods: ['POST'])]
    public function toggleOfferActive(
        Request $request,
        FoundingOfferRepository $offerRepo,
        EntityManagerInterface $em,
        CsrfTokenManagerInterface $csrf,
    ): Response {
        $token = new CsrfToken('toggle_offer', (string) $request->request->get('_token'));
        if (!$csrf->isTokenValid($token)) {
            throw $this->createAccessDeniedException('Token CSRF invalide.');
        }

        $offer = $offerRepo->findOneBy([], ['createdAt' => 'DESC']);
        if ($offer === null) {
            $this->addFlash('error', 'Aucune offre Founding trouvée.');
            return $this->redirectToRoute('app_admin_founding_list');
        }

        $offer->setIsActive(!$offer->isActive());
        $em->flush();

        $state = $offer->isActive() ? 'activée' : 'désactivée';
        $this->addFlash('success', sprintf('Offre Founding Member "%s" %s.', $offer->getTitle(), $state));

        return $this->redirectToRoute('app_admin_founding_list');
    }
}
