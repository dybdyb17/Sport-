<?php

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class SitemapController extends AbstractController
{
    #[Route('/sitemap.xml', name: 'app_sitemap', defaults: ['_format' => 'xml'], methods: ['GET'])]
    public function index(): Response
    {
        $routes = [
            ['name' => 'app_home',                     'priority' => '1.0', 'changefreq' => 'weekly'],
            ['name' => 'app_about',                    'priority' => '0.8', 'changefreq' => 'monthly'],
            ['name' => 'app_concept',                  'priority' => '0.8', 'changefreq' => 'monthly'],
            ['name' => 'app_tarifs',                   'priority' => '0.9', 'changefreq' => 'monthly'],
            ['name' => 'app_coachs_list',              'priority' => '0.8', 'changefreq' => 'monthly'],
            ['name' => 'app_faq',                      'priority' => '0.6', 'changefreq' => 'monthly'],
            ['name' => 'app_contact',                  'priority' => '0.6', 'changefreq' => 'yearly'],
            ['name' => 'app_founding_detail',          'priority' => '0.9', 'changefreq' => 'weekly'],
            ['name' => 'app_legal_mentions',           'priority' => '0.3', 'changefreq' => 'yearly'],
            ['name' => 'app_legal_cgv',                'priority' => '0.3', 'changefreq' => 'yearly'],
            ['name' => 'app_legal_confidentialite',    'priority' => '0.3', 'changefreq' => 'yearly'],
            ['name' => 'app_legal_cookies',            'priority' => '0.3', 'changefreq' => 'yearly'],
        ];

        $response = $this->render('sitemap/index.xml.twig', [
            'routes'  => $routes,
            'lastmod' => (new \DateTimeImmutable())->format('Y-m-d'),
        ]);

        $response->headers->set('Content-Type', 'application/xml; charset=UTF-8');

        return $response;
    }
}
