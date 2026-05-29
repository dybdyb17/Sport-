<?php

namespace App\Service;

use App\Entity\Booking;
use App\Entity\Coach;
use App\Entity\Conversation;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

class BookingManager
{
    /**
     * Configuration des prestations : durée (minutes) + tarif de base + tarif/minute.
     * Centralisé ici pour rester la seule source de vérité (prix ET durée).
     */
    private const SERVICES = [
        'night_coach' => ['duration' => 60, 'base' => 45.0, 'per_min' => 0.75],
        'small_group' => ['duration' => 60, 'base' => 22.0, 'per_min' => 0.30],
        'solo_day' => ['duration' => 60, 'base' => 35.0, 'per_min' => 0.60],
        'groupe_6' => ['duration' => 90, 'base' => 20.0, 'per_min' => 0.25],
    ];

    private const DEFAULT_SERVICE = ['duration' => 60, 'base' => 40.0, 'per_min' => 0.65];

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly NotificationService $notifier,
    ) {
    }

    /**
     * Crée une nouvelle réservation (premier arrivé, premier servi + préavis 2h).
     * La durée et le prix sont déduits du type de prestation.
     */
    public function create(
        User $client,
        Coach $coach,
        string $serviceType,
        \DateTimeImmutable $startAt,
        ?string $message = null,
    ): Booking {
        $config = self::SERVICES[$serviceType] ?? self::DEFAULT_SERVICE;
        $endAt = $startAt->modify(sprintf('+%d minutes', $config['duration']));

        // Vérifier la disponibilité (premier arrivé, premier servi).
        if (!$coach->isAvailableOnSlot($startAt, $endAt)) {
            throw new ConflictHttpException('Ce créneau n\'est plus disponible. Veuillez en choisir un autre.');
        }

        $price = $this->calculatePrice($serviceType, $config['duration'], $coach->getHourlyRate());

        $booking = new Booking();
        $booking
            ->setClient($client)
            ->setCoach($coach)
            ->setServiceType($serviceType)
            ->setStartAt($startAt)
            ->setEndAt($endAt)
            ->setStatus(Booking::STATUS_PENDING)
            ->setMessage($message)
            ->setPrice($price);

        $this->em->persist($booking);
        $this->em->flush();

        // Notifier le coach en temps réel (Mercure).
        $this->notifier->notifyCoach($booking);

        return $booking;
    }

    /**
     * Confirme une réservation (action du coach).
     */
    public function confirm(Booking $booking, User $coachUser): Booking
    {
        if ($booking->getCoach()?->getUser() !== $coachUser) {
            throw new \LogicException('Vous ne pouvez pas confirmer cette réservation');
        }

        if ($booking->getStatus() !== Booking::STATUS_PENDING) {
            throw new \LogicException('Cette réservation n\'est plus en attente');
        }

        $booking
            ->setStatus(Booking::STATUS_CONFIRMED)
            ->setConfirmedAt(new \DateTimeImmutable());

        // Ouvrir la conversation dès que la réservation est confirmée
        if ($booking->getConversation() === null) {
            $conversation = new Conversation();
            $conversation->setBooking($booking);
            $this->em->persist($conversation);
        }

        $this->em->flush();
        $this->notifier->notifyClient($booking);

        return $booking;
    }

    /**
     * Refuse une réservation (action du coach).
     */
    public function refuse(Booking $booking, User $coachUser): Booking
    {
        if ($booking->getCoach()?->getUser() !== $coachUser) {
            throw new \LogicException('Vous ne pouvez pas refuser cette réservation');
        }

        $booking->setStatus(Booking::STATUS_REFUSED);
        $this->em->flush();

        return $booking;
    }

    /**
     * Calcule le prix selon la prestation et la durée, ajusté par le tarif du coach.
     * Retourne une chaîne décimale (format BDD / Stripe).
     */
    private function calculatePrice(string $serviceType, int $durationMinutes, ?string $coachRate): string
    {
        $config = self::SERVICES[$serviceType] ?? self::DEFAULT_SERVICE;
        $total = $config['base'] + ($config['per_min'] * $durationMinutes);

        // Modulation par le tarif horaire du coach (référence : 40 €/h).
        $rate = $coachRate !== null ? (float) $coachRate : 40.0;
        if ($rate > 0 && abs($rate - 40.0) > 0.001) {
            $total *= ($rate / 40.0);
        }

        return number_format($total, 2, '.', '');
    }
}
