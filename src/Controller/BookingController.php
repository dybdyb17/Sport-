<?php

namespace App\Controller;

use App\Entity\Booking;
use App\Form\BookingType;
use App\Repository\BookingRepository;
use App\Service\BookingManager;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/reservation')]
#[IsGranted('ROLE_CLIENT')]
class BookingController extends AbstractController
{
    #[Route('/new', name: 'app_booking_new', methods: ['GET', 'POST'])]
    public function new(Request $request, BookingManager $manager): Response
    {
        $booking = new Booking();

        $form = $this->createForm(BookingType::class, $booking);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            try {
                /** @var \App\Entity\User $client */
                $client = $this->getUser();

                $created = $manager->create(
                    $client,
                    $booking->getCoach(),
                    $booking->getServiceType(),
                    $booking->getStartAt(),
                    $booking->getMessage(),
                );

                $this->addFlash('success', 'Demande envoyée au coach. Tu recevras une confirmation en temps réel.');

                return $this->redirectToRoute('app_booking_status', ['ref' => $created->getReference()]);
            } catch (\Exception $e) {
                $this->addFlash('error', $e->getMessage());
            }
        }

        return $this->render('booking/new.html.twig', [
            'form' => $form,
        ]);
    }

    #[Route('/{ref}/suivi', name: 'app_booking_status', methods: ['GET'])]
    public function status(string $ref, BookingRepository $bookingRepository): Response
    {
        $booking = $bookingRepository->findOneBy(['reference' => $ref]);

        if (!$booking) {
            throw $this->createNotFoundException();
        }

        // Un client ne peut suivre que ses propres réservations.
        if ($booking->getClient() !== $this->getUser()) {
            throw $this->createAccessDeniedException();
        }

        return $this->render('booking/status.html.twig', [
            'booking' => $booking,
        ]);
    }

    #[Route('/{ref}/status.json', name: 'app_booking_status_json', methods: ['GET'])]
    public function statusJson(string $ref, BookingRepository $bookingRepository): Response
    {
        $booking = $bookingRepository->findOneBy(['reference' => $ref]);

        if (!$booking || $booking->getClient() !== $this->getUser()) {
            throw $this->createNotFoundException();
        }

        return $this->json([
            'status' => $booking->getStatus(),
            'label' => $booking->getStatutLabel(),
            'price' => $booking->getPrixFormatted(),
            'coach' => $booking->getCoach()?->getNomComplet(),
        ]);
    }
}
