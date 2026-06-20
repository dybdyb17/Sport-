<?php

namespace App\Controller;

use App\Entity\Booking;
use App\Repository\BookingRepository;
use App\Service\BookingManager;
use Doctrine\ORM\EntityManagerInterface;
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
    public function dashboard(BookingRepository $bookingRepository): Response
    {
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

        $since = new \DateTimeImmutable('first day of this month 00:00');

        return $this->render('coach/dashboard.html.twig', [
            'pendingBookings'      => $pendingBookings,
            'upcomingBookings'     => $upcomingBookings,
            'historyBookings'      => $historyBookings,
            'nbPending'            => count($pendingBookings),
            'nbConfirmedThisMonth' => $bookingRepository->countConfirmedThisMonthForCoach($coach),
            'revenueThisMonth'     => $bookingRepository->sumRevenueForCoach($coach, $since),
            'revenueAllTime'       => $bookingRepository->sumRevenueForCoach($coach, new \DateTimeImmutable('2020-01-01')),
        ]);
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
        if (!$this->isCsrfTokenValid('declare_payment_'.$id, (string) $request->request->get('_token'))) {
            $this->addFlash('danger', 'Jeton CSRF invalide.');
            return $this->redirectToRoute('app_coach_dashboard');
        }
        $method = $request->request->get('method');
        if (!in_array($method, ['cash', 'card', 'xplor'], true)) {
            $this->addFlash('danger', 'Mode de paiement invalide.');
            return $this->redirectToRoute('app_coach_dashboard');
        }
        $note = (string) $request->request->get('note');
        $booking->setPaymentMethod($method);
        $booking->setPaymentDeclaredAt(new \DateTimeImmutable());
        $booking->setPaymentDeclaredBy($user);
        $booking->setPaymentNote($note);
        $em->flush();

        $auditLogger->log(\App\Entity\Enum\AuditAction::PAYMENT_DECLARED, $booking, [
            'method'        => $method,
            'amount'        => $booking->getPrice(),
            'note'          => $note ?: null,
            'client'        => $booking->getClient()?->getNomComplet(),
            'startAt'       => $booking->getStartAt()?->format('c'),
        ]);

        $this->addFlash('success', 'Paiement déclaré.');
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
