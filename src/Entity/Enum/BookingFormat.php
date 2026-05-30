<?php

namespace App\Entity\Enum;

enum BookingFormat: string
{
    case SOLO  = 'solo';  // 1 personne
    case DUO   = 'duo';   // 2 personnes
    case GROUP = 'group'; // 4 à 6 personnes (capacité variable)

    public function label(): string
    {
        return match ($this) {
            self::SOLO  => 'Solo (1 pers.)',
            self::DUO   => 'Duo (2 pers.)',
            self::GROUP => 'Groupe (4-6 pers.)',
        };
    }

    public function personsMin(): int
    {
        return match ($this) {
            self::SOLO  => 1,
            self::DUO   => 2,
            self::GROUP => 4,
        };
    }

    public function personsMax(): int
    {
        return match ($this) {
            self::SOLO  => 1,
            self::DUO   => 2,
            self::GROUP => 6,
        };
    }

    /** Nombre fixe pour SOLO/DUO ; pour GROUP, retourne personsMin(). */
    public function personsCount(): int
    {
        return $this->personsMin();
    }

    /** Ratio vs SOLO utilisé pour rétro-compatibilité (certains calculs anciens). */
    public function ratioFromSolo(): float
    {
        return match ($this) {
            self::SOLO  => 1.00,
            self::DUO   => 0.70,
            self::GROUP => 0.40,
        };
    }
}
