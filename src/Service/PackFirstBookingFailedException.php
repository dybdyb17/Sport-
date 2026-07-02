<?php

namespace App\Service;

/**
 * Signale que le pack a bien été activé (paiement acté, Subscription créée)
 * mais que la 1ère séance associée n'a pas pu être posée : typiquement le
 * créneau initialement demandé a été pris pendant l'attente de validation.
 *
 * L'appelant (webhook Stripe ou controller coach) DOIT catcher cette exception
 * et informer l'humain (flash coach, mail admin), mais ne PAS annuler le pack :
 * le client a payé, on ne peut pas le lui retirer.
 */
final class PackFirstBookingFailedException extends \RuntimeException
{
}
