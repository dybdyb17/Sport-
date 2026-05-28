<?php
namespace App\Entity\Enum;

enum UserRole: string
{
    case CLIENT = 'client';
    case COACH = 'coach';
    case ADMIN = 'admin';

    public function getLabel(): string
    {
        return match($this) {
            self::CLIENT => 'Client',
            self::COACH => 'Coach',
            self::ADMIN => 'Admin',
        };
    }
}
