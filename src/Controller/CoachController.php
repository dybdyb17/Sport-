<?php

namespace App\Controller;

use App\Entity\Booking;
use App\Entity\Enum\PackRequestStatus;
use App\Entity\PendingPackRequest;
use App\Repository\BookingRepository;
use App\Repository\PendingPackRequestRepository;
use App\Service\BookingManager;
use App\Service\PackFirstBookingFailedException;
use App\Service\PricingCalculator;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/coach')]
#[IsGranted('ROLE_COACH')]
class CoachController extends AbstractController
{
    #[Route('/dashboard', name: 'app_coach_dashboard', methods: ['GET'])]
    public function dashboard(
        BookingRepository $bookingRepository,
        PendingPackRequestRepository $packRequestRepository,
        PricingCalculator $pricing,
    ): Response {
        /** @var \App\Entity\User $user */
        $user  = $this->getUser();
        $coach = $user->getCoach();

        if (!$coach) {
            // Admin sans profil coach → retour silencieux au tableau de bord admin
            // (le menu cache déjà le lien, on n'arrive ici que si URL tapée à la main).
            if ($this->isGranted('ROLE_ADMIN')) {
                return $this->redirectToRoute('app_admin_dashboard');
            }
            throw $this->createAccessDeniedException('Aucun profil coach associé à ce compte.');
        }

        $pendingBookings  = $bookingRepository->findPendingForCoach($coach);
        $upcomingBookings = $bookingRepository->findConfirmedUpcomingForCoach($coach);
        $historyBookings  = $bookingRepository->findHistoryForCoach($coach);

        // Demandes de pack sur-place à valider (Partie 2). Le prix est calculé
        // serveur pour que le coach sache combien encaisser exactement.
        $pendingPacks = $packRequestRepository->findPendingOnSiteForCoach($coach);
        $pendingPackItems = [];
        foreach ($pendingPacks as $ppr) {
            $pendingPackItems[] = [
                'request' => $ppr,
                'amount'  => $pricing->monthlyPackPrice(
                    $ppr->getFormat(),
                    $ppr->getPackType(),
                    $ppr->getTimeSlot(),
                    $ppr->isFullAccess(),
                ),
            ];
        }

        $since = new \DateTimeImmutable('first day of this month 00:00');

        return $this->render('coach/dashboard.html.twig', [
            'pendingBookings'      => $pendingBookings,
            'upcomingBookings'     => $upcomingBookings,
            'historyBookings'      => $historyBookings,
            'pendingPackItems'     => $pendingPackItems,
            'nbPending'            => count($pendingBookings),
            'nbPendingPacks'       => count($pendingPackItems),
            'nbConfirmedThisMonth' => $bookingRepository->countConfirmedThisMonthForCoach($coach),
            'revenueThisMonth'     => $bookingRepository->sumRevenueForCoach($coach, $since),
            'revenueAllTime'       => $bookingRepository->sumRevenueForCoach($coach, new \DateTimeImmutable('2020-01-01')),
        ]);
    }

    /**
     * Le coach a encaissé le pack (espèces ou CB au club) → il valide.
     * C'est CE POST qui déclenche la matérialisation du pack (Subscription +
     * 1ère séance). Aucune autre voie ne peut activer un pack sur place.
     *
     * Vérifs serveur :
     * - ROLE_COACH (attribut classe)
     * - le coach connecté = le coach de la demande
     * - CSRF token spécifique à cette demande
     * - la demande est encore PENDING (pas déjà validée)
     * - la demande est bien sur place (paymentMethod cash/card) — un flow
     *   Stripe pending ne doit jamais être validable manuellement
     */
    #[Route('/pack/{id}/validate', name: 'app_coach_validate_pack', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function validatePack(
        int $id,
        Request $request,
        PendingPackRequestRepository $pprRepository,
        BookingManager $bookingManager,
        \App\Service\AuditLogger $auditLogger,
        LoggerInterface $logger,
    ): Response {
        /** @var \App\Entity\User $user */
        $user  = $this->getUser();
        $coach = $user->getCoach();
        $ppr   = $pprRepository->find($id);

        if (!$ppr || !$coach || $ppr->getCoach() !== $coach) {
            throw $this->createAccessDeniedException();
        }
        if (!$this->isCsrfTokenValid('validate_pack_'.$id, (string) $request->request->get('_token'))) {
            $this->addFlash('danger', 'Jeton CSRF invalide.');
            return $this->redirectToRoute('app_coach_dashboard');
        }
        if (!$ppr->isOnSite()) {
            $this->addFlash('danger', 'Cette demande n\'est pas payée sur place — elle sera activée par Stripe automatiquement.');
            return $this->redirectToRoute('app_coach_dashboard');
        }
        if ($ppr->getStatus() !== PackRequestStatus::PENDING) {
            $this->addFlash('info', 'Cette demande a déjà été traitée.');
            return $this->redirectToRoute('app_coach_dashboard');
        }

        try {
            $subscription = $bookingManager->materializePackFromRequest(
                $ppr,
                $ppr->getPaymentMethod(),
                $user,
            );
            $this->addFlash('success', sprintf(
                'Pack de %s activé. La 1ʳᵉ séance du %s a été programmée.',
                (string) $ppr->getClient()->getNomComplet(),
                $ppr->getStartAt()->format('d/m/Y à H\hi'),
            ));
        } catch (PackFirstBookingFailedException $e) {
            // Pack ACTIVÉ, mais créneau plus dispo. Loggue et informe le coach.
            $logger->warning('Pack sur place validé, mais créneau initial pris.', [
                'ppr_id'    => $ppr->getId(),
                'coach_id'  => $coach->getId(),
                'client_id' => $ppr->getClient()->getId(),
                'start_at'  => $ppr->getStartAt()->format(\DateTimeInterface::ATOM),
                'reason'    => $e->getMessage(),
            ]);
            $this->addFlash('warning', sprintf(
                'Pack de %s activé, mais le créneau du %s n\'est plus disponible. Préviens le client — il devra réserver une autre séance depuis son espace.',
                (string) $ppr->getClient()->getNomComplet(),
                $ppr->getStartAt()->format('d/m/Y à H\hi'),
            ));
            $subscription = $ppr->getSubscription();
        }

        if ($subscription) {
            $auditLogger->log(\App\Entity\Enum\AuditAction::PAYMENT_DECLARED, $subscription, [
                'source'         => 'pack_onsite_validation',
                'method'         => $ppr->getPaymentMethod(),
                'client'         => $ppr->getClient()->getNomComplet(),
                'pack'           => $ppr->getPackType()->label(),
                'validated_by'   => $user->getNomComplet(),
                'ppr_id'         => $ppr->getId(),
            ]);
        }

        return $this->redirectToRoute('app_coach_dashboard');
    }

    #[Route('/reservation/{id}/accept', name: 'app_coach_accept', methods: ['POST'])]
    public function accept(Booking $booking, BookingManager $manager, Request $request): Response
    {
        $this->validateCsrf('accept', $booking, $request);

        /** @var \App\Entity\User $user */
        $user = $this->getUser();

        try {
            $manager->confirm($booking, $user);
            $this->addFlash('success', 'Réservation confirmée & paiement initié.');
        } catch (\Exception $e) {
            $this->addFlash('error', $e->getMessage());
        }

        return $this->redirectToRoute('app_coach_dashboard');
    }

    #[Route('/reservation/{id}/refuse', name: 'app_coach_refuse', methods: ['POST'])]
    public function refuse(Booking $booking, BookingManager $manager, Request $request): Response
    {
        $this->validateCsrf('refuse', $booking, $request);

        /** @var \App\Entity\User $user */
        $user = $this->getUser();

        try {
            $reason = (string) $request->request->get('reason', '');
            $manager->refuse($booking, $user, $reason !== '' ? $reason : null);
            $this->addFlash('info', 'Réservation refusée. Le client a été notifié.');
        } catch (\Exception $e) {
            $this->addFlash('error', $e->getMessage());
        }

        return $this->redirectToRoute('app_coach_dashboard');
    }

    private function validateCsrf(string $action, Booking $booking, Request $request): void
    {
        $token = (string) $request->request->get('_token');
        if (!$this->isCsrfTokenValid($action . $booking->getId(), $token)) {
            throw $this->createAccessDeniedException('Jeton CSRF invalide.');
        }
    }

    #[Route('/toggle-available-tonight', name: 'app_coach_toggle_tonight', methods: ['POST'])]
    public function toggleAvailableTonight(Request $request, EntityManagerInterface $em): Response
    {
        /** @var \App\Entity\User $user */
        $user = $this->getUser();
        $coach = $user->getCoach();

        if (!$coach) {
            throw $this->createAccessDeniedException();
        }

        if (!$this->isCsrfTokenValid('toggle_tonight', (string) $request->request->get('_token'))) {
            $this->addFlash('danger', 'Action invalide.');
            return $this->redirectToRoute('app_coach_dashboard');
        }

        // Toggle : si déjà actif et non expiré → off, sinon → on
        if ($coach->isAvailableTonightNow()) {
            $coach->setIsAvailableTonight(false);
            $coach->setAvailableTonightSetAt(null);
            $this->addFlash('success', 'Tu n\'es plus annoncé disponible cette nuit.');
        } else {
            $coach->setIsAvailableTonight(true);
            $coach->setAvailableTonightSetAt(new \DateTimeImmutable());
            $this->addFlash('success', '🌙 Tu es annoncé disponible cette nuit pour 24h.');
        }

        $em->flush();
        return $this->redirectToRoute('app_coach_dashboard');
    }

    #[Route('/booking/{id}/declare-payment', name: 'app_coach_declare_payment', methods: ['POST'])]
    public function declarePayment(
        int $id,
        Request $request,
        BookingRepository $bookings,
        BookingManager $bookingManager,
    ): Response {
        /** @var \App\Entity\User $user */
        $user = $this->getUser();
        $coach = $user->getCoach();
        $booking = $bookings->find($id);
        if (!$booking || !$coach || $booking->getCoach() !== $coach) {
            throw $this->createAccessDeniedException();
        }
        if (!$this->isCsrfTokenValid('declare_payment_'.$id, (string) $request->request->get('_token'))) {
            $this->addFlash('danger', 'Jeton CSRF invalide.');
            return $this->redirectToRoute('app_coach_dashboard');
        }

        try {
            $bookingManager->declareOnSitePayment(
                $booking,
                (string) $request->request->get('method'),
                (string) $request->request->get('note') ?: null,
                $user,
                'coach_dashboard',
            );
            $this->addFlash('success', 'Paiement déclaré.');
        } catch (\InvalidArgumentException $e) {
            $this->addFlash('danger', 'Mode de paiement invalide.');
        } catch (\LogicException $e) {
            $this->addFlash('info', $e->getMessage());
        }

        return $this->redirectToRoute('app_coach_dashboard');
    }

    /**
     * Le coach marque la séance comme no-show (client absent).
     * Calcul automatique d'un fee = 30% du prix unitaire de la séance.
     */
    #[Route('/booking/{id}/no-show', name: 'app_coach_mark_no_show', methods: ['POST'])]
    public function markNoShow(
        int $id,
        Request $request,
        BookingRepository $bookings,
        EntityManagerInterface $em,
        \App\Service\AuditLogger $auditLogger,
    ): Response {
        /** @var \App\Entity\User $user */
        $user = $this->getUser();
        $coach = $user->getCoach();
        $booking = $bookings->find($id);
        if (!$booking || !$coach || $booking->getCoach() !== $coach) {
            throw $this->createAccessDeniedException();
        }
        if (!$this->isCsrfTokenValid('no_show_' . $id, (string) $request->request->get('_token'))) {
            $this->addFlash('danger', 'Jeton CSRF invalide.');
            return $this->redirectToRoute('app_coach_dashboard');
        }
        if ($booking->getStartAt() > new \DateTimeImmutable()) {
            $this->addFlash('danger', 'Impossible de marquer no-show avant la séance.');
            return $this->redirectToRoute('app_coach_dashboard');
        }
        // Fee = 30% du prix unitaire
        $price = (float) ($booking->getPrice() ?? 0);
        $fee   = number_format($price * 0.30, 2, '.', '');

        $booking->setNoShow(true);
        $booking->setNoShowMarkedAt(new \DateTimeImmutable());
        $booking->setNoShowFee($fee);
        $booking->setPaymentMethod('no_show');
        $booking->setPaymentDeclaredAt(new \DateTimeImmutable());
        $booking->setPaymentDeclaredBy($user);
        $booking->setPaymentNote(sprintf('No-show — frais de 30%% facturés : %s €', $fee));
        $em->flush();

        $auditLogger->log(\App\Entity\Enum\AuditAction::NO_SHOW_MARKED, $booking, [
            'fee'     => $fee,
            'price'   => $booking->getPrice(),
            'client'  => $booking->getClient()?->getNomComplet(),
            'startAt' => $booking->getStartAt()?->format('c'),
        ]);

        $this->addFlash('success', sprintf('Marqué « non venu » — %s € à facturer au client.', $fee));
        return $this->redirectToRoute('app_coach_dashboard');
    }
}
