<?php

namespace App\Controller;

use App\Entity\Booking;
use App\Entity\Enum\BookingFormat;
use App\Entity\Enum\PackType;
use App\Form\BookingType;
use App\Repository\BookingRepository;
use App\Service\BookingManager;
use App\Service\PricingCalculator;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
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

                // Déduire le vrai personsCount selon le format
                $format = $booking->getFormat();
                if ($format !== BookingFormat::GROUP) {
                    $personsCount = $format->personsMin();
                } else {
                    // Pour GROUP : valeur saisie par l'utilisateur (mapped: false → lu manuellement)
                    $personsCount = (int) ($form->get('personsCount')->getData() ?? 4);
                    $personsCount = max(4, min(6, $personsCount)); // borne entre 4 et 6
                }
                $booking->setPersonsCount($personsCount);

                // Créer un abonnement si l'utilisateur a choisi un pack mensuel
                $packType   = $form->get('packType')->getData();   // PackType enum
                $fullAccess = (bool) $form->get('fullAccess')->getData();
                $subscription = null;

                if ($packType instanceof PackType && $packType !== PackType::SINGLE) {
                    $subscription = $manager->createSubscription(
                        $client,
                        $format,
                        $booking->getTimeSlot(),
                        $packType,
                        $personsCount,
                        $fullAccess,
                        $booking->getCoach(),
                    );
                }

                $created = $manager->create(
                    $client,
                    $booking->getCoach(),
                    $format,
                    $booking->getTimeSlot(),
                    $personsCount,
                    $booking->getStartAt(),
                    $booking->getMessage(),
                    $subscription,
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

    /**
     * Aperçu tarifaire dynamique — appelé par JS lors du changement de sélections.
     */
    #[Route('/api/pricing-preview', name: 'app_pricing_preview', methods: ['GET'])]
    public function pricingPreview(Request $request, PricingCalculator $pricing): JsonResponse
    {
        try {
            $formatVal  = $request->query->get('format', 'solo');
            $slotVal    = $request->query->get('slot', 'day');
            $packVal    = $request->query->get('pack', 'single');
            $personsRaw = (int) $request->query->get('persons', 1);
            $fullAccess = (bool) $request->query->getBoolean('fullAccess', false);

            $format  = BookingFormat::from($formatVal);
            $slot    = \App\Entity\Enum\TimeSlot::from($slotVal);
            $pack    = PackType::from($packVal);

            // Borner le nombre de personnes
            $persons = max($format->personsMin(), min($format->personsMax(), $personsRaw));

            $singlePrice = $pricing->singleSessionPrice($format, $slot);
            $totalSingle = number_format((float) $singlePrice * $persons, 2, '.', '');

            $result = [
                'format'        => $format->label(),
                'slot'          => $slot->label(),
                'persons'       => $persons,
                'singlePerPers' => $pricing->formatPrice($singlePrice),
                'singleTotal'   => $pricing->formatPrice($totalSingle),
            ];

            if ($pack !== PackType::SINGLE) {
                $monthly  = $pricing->monthlyPackPrice($format, $pack, $slot, $fullAccess);
                $savings  = $pricing->packSavingsPerPerson($format, $pack, $slot);
                $result['pack']               = $pack->label();
                $result['packSessions']        = $pack->sessionsCount();
                $result['monthly']            = $pricing->formatPrice($monthly);
                $result['monthlyRaw']         = $monthly;
                $result['savingsPerPerson']   = $pricing->formatPrice(number_format($savings, 2, '.', ''));
                $result['savingsRaw']         = $savings;
                $result['fullAccess']         = $fullAccess;
            }

            return $this->json($result);
        } catch (\ValueError) {
            return $this->json(['error' => 'Paramètres invalides'], 400);
        }
    }

    #[Route('/{ref}/suivi', name: 'app_booking_status', methods: ['GET'])]
    public function status(string $ref, BookingRepository $bookingRepository): Response
    {
        $booking = $bookingRepository->findOneBy(['reference' => $ref]);

        if (!$booking) {
            throw $this->createNotFoundException();
        }

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
            'label'  => $booking->getStatutLabel(),
            'price'  => $booking->getPrixFormatted(),
            'coach'  => $booking->getCoach()?->getNomComplet(),
            'offer'  => $booking->getOfferLabel(),
        ]);
    }
}
