<?php

namespace App\Controller;

use App\Entity\Booking;
use App\Repository\BookingRepository;
use App\Service\BookingManager;
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
        $user = $this->getUser();
        $coach = $user->getCoach();

        if (!$coach) {
            throw $this->createAccessDeniedException('Aucun profil coach associé à ce compte.');
        }

        return $this->render('coach/dashboard.html.twig', [
            'bookings' => $bookingRepository->findForCoach($coach),
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
            $manager->refuse($booking, $user);
            $this->addFlash('info', 'Réservation refusée.');
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
}
