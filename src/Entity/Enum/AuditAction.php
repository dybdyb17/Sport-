<?php

namespace App\Entity\Enum;

enum AuditAction: string
{
    // Actions sur les bookings
    case BOOKING_CREATED   = 'booking_created';     // client crée une demande
    case BOOKING_CONFIRMED = 'booking_confirmed';   // coach accepte
    case BOOKING_REFUSED   = 'booking_refused';     // coach refuse
    case BOOKING_CANCELLED = 'booking_cancelled';   // client annule

    // Actions paiement (sensibles)
    case PAYMENT_DECLARED      = 'payment_declared';      // coach déclare encaissement
    case PAYMENT_CONFIRMED     = 'payment_confirmed';     // client confirme avoir payé
    case PAYMENT_DISPUTED      = 'payment_disputed';      // client conteste
    case NO_SHOW_MARKED        = 'no_show_marked';        // coach marque absence client

    // Actions admin
    case ROLE_GRANTED          = 'role_granted';          // ROLE_ADMIN accordé
    case ROLE_REVOKED          = 'role_revoked';          // rôle retiré
    case USER_DELETED          = 'user_deleted';          // suppression compte
    case ADMIN_PRICE_OVERRIDE  = 'admin_price_override';  // admin modifie un prix
    case FOUNDING_BILAN_DONE   = 'founding_bilan_done';   // bilan fondateur marqué fait

    public function label(): string
    {
        return match ($this) {
            self::BOOKING_CREATED        => 'Nouvelle demande',
            self::BOOKING_CONFIRMED      => 'Réservation confirmée',
            self::BOOKING_REFUSED        => 'Réservation refusée',
            self::BOOKING_CANCELLED      => 'Réservation annulée',
            self::PAYMENT_DECLARED       => 'Paiement déclaré',
            self::PAYMENT_CONFIRMED      => 'Paiement confirmé par le client',
            self::PAYMENT_DISPUTED       => 'Paiement contesté par le client',
            self::NO_SHOW_MARKED         => 'Absence client marquée',
            self::ROLE_GRANTED           => 'Rôle accordé',
            self::ROLE_REVOKED           => 'Rôle retiré',
            self::USER_DELETED           => 'Compte supprimé',
            self::ADMIN_PRICE_OVERRIDE   => 'Prix modifié par admin',
            self::FOUNDING_BILAN_DONE    => 'Bilan fondateur effectué',
        };
    }

    /** Niveau de criticité affiché dans l'UI admin. */
    public function severity(): string
    {
        return match ($this) {
            self::PAYMENT_DISPUTED, self::USER_DELETED, self::ADMIN_PRICE_OVERRIDE => 'critical',
            self::PAYMENT_DECLARED, self::NO_SHOW_MARKED, self::ROLE_GRANTED, self::ROLE_REVOKED => 'warning',
            default => 'info',
        };
    }
}
