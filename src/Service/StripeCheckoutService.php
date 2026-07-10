<?php

namespace App\Service;

use App\Entity\Booking;
use App\Entity\FoundingClaim;
use App\Entity\FoundingOffer;
use App\Entity\PendingPackRequest;
use App\Entity\PromoPurchase;
use App\Entity\Subscription;
use App\Entity\User;
use App\Repository\BookingRepository;
use App\Repository\FoundingOfferRepository;
use App\Repository\PendingPackRequestRepository;
use App\Repository\PromoPurchaseRepository;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Stripe\Checkout\Session;
use Stripe\Event;
use Stripe\StripeClient;
use Stripe\Webhook;

final class StripeCheckoutService
{
    private ?StripeClient $client = null;
    private readonly LoggerInterface $logger;

    public function __construct(
        private readonly string $stripeSecretKey,
        private readonly string $stripeWebhookSecret,
        private readonly string $appUrl,
        private readonly FoundingOfferService $foundingOfferService,
        private readonly FoundingOfferRepository $foundingOfferRepository,
        private readonly UserRepository $userRepository,
        private readonly BookingRepository $bookingRepository,
        private readonly EntityManagerInterface $em,
        private readonly PricingCalculator $pricing,
        private readonly BookingManager $bookingManager,
        private readonly PendingPackRequestRepository $pendingPackRepository,
        private readonly PromoPurchaseRepository $promoPurchaseRepository,
        ?LoggerInterface $logger = null,
    ) {
        $this->logger = $logger ?? new NullLogger();
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
                            'Séance avec %s · %s à %s · Réf. %s%s',
                            (string) $booking->getCoach()?->getNomComplet(),
                            $booking->getStartAt()?->format('d/m/Y') ?? '?',
                            $booking->getTimeRangeFormatted(),
                            $booking->getReference(),
                            $client->isFoundingMember() ? ' · Membre Fondateur -5% inclus' : '',
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

    // ─────────────────────────────────────────────────────────────────────────
    //  PACK checkout (abonnement multi-séances) — ajouté le 01/07
    //
    //  Le pack n'est JAMAIS créé côté BookingController — on persiste juste
    //  un PendingPackRequest avec toutes les infos, on redirige vers Stripe,
    //  et c'est fulfillPackCheckout (déclenché par le webhook) qui crée la
    //  Subscription + le Booking APRÈS validation du paiement.
    // ─────────────────────────────────────────────────────────────────────────

    public function createPackCheckout(PendingPackRequest $ppr): Session
    {
        if ($ppr->isFulfilled()) {
            throw new \LogicException('Ce pack est déjà réglé et créé.');
        }

        $client = $ppr->getClient();
        if (!$client->getEmail()) {
            throw new \LogicException('Le client de ce pack n\'a pas d\'email valide.');
        }

        $amountCents = $this->packPriceInCents($ppr);

        $session = $this->client()->checkout->sessions->create([
            'mode' => 'payment',
            'locale' => 'fr',
            'customer_email' => $client->getEmail(),
            'client_reference_id' => 'pack-' . $ppr->getId(),
            'line_items' => [[
                'quantity' => 1,
                'price_data' => [
                    'currency' => 'eur',
                    'unit_amount' => $amountCents,
                    'product_data' => [
                        'name' => sprintf(
                            'SPORT+ — Pack %s (%s / %s)',
                            $ppr->getPackType()->label(),
                            $ppr->getFormat()->value,
                            $ppr->getTimeSlot()->value,
                        ),
                        'description' => sprintf(
                            'Pack mensuel · 1ʳᵉ séance : %s à %s · avec %s%s',
                            $ppr->getStartAt()->format('d/m/Y'),
                            $ppr->getStartAt()->format('H\hi'),
                            (string) $ppr->getCoach()->getNomComplet(),
                            $ppr->getClient()->isFoundingMember() ? ' · Membre Fondateur -5% inclus' : '',
                        ),
                    ],
                ],
            ]],
            'metadata' => $this->packMetadata($ppr),
            'payment_intent_data' => [
                'metadata' => $this->packMetadata($ppr),
            ],
            'success_url' => $this->absoluteUrl(sprintf(
                '/pack-checkout/paiement/succes?ppr=%d&session_id={CHECKOUT_SESSION_ID}',
                $ppr->getId(),
            )),
            'cancel_url' => $this->absoluteUrl(sprintf(
                '/pack-checkout/paiement/annule?ppr=%d',
                $ppr->getId(),
            )),
        ], [
            'idempotency_key' => sprintf('pack-checkout-%d', $ppr->getId()),
        ]);

        if (!$session->url) {
            throw new \RuntimeException('Stripe n\'a pas retourné de page de paiement.');
        }

        return $session;
    }

    /**
     * Appelé par la route success ET par le webhook. Idempotent : si la
     * PendingPackRequest est déjà fulfilled, ne re-fait rien.
     */
    public function fulfillPackCheckout(Session $session, ?User $expectedClient = null): Subscription
    {
        if ($session->mode !== 'payment' || $session->payment_status !== 'paid') {
            throw new \LogicException('Le paiement Stripe n\'est pas encore confirmé.');
        }

        $metadata = $session->metadata;
        if (($metadata['purchase_type'] ?? null) !== 'pack_purchase') {
            throw new \LogicException('Ce paiement ne correspond pas à un pack SPORT+.');
        }

        $pprId = filter_var($metadata['pending_pack_id'] ?? null, FILTER_VALIDATE_INT);
        if (!$pprId) {
            throw new \LogicException('Les informations du paiement Stripe sont incomplètes.');
        }

        $ppr = $this->pendingPackRepository->find($pprId);
        if (!$ppr instanceof PendingPackRequest) {
            throw new \LogicException('La demande de pack associée à ce paiement est introuvable.');
        }
        if ($expectedClient !== null && $expectedClient->getId() !== $ppr->getClient()->getId()) {
            throw new \LogicException('Ce paiement Stripe appartient à un autre compte.');
        }

        // Idempotence (protège contre les doublons webhook)
        if ($ppr->isFulfilled() && $ppr->getSubscription() !== null) {
            return $ppr->getSubscription();
        }

        // Vérif montant AVANT création
        if (strtolower((string) $session->currency) !== 'eur'
            || (int) $session->amount_total !== $this->packPriceInCents($ppr)) {
            throw new \LogicException('Le montant du paiement Stripe ne correspond pas au pack.');
        }

        // Matérialisation UNIFIÉE (même code que le sur-place côté coach) :
        // 1) Subscription (actif, sessionsRemaining = N)
        // 2) 1ère séance sur startAt (pending — décomptée à la confirm coach)
        // 3) PPR marqué CONFIRMED + lié au Subscription
        //
        // Si la 1ère séance échoue (créneau pris), PackFirstBookingFailedException
        // est jetée — le pack reste actif, on logge et on laisse le webhook
        // retourner 200 (paiement acquitté OK côté Stripe).
        try {
            $subscription = $this->bookingManager->materializePackFromRequest($ppr, 'stripe');
        } catch (PackFirstBookingFailedException $e) {
            $this->logger->error('Pack Stripe payé mais création 1ʳᵉ séance échouée — pack reste actif, client à recontacter.', [
                'ppr_id'    => $ppr->getId(),
                'client_id' => $ppr->getClient()->getId(),
                'coach_id'  => $ppr->getCoach()->getId(),
                'start_at'  => $ppr->getStartAt()->format(\DateTimeInterface::ATOM),
                'reason'    => $e->getMessage(),
            ]);
            $subscription = $ppr->getSubscription()
                ?? throw new \LogicException('Pack materialization failed without subscription trace.');
        }

        // Trace Stripe spécifique (le sur-place n'en aura pas)
        $paymentIntentId = is_string($session->payment_intent)
            ? $session->payment_intent
            : ($session->payment_intent->id ?? null);

        $subscription->setStripeCheckoutSessionId($session->id);
        $subscription->setStripePaymentIntentId($paymentIntentId);

        // Compat historique — fulfilledAt était utilisé avant l'enum status
        $ppr->setFulfilledAt($ppr->getValidatedAt());
        $ppr->setStripeSessionId($session->id);

        $this->em->flush();

        return $subscription;
    }


    // ─────────────────────────────────────────────────────────────────────────
    //  PROMO OFFER checkout — liens Instagram / Ads / bio
    // ─────────────────────────────────────────────────────────────────────────

    public function createPromoOfferCheckout(PromoPurchase $purchase): Session
    {
        $offer = $purchase->getOffer();
        if (!$offer->isCurrentlyAvailable()) {
            throw new \LogicException('Cette offre n’est plus disponible.');
        }
        if ($purchase->isPaid()) {
            throw new \LogicException('Cette réservation est déjà payée.');
        }
        if (!$purchase->getBuyerEmail()) {
            throw new \LogicException('Une adresse email est obligatoire pour envoyer la confirmation.');
        }

        $session = $this->client()->checkout->sessions->create([
            'mode' => 'payment',
            'locale' => 'fr',
            'customer_email' => $purchase->getBuyerEmail(),
            'client_reference_id' => 'promo-' . $purchase->getId(),
            'line_items' => [[
                'quantity' => 1,
                'price_data' => [
                    'currency' => $purchase->getCurrency(),
                    'unit_amount' => $this->promoPurchasePriceInCents($purchase),
                    'product_data' => [
                        'name' => 'SPORT+ — ' . $offer->getTitle(),
                        'description' => mb_substr((string) ($offer->getDescription() ?: 'Offre SPORT+ à réserver en ligne.'), 0, 500),
                    ],
                ],
            ]],
            'metadata' => $this->promoPurchaseMetadata($purchase),
            'payment_intent_data' => [
                'metadata' => $this->promoPurchaseMetadata($purchase),
            ],
            'success_url' => $this->absoluteUrl('/offres/paiement/succes?session_id={CHECKOUT_SESSION_ID}'),
            'cancel_url' => $this->absoluteUrl(sprintf('/offres/paiement/annule?purchase=%s', $purchase->getReference())),
        ], [
            'idempotency_key' => sprintf('promo-offer-checkout-%d', $purchase->getId()),
        ]);

        if (!$session->url) {
            throw new \RuntimeException('Stripe n’a pas retourné de page de paiement.');
        }

        $purchase->setStripeCheckoutSessionId($session->id);
        $this->em->flush();

        return $session;
    }

    public function fulfillPromoOfferCheckout(Session $session): PromoPurchase
    {
        if ($session->mode !== 'payment' || $session->payment_status !== 'paid') {
            throw new \LogicException('Le paiement Stripe n’est pas encore confirmé.');
        }

        $metadata = $session->metadata;
        if (($metadata['purchase_type'] ?? null) !== 'promo_offer') {
            throw new \LogicException('Ce paiement ne correspond pas à une offre promotionnelle SPORT+.');
        }

        $purchaseId = filter_var($metadata['promo_purchase_id'] ?? null, FILTER_VALIDATE_INT);
        if (!$purchaseId) {
            throw new \LogicException('Les informations du paiement Stripe sont incomplètes.');
        }

        $purchase = $this->promoPurchaseRepository->find($purchaseId);
        if (!$purchase instanceof PromoPurchase) {
            throw new \LogicException('La réservation associée à ce paiement est introuvable.');
        }
        if (strtolower((string) $session->currency) !== $purchase->getCurrency()
            || (int) $session->amount_total !== $this->promoPurchasePriceInCents($purchase)) {
            throw new \LogicException('Le montant du paiement Stripe ne correspond pas à l’offre.');
        }

        if ($purchase->isPaid()) {
            return $purchase;
        }

        $paymentIntentId = is_string($session->payment_intent)
            ? $session->payment_intent
            : ($session->payment_intent->id ?? null);

        $purchase
            ->setStatus(PromoPurchase::STATUS_PAID)
            ->setPaidAt(new \DateTimeImmutable())
            ->setStripeCheckoutSessionId($session->id)
            ->setStripePaymentIntentId($paymentIntentId);

        $this->em->flush();

        return $purchase;
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

    /** @return array<string, string> */
    private function packMetadata(PendingPackRequest $ppr): array
    {
        return [
            'purchase_type'    => 'pack_purchase',
            'pending_pack_id'  => (string) $ppr->getId(),
            'client_id'        => (string) $ppr->getClient()->getId(),
            'format'           => $ppr->getFormat()->value,
            'time_slot'        => $ppr->getTimeSlot()->value,
            'pack_type'        => $ppr->getPackType()->value,
        ];
    }

    private function packPriceInCents(PendingPackRequest $ppr): int
    {
        // Prix TOTAL du pack. Le PricingCalculator retourne le prix par personne ;
        // en Solo/Duo l'unité de facturation est le client (1 pack couvre le compte).
        // En Group, chaque personne aurait son propre pack — mais le paiement en
        // ligne Group n'est pas ouvert dans cette Partie 1 (règle métier).
        //
        // -5% Membre Fondateur appliqué ici pour que le montant DÉBITÉ Stripe
        // corresponde exactement au prix vu par le client dans le preview + au
        // prix figé dans la Subscription au fulfillment. CRITIQUE : c'est le
        // vrai paiement, l'écart avec le preview serait un bug de confiance.
        $unit = $this->pricing->monthlyPackPrice(
            $ppr->getFormat(),
            $ppr->getPackType(),
            $ppr->getTimeSlot(),
            $ppr->isFullAccess(),
            $ppr->getClient()->isFoundingMember(),
        );
        return (int) round((float) $unit * 100);
    }


    /** @return array<string, string> */
    private function promoPurchaseMetadata(PromoPurchase $purchase): array
    {
        return [
            'purchase_type'      => 'promo_offer',
            'promo_purchase_id'  => (string) $purchase->getId(),
            'promo_offer_id'     => (string) $purchase->getOffer()->getId(),
            'promo_offer_slug'   => $purchase->getOffer()->getSlug(),
            'promo_reference'    => $purchase->getReference(),
        ];
    }

    private function promoPurchasePriceInCents(PromoPurchase $purchase): int
    {
        return (int) round((float) $purchase->getAmount() * 100);
    }

    private function absoluteUrl(string $path): string
    {
        return rtrim($this->appUrl, '/').'/'.ltrim($path, '/');
    }
}
