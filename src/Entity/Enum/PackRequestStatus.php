<?php

namespace App\Entity\Enum;

/**
 * Statut de traitement d'une PendingPackRequest.
 *
 * - PENDING   : demande créée, en attente (soit paiement Stripe non reçu,
 *               soit paiement sur place non encaissé par le coach).
 * - CONFIRMED : demande validée → Subscription + 1ère séance créées.
 *               Pour le online = webhook Stripe. Pour le sur-place = clic coach.
 * - REFUSED   : demande annulée (client absent, refus coach).
 *
 * ⚠️ Le statut PENDING n'active JAMAIS un pack. Vérifié côté serveur
 * (findActiveForClient sur Subscription ne renvoie que status=active).
 */
enum PackRequestStatus: string
{
    case PENDING   = 'pending';
    case CONFIRMED = 'confirmed';
    case REFUSED   = 'refused';

    public function label(): string
    {
        return match ($this) {
            self::PENDING   => 'En attente',
            self::CONFIRMED => 'Validée',
            self::REFUSED   => 'Refusée',
        };
    }
}
