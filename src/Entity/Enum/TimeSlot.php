<?php

namespace App\Entity\Enum;

enum TimeSlot: string
{
    case DAY = 'day';
    case NIGHT = 'night';
    case ASTREINTE = 'astreinte';

    public function label(): string
    {
        return match ($this) {
            self::DAY => 'Day',
            self::NIGHT => 'Night',
            self::ASTREINTE => 'Astreinte',
        };
    }

    public function rangeLabel(): string
    {
        return match ($this) {
            self::DAY => '06:00 — 21:00',
            self::NIGHT => '21:00 — 00:00',
            self::ASTREINTE => '00:00 — 06:00',
        };
    }

    public function structureMargin(): float
    {
        return match ($this) {
            self::DAY => 0.45,
            self::NIGHT => 0.40,
            self::ASTREINTE => 0.35,
        };
    }

    public function icon(): string
    {
        return match ($this) {
            self::DAY => 'ti-sun',
            self::NIGHT => 'ti-moon',
            self::ASTREINTE => 'ti-moon-stars',
        };
    }

    public static function fromDateTime(\DateTimeInterface $date): self
    {
        $hour = (int) $date->format('G');

        return match (true) {
            $hour >= 6 && $hour < 21 => self::DAY,
            $hour >= 21 => self::NIGHT,
            default => self::ASTREINTE,
        };
    }
}
