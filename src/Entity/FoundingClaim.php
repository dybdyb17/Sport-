<?php

namespace App\Entity;

use App\Repository\FoundingClaimRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: FoundingClaimRepository::class)]
class FoundingClaim
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(inversedBy: 'claims')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?FoundingOffer $offer = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false)]
    private ?User $user = null;

    #[ORM\Column]
    private int $claimNumber = 1;

    #[ORM\Column]
    private int $sessionsUsed = 0;

    #[ORM\Column]
    private bool $bilanDone = false;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $bilanDoneAt = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    public function __construct() { $this->createdAt = new \DateTimeImmutable(); }
    public function getId(): ?int { return $this->id; }
    public function getOffer(): ?FoundingOffer { return $this->offer; }
    public function setOffer(?FoundingOffer $offer): static { $this->offer = $offer; return $this; }
    public function getUser(): ?User { return $this->user; }
    public function setUser(?User $user): static { $this->user = $user; return $this; }
    public function getClaimNumber(): int { return $this->claimNumber; }
    public function setClaimNumber(int $claimNumber): static { $this->claimNumber = $claimNumber; return $this; }
    public function getSessionsUsed(): int { return $this->sessionsUsed; }
    public function setSessionsUsed(int $sessionsUsed): static { $this->sessionsUsed = max(0, $sessionsUsed); return $this; }
    public function getSessionsRemaining(): int { return max(0, 3 - $this->sessionsUsed); }
    public function isBilanDone(): bool { return $this->bilanDone; }
    public function setBilanDone(bool $bilanDone): static { $this->bilanDone = $bilanDone; $this->bilanDoneAt = $bilanDone ? new \DateTimeImmutable() : null; return $this; }
    public function getBilanDoneAt(): ?\DateTimeImmutable { return $this->bilanDoneAt; }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
}
