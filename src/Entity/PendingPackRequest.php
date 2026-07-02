<?php

namespace App\Entity;

use App\Entity\Enum\BookingFormat;
use App\Entity\Enum\PackRequestStatus;
use App\Entity\Enum\PackType;
use App\Entity\Enum\TimeSlot;
use App\Repository\PendingPackRequestRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Demande de pack en attente de paiement Stripe.
 *
 * Persistée avant le redirect vers Stripe Checkout, avec toutes les infos
 * nécessaires pour recréer le pack + la 1ère séance quand le webhook confirme
 * le paiement. Le seul id est passé en metadata Stripe (`pending_pack_id`).
 *
 * Raison d'être : les metadata Stripe sont limitées à des strings courtes
 * (500 chars/clé, 50 clés max), et on doit passer format + timeSlot + packType
 * + personsCount + fullAccess + coach_id + startAt + message client (jusqu'à
 * 500 chars aussi). Une entité dédiée est plus robuste, plus typée, et
 * réutilisable pour la Partie 2 (pack payé sur place) — il suffira de changer
 * l'aiguillage post-création.
 *
 * `fulfilledAt` posé par le webhook après création effective du Subscription
 * + Booking → idempotence : si un webhook Stripe est ré-émis, on ne recrée pas.
 */
#[ORM\Entity(repositoryClass: PendingPackRequestRepository::class)]
#[ORM\Table(name: 'pending_pack_request')]
#[ORM\Index(columns: ['client_id'], name: 'idx_ppr_client')]
class PendingPackRequest
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private User $client;

    #[ORM\ManyToOne(targetEntity: Coach::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Coach $coach;

    #[ORM\Column(enumType: BookingFormat::class)]
    private BookingFormat $format;

    #[ORM\Column(enumType: TimeSlot::class)]
    private TimeSlot $timeSlot;

    #[ORM\Column(enumType: PackType::class)]
    private PackType $packType;

    #[ORM\Column]
    private int $personsCount = 1;

    #[ORM\Column]
    private bool $fullAccess = false;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $startAt;

    #[ORM\Column(length: 500, nullable: true)]
    private ?string $message = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    /** Set par le webhook après création du Subscription + Booking. Idempotent. */
    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $fulfilledAt = null;

    /** Le id de la session Stripe qui a payé cette demande (trace + idempotence). */
    #[ORM\Column(length: 100, nullable: true)]
    private ?string $stripeSessionId = null;

    /** Le pack créé (une fois fulfilled). Permet un lookup rapide. */
    #[ORM\OneToOne(targetEntity: Subscription::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?Subscription $subscription = null;

    // ── Ajouts Partie 2 (paiement sur place) ────────────────────────────
    //
    // paymentMethod : mode choisi par le client à la résa. Valeurs alignées
    // sur Booking (cash, card, stripe). Distingue le flow online (stripe →
    // fulfillPackCheckout) du flow on-site (cash/card → validate() coach).
    //
    // status : cycle de vie de la demande. Voir enum PackRequestStatus.
    //   - PENDING  = créée, pas encore matérialisée
    //   - CONFIRMED= subscription + 1ère séance créées
    //   - REFUSED  = annulée par le coach (client absent ou refus)
    //
    // ⚠️ Pas de valeur par défaut sur $status au niveau propriété (piège
    // PHP 8.4 lazy objects — cf User::$role). Initialisé dans __construct.

    #[ORM\Column(length: 20)]
    private string $paymentMethod;

    #[ORM\Column(enumType: PackRequestStatus::class)]
    private PackRequestStatus $status;

    /** Set par validate() côté coach. Trace de qui a validé et quand. */
    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $validatedAt = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?User $validatedBy = null;

    public function __construct()
    {
        $this->createdAt     = new \DateTimeImmutable();
        $this->status        = PackRequestStatus::PENDING;
        $this->paymentMethod = 'stripe'; // écrasé par le controller à la création
    }

    public function getId(): ?int { return $this->id; }

    public function getClient(): User { return $this->client; }
    public function setClient(User $client): static { $this->client = $client; return $this; }

    public function getCoach(): Coach { return $this->coach; }
    public function setCoach(Coach $coach): static { $this->coach = $coach; return $this; }

    public function getFormat(): BookingFormat { return $this->format; }
    public function setFormat(BookingFormat $format): static { $this->format = $format; return $this; }

    public function getTimeSlot(): TimeSlot { return $this->timeSlot; }
    public function setTimeSlot(TimeSlot $timeSlot): static { $this->timeSlot = $timeSlot; return $this; }

    public function getPackType(): PackType { return $this->packType; }
    public function setPackType(PackType $packType): static { $this->packType = $packType; return $this; }

    public function getPersonsCount(): int { return $this->personsCount; }
    public function setPersonsCount(int $personsCount): static { $this->personsCount = $personsCount; return $this; }

    public function isFullAccess(): bool { return $this->fullAccess; }
    public function setFullAccess(bool $fullAccess): static { $this->fullAccess = $fullAccess; return $this; }

    public function getStartAt(): \DateTimeImmutable { return $this->startAt; }
    public function setStartAt(\DateTimeImmutable $startAt): static { $this->startAt = $startAt; return $this; }

    public function getMessage(): ?string { return $this->message; }
    public function setMessage(?string $message): static { $this->message = $message; return $this; }

    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }

    public function getFulfilledAt(): ?\DateTimeImmutable { return $this->fulfilledAt; }
    public function setFulfilledAt(?\DateTimeImmutable $fulfilledAt): static { $this->fulfilledAt = $fulfilledAt; return $this; }

    public function isFulfilled(): bool { return $this->fulfilledAt !== null; }

    public function getStripeSessionId(): ?string { return $this->stripeSessionId; }
    public function setStripeSessionId(?string $id): static { $this->stripeSessionId = $id; return $this; }

    public function getSubscription(): ?Subscription { return $this->subscription; }
    public function setSubscription(?Subscription $s): static { $this->subscription = $s; return $this; }

    public function getPaymentMethod(): string { return $this->paymentMethod; }
    public function setPaymentMethod(string $method): static { $this->paymentMethod = $method; return $this; }

    public function getStatus(): PackRequestStatus { return $this->status; }
    public function setStatus(PackRequestStatus $status): static { $this->status = $status; return $this; }

    public function getValidatedAt(): ?\DateTimeImmutable { return $this->validatedAt; }
    public function setValidatedAt(?\DateTimeImmutable $dt): static { $this->validatedAt = $dt; return $this; }

    public function getValidatedBy(): ?User { return $this->validatedBy; }
    public function setValidatedBy(?User $u): static { $this->validatedBy = $u; return $this; }

    /** Est-ce une demande de pack payé sur place (cash / card) ? */
    public function isOnSite(): bool
    {
        return in_array($this->paymentMethod, ['cash', 'card'], true);
    }

    /** Encore en attente et non refusée ? */
    public function isPending(): bool
    {
        return $this->status === PackRequestStatus::PENDING;
    }
}
