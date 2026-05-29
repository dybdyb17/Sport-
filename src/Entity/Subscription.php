<?php

namespace App\Entity;

use App\Entity\Enum\BookingFormat;
use App\Entity\Enum\PackType;
use App\Entity\Enum\TimeSlot;
use App\Repository\SubscriptionRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: SubscriptionRepository::class)]
class Subscription
{
    public const STATUS_ACTIVE    = 'active';
    public const STATUS_EXPIRED   = 'expired';
    public const STATUS_CANCELLED = 'cancelled';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    /** Référence unique au format SPT-SUB-XXXXXXXX */
    #[ORM\Column(length: 30, unique: true)]
    private ?string $reference = null;

    #[ORM\ManyToOne(inversedBy: 'subscriptions')]
    #[ORM\JoinColumn(nullable: false)]
    private ?User $client = null;

    /**
     * Coach préféré (optionnel). Nullable car un pack peut être utilisé avec
     * différents coachs si la disponibilité l'exige.
     */
    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true)]
    private ?Coach $coach = null;

    #[ORM\Column(enumType: TimeSlot::class)]
    private TimeSlot $timeSlot = TimeSlot::DAY;

    #[ORM\Column(enumType: BookingFormat::class)]
    private BookingFormat $format = BookingFormat::SOLO;

    #[ORM\Column(enumType: PackType::class)]
    private PackType $packType = PackType::PACK_4;

    /** Nombre réel de participants (1 pour SOLO, 2 pour DUO, 3 pour TRIO, 4-6 pour GROUP) */
    #[ORM\Column]
    private int $personsCount = 1;

    /** Option accès salle 24h/24 — surcoût mensuel de 30€ (ou 25€ pour GROUP) */
    #[ORM\Column]
    private bool $fullAccess = false;

    /** Prix mensuel par personne calculé via PricingCalculator (DECIMAL stocké en string) */
    #[ORM\Column(type: Types::DECIMAL, precision: 8, scale: 2)]
    private ?string $monthlyPrice = null;

    /** Séances restantes dans le mois — décrémentées à chaque booking confirmé */
    #[ORM\Column]
    private int $sessionsRemaining = 0;

    #[ORM\Column(length: 20, options: ['default' => self::STATUS_ACTIVE])]
    private string $status = self::STATUS_ACTIVE;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $startsAt;

    /** Fin du mois d'abonnement : startsAt + 1 month */
    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $endsAt;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    /**
     * @var Collection<int, Booking>
     */
    #[ORM\OneToMany(mappedBy: 'subscription', targetEntity: Booking::class)]
    private Collection $bookings;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
        $this->startsAt  = new \DateTimeImmutable();
        $this->endsAt    = $this->startsAt->modify('+1 month');
        $this->reference = 'SPT-SUB-' . strtoupper(bin2hex(random_bytes(4)));
        $this->bookings  = new ArrayCollection();
    }

    public function getId(): ?int { return $this->id; }

    public function getReference(): ?string { return $this->reference; }

    public function getClient(): ?User { return $this->client; }
    public function setClient(User $client): static { $this->client = $client; return $this; }

    public function getCoach(): ?Coach { return $this->coach; }
    public function setCoach(?Coach $coach): static { $this->coach = $coach; return $this; }

    public function getTimeSlot(): TimeSlot { return $this->timeSlot; }
    public function setTimeSlot(TimeSlot $timeSlot): static { $this->timeSlot = $timeSlot; return $this; }

    public function getFormat(): BookingFormat { return $this->format; }
    public function setFormat(BookingFormat $format): static { $this->format = $format; return $this; }

    public function getPackType(): PackType { return $this->packType; }
    public function setPackType(PackType $packType): static { $this->packType = $packType; return $this; }

    public function getPersonsCount(): int { return $this->personsCount; }
    public function setPersonsCount(int $count): static { $this->personsCount = $count; return $this; }

    public function isFullAccess(): bool { return $this->fullAccess; }
    public function setFullAccess(bool $fullAccess): static { $this->fullAccess = $fullAccess; return $this; }

    public function getMonthlyPrice(): ?string { return $this->monthlyPrice; }
    public function setMonthlyPrice(string $price): static { $this->monthlyPrice = $price; return $this; }

    public function getSessionsRemaining(): int { return $this->sessionsRemaining; }
    public function setSessionsRemaining(int $n): static { $this->sessionsRemaining = $n; return $this; }

    public function getStatus(): string { return $this->status; }
    public function setStatus(string $status): static { $this->status = $status; return $this; }

    public function getStartsAt(): \DateTimeImmutable { return $this->startsAt; }
    public function setStartsAt(\DateTimeImmutable $dt): static { $this->startsAt = $dt; return $this; }

    public function getEndsAt(): \DateTimeImmutable { return $this->endsAt; }
    public function setEndsAt(\DateTimeImmutable $dt): static { $this->endsAt = $dt; return $this; }

    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }

    /** @return Collection<int, Booking> */
    public function getBookings(): Collection { return $this->bookings; }

    // ─── Helpers métier ────────────────────────────────────────────────────

    public function isActive(): bool
    {
        return $this->status === self::STATUS_ACTIVE;
    }

    public function hasSessionsLeft(): bool
    {
        return $this->sessionsRemaining > 0;
    }

    /**
     * Consomme une séance. Passe automatiquement en EXPIRED si plus de séances.
     */
    public function consume(): void
    {
        if (!$this->hasSessionsLeft()) {
            throw new \LogicException('Ce pack n\'a plus de séances disponibles.');
        }

        $this->sessionsRemaining--;

        if ($this->sessionsRemaining === 0) {
            $this->status = self::STATUS_EXPIRED;
        }
    }

    public function getRemainingLabel(): string
    {
        $n = $this->sessionsRemaining;

        return sprintf(
            '%d séance%s restante%s sur %d',
            $n,
            $n > 1 ? 's' : '',
            $n > 1 ? 's' : '',
            $this->packType->sessionsCount()
        );
    }

    public function getStatusLabel(): string
    {
        return match ($this->status) {
            self::STATUS_ACTIVE    => 'Actif',
            self::STATUS_EXPIRED   => 'Expiré',
            self::STATUS_CANCELLED => 'Annulé',
            default                => 'Inconnu',
        };
    }

    public function getMonthlyPriceFormatted(): string
    {
        return $this->monthlyPrice !== null
            ? number_format((float) $this->monthlyPrice, 2, ',', "\u{202F}") . ' € / mois'
            : '—';
    }
}
