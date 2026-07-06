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
    case ADMIN_IMPERSONATE     = 'admin_impersonate';     // admin prend identité d'un user
    case ADMIN_LEAVE_IMPERSONATE = 'admin_leave_impersonate'; // admin sort d'impersonnification

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
            self::NO_SHOW_MARKED         => 'Client marqué absent',
            self::ROLE_GRANTED           => 'Rôle accordé',
            self::ROLE_REVOKED           => 'Rôle retiré',
            self::USER_DELETED           => 'Compte supprimé',
            self::ADMIN_PRICE_OVERRIDE   => 'Prix modifié par admin',
            self::FOUNDING_BILAN_DONE    => 'Bilan fondateur effectué',
            self::ADMIN_IMPERSONATE      => 'Admin entré dans un espace utilisateur',
            self::ADMIN_LEAVE_IMPERSONATE => 'Admin sorti d\'un espace utilisateur',
        };
    }


    public function icon(): string
    {
        return match ($this) {
            self::BOOKING_CREATED         => 'ti-calendar-plus',
            self::BOOKING_CONFIRMED       => 'ti-calendar-check',
            self::BOOKING_REFUSED         => 'ti-calendar-x',
            self::BOOKING_CANCELLED       => 'ti-calendar-off',
            self::PAYMENT_DECLARED        => 'ti-cash-banknote',
            self::PAYMENT_CONFIRMED       => 'ti-circle-check',
            self::PAYMENT_DISPUTED        => 'ti-alert-octagon',
            self::NO_SHOW_MARKED          => 'ti-user-x',
            self::ROLE_GRANTED            => 'ti-shield-plus',
            self::ROLE_REVOKED            => 'ti-shield-minus',
            self::USER_DELETED            => 'ti-user-x',
            self::ADMIN_PRICE_OVERRIDE    => 'ti-pencil-dollar',
            self::FOUNDING_BILAN_DONE     => 'ti-clipboard-check',
            self::ADMIN_IMPERSONATE       => 'ti-user-scan',
            self::ADMIN_LEAVE_IMPERSONATE => 'ti-logout',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::PAYMENT_CONFIRMED, self::PAYMENT_DECLARED, self::BOOKING_CONFIRMED, self::FOUNDING_BILAN_DONE => '#2ecc71',
            self::PAYMENT_DISPUTED, self::NO_SHOW_MARKED, self::BOOKING_REFUSED, self::BOOKING_CANCELLED, self::USER_DELETED => '#ff6b6b',
            self::ADMIN_IMPERSONATE => '#a78bfa',
            self::ADMIN_LEAVE_IMPERSONATE => '#60a5fa',
            self::ROLE_GRANTED, self::ROLE_REVOKED, self::ADMIN_PRICE_OVERRIDE => '#ffd166',
            default => '#d7d7df',
        };
    }

    /** Niveau de criticité affiché dans l'UI admin. */
    public function severity(): string
    {
        return match ($this) {
            self::PAYMENT_DISPUTED, self::USER_DELETED, self::ADMIN_PRICE_OVERRIDE => 'critical',
            self::PAYMENT_DECLARED, self::NO_SHOW_MARKED, self::ROLE_GRANTED, self::ROLE_REVOKED, self::ADMIN_IMPERSONATE => 'warning',
            default => 'info',
        };
    }
}
