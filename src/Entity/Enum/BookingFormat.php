<?php

namespace App\Entity\Enum;

enum BookingFormat: string
{
    case SOLO = 'solo';
    case DUO = 'duo';
    case TRIO = 'trio';
    case GROUP = 'group';

    public function label(): string
    {
        return match ($this) {
            self::SOLO => 'Solo',
            self::DUO => 'Duo',
            self::TRIO => 'Trio',
            self::GROUP => 'Groupe',
        };
    }
}
