<?php

namespace App\Entity\Enum;

enum PackType: string
{
    case SINGLE  = 'single';   // Séance unique — paiement à la séance
    case PACK_4  = 'pack_4';   // 4 séances / mois
    case PACK_8  = 'pack_8';   // 8 séances / mois
    case PACK_12 = 'pack_12';  // 12 séances / mois

    // ─── Libellé affiché ───────────────────────────────────────────────────
    public function label(): string
    {
        return match ($this) {
            self::SINGLE  => 'Séance unique',
            self::PACK_4  => 'Pack 4 séances / mois',
            self::PACK_8  => 'Pack 8 séances / mois',
            self::PACK_12 => 'Pack 12 séances / mois',
        };
    }

    // ─── Nombre de séances incluses ────────────────────────────────────────
    public function sessionsCount(): int
    {
        return match ($this) {
            self::SINGLE  => 1,
            self::PACK_4  => 4,
            self::PACK_8  => 8,
            self::PACK_12 => 12,
        };
    }

    /**
     * Prix mensuel de référence SOLO + créneau DAY (multiplicateur 1.0).
     * Retourne null pour SINGLE (pas de tarif mensuel, c'est du pay-per-session).
     *
     * Valeurs officielles : Pack 4 = 150 €, Pack 8 = 240 €, Pack 12 = 320 €.
     */
    public function soloMonthlyPrice(): ?float
    {
        return match ($this) {
            self::SINGLE  => null,   // pas de forfait mensuel
            self::PACK_4  => 150.0,
            self::PACK_8  => 240.0,
            self::PACK_12 => 320.0,
        };
    }
}
