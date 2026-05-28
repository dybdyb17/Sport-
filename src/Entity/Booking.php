<?php

namespace App\Entity;

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
    public const STATUS_PENDING = 'pending';
    public const STATUS_CONFIRMED = 'confirmed';
    public const STATUS_REFUSED = 'refused';
    public const STATUS_CANCELLED = 'cancelled';

    public const MIN_ADVANCE_SECONDS = 7200; // 2 heures

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

    #[ORM\Column(length: 50)]
    #[Assert\NotBlank]
    private ?string $serviceType = null;

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

    #[ORM\Column(type: Types::DECIMAL, precision: 6, scale: 2, nullable: true)]
    private ?string $price = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private ?\DateTimeImmutable $createdAt = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $confirmedAt = null;

    /**
     * @var array<string, mixed>|null
     */
    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $stripeData = null;

    #[ORM\OneToOne(mappedBy: 'booking', targetEntity: Conversation::class)]
    private ?Conversation $conversation = null;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
        $this->reference = 'SPT-' . strtoupper(bin2hex(random_bytes(4)));
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getReference(): ?string
    {
        return $this->reference;
    }

    public function getClient(): ?User
    {
        return $this->client;
    }

    public function setClient(?User $client): static
    {
        $this->client = $client;

        return $this;
    }

    public function getCoach(): ?Coach
    {
        return $this->coach;
    }

    public function setCoach(?Coach $coach): static
    {
        $this->coach = $coach;

        return $this;
    }

    public function getServiceType(): ?string
    {
        return $this->serviceType;
    }

    public function setServiceType(string $serviceType): static
    {
        $this->serviceType = $serviceType;

        return $this;
    }

    public function getStartAt(): ?\DateTimeImmutable
    {
        return $this->startAt;
    }

    public function setStartAt(\DateTimeImmutable $startAt): static
    {
        $this->startAt = $startAt;

        return $this;
    }

    public function getEndAt(): ?\DateTimeImmutable
    {
        return $this->endAt;
    }

    public function setEndAt(\DateTimeImmutable $endAt): static
    {
        $this->endAt = $endAt;

        return $this;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function setStatus(string $status): static
    {
        $this->status = $status;

        return $this;
    }

    public function getMessage(): ?string
    {
        return $this->message;
    }

    public function setMessage(?string $message): static
    {
        $this->message = $message;

        return $this;
    }

    public function getPrice(): ?string
    {
        return $this->price;
    }

    public function setPrice(?string $price): static
    {
        $this->price = $price;

        return $this;
    }

    public function getCreatedAt(): ?\DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getConfirmedAt(): ?\DateTimeImmutable
    {
        return $this->confirmedAt;
    }

    public function setConfirmedAt(?\DateTimeImmutable $confirmedAt): static
    {
        $this->confirmedAt = $confirmedAt;

        return $this;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getStripeData(): ?array
    {
        return $this->stripeData;
    }

    /**
     * @param array<string, mixed>|null $stripeData
     */
    public function setStripeData(?array $stripeData): static
    {
        $this->stripeData = $stripeData;

        return $this;
    }

    // === HELPERS ===

    public function getStatutLabel(): string
    {
        return match ($this->status) {
            self::STATUS_PENDING => '⏳ En attente du coach',
            self::STATUS_CONFIRMED => '✅ Confirmée',
            self::STATUS_REFUSED => '❌ Refusée par le coach',
            self::STATUS_CANCELLED => '🚫 Annulée',
            default => 'Inconnu',
        };
    }

    public function getServiceTypeLabel(): string
    {
        return match ($this->serviceType) {
            'night_coach' => '🌙 Night Coach',
            'small_group' => '👥 Small Group',
            'solo_day' => '☀️ Coaching Journée',
            'groupe_6' => '🏋️ Groupe 6 pers.',
            default => $this->serviceType ?? '—',
        };
    }

    public function getPrixFormatted(): string
    {
        return $this->price !== null
            ? number_format((float) $this->price, 2, ',', ' ') . ' €'
            : '—';
    }

    public function isConfirmed(): bool
    {
        return $this->status === self::STATUS_CONFIRMED;
    }

    public function isPending(): bool
    {
        return $this->status === self::STATUS_PENDING;
    }

    public function canBeCancelled(): bool
    {
        if (!$this->startAt || $this->status !== self::STATUS_CONFIRMED) {
            return false;
        }

        $now = new \DateTimeImmutable();
        $twoHoursBefore = $this->startAt->modify('-2 hours');

        return $now < $twoHoursBefore;
    }

    public function getConversation(): ?Conversation
    {
        return $this->conversation;
    }

    public function getDurationMinutes(): int
    {
        if (!$this->startAt || !$this->endAt) {
            return 60;
        }

        return intdiv($this->endAt->getTimestamp() - $this->startAt->getTimestamp(), 60);
    }

    // === VALIDATION CUSTOM ===

    #[Assert\Callback]
    public function validateAdvanceNotice(ExecutionContextInterface $context): void
    {
        if (!$this->startAt) {
            return;
        }

        $now = new \DateTimeImmutable();
        $timeDiff = $this->startAt->getTimestamp() - $now->getTimestamp();

        if ($timeDiff < self::MIN_ADVANCE_SECONDS) {
            $context->buildViolation('Vous devez réserver au moins 2 heures à l\'avance')
                ->atPath('startAt')
                ->addViolation();
        }
    }
}
