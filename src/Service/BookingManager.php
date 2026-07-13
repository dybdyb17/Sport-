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
        private readonly \App\Service\AuditLogger $auditLogger,
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

        // Vérification de disponibilité : le coach accepte jusqu'à
        // MAX_BOOKINGS_PER_EXACT_SLOT (= 2) réservations sur le même créneau
        // exact (même heure de début). Les chevauchements d'horaires
        // différents (ex 21h00 vs 21h30) ne sont PAS bloqués — le coach les
        // gère humainement.
        if (!$coach->isSlotAcceptingBooking($startAt)) {
            throw new ConflictHttpException(sprintf(
                'Ce créneau est complet (%d réservations maximum). Choisis un autre horaire.',
                Coach::MAX_BOOKINGS_PER_EXACT_SLOT,
            ));
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

        // Prix par personne × nombre de participants.
        // -5% Membre Fondateur appliqué automatiquement dans singleSessionPrice()
        // si le client est fondateur — le prix figé sur la Booking reflète le
        // vrai montant que le client va payer.
        $pricePerPerson = $this->pricing->singleSessionPrice($format, $timeSlot, $client->isFoundingMember());
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
    /**
     * Le coach déclare un encaissement SUR PLACE (espèces ou carte).
     *
     * Point d'entrée UNIQUE pour les 2 flows :
     *  - dashboard coach (CoachController::declarePayment) → source 'coach_dashboard'
     *  - page de scan QR (AdminCheckinController::declarePaymentOnScan) → source 'checkin_scan'
     *
     * ⚠️ 'stripe' interdit — c'est le webhook Stripe qui pose ce mode-là, jamais
     * un humain. Idempotent : si paymentMethod déjà posé, jette LogicException.
     *
     * @throws \InvalidArgumentException si $method n'est pas cash/card
     * @throws \LogicException           si le booking est déjà encaissé
     */
    public function declareOnSitePayment(
        Booking $booking,
        string  $method,
        ?string $note,
        User    $declaredBy,
        string  $auditSource = 'coach_dashboard',
    ): Booking {
        if (!in_array($method, ['cash', 'card'], true)) {
            throw new \InvalidArgumentException('Mode de paiement invalide (attendu : cash ou card).');
        }
        if ($booking->getPaymentMethod() !== null) {
            throw new \LogicException('Ce paiement est déjà déclaré.');
        }

        $booking
            ->setPaymentMethod($method)
            ->setPaymentDeclaredAt(new \DateTimeImmutable())
            ->setPaymentDeclaredBy($declaredBy)
            ->setPaymentNote($note);
        $this->em->flush();

        $this->auditLogger->log(\App\Entity\Enum\AuditAction::PAYMENT_DECLARED, $booking, [
            'source'  => $auditSource,
            'method'  => $method,
            'amount'  => $booking->getPrice(),
            'note'    => $note ?: null,
            'client'  => $booking->getClient()?->getNomComplet(),
            'startAt' => $booking->getStartAt()?->format('c'),
        ]);

        return $booking;
    }

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
        // -5% Membre Fondateur appliqué automatiquement — le monthlyPrice
        // stocké dans Subscription est le vrai prix qui sera facturé.
        $monthlyPrice = $this->pricing->monthlyPackPrice(
            $format,
            $pack,
            $timeSlot,
            $fullAccess,
            $client->isFoundingMember(),
        );

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

    /**
     * Matérialise un pack à partir d'une PendingPackRequest confirmée :
     * crée le Subscription + tente la création de la 1ère séance.
     *
     * Point d'entrée UNIQUE pour les 2 flows :
     *  - online  : appelé par StripeCheckoutService::fulfillPackCheckout (webhook)
     *  - on-site : appelé par CoachController::validatePack (action coach)
     *
     * Idempotent : si le PPR est déjà validé et lié à un Subscription, on
     * retourne l'existant sans rien créer.
     *
     * Si la création de la 1ère séance échoue (créneau pris entre la demande
     * et la validation), le pack est QUAND MÊME activé (client a payé) et
     * l'erreur est loggée pour info coach/admin. La cause est retournée via
     * $firstBookingError pour permettre à l'appelant d'informer l'humain.
     */
    public function materializePackFromRequest(
        \App\Entity\PendingPackRequest $ppr,
        string $paymentMethod,
        ?User $validatedBy = null,
    ): Subscription {
        // Idempotence — protège contre les webhooks doublons + doubles clics coach
        if ($ppr->getSubscription() !== null
            && $ppr->getStatus() === \App\Entity\Enum\PackRequestStatus::CONFIRMED
        ) {
            return $ppr->getSubscription();
        }

        // 1) Créer le Subscription
        $subscription = $this->createSubscription(
            $ppr->getClient(),
            $ppr->getFormat(),
            $ppr->getTimeSlot(),
            $ppr->getPackType(),
            $ppr->getPersonsCount(),
            $ppr->isFullAccess(),
            $ppr->getCoach(),
        );

        $subscription
            ->setPaymentMethod($paymentMethod)
            ->setPaidAt(new \DateTimeImmutable());

        // 2) Créer la 1ʳᵉ séance — cas créneau pris n'invalide PAS le pack
        $firstBooking = null;
        try {
            $firstBooking = $this->create(
                $ppr->getClient(),
                $ppr->getCoach(),
                $ppr->getFormat(),
                $ppr->getTimeSlot(),
                $ppr->getPersonsCount(),
                $ppr->getStartAt(),
                $ppr->getMessage(),
                $subscription,
            );
        } catch (\Throwable $e) {
            // Pack activé quand même. Le client verra son pack actif + 0 séance
            // programmée et pourra réserver un autre créneau depuis /mes-packs.
            // L'appelant peut choisir de flash un message au coach/admin.
            $ppr->setStatus(\App\Entity\Enum\PackRequestStatus::CONFIRMED);
            $ppr->setSubscription($subscription);
            $ppr->setValidatedAt(new \DateTimeImmutable());
            if ($validatedBy) {
                $ppr->setValidatedBy($validatedBy);
            }
            $this->em->flush();

            // Mails d'activation — pack effectivement créé, 1ère séance manquante.
            // Ces try/catch internes garantissent qu'un échec Resend ne masque
            // pas la PackFirstBookingFailedException métier ci-dessous.
            $this->mailer->sendPackActivatedToClient($subscription, null);
            $this->mailer->sendPackSoldToAdmin($subscription);

            throw new PackFirstBookingFailedException(
                sprintf('Pack activé (id=%d) mais créneau du %s non dispo : %s',
                    $subscription->getId(),
                    $ppr->getStartAt()->format('d/m/Y à H\hi'),
                    $e->getMessage()),
                previous: $e,
            );
        }

        // 3) Marquer le PPR confirmed + lien
        $ppr->setStatus(\App\Entity\Enum\PackRequestStatus::CONFIRMED);
        $ppr->setSubscription($subscription);
        $ppr->setValidatedAt(new \DateTimeImmutable());
        if ($validatedBy) {
            $ppr->setValidatedBy($validatedBy);
        }
        $this->em->flush();

        // Mails d'activation — point d'appel UNIQUE pour les 2 flows (stripe +
        // sur place). L'idempotence est garantie par le short-circuit tout en
        // haut de la méthode : si le PPR est déjà CONFIRMED, on retourne sans
        // rien créer et les mails ne partent PAS. Un pack = un mail.
        // Les méthodes du mailer avalent leurs propres exceptions (try/catch +
        // logger), donc un Resend down ne cassera ni le paiement ni la
        // validation coach.
        $this->mailer->sendPackActivatedToClient($subscription, $firstBooking);
        $this->mailer->sendPackSoldToAdmin($subscription);

        return $subscription;
    }
}
