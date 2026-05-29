<?php

namespace App\Controller;

use App\Repository\CoachRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

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
        return $this->render('public/tarifs.html.twig');
    }

    #[Route('/faq', name: 'app_faq', methods: ['GET'])]
    public function faq(): Response
    {
        return $this->render('public/faq.html.twig');
    }

    #[Route('/contact', name: 'app_contact', methods: ['GET'])]
    public function contact(): Response
    {
        return $this->render('public/contact.html.twig');
    }
}
