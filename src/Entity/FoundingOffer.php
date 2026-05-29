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
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 120)]
    private string $name = 'Offre Fondateurs';

    #[ORM\Column(type: Types::DECIMAL, precision: 8, scale: 2)]
    private string $price = '0.00';

    #[ORM\Column]
    private int $totalPlaces = 50;

    #[ORM\Column]
    private bool $isActive = true;

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
    public function getName(): string { return $this->name; }
    public function setName(string $name): static { $this->name = $name; return $this; }
    public function getPrice(): string { return $this->price; }
    public function setPrice(string $price): static { $this->price = $price; return $this; }
    public function getTotalPlaces(): int { return $this->totalPlaces; }
    public function setTotalPlaces(int $totalPlaces): static { $this->totalPlaces = $totalPlaces; return $this; }
    public function isActive(): bool { return $this->isActive; }
    public function setIsActive(bool $isActive): static { $this->isActive = $isActive; return $this; }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
    /** @return Collection<int, FoundingClaim> */
    public function getClaims(): Collection { return $this->claims; }
    public function getPlacesTaken(): int { return $this->claims->count(); }
    public function getPlacesRemaining(): int { return max(0, $this->totalPlaces - $this->getPlacesTaken()); }
}
