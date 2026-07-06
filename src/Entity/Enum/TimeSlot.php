<?php

namespace App\Entity\Enum;

enum TimeSlot: string
{
    case DAY       = 'day';       // 6h-20h
    case NIGHT     = 'night';     // 20h-minuit
    case ASTREINTE = 'astreinte'; // minuit-6h

    public function multiplier(): float
    {
        return match ($this) {
            self::DAY       => 1.0,
            self::NIGHT     => 1.40,
            self::ASTREINTE => 1.80,
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::DAY       => 'Journée 6h-20h',
            self::NIGHT     => 'Night Coach 20h-minuit',
            self::ASTREINTE => 'Astreinte minuit-6h',
        };
    }

    public function shortLabel(): string
    {
        return match ($this) {
            self::DAY       => 'Journée',
            self::NIGHT     => 'Night Coach',
            self::ASTREINTE => 'Astreinte',
        };
    }

    public function bookingWindowStartLabel(): string
    {
        return match ($this) {
            self::DAY       => '06h00',
            self::NIGHT     => '20h00',
            self::ASTREINTE => '00h00',
        };
    }

    public function bookingWindowEndLabel(): string
    {
        return match ($this) {
            self::DAY       => '19h59',
            self::NIGHT     => '23h59',
            self::ASTREINTE => '05h59',
        };
    }

    /**
     * Détermine le créneau d'après l'heure d'un DateTimeImmutable.
     * 0h ≤ h < 6h  → ASTREINTE
     * 6h ≤ h < 20h → DAY
     * 20h ≤ h      → NIGHT
     */
    public static function fromDateTime(\DateTimeImmutable $dt): self
    {
        $hour = (int) $dt->format('H');

        if ($hour >= 6 && $hour < 20) {
            return self::DAY;
        }
        if ($hour >= 20) {
            return self::NIGHT;
        }
        return self::ASTREINTE;
    }

    public function structureMarginRate(): float
    {
        return match ($this) {
            self::DAY       => 0.45,
            self::NIGHT     => 0.40,
            self::ASTREINTE => 0.35,
        };
    }
}
