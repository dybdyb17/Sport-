<?php

namespace App\Service;

use App\Entity\Booking;
use App\Entity\FoundingClaim;
use App\Entity\FoundingOffer;
use App\Entity\User;
use App\Repository\BookingRepository;
use App\Repository\FoundingOfferRepository;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Stripe\Checkout\Session;
use Stripe\Event;
use Stripe\StripeClient;
use Stripe\Webhook;

final class StripeCheckoutService
{
    private ?StripeClient $client = null;

    public function __construct(
        private readonly string $stripeSecretKey,
        private readonly string $stripeWebhookSecret,
        private readonly string $appUrl,
        private readonly FoundingOfferService $foundingOfferService,
        private readonly FoundingOfferRepository $foundingOfferRepository,
        private readonly UserRepository $userRepository,
        private readonly BookingRepository $bookingRepository,
        private readonly EntityManagerInterface $em,
    ) {
    }

    public function createFoundingCheckout(User $user, FoundingOffer $offer): Session
    {
        if ($user->getFoundingClaim() !== null) {
            throw new \LogicException('Vous êtes déjà Membre Fondateur.');
        }
        if (!$offer->isStillRunning()) {
            throw new \LogicException('Cette offre n’est plus disponible.');
        }
        if (!$offer->hasPlacesLeft()) {
            throw new \LogicException('L’offre est complète — toutes les places ont été prises.');
        }

        $session = $this->client()->checkout->sessions->create([
            'mode' => 'payment',
            'locale' => 'fr',
            'customer_email' => $user->getEmail(),
            'client_reference_id' => (string) $user->getId(),
            'line_items' => [[
                'quantity' => 1,
                'price_data' => [
                    'currency' => 'eur',
                    'unit_amount' => $this->priceInCents($offer),
                    'product_data' => [
                        'name' => $offer->getTitle(),
                        'description' => sprintf(
                            '%d séances de coaching SPORT+ et les avantages Membre Fondateur.',
                            $offer->getSessionsIncluded(),
                        ),
                    ],
                ],
            ]],
            'metadata' => $this->metadata($user, $offer),
            'payment_intent_data' => [
                'metadata' => $this->metadata($user, $offer),
            ],
            'success_url' => $this->absoluteUrl('/offre/founding/paiement/succes?session_id={CHECKOUT_SESSION_ID}'),
            'cancel_url' => $this->absoluteUrl('/offre/founding/paiement/annule'),
        ], [
            'idempotency_key' => sprintf('founding-checkout-%d-%d', $offer->getId(), $user->getId()),
        ]);

        if (!$session->url) {
            throw new \RuntimeException('Stripe n’a pas retourné de page de paiement.');
        }

        return $session;
    }

    public function retrieveSession(string $sessionId): Session
    {
        if (!str_starts_with($sessionId, 'cs_')) {
            throw new \InvalidArgumentException('Identifiant de paiement Stripe invalide.');
        }

        return $this->client()->checkout->sessions->retrieve($sessionId, []);
    }

    public function fulfillFoundingCheckout(Session $session, ?User $expectedUser = null): FoundingClaim
    {
        if ($session->mode !== 'payment' || $session->payment_status !== 'paid') {
            throw new \LogicException('Le paiement Stripe n’est pas encore confirmé.');
        }

        $metadata = $session->metadata;
        if (($metadata['purchase_type'] ?? null) !== 'founding_offer') {
            throw new \LogicException('Ce paiement ne correspond pas à l’offre Membre Fondateur.');
        }

        $userId = filter_var($metadata['user_id'] ?? null, FILTER_VALIDATE_INT);
        $offerId = filter_var($metadata['offer_id'] ?? null, FILTER_VALIDATE_INT);
        if (!$userId || !$offerId) {
            throw new \LogicException('Les informations du paiement Stripe sont incomplètes.');
        }

        $user = $this->userRepository->find($userId);
        $offer = $this->foundingOfferRepository->find($offerId);
        if (!$user instanceof User || !$offer instanceof FoundingOffer) {
            throw new \LogicException('Le membre ou l’offre associé au paiement est introuvable.');
        }
        if ($expectedUser !== null && $expectedUser->getId() !== $user->getId()) {
            throw new \LogicException('Ce paiement Stripe appartient à un autre compte.');
        }
        if (strtolower((string) $session->currency) !== 'eur' || (int) $session->amount_total !== $this->priceInCents($offer)) {
            throw new \LogicException('Le montant du paiement Stripe ne correspond pas à l’offre.');
        }

        $paymentIntentId = is_string($session->payment_intent)
            ? $session->payment_intent
            : ($session->payment_intent->id ?? null);

        return $this->foundingOfferService->claimPaid(
            $user,
            $offer,
            $session->id,
            $paymentIntentId,
        );
    }

    // ─────────────────────────────────────────────────────────────────────────
    //  BOOKING checkout (séance coaching) — Xplor remplacé par Stripe le 29/06
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Crée une session Stripe Checkout pour régler une séance déjà confirmée
     * par le coach. À ne PAS appeler tant que status != confirmed (paiement
     * uniquement après acceptation coach, pas avant — décision Loïc).
     */
    public function createBookingCheckout(Booking $booking): Session
    {
        if ($booking->getStatus() !== Booking::STATUS_CONFIRMED) {
            throw new \LogicException('La séance doit être confirmée par le coach avant paiement.');
        }
        if ($booking->getPaymentMethod() !== null) {
            throw new \LogicException('Cette séance est déjà payée.');
        }
        if ($booking->getCoveredBy() !== null) {
            throw new \LogicException('Cette séance est couverte par un pack/Fondateur, aucun paiement n\'est dû.');
        }

        $client = $booking->getClient();
        if (!$client instanceof User || !$client->getEmail()) {
            throw new \LogicException('Le client de cette séance n\'a pas d\'email valide.');
        }

        $session = $this->client()->checkout->sessions->create([
            'mode' => 'payment',
            'locale' => 'fr',
            'customer_email' => $client->getEmail(),
            'client_reference_id' => 'booking-' . $booking->getId(),
            'line_items' => [[
                'quantity' => 1,
                'price_data' => [
                    'currency' => 'eur',
                    'unit_amount' => $this->bookingPriceInCents($booking),
                    'product_data' => [
                        'name' => sprintf('SPORT+ — %s', $booking->getOfferLabel()),
                        'description' => sprintf(
                            'Séance avec %s · %s à %s · Réf. %s',
                            (string) $booking->getCoach()?->getNomComplet(),
                            $booking->getStartAt()?->format('d/m/Y') ?? '?',
                            $booking->getTimeRangeFormatted(),
                            $booking->getReference(),
                        ),
                    ],
                ],
            ]],
            'metadata' => $this->bookingMetadata($booking),
            'payment_intent_data' => [
                'metadata' => $this->bookingMetadata($booking),
            ],
            'success_url' => $this->absoluteUrl(sprintf('/reservation/%s/paiement/succes?session_id={CHECKOUT_SESSION_ID}', $booking->getReference())),
            'cancel_url' => $this->absoluteUrl(sprintf('/reservation/%s/paiement/annule', $booking->getReference())),
        ], [
            'idempotency_key' => sprintf('booking-checkout-%d', $booking->getId()),
        ]);

        if (!$session->url) {
            throw new \RuntimeException('Stripe n\'a pas retourné de page de paiement.');
        }

        return $session;
    }

    /**
     * Appelé par la route success ET par le webhook. Idempotent : si le booking
     * est déjà marqué payé, ne re-fait rien.
     */
    public function fulfillBookingCheckout(Session $session, ?User $expectedClient = null): Booking
    {
        if ($session->mode !== 'payment' || $session->payment_status !== 'paid') {
            throw new \LogicException('Le paiement Stripe n\'est pas encore confirmé.');
        }

        $metadata = $session->metadata;
        if (($metadata['purchase_type'] ?? null) !== 'booking_session') {
            throw new \LogicException('Ce paiement ne correspond pas à une séance SPORT+.');
        }

        $bookingId = filter_var($metadata['booking_id'] ?? null, FILTER_VALIDATE_INT);
        if (!$bookingId) {
            throw new \LogicException('Les informations du paiement Stripe sont incomplètes.');
        }

        $booking = $this->bookingRepository->find($bookingId);
        if (!$booking instanceof Booking) {
            throw new \LogicException('La séance associée à ce paiement est introuvable.');
        }
        if ($expectedClient !== null && $expectedClient->getId() !== $booking->getClient()?->getId()) {
            throw new \LogicException('Ce paiement Stripe appartient à un autre compte.');
        }
        if (strtolower((string) $session->currency) !== 'eur' || (int) $session->amount_total !== $this->bookingPriceInCents($booking)) {
            throw new \LogicException('Le montant du paiement Stripe ne correspond pas à la séance.');
        }

        // Idempotence : webhook + return URL peuvent arriver en parallèle
        if ($booking->getPaymentMethod() !== null) {
            return $booking;
        }

        $paymentIntentId = is_string($session->payment_intent)
            ? $session->payment_intent
            : ($session->payment_intent->id ?? null);

        $booking
            ->setPaymentMethod('stripe')
            ->setPaymentDeclaredAt(new \DateTimeImmutable())
            ->setStripeData([
                'session_id'        => $session->id,
                'payment_intent_id' => $paymentIntentId,
                'paid_at'           => (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM),
                'amount_cents'      => (int) $session->amount_total,
            ]);

        $this->em->flush();

        return $booking;
    }

    public function constructWebhookEvent(string $payload, string $signature): Event
    {
        if ($this->stripeWebhookSecret === '') {
            throw new \RuntimeException('STRIPE_WEBHOOK_SECRET n\'est pas configuré.');
        }

        return Webhook::constructEvent($payload, $signature, $this->stripeWebhookSecret);
    }

    private function client(): StripeClient
    {
        if ($this->stripeSecretKey === '') {
            throw new \RuntimeException('STRIPE_SECRET_KEY n’est pas configuré.');
        }

        return $this->client ??= new StripeClient($this->stripeSecretKey);
    }

    /** @return array<string, string> */
    private function metadata(User $user, FoundingOffer $offer): array
    {
        return [
            'purchase_type' => 'founding_offer',
            'user_id' => (string) $user->getId(),
            'offer_id' => (string) $offer->getId(),
            'offer_code' => $offer->getCode(),
        ];
    }

    private function priceInCents(FoundingOffer $offer): int
    {
        return (int) round((float) $offer->getPrice() * 100);
    }

    /** @return array<string, string> */
    private function bookingMetadata(Booking $booking): array
    {
        return [
            'purchase_type' => 'booking_session',
            'booking_id'    => (string) $booking->getId(),
            'booking_ref'   => (string) $booking->getReference(),
            'client_id'     => (string) $booking->getClient()?->getId(),
        ];
    }

    private function bookingPriceInCents(Booking $booking): int
    {
        return (int) round((float) $booking->getPrice() * 100);
    }

    private function absoluteUrl(string $path): string
    {
        return rtrim($this->appUrl, '/').'/'.ltrim($path, '/');
    }
}
