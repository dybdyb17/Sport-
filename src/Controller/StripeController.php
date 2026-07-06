<?php

namespace App\Controller;

use App\Entity\User;
use App\Repository\BookingRepository;
use App\Repository\PendingPackRequestRepository;
use App\Service\FoundingOfferService;
use App\Service\StripeCheckoutService;
use Psr\Log\LoggerInterface;
use Stripe\Checkout\Session;
use Stripe\Exception\SignatureVerificationException;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

final class StripeController extends AbstractController
{
    #[Route('/offre/founding/paiement', name: 'app_founding_checkout', methods: ['POST'])]
    #[IsGranted('ROLE_CLIENT')]
    public function checkout(
        Request $request,
        FoundingOfferService $foundingOfferService,
        StripeCheckoutService $stripeCheckout,
        LoggerInterface $logger,
    ): RedirectResponse {
        if (!$this->isCsrfTokenValid('founding-checkout', (string) $request->request->get('_token'))) {
            $this->addFlash('error', 'Token de sécurité invalide. Veuillez réessayer.');

            return $this->redirectToRoute('app_founding_detail');
        }

        $user = $this->getUser();
        if (!$user instanceof User) {
            return $this->redirectToRoute('app_login');
        }

        $offer = $foundingOfferService->getActive();
        if ($offer === null) {
            $this->addFlash('error', 'Aucune offre Membre Fondateur n’est disponible actuellement.');

            return $this->redirectToRoute('app_founding_detail');
        }

        try {
            $session = $stripeCheckout->createFoundingCheckout($user, $offer);

            return new RedirectResponse((string) $session->url, Response::HTTP_SEE_OTHER);
        } catch (\Throwable $exception) {
            $logger->error('Unable to create the Stripe Checkout session.', [
                'user_id' => $user->getId(),
                'offer_id' => $offer->getId(),
                'exception' => $exception,
            ]);
            $this->addFlash('error', 'Impossible d’ouvrir le paiement Stripe pour le moment. Veuillez réessayer.');

            return $this->redirectToRoute('app_founding_detail');
        }
    }

    #[Route('/offre/founding/paiement/succes', name: 'app_founding_checkout_success', methods: ['GET'])]
    #[IsGranted('ROLE_CLIENT')]
    public function success(
        Request $request,
        StripeCheckoutService $stripeCheckout,
        LoggerInterface $logger,
    ): RedirectResponse
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            return $this->redirectToRoute('app_login');
        }

        try {
            $session = $stripeCheckout->retrieveSession((string) $request->query->get('session_id'));
            $claim = $stripeCheckout->fulfillFoundingCheckout($session, $user);
            $this->addFlash('success', sprintf(
                'Paiement confirmé ! Bienvenue, %s. Vous êtes maintenant %s.',
                $user->getNomComplet(),
                $claim->getFoundingLabel(),
            ));
        } catch (\Throwable $exception) {
            $logger->warning('Stripe Checkout return could not be fulfilled immediately.', [
                'user_id' => $user->getId(),
                'session_id' => (string) $request->query->get('session_id'),
                'exception' => $exception,
            ]);
            $this->addFlash('error', 'Le paiement n’a pas encore pu être confirmé. Votre compte sera mis à jour automatiquement dès la confirmation Stripe.');
        }

        return $this->redirectToRoute('app_founding_detail');
    }

    #[Route('/offre/founding/paiement/annule', name: 'app_founding_checkout_cancel', methods: ['GET'])]
    public function cancel(): RedirectResponse
    {
        $this->addFlash('info', 'Paiement annulé : aucun montant n’a été débité. Votre place n’a pas été activée.');

        return $this->redirectToRoute('app_founding_detail');
    }

    // ─────────────────────────────────────────────────────────────────────────
    //  BOOKING checkout (séance coaching) — remplace Xplor depuis le 29/06
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Démarre le Stripe Checkout pour régler une séance.
     * Vérifie côté contrôleur que le booking appartient bien au user connecté
     * et qu'il est confirmé par le coach (le service revérifie aussi en interne).
     */
    #[Route('/reservation/{ref}/payer-stripe', name: 'app_booking_checkout_stripe', methods: ['POST'], requirements: ['ref' => 'SPT-[A-F0-9]{8}'])]
    #[IsGranted('ROLE_CLIENT')]
    public function bookingCheckout(
        string $ref,
        Request $request,
        BookingRepository $bookings,
        StripeCheckoutService $stripeCheckout,
        LoggerInterface $logger,
    ): RedirectResponse {
        $booking = $bookings->findOneBy(['reference' => $ref]);
        if (!$booking) {
            throw $this->createNotFoundException();
        }

        $user = $this->getUser();
        if (!$user instanceof User || $booking->getClient()?->getId() !== $user->getId()) {
            throw $this->createAccessDeniedException();
        }

        if (!$this->isCsrfTokenValid('booking-stripe-' . $booking->getId(), (string) $request->request->get('_token'))) {
            $this->addFlash('error', 'Token de sécurité invalide. Réessaie depuis ton espace.');
            return $this->redirectToRoute('app_espace_client_rdv', ['reference' => $ref]);
        }

        try {
            $session = $stripeCheckout->createBookingCheckout($booking);
            return new RedirectResponse((string) $session->url, Response::HTTP_SEE_OTHER);
        } catch (\Throwable $exception) {
            $logger->error('Unable to create Stripe Checkout session for booking.', [
                'booking_id' => $booking->getId(),
                'exception'  => $exception,
            ]);
            $this->addFlash('error', 'Impossible d\'ouvrir le paiement Stripe pour le moment. Réessaie dans un instant.');
            return $this->redirectToRoute('app_espace_client_rdv', ['reference' => $ref]);
        }
    }

    #[Route('/reservation/{ref}/paiement/succes', name: 'app_booking_checkout_success', methods: ['GET'], requirements: ['ref' => 'SPT-[A-F0-9]{8}'])]
    #[IsGranted('ROLE_CLIENT')]
    public function bookingCheckoutSuccess(
        string $ref,
        Request $request,
        StripeCheckoutService $stripeCheckout,
        LoggerInterface $logger,
    ): RedirectResponse {
        $user = $this->getUser();
        if (!$user instanceof User) {
            return $this->redirectToRoute('app_login');
        }

        try {
            $session = $stripeCheckout->retrieveSession((string) $request->query->get('session_id'));
            $stripeCheckout->fulfillBookingCheckout($session, $user);
            $this->addFlash('success', 'Paiement confirmé ! Ton QR est débloqué, on t\'attend à la salle.');
        } catch (\Throwable $exception) {
            // Le webhook arrivera (ou est déjà arrivé) → on n'effraie pas le client
            $logger->warning('Stripe Checkout booking return could not be fulfilled immediately.', [
                'session_id' => (string) $request->query->get('session_id'),
                'reason'     => $exception->getMessage(),
            ]);
            $this->addFlash('info', 'Ton paiement est en cours de vérification. Ton QR sera débloqué automatiquement.');
        }

        return $this->redirectToRoute('app_espace_client_rdv', ['reference' => $ref]);
    }

    #[Route('/reservation/{ref}/paiement/annule', name: 'app_booking_checkout_cancel', methods: ['GET'], requirements: ['ref' => 'SPT-[A-F0-9]{8}'])]
    public function bookingCheckoutCancel(string $ref): RedirectResponse
    {
        $this->addFlash('info', 'Paiement annulé : aucun montant n\'a été débité. Tu peux réessayer quand tu veux ou régler sur place.');
        return $this->redirectToRoute('app_espace_client_rdv', ['reference' => $ref]);
    }

    // ─────────────────────────────────────────────────────────────────────────
    //  PACK checkout (abonnement multi-séances) — ajouté le 01/07
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Démarre le Stripe Checkout pour régler un pack.
     * Le PendingPackRequest a été créé par BookingController::new juste avant
     * de rediriger vers cette route. Aucun Subscription n'existe encore : la
     * création se fait dans le webhook après paiement.
     */
    #[Route('/pack-checkout/{id}/payer-stripe', name: 'app_pack_checkout_stripe', methods: ['POST'], requirements: ['id' => '\d+'])]
    #[IsGranted('ROLE_CLIENT')]
    public function packCheckout(
        int $id,
        Request $request,
        PendingPackRequestRepository $pprRepo,
        StripeCheckoutService $stripeCheckout,
        LoggerInterface $logger,
    ): RedirectResponse {
        $ppr = $pprRepo->find($id);
        if (!$ppr) {
            throw $this->createNotFoundException();
        }

        $user = $this->getUser();
        if (!$user instanceof User || $ppr->getClient()->getId() !== $user->getId()) {
            throw $this->createAccessDeniedException();
        }

        if (!$this->isCsrfTokenValid('pack-stripe-' . $ppr->getId(), (string) $request->request->get('_token'))) {
            $this->addFlash('error', 'Token de sécurité invalide. Réessaie depuis la page de réservation.');
            return $this->redirectToRoute('app_booking_new');
        }

        if ($ppr->isFulfilled()) {
            $this->addFlash('info', 'Ce pack est déjà réglé et actif.');
            return $this->redirectToRoute('app_espace_client');
        }

        try {
            $session = $stripeCheckout->createPackCheckout($ppr);
            return new RedirectResponse((string) $session->url, Response::HTTP_SEE_OTHER);
        } catch (\Throwable $exception) {
            $logger->error('Unable to create Stripe Checkout session for pack.', [
                'ppr_id'    => $ppr->getId(),
                'exception' => $exception,
            ]);
            $this->addFlash('error', 'Impossible d\'ouvrir le paiement Stripe pour le moment. Réessaie dans un instant.');
            return $this->redirectToRoute('app_booking_new');
        }
    }

    #[Route('/pack-checkout/paiement/succes', name: 'app_pack_checkout_success', methods: ['GET'])]
    #[IsGranted('ROLE_CLIENT')]
    public function packCheckoutSuccess(
        Request $request,
        StripeCheckoutService $stripeCheckout,
        LoggerInterface $logger,
    ): RedirectResponse {
        $user = $this->getUser();
        if (!$user instanceof User) {
            return $this->redirectToRoute('app_login');
        }

        try {
            $session = $stripeCheckout->retrieveSession((string) $request->query->get('session_id'));
            $stripeCheckout->fulfillPackCheckout($session, $user);
            $this->addFlash('success', 'Ton pack est actif ! Ta 1ʳᵉ séance est en attente de validation par le coach.');
        } catch (\Throwable $exception) {
            $logger->warning('Stripe Checkout pack return could not be fulfilled immediately.', [
                'session_id' => (string) $request->query->get('session_id'),
                'reason'     => $exception->getMessage(),
            ]);
            $this->addFlash('info', 'Ton paiement est en cours de vérification. Ton pack sera actif dans un instant.');
        }

        return $this->redirectToRoute('app_espace_client');
    }

    #[Route('/pack-checkout/paiement/annule', name: 'app_pack_checkout_cancel', methods: ['GET'])]
    #[IsGranted('ROLE_CLIENT')]
    public function packCheckoutCancel(): RedirectResponse
    {
        $this->addFlash('info', 'Paiement annulé : aucun montant n\'a été débité et le pack n\'a pas été activé.');
        return $this->redirectToRoute('app_booking_new');
    }

    #[Route('/stripe/webhook', name: 'app_stripe_webhook', methods: ['POST'])]
    public function webhook(
        Request $request,
        StripeCheckoutService $stripeCheckout,
        LoggerInterface $logger,
    ): JsonResponse {
        try {
            $event = $stripeCheckout->constructWebhookEvent(
                $request->getContent(),
                (string) $request->headers->get('Stripe-Signature'),
            );
        } catch (SignatureVerificationException|\UnexpectedValueException $exception) {
            return $this->json(['error' => 'Invalid Stripe signature or payload.'], Response::HTTP_BAD_REQUEST);
        } catch (\RuntimeException $exception) {
            $logger->critical('Stripe webhook configuration error.', ['exception' => $exception]);

            return $this->json(['error' => 'Stripe webhook is not configured.'], Response::HTTP_SERVICE_UNAVAILABLE);
        }

        if (in_array($event->type, ['checkout.session.completed', 'checkout.session.async_payment_succeeded'], true)) {
            $session = $event->data->object;
            if ($session instanceof Session && $session->payment_status === 'paid') {
                // Aiguillage selon purchase_type — Founding existant + Booking ajouté le 29/06
                $purchaseType = $session->metadata['purchase_type'] ?? null;

                try {
                    if ($purchaseType === 'booking_session') {
                        $stripeCheckout->fulfillBookingCheckout($session);
                    } elseif ($purchaseType === 'pack_purchase') {
                        $stripeCheckout->fulfillPackCheckout($session);
                    } elseif ($purchaseType === 'promo_offer') {
                        $stripeCheckout->fulfillPromoOfferCheckout($session);
                    } else {
                        // Default = founding_offer (compat historique)
                        $stripeCheckout->fulfillFoundingCheckout($session);
                    }
                } catch (\LogicException $exception) {
                    $logger->warning('Stripe Checkout session could not be fulfilled.', [
                        'session_id'    => $session->id,
                        'purchase_type' => $purchaseType,
                        'reason'        => $exception->getMessage(),
                    ]);

                    return $this->json(['error' => 'Checkout session not fulfilled.'], Response::HTTP_UNPROCESSABLE_ENTITY);
                } catch (\Throwable $exception) {
                    $logger->error('Unexpected Stripe fulfillment failure.', [
                        'session_id'    => $session->id,
                        'purchase_type' => $purchaseType,
                        'exception'     => $exception,
                    ]);

                    return $this->json(['error' => 'Fulfillment failed.'], Response::HTTP_INTERNAL_SERVER_ERROR);
                }
            }
        }

        return $this->json(['received' => true]);
    }
}
