<?php

namespace App\Entity;

use App\Repository\CoachRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity(repositoryClass: CoachRepository::class)]
class Coach
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\OneToOne(inversedBy: 'coach', cascade: ['persist'])]
    #[ORM\JoinColumn(nullable: false)]
    private ?User $user = null;

    /**
     * @var list<string>
     */
    #[ORM\Column(type: Types::JSON)]
    private array $specialties = [];

    #[ORM\Column(type: Types::DECIMAL, precision: 6, scale: 2)]
    #[Assert\Positive]
    private ?string $hourlyRate = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $photoFilename = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $photoData = null;

    #[ORM\Column(length: 80, nullable: true)]
    private ?string $photoMimeType = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $bio = null;

    #[ORM\Column(options: ['default' => false])]
    private bool $isAvailableTonight = false;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $availableTonightSetAt = null;

    /**
     * @var Collection<int, Booking>
     */
    #[ORM\OneToMany(mappedBy: 'coach', targetEntity: Booking::class)]
    private Collection $bookings;

    public function __construct()
    {
        $this->bookings = new ArrayCollection();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getUser(): ?User
    {
        return $this->user;
    }

    public function setUser(?User $user): static
    {
        $this->user = $user;

        return $this;
    }

    /**
     * @return list<string>
     */
    public function getSpecialties(): array
    {
        return $this->specialties;
    }

    /**
     * @param list<string> $specialties
     */
    public function setSpecialties(array $specialties): static
    {
        $this->specialties = $specialties;

        return $this;
    }

    public function getHourlyRate(): ?string
    {
        return $this->hourlyRate;
    }

    public function setHourlyRate(string $hourlyRate): static
    {
        $this->hourlyRate = $hourlyRate;

        return $this;
    }

    /**
     * @return Collection<int, Booking>
     */
    public function getBookings(): Collection
    {
        return $this->bookings;
    }

    public function addBooking(Booking $booking): static
    {
        if (!$this->bookings->contains($booking)) {
            $this->bookings->add($booking);
            $booking->setCoach($this);
        }

        return $this;
    }

    public function removeBooking(Booking $booking): static
    {
        if ($this->bookings->removeElement($booking)) {
            // Comparaison par ID — cf User::removeBooking, même justification :
            // les proxies lazy Doctrine cassent le ===.
            if ($booking->getCoach()?->getId() === $this->getId()) {
                $booking->setCoach(null);
            }
        }

        return $this;
    }

    public function getPhotoFilename(): ?string { return $this->photoFilename; }
    public function setPhotoFilename(?string $photoFilename): static { $this->photoFilename = $photoFilename; return $this; }

    public function getPhotoData(): ?string { return $this->photoData; }
    public function setPhotoData(?string $photoData): static { $this->photoData = $photoData; return $this; }

    public function getPhotoMimeType(): ?string { return $this->photoMimeType; }
    public function setPhotoMimeType(?string $photoMimeType): static { $this->photoMimeType = $photoMimeType; return $this; }

    public function getPhotoSrc(): ?string
    {
        if ($this->photoData && $this->photoMimeType) {
            return sprintf('data:%s;base64,%s', $this->photoMimeType, $this->photoData);
        }

        return $this->photoFilename ? '/img/coaches/' . $this->photoFilename : null;
    }

    public function getBio(): ?string { return $this->bio; }
    public function setBio(?string $bio): static { $this->bio = $bio; return $this; }

    // === HELPERS ===

    public function getPhotoUrl(): string
    {
        return $this->getPhotoSrc() ?? '';
    }

    public function getNomComplet(): ?string
    {
        return $this->user?->getNomComplet();
    }

    public function getSpecialtiesLabel(): string
    {
        return empty($this->specialties)
            ? 'Toutes disciplines'
            : implode(', ', array_map('ucfirst', $this->specialties));
    }

    public function getPrixFormatted(): string
    {
        return number_format((float) ($this->hourlyRate ?? 0), 2, ',', ' ') . ' €/h';
    }

    /**
     * Vérifie si le coach est disponible sur un créneau donné.
     * Un créneau est considéré occupé par toute réservation confirmée
     * OU en attente qui chevauche l'intervalle demandé (premier arrivé, premier servi).
     */
    public function isAvailableOnSlot(\DateTimeInterface $start, \DateTimeInterface $end): bool
    {
        foreach ($this->bookings as $booking) {
            if (!in_array($booking->getStatus(), [Booking::STATUS_PENDING, Booking::STATUS_CONFIRMED], true)) {
                continue;
            }

            // Chevauchement d'intervalles : [start, end) ∩ [bStart, bEnd) ≠ ∅
            if ($start < $booking->getEndAt() && $end > $booking->getStartAt()) {
                return false;
            }
        }

        return true;
    }

    public function getIsAvailableTonight(): bool { return $this->isAvailableTonight; }
    public function setIsAvailableTonight(bool $isAvailableTonight): static { $this->isAvailableTonight = $isAvailableTonight; return $this; }

    public function getAvailableTonightSetAt(): ?\DateTimeImmutable { return $this->availableTonightSetAt; }
    public function setAvailableTonightSetAt(?\DateTimeImmutable $availableTonightSetAt): static { $this->availableTonightSetAt = $availableTonightSetAt; return $this; }

    /**
     * Le coach est "disponible cette nuit" uniquement si :
     * - le flag est à true
     * - ET la date d'activation est < 24h (sinon expiré, considéré comme false)
     */
    public function isAvailableTonightNow(): bool
    {
        if (!$this->isAvailableTonight || null === $this->availableTonightSetAt) {
            return false;
        }
        $expiresAt = $this->availableTonightSetAt->modify('+24 hours');
        return $expiresAt > new \DateTimeImmutable();
    }

    /**
     * Le coach est "en ligne" si son user a un lastSeenAt < 5 minutes.
     */
    public function isOnline(): bool
    {
        $lastSeen = $this->user?->getLastSeenAt();
        if (null === $lastSeen) {
            return false;
        }
        $threshold = new \DateTimeImmutable('-5 minutes');
        return $lastSeen > $threshold;
    }
}
