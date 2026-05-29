<?php

namespace App\Controller;

use App\Entity\Booking;
use App\Entity\User;
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
    public function espace(BookingRepository $bookingRepository): Response
    {
        /** @var User $client */
        $client = $this->getUser();
        $now = new \DateTimeImmutable();

        $reservations = $bookingRepository->findForClient($client);

        $estAvenir = static fn (Booking $booking): bool => $booking->getStartAt() >= $now
            && \in_array($booking->getStatus(), [Booking::STATUS_PENDING, Booking::STATUS_CONFIRMED], true);

        $reservationsAVenir = array_values(array_filter($reservations, $estAvenir));
        $reservationsPassees = array_values(array_filter(
            $reservations,
            static fn (Booking $booking): bool => !$estAvenir($booking),
        ));

        return $this->render('client/espace.html.twig', [
            'reservationsAVenir' => $reservationsAVenir,
            'reservationsPassees' => $reservationsPassees,
            'stats' => [
                'total' => \count($reservations),
                'confirmees' => \count(array_filter($reservations, static fn (Booking $booking): bool => $booking->isConfirmed())),
                'enAttente' => \count(array_filter($reservations, static fn (Booking $booking): bool => $booking->isPending())),
            ],
        ]);
    }

    #[Route('/reservation/{ref}/annuler', name: 'app_booking_cancel', methods: ['POST'])]
    public function cancel(
        string $ref,
        Request $request,
        BookingRepository $bookingRepository,
        EntityManagerInterface $entityManager,
    ): Response {
        $booking = $bookingRepository->findOneBy(['reference' => $ref]);

        if (!$booking) {
            throw $this->createNotFoundException('Réservation introuvable.');
        }

        /** @var User $client */
        $client = $this->getUser();

        if ($booking->getClient()?->getId() !== $client->getId()) {
            throw $this->createAccessDeniedException('Cette réservation ne t’appartient pas.');
        }

        if (!$this->isCsrfTokenValid('cancel' . $booking->getId(), (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Token CSRF invalide.');
        }

        if (!$booking->canBeCancelled()) {
            $this->addFlash('error', 'Cette réservation ne peut plus être annulée.');

            return $this->redirectToRoute('app_espace_client');
        }

        $booking->setStatus(Booking::STATUS_CANCELLED);
        $entityManager->flush();

        $this->addFlash('success', 'Ta réservation a bien été annulée.');

        return $this->redirectToRoute('app_espace_client');
    }
}
