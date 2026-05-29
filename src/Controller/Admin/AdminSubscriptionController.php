<?php

namespace App\Controller\Admin;

use App\Entity\Enum\PackType;
use App\Repository\SubscriptionRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_ADMIN')]
class AdminSubscriptionController extends AbstractController
{
    #[Route('/admin/abonnements', name: 'app_admin_subscriptions', methods: ['GET'])]
    public function list(Request $request, SubscriptionRepository $repository): Response
    {
        $subscriptions = $request->query->getBoolean('expiring')
            ? $repository->findExpiringSoon(7)
            : $repository->findAllActive();

        $pack = $request->query->get('pack');
        if ($pack) {
            $subscriptions = array_values(array_filter($subscriptions, static fn ($sub): bool => $sub->getPackType()->value === $pack));
        }

        return $this->render('admin/subscriptions/list.html.twig', [
            'subscriptions' => $subscriptions,
            'stats' => $repository->getGlobalStats(),
            'packs' => PackType::cases(),
            'currentPack' => $pack,
            'expiringOnly' => $request->query->getBoolean('expiring'),
        ]);
    }
}
