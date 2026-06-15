<?php

namespace App\Twig;

use App\Service\PricingCalculator;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * Expose la décomposition TVA aux templates Twig.
 *
 * Usage : {% set parts = tva_split(montantTtc) %}
 *         HT : {{ parts.ht }} · TVA : {{ parts.tva }} · TTC : {{ parts.ttc }}
 *
 * Ou directement : {{ tva_ht(montant) }}, {{ tva_amount(montant) }}
 */
class TvaExtension extends AbstractExtension
{
    public function __construct(
        private readonly PricingCalculator $pricing,
    ) {}

    public function getFunctions(): array
    {
        return [
            new TwigFunction('tva_split',   $this->split(...)),
            new TwigFunction('tva_ht',      $this->ht(...)),
            new TwigFunction('tva_amount',  $this->amount(...)),
            new TwigFunction('tva_rate',    fn() => PricingCalculator::TVA_RATE),
        ];
    }

    /** @return array{ht:string, tva:string, ttc:string} */
    public function split(string|float $ttc): array
    {
        return $this->pricing->decomposeTva((string) $ttc);
    }

    public function ht(string|float $ttc): string
    {
        return $this->pricing->decomposeTva((string) $ttc)['ht'];
    }

    public function amount(string|float $ttc): string
    {
        return $this->pricing->decomposeTva((string) $ttc)['tva'];
    }
}
