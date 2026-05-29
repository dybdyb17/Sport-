<?php

namespace App\Controller\Admin;

use App\Repository\SubscriptionRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/admin')]
#[IsGranted('ROLE_ADMIN')]
class AdminSubscriptionController extends AbstractController
{
    #[Route('/abonnements', name: 'app_admin_subscriptions', methods: ['GET'])]
    public function list(Request $request, SubscriptionRepository $subRepo): Response
    {
        $expiring = $request->query->getBoolean('expiring');

        if ($expiring) {
            $subscriptions = $subRepo->findExpiringSoon(7);
        } else {
            $filterPack = $request->query->get('pack');
            if ($filterPack !== null) {
                $all = $subRepo->findAllActive();
                $subscriptions = array_filter($all, fn ($s) => $s->getPackType()->value === $filterPack);
                $subscriptions = array_values($subscriptions);
            } else {
                $subscriptions = $subRepo->findAllActive();
            }
        }

        $stats = $subRepo->getGlobalStats();

        return $this->render('admin/subscriptions/list.html.twig', [
            'subscriptions' => $subscriptions,
            'stats'         => $stats,
            'filterExpiring' => $expiring,
            'filterPack'    => $request->query->get('pack'),
        ]);
    }
}
