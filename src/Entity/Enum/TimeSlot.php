<?php

namespace App\Entity\Enum;

enum TimeSlot: string
{
    case DAY       = 'day';       // 6h-21h
    case NIGHT     = 'night';     // 21h-minuit
    case ASTREINTE = 'astreinte'; // minuit-6h

    // ─── Multiplicateurs tarifaires ────────────────────────────────────────
    public function multiplier(): float
    {
        return match ($this) {
            self::DAY       => 1.0,
            self::NIGHT     => 1.40,
            self::ASTREINTE => 1.80,
        };
    }

    // ─── Libellé affiché ───────────────────────────────────────────────────
    public function label(): string
    {
        return match ($this) {
            self::DAY       => 'Journée 6h-21h',
            self::NIGHT     => 'Night Coach 21h-minuit',
            self::ASTREINTE => 'Astreinte minuit-6h',
        };
    }

    /**
     * Détermine le créneau d'après l'heure d'un DateTimeImmutable.
     * 0h ≤ h < 6h  → ASTREINTE
     * 6h ≤ h < 21h → DAY
     * 21h ≤ h      → NIGHT
     */
    public static function fromDateTime(\DateTimeImmutable $dt): self
    {
        $hour = (int) $dt->format('G'); // heure sans zéro initial (0-23)

        return match (true) {
            $hour >= 6 && $hour < 21 => self::DAY,
            $hour >= 21              => self::NIGHT,
            default                  => self::ASTREINTE,
        };
    }

    // ─── Marge structure (part structure sur le CA de ce créneau) ──────────
    public function structureMarginRate(): float
    {
        return match ($this) {
            self::DAY       => 0.45, // 45% structure / 55% coach
            self::NIGHT     => 0.40, // 40% structure / 60% coach
            self::ASTREINTE => 0.35, // 35% structure / 65% coach
        };
    }
}
