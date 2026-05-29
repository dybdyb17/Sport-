<?php

namespace App\Entity\Enum;

enum PackType: string
{
    case PACK_4 = 'pack_4';
    case PACK_8 = 'pack_8';
    case PACK_12 = 'pack_12';

    public function label(): string
    {
        return match ($this) {
            self::PACK_4 => 'Pack 4',
            self::PACK_8 => 'Pack 8',
            self::PACK_12 => 'Pack 12',
        };
    }

    public function sessions(): int
    {
        return match ($this) {
            self::PACK_4 => 4,
            self::PACK_8 => 8,
            self::PACK_12 => 12,
        };
    }
}
