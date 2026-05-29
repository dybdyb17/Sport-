<?php

namespace App\Controller;

use App\Repository\CoachRepository;
use App\Service\FoundingOfferService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class HomeController extends AbstractController
{
    #[Route('/', name: 'app_home')]
    public function index(CoachRepository $coachRepository, FoundingOfferService $foundingOfferService): Response
    {
        return $this->render('home/index.html.twig', [
            'coaches'       => $coachRepository->findAll(),
            'foundingOffer' => $foundingOfferService->getActive(),
        ]);
    }
}
