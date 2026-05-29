<?php

namespace App\Service;

use App\Entity\Enum\BookingFormat;
use App\Entity\Enum\PackType;
use App\Entity\Enum\TimeSlot;

/**
 * Source de vérité unique pour TOUS les calculs de prix SPORT+.
 *
 * Principe de calcul :
 * - Séance unique par personne = BASE_SOLO × format.ratioFromSolo() × slot.multiplier()
 * - Pack mensuel par personne  = PACK_SOLO[pack] × format.ratioFromSolo() × slot.multiplier()
 *                                + surcharge FullAccess si activée
 *
 * Les prix sont manipulés en float en interne et retournés en string DECIMAL "0.00"
 * pour la compatibilité avec les colonnes DECIMAL de la base de données.
 */
final class PricingCalculator
{
    /**
     * Prix de référence : séance unique SOLO en créneau DAY.
     * Calcul : 150 € (pack 4) / 4 séances = 37,50 € la séance.
     */
    private const SOLO_SINGLE_BASE = 37.50;

    /**
     * Prix mensuel Solo × créneau DAY pour chaque type de pack.
     * Source officielle : grille tarifaire validée par le gérant.
     */
    private const PACK_SOLO_MONTHLY = [
        PackType::PACK_4->value  => 150.0,
        PackType::PACK_8->value  => 240.0,
        PackType::PACK_12->value => 320.0,
    ];

    /**
     * Surcharge mensuelle FullAccess (accès salle 24h/24).
     * GROUP bénéficie d'un tarif réduit car l'accès est partagé.
     */
    private const FULL_ACCESS_SURCHARGE_GROUP  = 25.0;  // €/mois pour GROUP
    private const FULL_ACCESS_SURCHARGE_OTHERS = 30.0;  // €/mois pour SOLO/DUO/TRIO

    /**
     * Prix d'une séance unique pour 1 personne (avant multiplication par le nombre de participants).
     *
     * Exemples :
     *   SOLO  + DAY       → 37,50 €
     *   DUO   + NIGHT     → 37,50 × 0,70 × 1,40 = 36,75 €
     *   TRIO  + ASTREINTE → 37,50 × 0,56 × 1,80 = 37,80 €
     *   GROUP + DAY       → 37,50 × 0,40 × 1,00 = 15,00 €
     */
    public function singleSessionPrice(BookingFormat $format, TimeSlot $slot): string
    {
        $price = self::SOLO_SINGLE_BASE * $format->ratioFromSolo() * $slot->multiplier();

        return number_format($price, 2, '.', '');
    }

    /**
     * Prix mensuel par personne pour un pack donné.
     *
     * @throws \InvalidArgumentException si PackType::SINGLE est fourni (pas de tarif mensuel)
     */
    public function monthlyPackPrice(
        BookingFormat $format,
        PackType $pack,
        TimeSlot $slot,
        bool $fullAccess = false,
    ): string {
        $soloBase = self::PACK_SOLO_MONTHLY[$pack->value] ?? null;
        if (null === $soloBase) {
            throw new \InvalidArgumentException(
                sprintf('"%s" est une séance unique, pas un pack mensuel.', $pack->label())
            );
        }

        // Prix de base : Solo × ratio format × multiplicateur créneau
        $price = $soloBase * $format->ratioFromSolo() * $slot->multiplier();

        // Surcharge FullAccess si demandée
        if ($fullAccess) {
            $price += $format === BookingFormat::GROUP
                ? self::FULL_ACCESS_SURCHARGE_GROUP
                : self::FULL_ACCESS_SURCHARGE_OTHERS;
        }

        return number_format($price, 2, '.', '');
    }

    /**
     * Répartition marge structure / coach sur un prix total.
     *
     * @return array{total: string, structure: string, coach: string}
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

    /**
     * Formatage affichage français : "1 234,56 €"
     */
    public function formatPrice(string $price): string
    {
        return number_format((float) $price, 2, ',', "\u{202F}") . ' €';
    }
}
