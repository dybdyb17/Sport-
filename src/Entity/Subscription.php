<?php

namespace App\Entity;

use App\Entity\Enum\BookingFormat;
use App\Entity\Enum\PackType;
use App\Entity\Enum\TimeSlot;
use App\Repository\SubscriptionRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: SubscriptionRepository::class)]
class Subscription
{
    public const STATUS_ACTIVE = 'active';
    public const STATUS_EXPIRED = 'expired';
    public const STATUS_CANCELLED = 'cancelled';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 24, unique: true)]
    private string $reference;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false)]
    private ?User $client = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false)]
    private ?Coach $coach = null;

    #[ORM\Column(enumType: BookingFormat::class)]
    private BookingFormat $format = BookingFormat::SOLO;

    #[ORM\Column(enumType: TimeSlot::class)]
    private TimeSlot $timeSlot = TimeSlot::DAY;

    #[ORM\Column(enumType: PackType::class)]
    private PackType $packType = PackType::PACK_4;

    #[ORM\Column]
    private int $sessionsRemaining = 4;

    #[ORM\Column(type: Types::DECIMAL, precision: 8, scale: 2)]
    private string $monthlyPrice = '0.00';

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $startsAt;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $endsAt;

    #[ORM\Column(length: 30)]
    private string $status = self::STATUS_ACTIVE;

    public function __construct()
    {
        $this->reference = 'SUB-' . strtoupper(bin2hex(random_bytes(4)));
        $this->startsAt = new \DateTimeImmutable();
        $this->endsAt = new \DateTimeImmutable('+1 month');
    }

    public function getId(): ?int { return $this->id; }
    public function getReference(): string { return $this->reference; }
    public function getClient(): ?User { return $this->client; }
    public function setClient(?User $client): static { $this->client = $client; return $this; }
    public function getCoach(): ?Coach { return $this->coach; }
    public function setCoach(?Coach $coach): static { $this->coach = $coach; return $this; }
    public function getFormat(): BookingFormat { return $this->format; }
    public function setFormat(BookingFormat $format): static { $this->format = $format; return $this; }
    public function getTimeSlot(): TimeSlot { return $this->timeSlot; }
    public function setTimeSlot(TimeSlot $timeSlot): static { $this->timeSlot = $timeSlot; return $this; }
    public function getPackType(): PackType { return $this->packType; }
    public function setPackType(PackType $packType): static { $this->packType = $packType; $this->sessionsRemaining = $packType->sessions(); return $this; }
    public function getSessionsRemaining(): int { return $this->sessionsRemaining; }
    public function setSessionsRemaining(int $sessionsRemaining): static { $this->sessionsRemaining = max(0, $sessionsRemaining); return $this; }
    public function getMonthlyPrice(): string { return $this->monthlyPrice; }
    public function setMonthlyPrice(string $monthlyPrice): static { $this->monthlyPrice = $monthlyPrice; return $this; }
    public function getStartsAt(): \DateTimeImmutable { return $this->startsAt; }
    public function setStartsAt(\DateTimeImmutable $startsAt): static { $this->startsAt = $startsAt; return $this; }
    public function getEndsAt(): \DateTimeImmutable { return $this->endsAt; }
    public function setEndsAt(\DateTimeImmutable $endsAt): static { $this->endsAt = $endsAt; return $this; }
    public function getStatus(): string { return $this->status; }
    public function setStatus(string $status): static { $this->status = $status; return $this; }
    public function isActive(): bool { return $this->status === self::STATUS_ACTIVE && $this->endsAt >= new \DateTimeImmutable('today'); }
}
