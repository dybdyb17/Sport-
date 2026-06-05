<?php

namespace App\Entity;

use App\Entity\Enum\BookingFormat;
use App\Entity\Enum\TimeSlot;
use App\Repository\BookingRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Validator\Constraints\UniqueEntity;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

#[ORM\Entity(repositoryClass: BookingRepository::class)]
#[UniqueEntity(fields: ['client', 'startAt', 'coach'], message: 'Réservation en double détectée')]
class Booking
{
    public const STATUS_PENDING   = 'pending';
    public const STATUS_CONFIRMED = 'confirmed';
    public const STATUS_REFUSED   = 'refused';
    public const STATUS_CANCELLED = 'cancelled';

    /** Préavis minimum obligatoire en secondes (2 heures) */
    public const MIN_ADVANCE_SECONDS = 7200;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 20, unique: true)]
    private ?string $reference = null;

    #[ORM\ManyToOne(inversedBy: 'bookings')]
    #[ORM\JoinColumn(nullable: false)]
    private ?User $client = null;

    #[ORM\ManyToOne(inversedBy: 'bookings')]
    #[ORM\JoinColumn(nullable: false)]
    private ?Coach $coach = null;

    /** Format de séance : SOLO / DUO / TRIO / GROUP */
    #[ORM\Column(enumType: BookingFormat::class)]
    private BookingFormat $format = BookingFormat::SOLO;

    /** Créneau horaire : DAY / NIGHT / ASTREINTE */
    #[ORM\Column(enumType: TimeSlot::class)]
    private TimeSlot $timeSlot = TimeSlot::DAY;

    /**
     * Nombre réel de participants :
     * - SOLO : 1, DUO : 2, TRIO : 3 (déduits automatiquement du format)
     * - GROUP : 4 à 6 (saisi par le client)
     */
    #[ORM\Column]
    private int $personsCount = 1;

    /**
     * Pack mensuel rattaché (null pour une séance unique pay-per-session).
     *
     * Décision tarifaire : même si la séance est couverte par un pack,
     * on stocke le prix unitaire "théorique" (singleSessionPrice) dans price.
     * Cela permet aux stats CA d'être cohérentes et comparables.
     */
    #[ORM\ManyToOne(inversedBy: 'bookings')]
    #[ORM\JoinColumn(nullable: true)]
    private ?Subscription $subscription = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    #[Assert\NotNull]
    #[Assert\GreaterThan('now', message: 'La date doit être dans le futur')]
    private ?\DateTimeImmutable $startAt = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private ?\DateTimeImmutable $endAt = null;

    #[ORM\Column(length: 30, options: ['default' => self::STATUS_PENDING])]
    private string $status = self::STATUS_PENDING;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $message = null;

    /**
     * Prix total de la séance en DECIMAL string (format BDD / Stripe).
     * = singleSessionPrice(format, slot) × personsCount pour toutes les formules.
     * Stocké même pour les séances pack (valeur théorique).
     */
    #[ORM\Column(type: Types::DECIMAL, precision: 8, scale: 2, nullable: true)]
    private ?string $price = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private ?\DateTimeImmutable $createdAt = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $confirmedAt = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $checkinAt = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?User $checkinBy = null;

    #[ORM\Column(length: 20, nullable: true)]
    private ?string $paymentMethod = null;
    // Valeurs : 'cash', 'card', 'xplor'

    #[ORM\Column(length: 20, nullable: true)]
    private ?string $intendedPaymentMethod = null;
    // Choix annoncé par le client à la réservation. Valeurs : 'cash', 'card', 'xplor'

    #[ORM\Column(length: 500, nullable: true)]
    private ?string $refusalReason = null;
    // Motif du refus saisi par le coach (visible par le client sur Mon RDV)

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $paymentDeclaredAt = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?User $paymentDeclaredBy = null;

    #[ORM\Column(length: 500, nullable: true)]
    private ?string $paymentNote = null;

    /**
     * @var array<string, mixed>|null
     */
    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $stripeData = null;

    public const COVERED_BY_FOUNDING      = 'founding';
    public const COVERED_BY_SUBSCRIPTION  = 'subscription';

    #[ORM\Column(length: 30, nullable: true)]
    private ?string $coveredBy = null;

    // ManyToOne : plusieurs bookings (du même client/coach) peuvent partager la même conv.
    #[ORM\ManyToOne(targetEntity: Conversation::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?Conversation $conversation = null;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
        $this->reference = 'SPT-' . strtoupper(bin2hex(random_bytes(4)));
    }

    public function getId(): ?int { return $this->id; }

    public function getReference(): ?string { return $this->reference; }

    public function getClient(): ?User { return $this->client; }
    public function setClient(?User $client): static { $this->client = $client; return $this; }

    public function getCoach(): ?Coach { return $this->coach; }
    public function setCoach(?Coach $coach): static { $this->coach = $coach; return $this; }

    public function getFormat(): BookingFormat { return $this->format; }
    public function setFormat(BookingFormat $format): static { $this->format = $format; return $this; }

    public function getTimeSlot(): TimeSlot { return $this->timeSlot; }
    public function setTimeSlot(TimeSlot $timeSlot): static { $this->timeSlot = $timeSlot; return $this; }

    public function getPersonsCount(): int { return $this->personsCount; }
    public function setPersonsCount(int $count): static { $this->personsCount = $count; return $this; }

    public function getSubscription(): ?Subscription { return $this->subscription; }
    public function setSubscription(?Subscription $subscription): static { $this->subscription = $subscription; return $this; }

    public function getStartAt(): ?\DateTimeImmutable { return $this->startAt; }
    public function setStartAt(?\DateTimeImmutable $startAt): static { $this->startAt = $startAt; return $this; }

    public function getEndAt(): ?\DateTimeImmutable { return $this->endAt; }
    public function setEndAt(\DateTimeImmutable $endAt): static { $this->endAt = $endAt; return $this; }

    public function getStatus(): string { return $this->status; }
    public function setStatus(string $status): static { $this->status = $status; return $this; }

    public function getMessage(): ?string { return $this->message; }
    public function setMessage(?string $message): static { $this->message = $message; return $this; }

    public function getPrice(): ?string { return $this->price; }
    public function setPrice(?string $price): static { $this->price = $price; return $this; }

    public function getCreatedAt(): ?\DateTimeImmutable { return $this->createdAt; }

    public function getConfirmedAt(): ?\DateTimeImmutable { return $this->confirmedAt; }
    public function setConfirmedAt(?\DateTimeImmutable $confirmedAt): static { $this->confirmedAt = $confirmedAt; return $this; }

    public function getCheckinAt(): ?\DateTimeImmutable { return $this->checkinAt; }
    public function setCheckinAt(?\DateTimeImmutable $checkinAt): static { $this->checkinAt = $checkinAt; return $this; }

    public function getCheckinBy(): ?User { return $this->checkinBy; }
    public function setCheckinBy(?User $checkinBy): static { $this->checkinBy = $checkinBy; return $this; }

    public function getPaymentMethod(): ?string { return $this->paymentMethod; }
    public function setPaymentMethod(?string $paymentMethod): static { $this->paymentMethod = $paymentMethod; return $this; }

    public function getIntendedPaymentMethod(): ?string { return $this->intendedPaymentMethod; }
    public function setIntendedPaymentMethod(?string $intendedPaymentMethod): static { $this->intendedPaymentMethod = $intendedPaymentMethod; return $this; }

    public function getRefusalReason(): ?string { return $this->refusalReason; }
    public function setRefusalReason(?string $refusalReason): static { $this->refusalReason = $refusalReason; return $this; }

    public function getPaymentDeclaredAt(): ?\DateTimeImmutable { return $this->paymentDeclaredAt; }
    public function setPaymentDeclaredAt(?\DateTimeImmutable $paymentDeclaredAt): static { $this->paymentDeclaredAt = $paymentDeclaredAt; return $this; }

    public function getPaymentDeclaredBy(): ?User { return $this->paymentDeclaredBy; }
    public function setPaymentDeclaredBy(?User $paymentDeclaredBy): static { $this->paymentDeclaredBy = $paymentDeclaredBy; return $this; }

    public function getPaymentNote(): ?string { return $this->paymentNote; }
    public function setPaymentNote(?string $paymentNote): static { $this->paymentNote = $paymentNote; return $this; }

    /** @return array<string, mixed>|null */
    public function getStripeData(): ?array { return $this->stripeData; }
    /** @param array<string, mixed>|null $stripeData */
    public function setStripeData(?array $stripeData): static { $this->stripeData = $stripeData; return $this; }

    public function getCoveredBy(): ?string { return $this->coveredBy; }
    public function setCoveredBy(?string $coveredBy): static { $this->coveredBy = $coveredBy; return $this; }

    public function getConversation(): ?Conversation { return $this->conversation; }
    public function setConversation(?Conversation $conversation): static { $this->conversation = $conversation; return $this; }

    // ─── Helpers ──────────────────────────────────────────────────────────

    public function getStatutLabel(): string
    {
        return match ($this->status) {
            self::STATUS_PENDING   => '⏳ En attente du coach',
            self::STATUS_CONFIRMED => '✅ Confirmée',
            self::STATUS_REFUSED   => '❌ Refusée par le coach',
            self::STATUS_CANCELLED => '🚫 Annulée',
            default                => 'Inconnu',
        };
    }

    /**
     * Libellé de l'offre combinée format + créneau.
     * Remplace l'ancien getServiceTypeLabel().
     */
    public function getOfferLabel(): string
    {
        return $this->format->label() . ' · ' . $this->timeSlot->label();
    }

    public function getPrixFormatted(): string
    {
        return $this->price !== null
            ? number_format((float) $this->price, 2, ',', "\u{202F}") . ' €'
            : '—';
    }

    public function isConfirmed(): bool { return $this->status === self::STATUS_CONFIRMED; }
    public function isPending(): bool   { return $this->status === self::STATUS_PENDING; }

    public function canBeCancelled(): bool
    {
        if (!$this->startAt || $this->status !== self::STATUS_CONFIRMED) {
            return false;
        }

        return new \DateTimeImmutable() < $this->startAt->modify('-2 hours');
    }

    public function getDurationMinutes(): int
    {
        if (!$this->startAt || !$this->endAt) {
            return 60;
        }

        return intdiv($this->endAt->getTimestamp() - $this->startAt->getTimestamp(), 60);
    }

    // ─── Callbacks de validation Symfony ──────────────────────────────────

    #[Assert\Callback]
    public function validateAdvanceNotice(ExecutionContextInterface $context): void
    {
        if (!$this->startAt) {
            return;
        }

        $timeDiff = $this->startAt->getTimestamp() - (new \DateTimeImmutable())->getTimestamp();

        if ($timeDiff < self::MIN_ADVANCE_SECONDS) {
            $context->buildViolation('Vous devez réserver au moins 2 heures à l\'avance')
                ->atPath('startAt')
                ->addViolation();
        }
    }

    /**
     * Vérifie que l'heure de début correspond au créneau déclaré.
     * Ex : si timeSlot = NIGHT, startAt doit être entre 21h et minuit.
     */
    #[Assert\Callback]
    public function validateTimeSlotCoherence(ExecutionContextInterface $context): void
    {
        if (!$this->startAt) {
            return;
        }

        $expected = TimeSlot::fromDateTime($this->startAt);
        if ($expected !== $this->timeSlot) {
            $context->buildViolation(
                sprintf(
                    'L\'heure choisie (%s) ne correspond pas au créneau "%s". Attendu : "%s".',
                    $this->startAt->format('H:i'),
                    $this->timeSlot->label(),
                    $expected->label()
                )
            )
                ->atPath('startAt')
                ->addViolation();
        }
    }
}
