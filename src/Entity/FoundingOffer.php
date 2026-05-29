<?php
namespace App\Entity;

use App\Repository\FoundingOfferRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: FoundingOfferRepository::class)]
class FoundingOffer
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 50, unique: true)]
    private string $code;

    #[ORM\Column(length: 100)]
    private string $title;

    #[ORM\Column(type: Types::TEXT)]
    private string $description;

    #[ORM\Column]
    private int $totalPlaces = 50;

    #[ORM\Column]
    private int $placesTaken = 0;

    #[ORM\Column]
    private int $sessionsIncluded = 3;

    #[ORM\Column(type: Types::DECIMAL, precision: 8, scale: 2)]
    private string $price = '99.00';

    #[ORM\Column(type: Types::DECIMAL, precision: 8, scale: 2, nullable: true)]
    private ?string $regularPrice = '175.00';

    #[ORM\Column]
    private bool $includesFreeBilan = true;

    #[ORM\Column]
    private bool $includesPriorityNight = true;

    #[ORM\Column(length: 50)]
    private string $badgeName = 'Membre Fondateur';

    #[ORM\Column]
    private bool $isActive = false;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $startsAt = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $endsAt = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    /** @var Collection<int, FoundingClaim> */
    #[ORM\OneToMany(mappedBy: 'offer', targetEntity: FoundingClaim::class, cascade: ['persist', 'remove'])]
    private Collection $claims;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
        $this->claims = new ArrayCollection();
    }

    public function getId(): ?int { return $this->id; }
    public function getCode(): string { return $this->code; }
    public function setCode(string $c): static { $this->code = $c; return $this; }
    public function getTitle(): string { return $this->title; }
    public function setTitle(string $t): static { $this->title = $t; return $this; }
    public function getDescription(): string { return $this->description; }
    public function setDescription(string $d): static { $this->description = $d; return $this; }
    public function getTotalPlaces(): int { return $this->totalPlaces; }
    public function setTotalPlaces(int $n): static { $this->totalPlaces = $n; return $this; }
    public function getPlacesTaken(): int { return $this->placesTaken; }
    public function setPlacesTaken(int $n): static { $this->placesTaken = $n; return $this; }
    public function incrementPlacesTaken(): static { $this->placesTaken++; return $this; }
    public function getSessionsIncluded(): int { return $this->sessionsIncluded; }
    public function setSessionsIncluded(int $n): static { $this->sessionsIncluded = $n; return $this; }
    public function getPrice(): string { return $this->price; }
    public function setPrice(string $p): static { $this->price = $p; return $this; }
    public function getRegularPrice(): ?string { return $this->regularPrice; }
    public function setRegularPrice(?string $p): static { $this->regularPrice = $p; return $this; }
    public function isIncludesFreeBilan(): bool { return $this->includesFreeBilan; }
    public function setIncludesFreeBilan(bool $b): static { $this->includesFreeBilan = $b; return $this; }
    public function isIncludesPriorityNight(): bool { return $this->includesPriorityNight; }
    public function setIncludesPriorityNight(bool $b): static { $this->includesPriorityNight = $b; return $this; }
    public function getBadgeName(): string { return $this->badgeName; }
    public function setBadgeName(string $n): static { $this->badgeName = $n; return $this; }
    public function isActive(): bool { return $this->isActive; }
    public function setIsActive(bool $a): static { $this->isActive = $a; return $this; }
    public function getStartsAt(): ?\DateTimeImmutable { return $this->startsAt; }
    public function setStartsAt(?\DateTimeImmutable $d): static { $this->startsAt = $d; return $this; }
    public function getEndsAt(): ?\DateTimeImmutable { return $this->endsAt; }
    public function setEndsAt(?\DateTimeImmutable $d): static { $this->endsAt = $d; return $this; }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
    /** @return Collection<int, FoundingClaim> */
    public function getClaims(): Collection { return $this->claims; }

    public function getPlacesRemaining(): int { return max(0, $this->totalPlaces - $this->placesTaken); }
    public function hasPlacesLeft(): bool { return $this->getPlacesRemaining() > 0; }
    public function getProgressPercent(): int {
        if ($this->totalPlaces === 0) return 100;
        return (int) min(100, round($this->placesTaken / $this->totalPlaces * 100));
    }
    public function isStillRunning(): bool {
        if (!$this->isActive) return false;
        $now = new \DateTimeImmutable();
        if ($this->startsAt !== null && $now < $this->startsAt) return false;
        if ($this->endsAt !== null && $now > $this->endsAt) return false;
        return true;
    }
}
