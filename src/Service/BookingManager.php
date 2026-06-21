<?php

namespace App\Service;

use App\Entity\Booking;
use App\Entity\Coach;
use App\Entity\Conversation;
use App\Entity\Enum\BookingFormat;
use App\Entity\Enum\PackType;
use App\Entity\Enum\TimeSlot;
use App\Entity\Subscription;
use App\Entity\User;
use App\Repository\ConversationRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

class BookingManager
{
    /** Durée standard d'une séance en minutes (peut évoluer par format si besoin) */
    private const SESSION_DURATION_MINUTES = 60;

    /**
     * Si une séance est confirmée à moins de cette durée de son startAt, on envoie
     * le rappel J-1 IMMÉDIATEMENT (pas le lendemain) — sinon le cron quotidien à 10h
     * la raterait. 30h couvre :
     *  - une résa du soir pour le lendemain matin (8h-9h-10h)
     *  - une confirmation un peu tardive le matin pour le surlendemain matinal
     */
    private const IMMEDIATE_REMINDER_THRESHOLD_HOURS = 30;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly NotificationService    $notifier,
        private readonly PricingCalculator      $pricing,
        private readonly FoundingOfferService   $foundingService,
        private readonly MailerService          $mailer,
        private readonly ConversationRepository $conversationRepo,
        #[Autowire('%kernel.secret%')]
        private readonly string                 $appSecret,
    ) {}

    /**
     * Crée une nouvelle réservation.
     *
     * Prix stocké dans Booking.price = singleSessionPrice × personsCount.
     * Si un pack est fourni, ce prix est "théorique" (la séance est déjà payée
     * via le pack mensuel) — cela permet aux stats CA d'être cohérentes.
     */
    public function create(
        User         $client,
        Coach        $coach,
        BookingFormat $format,
        TimeSlot     $timeSlot,
        int          $personsCount,
        \DateTimeImmutable $startAt,
        ?string      $message      = null,
        ?Subscription $subscription = null,
    ): Booking {
        $endAt = $startAt->modify(sprintf('+%d minutes', self::SESSION_DURATION_MINUTES));

        // Vérification de disponibilité (premier arrivé, premier servi)
        if (!$coach->isAvailableOnSlot($startAt, $endAt)) {
            throw new ConflictHttpException('Ce créneau n\'est plus disponible. Veuillez en choisir un autre.');
        }

        // Vérification du pack si fourni
        if (null !== $subscription) {
            if ($subscription->getClient() !== $client) {
                throw new \LogicException('Ce pack ne vous appartient pas.');
            }
            if (!$subscription->hasSessionsLeft()) {
                throw new \LogicException('Ce pack n\'a plus de séances disponibles.');
            }
        }

        // Prix par personne × nombre de participants
        $pricePerPerson = $this->pricing->singleSessionPrice($format, $timeSlot);
        $totalPrice     = number_format((float) $pricePerPerson * $personsCount, 2, '.', '');

        $booking = new Booking();
        $booking
            ->setClient($client)
            ->setCoach($coach)
            ->setFormat($format)
            ->setTimeSlot($timeSlot)
            ->setPersonsCount($personsCount)
            ->setSubscription($subscription)
            ->setStartAt($startAt)
            ->setEndAt($endAt)
            ->setStatus(Booking::STATUS_PENDING)
            ->setMessage($message)
            ->setPrice($totalPrice);

        $this->em->persist($booking);
        $this->em->flush();

        $this->notifier->notifyCoach($booking);
        $this->mailer->sendBookingPendingToCoach($booking);

        return $booking;
    }

    /**
     * Confirme une réservation (action du coach).
     * Si un pack est rattaché, consomme une séance.
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

        // Ouvrir/réutiliser la conversation entre ce client et ce coach.
        // Une seule conv par couple (client, coach) — les bookings suivants partagent la même.
        if ($booking->getConversation() === null) {
            $existing = $this->conversationRepo->findForPair($booking->getClient(), $booking->getCoach());
            if ($existing !== null) {
                $booking->setConversation($existing);
            } else {
                $conversation = new Conversation();
                $conversation->setClient($booking->getClient());
                $conversation->setCoach($booking->getCoach());
                $conversation->setBooking($booking);
                $this->em->persist($conversation);
                $booking->setConversation($conversation);
            }
        }

        // Priorité couverte : Founding > Subscription > paiement normal
        $consumedFounding = $this->foundingService->consumeSessionFor($booking->getClient());
        if ($consumedFounding) {
            $booking->setCoveredBy(Booking::COVERED_BY_FOUNDING);
        } elseif ($booking->getSubscription() !== null) {
            $booking->getSubscription()->consume();
            $booking->setCoveredBy(Booking::COVERED_BY_SUBSCRIPTION);
        }

        $this->em->flush();
        $this->notifier->notifyClient($booking);
        $this->mailer->sendBookingConfirmedToClient($booking);

        // ── Rappel J-1 : envoi immédiat si la séance est trop proche pour le cron ──
        // Le cron tourne à 10h chaque jour. Si une séance est confirmée à moins de
        // 30h de son début (ex: résa du soir pour le lendemain matin), elle raterait
        // le cron J-1. On lui envoie le rappel tout de suite, et on flag
        // reminderSentAt pour éviter que le cron le renvoie en double.
        $this->sendImmediateReminderIfDue($booking);

        return $booking;
    }

    /**
     * Envoie le mail rappel J-1 immédiatement si la séance est dans <30h et que
     * le rappel n'a pas encore été envoyé. Idempotent : safe à appeler plusieurs fois.
     */
    private function sendImmediateReminderIfDue(Booking $booking): void
    {
        // Déjà envoyé → on ne refait pas
        if ($booking->getReminderSentAt() !== null) {
            return;
        }
        // Séance passée ou pas d'email client → on ne fait rien
        $startAt = $booking->getStartAt();
        $client  = $booking->getClient();
        if (!$startAt || !$client?->getEmail()) {
            return;
        }

        $now        = new \DateTimeImmutable();
        $hoursUntil = ($startAt->getTimestamp() - $now->getTimestamp()) / 3600;

        // Séance déjà commencée ou passée → on n'envoie pas (le mail dirait
        // "ta séance c'est demain" alors qu'elle est déjà finie)
        if ($hoursUntil <= 0) {
            return;
        }
        // Séance trop loin → le cron quotidien s'en occupera demain matin
        if ($hoursUntil > self::IMMEDIATE_REMINDER_THRESHOLD_HOURS) {
            return;
        }

        // Tous les cas entre 0 et 30h : on envoie tout de suite, peu importe l'heure
        // (résa à 1h du matin pour 7h, coach accepte à 5h pour séance à 10h, etc.)
        // On ne flag reminderSentAt QUE si le mail est vraiment parti — sinon
        // le cron du lendemain pourra retenter (sécurité contre une panne Resend).
        if ($this->mailer->sendDayBeforeReminder($booking, $this->appSecret)) {
            $booking->setReminderSentAt($now);
            $this->em->flush();
        }
    }

    /**
     * Refuse une réservation (action du coach).
     */
    public function refuse(Booking $booking, User $coachUser, ?string $reason = null): Booking
    {
        if ($booking->getCoach()?->getUser() !== $coachUser) {
            throw new \LogicException('Vous ne pouvez pas refuser cette réservation');
        }

        $booking->setStatus(Booking::STATUS_REFUSED);
        if ($reason !== null && trim($reason) !== '') {
            $booking->setRefusalReason(mb_substr(trim($reason), 0, 500));
        }
        $this->em->flush();
        $this->mailer->sendBookingRefusedToClient($booking);

        return $booking;
    }

    /**
     * Crée et persiste un abonnement mensuel.
     * Le prix est calculé via PricingCalculator (source de vérité unique).
     */
    public function createSubscription(
        User          $client,
        BookingFormat  $format,
        TimeSlot      $timeSlot,
        PackType      $pack,
        int           $personsCount,
        bool          $fullAccess = false,
        ?Coach        $coach      = null,
    ): Subscription {
        $monthlyPrice = $this->pricing->monthlyPackPrice($format, $pack, $timeSlot, $fullAccess);

        $subscription = new Subscription();
        $subscription
            ->setClient($client)
            ->setCoach($coach)
            ->setTimeSlot($timeSlot)
            ->setFormat($format)
            ->setPackType($pack)
            ->setPersonsCount($personsCount)
            ->setFullAccess($fullAccess)
            ->setMonthlyPrice($monthlyPrice)
            ->setSessionsRemaining($pack->sessionsCount())
            ->setStatus(Subscription::STATUS_ACTIVE);

        $this->em->persist($subscription);
        $this->em->flush();

        return $subscription;
    }
}
