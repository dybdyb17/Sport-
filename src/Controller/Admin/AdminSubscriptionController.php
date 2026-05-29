<?php

namespace App\Controller\Admin;

use App\Entity\Subscription;
use App\Repository\SubscriptionRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/admin/abonnements')]
#[IsGranted('ROLE_ADMIN')]
class AdminSubscriptionController extends AbstractController
{
    private const VALID_STATUSES = [
        Subscription::STATUS_ACTIVE,
        Subscription::STATUS_EXPIRED,
        Subscription::STATUS_CANCELLED,
    ];

    #[Route('', name: 'app_admin_subscriptions', methods: ['GET'])]
    public function index(Request $request, SubscriptionRepository $subscriptionRepository): Response
    {
        $status   = $request->query->get('status');
        $filtered = null !== $status && in_array($status, self::VALID_STATUSES, true);

        $subscriptions = $filtered
            ? $subscriptionRepository->findRecentByStatus($status)
            : $subscriptionRepository->findAllRecent();

        return $this->render('admin/subscriptions/index.html.twig', [
            'subscriptions'      => $subscriptions,
            'activeStatus'       => $filtered ? $status : null,
            'subscriptionCounts' => $subscriptionRepository->countByStatus(),
        ]);
    }

    #[Route('/{id}/annuler', name: 'app_admin_subscription_cancel', methods: ['POST'])]
    public function cancel(
        Request $request,
        Subscription $subscription,
        EntityManagerInterface $entityManager,
    ): Response {
        if (!$this->isCsrfTokenValid('cancel_subscription_' . $subscription->getId(), (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Jeton CSRF invalide.');
        }

        if (!$subscription->isActive()) {
            $this->addFlash('error', 'Seul un abonnement actif peut être annulé.');

            return $this->redirectToRoute('app_admin_subscriptions');
        }

        $subscription->setStatus(Subscription::STATUS_CANCELLED);
        $entityManager->flush();

        $this->addFlash('success', sprintf('Abonnement %s annulé.', $subscription->getReference()));

        return $this->redirectToRoute('app_admin_subscriptions');
    }
}
