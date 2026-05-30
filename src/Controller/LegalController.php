<?php

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class LegalController extends AbstractController
{
    #[Route('/mentions-legales', name: 'app_legal_mentions', methods: ['GET'])]
    public function mentions(): Response
    {
        return $this->render('legal/mentions.html.twig');
    }

    #[Route('/cgv', name: 'app_legal_cgv', methods: ['GET'])]
    public function cgv(): Response
    {
        return $this->render('legal/cgv.html.twig');
    }

    #[Route('/politique-de-confidentialite', name: 'app_legal_confidentialite', methods: ['GET'])]
    public function confidentialite(): Response
    {
        return $this->render('legal/confidentialite.html.twig');
    }

    #[Route('/politique-cookies', name: 'app_legal_cookies', methods: ['GET'])]
    public function cookies(): Response
    {
        return $this->render('legal/cookies.html.twig');
    }
}
