<?php

namespace App\Entity\Enum;

enum BookingFormat: string
{
    case SOLO  = 'solo';  // 1 personne
    case DUO   = 'duo';   // 2 personnes
    case TRIO  = 'trio';  // 3 personnes
    case GROUP = 'group'; // 4 à 6 personnes (capacité variable)

    // ─── Libellé affiché ───────────────────────────────────────────────────
    public function label(): string
    {
        return match ($this) {
            self::SOLO  => 'Solo',
            self::DUO   => 'Duo (2 pers.)',
            self::TRIO  => 'Trio (3 pers.)',
            self::GROUP => 'Groupe (4-6 pers.)',
        };
    }

    // ─── Plage du nombre de participants ───────────────────────────────────
    public function personsMin(): int
    {
        return match ($this) {
            self::SOLO  => 1,
            self::DUO   => 2,
            self::TRIO  => 3,
            self::GROUP => 4,
        };
    }

    public function personsMax(): int
    {
        return match ($this) {
            self::SOLO  => 1,
            self::DUO   => 2,
            self::TRIO  => 3,
            self::GROUP => 6,
        };
    }

    /**
     * Nombre fixe de participants pour SOLO/DUO/TRIO.
     * Pour GROUP, retourne personsMin() — la valeur exacte est stockée sur Booking.
     */
    public function personsCount(): int
    {
        return $this->personsMin();
    }

    /**
     * Ratio par rapport au tarif SOLO (source des formules de prix dérivés).
     * SOLO = 37,50 €, DUO = 26,25 €, TRIO = 21 €, GROUP = 15 € par personne.
     */
    public function ratioFromSolo(): float
    {
        return match ($this) {
            self::SOLO  => 1.00, // 37,50 €
            self::DUO   => 0.70, // 26,25 €
            self::TRIO  => 0.56, // 21,00 €
            self::GROUP => 0.40, // 15,00 €
        };
    }
}
