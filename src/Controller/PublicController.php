<?php

namespace App\Controller;

use App\Repository\CoachRepository;
use App\Service\FoundingOfferService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class PublicController extends AbstractController
{
    #[Route('/coachs', name: 'app_coachs_list', methods: ['GET'])]
    public function coachs(CoachRepository $repo): Response
    {
        $coaches = $repo->findAllWithUser();

        usort($coaches, static function ($a, $b): int {
            return (int) $b->isAvailableTonightNow() <=> (int) $a->isAvailableTonightNow();
        });

        $availableTonightCount = 0;
        $onlineCount = 0;
        $specialties = [];

        foreach ($coaches as $coach) {
            if ($coach->isAvailableTonightNow()) {
                ++$availableTonightCount;
            }
            if ($coach->isOnline()) {
                ++$onlineCount;
            }
            foreach ($coach->getSpecialties() as $specialty) {
                $specialties[$specialty] = ucfirst((string) $specialty);
            }
        }
        asort($specialties);

        return $this->render('public/coachs.html.twig', [
            'coaches'                => $coaches,
            'availableTonightCount' => $availableTonightCount,
            'onlineCount'           => $onlineCount,
            'specialties'           => $specialties,
        ]);
    }

    #[Route('/tarifs', name: 'app_tarifs', methods: ['GET'])]
    public function tarifs(FoundingOfferService $foundingOfferService): Response
    {
        return $this->render('public/tarifs_v2.html.twig', [
            'deciplusSlug'  => $this->getParameter('deciplus_slug'),
            'foundingOffer' => $foundingOfferService->getActive(),
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
}
