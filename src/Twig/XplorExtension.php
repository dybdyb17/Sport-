<?php

namespace App\Twig;

use App\Entity\Booking;
use App\Service\DeciplusPaymentUrlResolver;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

class XplorExtension extends AbstractExtension
{
    public function __construct(
        private readonly DeciplusPaymentUrlResolver $resolver,
    ) {}

    public function getFunctions(): array
    {
        return [
            new TwigFunction('xplor_pay_url', $this->payUrl(...)),
        ];
    }

    public function payUrl(Booking $booking): string
    {
        return $this->resolver->paymentUrlFor($booking);
    }
}
