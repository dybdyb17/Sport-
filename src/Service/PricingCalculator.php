<?php

namespace App\Service;

use App\Entity\Enum\BookingFormat;
use App\Entity\Enum\PackType;
use App\Entity\Enum\TimeSlot;

/**
 * Source de vérité unique pour TOUS les calculs de prix SPORT+.
 *
 * Deux tables indépendantes : prix séance unique (pay-per-session)
 * et prix packs mensuels. Les deux sont par personne.
 */
final class PricingCalculator
{
    /** Prix séance unique par personne : format × créneau.
     *  Day Pass : Journée 40€ · Night 60€ · Astreinte 80€ (Solo/Duo).
     *  Group : Journée 22€ · Night 31€ · Astreinte 40€ (inchangé).
     */
    private const SINGLE_SESSION_PRICES = [
        'solo'  => ['day' => 40.0, 'night' => 60.0, 'astreinte' => 80.0],
        'duo'   => ['day' => 40.0, 'night' => 60.0, 'astreinte' => 80.0],
        'group' => ['day' => 22.0, 'night' => 31.0, 'astreinte' => 40.0],
    ];

    /** Prix packs mensuels par personne : format × pack × créneau. */
    private const MONTHLY_PACK_PRICES = [
        'solo' => [
            'pack_4'  => ['day' => 150.0, 'night' => 210.0, 'astreinte' => 270.0],
            'pack_8'  => ['day' => 240.0, 'night' => 335.0, 'astreinte' => 430.0],
            'pack_12' => ['day' => 320.0, 'night' => 450.0, 'astreinte' => 575.0],
        ],
        'duo' => [
            'pack_4'  => ['day' => 105.0, 'night' => 147.0, 'astreinte' => 189.0],
            'pack_8'  => ['day' => 168.0, 'night' => 235.0, 'astreinte' => 302.0],
            'pack_12' => ['day' => 224.0, 'night' => 314.0, 'astreinte' => 403.0],
        ],
        'group' => [
            'pack_4'  => ['day' => 60.0,  'night' => 84.0,  'astreinte' => 108.0],
            'pack_8'  => ['day' => 96.0,  'night' => 134.0, 'astreinte' => 173.0],
            'pack_12' => ['day' => 128.0, 'night' => 179.0, 'astreinte' => 230.0],
        ],
    ];

    private const FULL_ACCESS_SURCHARGE = [
        'solo'  => 30.0,
        'duo'   => 30.0,
        'group' => 25.0,
    ];

    /** Prix séance unique pour 1 personne (€). */
    public function singleSessionPrice(BookingFormat $format, TimeSlot $slot): string
    {
        $price = self::SINGLE_SESSION_PRICES[$format->value][$slot->value];
        return number_format($price, 2, '.', '');
    }

    /**
     * Prix pack mensuel par personne, FullAccess optionnel.
     *
     * @throws \LogicException si PackType::SINGLE est fourni
     */
    public function monthlyPackPrice(
        BookingFormat $format,
        PackType $pack,
        TimeSlot $slot,
        bool $fullAccess = false,
    ): string {
        if ($pack === PackType::SINGLE) {
            throw new \LogicException('Pour une séance unique, utiliser singleSessionPrice().');
        }

        $base = self::MONTHLY_PACK_PRICES[$format->value][$pack->value][$slot->value];

        if ($fullAccess) {
            $base += self::FULL_ACCESS_SURCHARGE[$format->value];
        }

        return number_format($base, 2, '.', '');
    }

    /**
     * Économie réalisée avec un pack vs achat à la séance (par personne).
     * Économie = singlePrice × sessions - monthlyPrice
     */
    public function packSavingsPerPerson(BookingFormat $format, PackType $pack, TimeSlot $slot): float
    {
        if ($pack === PackType::SINGLE) {
            return 0.0;
        }

        $singlePrice  = (float) $this->singleSessionPrice($format, $slot);
        $monthlyPrice = (float) $this->monthlyPackPrice($format, $pack, $slot);
        $sessions     = $pack->sessionsCount();

        return max(0.0, $singlePrice * $sessions - $monthlyPrice);
    }

    /**
     * Répartition marge structure / coach sur un prix total.
     *
     * @return array{total:string,structure:string,coach:string}
     */
    public function structureMargin(string $price, TimeSlot $slot): array
    {
        $total         = (float) $price;
        $structureRate = $slot->structureMarginRate();
        $structure     = $total * $structureRate;
        $coach         = $total - $structure;

        return [
            'total'     => number_format($total, 2, '.', ''),
            'structure' => number_format($structure, 2, '.', ''),
            'coach'     => number_format($coach, 2, '.', ''),
        ];
    }

    /** Formatage affichage français : "1 234,56 €" */
    public function formatPrice(string $price): string
    {
        return number_format((float) $price, 2, ',', "\u{202F}") . ' €';
    }
}
