<?php

namespace App\Controller;

use App\Repository\CoachRepository;
use App\Repository\FoundingClaimRepository;
use App\Service\FoundingOfferService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

class PublicController extends AbstractController
{
    #[Route('/coachs', name: 'app_coachs_list', methods: ['GET'])]
    public function coachs(CoachRepository $repo): Response
    {
        return $this->render('public/coachs.html.twig', [
            'coaches' => $repo->findAllWithUser(),
        ]);
    }

    #[Route('/tarifs', name: 'app_tarifs', methods: ['GET'])]
    public function tarifs(): Response
    {
        return $this->render('public/tarifs.html.twig', [
            'deciplusSlug' => $this->getParameter('deciplus_slug'),
        ]);
    }

    #[Route('/faq', name: 'app_faq', methods: ['GET'])]
    public function faq(): Response
    {
        return $this->render('public/faq.html.twig');
    }

    #[Route('/contact', name: 'app_contact', methods: ['GET', 'POST'])]
    public function contact(Request $request): Response
    {
        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('contact-form', $request->request->get('_token'))) {
                $this->addFlash('error', 'Token de sécurité invalide. Veuillez réessayer.');
                return $this->redirectToRoute('app_contact');
            }
            $this->addFlash('success', 'Votre message a bien été envoyé. Nous vous répondrons dans les 24h.');
            return $this->redirectToRoute('app_contact');
        }

        return $this->render('public/contact.html.twig');
    }

    #[Route('/offre/founding', name: 'app_founding_detail', methods: ['GET'])]
    public function foundingDetail(FoundingOfferService $foundingOfferService): Response
    {
        $offer = $foundingOfferService->getActive();

        return $this->render('public/founding-detail.html.twig', [
            'offer' => $offer,
        ]);
    }

    #[Route('/offre/founding/claim', name: 'app_founding_claim', methods: ['POST'])]
    #[IsGranted('ROLE_CLIENT')]
    public function foundingClaim(Request $request, FoundingOfferService $foundingOfferService): Response
    {
        if (!$this->isCsrfTokenValid('founding-claim', $request->request->get('_token'))) {
            $this->addFlash('error', 'Token de sécurité invalide. Veuillez réessayer.');
            return $this->redirectToRoute('app_founding_detail');
        }

        try {
            $claim = $foundingOfferService->claim($this->getUser());
            $this->addFlash('success', sprintf(
                'Bienvenue, %s ! Vous êtes maintenant %s. Vos 3 séances sont prêtes.',
                $this->getUser()->getNomComplet(),
                $claim->getFoundingLabel()
            ));
        } catch (\LogicException $e) {
            $this->addFlash('error', $e->getMessage());
        }

        return $this->redirectToRoute('app_founding_detail');
    }

    #[Route('/a-propos', name: 'app_about', methods: ['GET'])]
    public function about(
        FoundingOfferService $foundingOfferService,
        CoachRepository $coachRepo
    ): Response {
        return $this->render('public/about.html.twig', [
            'foundingOffer' => $foundingOfferService->getActive(),
            'coachsCount'   => $coachRepo->count([]),
        ]);
    }

    #[Route('/espace-deciplus', name: 'app_deciplus', methods: ['GET'])]
    public function deciplus(): Response
    {
        return $this->render('public/deciplus.html.twig', [
            'slug'         => $this->getParameter('deciplus_slug'),
            'codeCentre'   => $this->getParameter('deciplus_code_centre'),
        ]);
    }

    #[Route('/concept', name: 'app_concept', methods: ['GET'])]
    public function concept(): Response
    {
        return $this->render('public/concept.html.twig');
    }

    #[Route('/programmes', name: 'app_programmes', methods: ['GET'])]
    public function programmes(): Response
    {
        return $this->render('public/programmes.html.twig');
    }
}
