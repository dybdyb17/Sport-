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
            if ($booking->getCoach() === $this) {
                $booking->setCoach(null);
            }
        }

        return $this;
    }

    // === HELPERS ===

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
}
