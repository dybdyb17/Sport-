<?php

namespace App\Controller;

use App\Entity\Booking;
use App\Repository\BookingRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_CLIENT')]
class ClientController extends AbstractController
{
    #[Route('/mon-espace', name: 'app_espace_client', methods: ['GET'])]
    public function index(BookingRepository $bookingRepository): Response
    {
        /** @var \App\Entity\User $user */
        $user = $this->getUser();
        $all  = $bookingRepository->findForClient($user);
        $now  = new \DateTimeImmutable();

        $estAVenir = static function (Booking $b) use ($now): bool {
            return $b->getStartAt() >= $now
                && in_array($b->getStatus(), [Booking::STATUS_PENDING, Booking::STATUS_CONFIRMED], true);
        };

        $reservationsAVenir  = array_values(array_filter($all, $estAVenir));
        $reservationsPassees = array_values(array_filter($all, static fn (Booking $b): bool => !$estAVenir($b)));

        $nbConfirmees = count(array_filter($all, static fn (Booking $b): bool => $b->isConfirmed()));
        $nbEnAttente  = count(array_filter($all, static fn (Booking $b): bool => $b->isPending()));

        return $this->render('client/espace.html.twig', [
            'reservationsAVenir'  => $reservationsAVenir,
            'reservationsPassees' => $reservationsPassees,
            'total'               => count($all),
            'nbConfirmees'        => $nbConfirmees,
            'nbEnAttente'         => $nbEnAttente,
        ]);
    }

    #[Route('/reservation/{ref}/annuler', name: 'app_booking_cancel', methods: ['POST'])]
    public function cancel(
        string $ref,
        Request $request,
        BookingRepository $bookingRepository,
        EntityManagerInterface $em,
    ): Response {
        $booking = $bookingRepository->findOneBy(['reference' => $ref]);

        if (!$booking) {
            throw $this->createNotFoundException('Réservation introuvable.');
        }

        /** @var \App\Entity\User $user */
        $user = $this->getUser();

        if ($booking->getClient() !== $user) {
            throw $this->createAccessDeniedException();
        }

        $token = (string) $request->request->get('_token');
        if (!$this->isCsrfTokenValid('cancel' . $booking->getId(), $token)) {
            $this->addFlash('error', 'Jeton CSRF invalide.');

            return $this->redirectToRoute('app_espace_client');
        }

        if (!$booking->canBeCancelled()) {
            $this->addFlash('error', 'Cette séance ne peut plus être annulée (moins de 2h avant le début ou statut incompatible).');

            return $this->redirectToRoute('app_espace_client');
        }

        $booking->setStatus(Booking::STATUS_CANCELLED);
        $em->flush();

        $this->addFlash('success', 'Séance annulée. Aucun frais ne sera prélevé.');

        return $this->redirectToRoute('app_espace_client');
    }
}
