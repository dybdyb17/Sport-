<?php

namespace App\Service;

use App\Entity\FoundingClaim;
use App\Entity\FoundingOffer;
use App\Entity\User;
use App\Repository\FoundingOfferRepository;
use App\Repository\UserRepository;
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

    public function constructWebhookEvent(string $payload, string $signature): Event
    {
        if ($this->stripeWebhookSecret === '') {
            throw new \RuntimeException('STRIPE_WEBHOOK_SECRET n’est pas configuré.');
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

    private function absoluteUrl(string $path): string
    {
        return rtrim($this->appUrl, '/').'/'.ltrim($path, '/');
    }
}
