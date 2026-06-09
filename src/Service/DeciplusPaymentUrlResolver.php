<?php

namespace App\Service;

use App\Entity\Booking;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;

class DeciplusPaymentUrlResolver
{
    private const MEMBER_APP_BASE = 'https://member-app.deciplus.pro';

    public function __construct(
        private readonly ParameterBagInterface $params,
    ) {}

    /**
     * Retourne l'URL externe Deciplus/Xplor à ouvrir pour payer une réservation.
     *
     * Si une URL produit précise est configurée pour le couple format/créneau, elle
     * est prioritaire. Sinon, on envoie vers la boutique SPORT+ Deciplus : c'est le
     * meilleur fallback tant que les prestations coaching de nuit ne sont pas encore
     * créées dans Deciplus.
     */
    public function paymentUrlFor(Booking $booking): string
    {
        return $this->directProductUrlFor($booking) ?? $this->shopUrl();
    }

    public function hasDirectProductUrlFor(Booking $booking): bool
    {
        return null !== $this->directProductUrlFor($booking);
    }

    public function shopUrl(): string
    {
        return sprintf('%s/%s/shop', self::MEMBER_APP_BASE, $this->slug());
    }

    public function tunnelUrl(): string
    {
        return sprintf('%s/%s/tunnel', self::MEMBER_APP_BASE, $this->slug());
    }

    private function directProductUrlFor(Booking $booking): ?string
    {
        $key = sprintf('%s_%s', $booking->getFormat()->value, $booking->getTimeSlot()->value);
        $urls = $this->configuredProductUrls();

        return $urls[$key] ?? null;
    }

    /**
     * @return array<string, string>
     */
    private function configuredProductUrls(): array
    {
        $raw = $_ENV['DECIPLUS_PRODUCT_URLS'] ?? $_SERVER['DECIPLUS_PRODUCT_URLS'] ?? '';
        if (!is_string($raw) || trim($raw) === '') {
            return [];
        }

        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            return [];
        }

        $urls = [];
        foreach ($decoded as $key => $url) {
            if (!is_string($key) || !is_string($url)) {
                continue;
            }

            $key = strtolower(trim($key));
            $url = trim($url);
            if ($key === '' || !str_starts_with($url, 'https://')) {
                continue;
            }

            $urls[$key] = $url;
        }

        return $urls;
    }

    private function slug(): string
    {
        return trim((string) $this->params->get('deciplus_slug'), '/');
    }
}
