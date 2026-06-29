<?php

namespace App\Controller;

use App\Entity\Booking;
use App\Entity\Enum\BookingFormat;
use App\Entity\Enum\PackType;
use App\Form\BookingType;
use App\Repository\BookingRepository;
use App\Service\BookingManager;
use App\Service\PricingCalculator;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/reservation')]
// ⚠️ Ne PAS mettre #[IsGranted] au niveau de la classe : confirmAttendance doit
// rester accessible sans connexion (lien signé HMAC reçu par email J-1). On
// applique IsGranted individuellement sur chaque méthode qui nécessite un client
// connecté — sauf confirmAttendance.
class BookingController extends AbstractController
{
    #[Route('/new', name: 'app_booking_new', methods: ['GET', 'POST'])]
    #[IsGranted('ROLE_CLIENT')]
    public function new(Request $request, BookingManager $manager, EntityManagerInterface $em): Response
    {
        $booking = new Booking();

        $form = $this->createForm(BookingType::class, $booking);
        $form->handleRequest($request);

        if ($form->isSubmitted() && !$form->isValid()) {
            foreach ($form->getErrors(true) as $error) {
                $this->addFlash('error', $error->getMessage());
            }
        }

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

                $intended = $request->request->get('intended_payment_method');
                if (in_array($intended, ['cash', 'card', 'stripe'], true)) {
                    // Groupe : paiement en ligne interdit côté UX et côté serveur.
                    // Si quelqu'un force "stripe" via DevTools sur un Groupe, on rebascule en cash.
                    if ($format === BookingFormat::GROUP && $intended === 'stripe') {
                        $intended = 'cash';
                    }
                    $created->setIntendedPaymentMethod($intended);
                    $em->flush();
                }

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
    #[IsGranted('ROLE_CLIENT')]
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

    /**
     * Confirmation J-1 par le client via le lien reçu dans le mail.
     * Pas besoin d'auth : on protège avec un hash signé du booking ID + APP_SECRET.
     */
    #[Route('/{ref}/confirmer/{sig}', name: 'app_booking_confirm_attendance', methods: ['GET'])]
    public function confirmAttendance(
        string $ref,
        string $sig,
        BookingRepository $bookings,
        EntityManagerInterface $em,
    ): Response {
        $booking = $bookings->findOneBy(['reference' => $ref]);
        if (!$booking) {
            throw $this->createNotFoundException();
        }
        $expected = substr(hash_hmac('sha256', 'confirm:' . $booking->getId(), $this->getParameter('kernel.secret')), 0, 32);
        if (!hash_equals($expected, $sig)) {
            throw $this->createAccessDeniedException('Lien invalide ou expiré.');
        }
        if ($booking->getStatus() !== Booking::STATUS_CONFIRMED) {
            $this->addFlash('warning', 'Cette séance n\'est pas confirmée par le coach, impossible de confirmer ta présence.');
            return $this->redirectToRoute('app_home');
        }
        if ($booking->getClientConfirmedAt() === null) {
            $booking->setClientConfirmedAt(new \DateTimeImmutable());
            $em->flush();
        }
        return $this->render('booking/confirmed_attendance.html.twig', [
            'booking' => $booking,
        ]);
    }

    // Route /payer-xplor supprimée le 29/06 — remplacée par /payer-stripe
    // (StripeController::bookingCheckout) qui redirige vers Stripe Checkout.

    #[Route('/{ref}/suivi', name: 'app_booking_status', methods: ['GET'])]
    #[IsGranted('ROLE_CLIENT')]
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
    #[IsGranted('ROLE_CLIENT')]
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

    /**
     * Le client confirme avoir effectivement payé la séance (cash ou CB).
     * Posté depuis la modal "Confirmer mon paiement" sur Mon RDV / Espace.
     */
    #[Route('/{ref}/paiement/confirmer', name: 'app_booking_payment_confirm', methods: ['POST'])]
    #[IsGranted('ROLE_CLIENT')]
    public function confirmPayment(
        string $ref,
        Request $request,
        BookingRepository $bookings,
        EntityManagerInterface $em,
        \App\Service\AuditLogger $auditLogger,
    ): Response {
        $booking = $bookings->findOneBy(['reference' => $ref]);
        if (!$booking || $booking->getClient() !== $this->getUser()) {
            throw $this->createAccessDeniedException();
        }
        if (!$this->isCsrfTokenValid('payment_confirm_' . $booking->getId(), (string) $request->request->get('_token'))) {
            $this->addFlash('error', 'Jeton CSRF invalide.');
            return $this->redirectToRoute('app_espace_client_rdv', ['reference' => $ref]);
        }
        if (!$booking->isPaymentAwaitingClientConfirmation()) {
            $this->addFlash('warning', 'Aucun paiement en attente de confirmation pour cette séance.');
            return $this->redirectToRoute('app_espace_client_rdv', ['reference' => $ref]);
        }

        $booking->setPaymentClientConfirmedAt(new \DateTimeImmutable());
        $em->flush();

        $auditLogger->log(\App\Entity\Enum\AuditAction::PAYMENT_CONFIRMED, $booking, [
            'method' => $booking->getPaymentMethod(),
            'amount' => $booking->getPrice(),
            'coach'  => $booking->getCoach()?->getNomComplet(),
        ]);

        $this->addFlash('success', 'Merci, ton paiement est confirmé.');
        return $this->redirectToRoute('app_espace_client_rdv', ['reference' => $ref]);
    }

    /**
     * Le client conteste le paiement déclaré par le coach.
     * Génère une alerte admin via le journal d'audit.
     */
    #[Route('/{ref}/paiement/contester', name: 'app_booking_payment_dispute', methods: ['POST'])]
    #[IsGranted('ROLE_CLIENT')]
    public function disputePayment(
        string $ref,
        Request $request,
        BookingRepository $bookings,
        EntityManagerInterface $em,
        \App\Service\AuditLogger $auditLogger,
    ): Response {
        $booking = $bookings->findOneBy(['reference' => $ref]);
        if (!$booking || $booking->getClient() !== $this->getUser()) {
            throw $this->createAccessDeniedException();
        }
        if (!$this->isCsrfTokenValid('payment_dispute_' . $booking->getId(), (string) $request->request->get('_token'))) {
            $this->addFlash('error', 'Jeton CSRF invalide.');
            return $this->redirectToRoute('app_espace_client_rdv', ['reference' => $ref]);
        }
        if (!$booking->isPaymentAwaitingClientConfirmation()) {
            $this->addFlash('warning', 'Aucun paiement en attente pour cette séance.');
            return $this->redirectToRoute('app_espace_client_rdv', ['reference' => $ref]);
        }

        $reason = trim((string) $request->request->get('reason', ''));
        if ($reason === '' || mb_strlen($reason) < 8) {
            $this->addFlash('error', 'Merci de détailler la raison de ta contestation (minimum 8 caractères).');
            return $this->redirectToRoute('app_espace_client_rdv', ['reference' => $ref]);
        }

        $booking->setPaymentClientDisputedAt(new \DateTimeImmutable());
        $booking->setPaymentDisputeReason(mb_substr($reason, 0, 2000));
        $em->flush();

        $auditLogger->log(\App\Entity\Enum\AuditAction::PAYMENT_DISPUTED, $booking, [
            'method'   => $booking->getPaymentMethod(),
            'amount'   => $booking->getPrice(),
            'coach'    => $booking->getCoach()?->getNomComplet(),
            'reason'   => mb_substr($reason, 0, 500),
        ]);

        $this->addFlash('success', 'Ta contestation a été enregistrée. L\'équipe SPORT+ va te recontacter sous 24 h.');
        return $this->redirectToRoute('app_espace_client_rdv', ['reference' => $ref]);
    }
}
