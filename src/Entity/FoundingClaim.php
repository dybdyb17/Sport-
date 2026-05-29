<?php
namespace App\Entity;

use App\Repository\FoundingClaimRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: FoundingClaimRepository::class)]
class FoundingClaim
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(inversedBy: 'claims')]
    #[ORM\JoinColumn(nullable: false)]
    private ?FoundingOffer $offer = null;

    #[ORM\OneToOne]
    #[ORM\JoinColumn(nullable: false, unique: true)]
    private ?User $user = null;

    #[ORM\Column]
    private int $claimNumber;

    #[ORM\Column]
    private int $sessionsUsed = 0;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $bilanDoneAt = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $claimedAt;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $expiresAt = null;

    public function __construct()
    {
        $this->claimedAt = new \DateTimeImmutable();
    }

    public function getId(): ?int { return $this->id; }
    public function getOffer(): ?FoundingOffer { return $this->offer; }
    public function setOffer(FoundingOffer $o): static { $this->offer = $o; return $this; }
    public function getUser(): ?User { return $this->user; }
    public function setUser(User $u): static { $this->user = $u; return $this; }
    public function getClaimNumber(): int { return $this->claimNumber; }
    public function setClaimNumber(int $n): static { $this->claimNumber = $n; return $this; }
    public function getSessionsUsed(): int { return $this->sessionsUsed; }
    public function getBilanDoneAt(): ?\DateTimeImmutable { return $this->bilanDoneAt; }
    public function setBilanDoneAt(?\DateTimeImmutable $d): static { $this->bilanDoneAt = $d; return $this; }
    public function getClaimedAt(): \DateTimeImmutable { return $this->claimedAt; }
    public function getExpiresAt(): ?\DateTimeImmutable { return $this->expiresAt; }
    public function setExpiresAt(?\DateTimeImmutable $d): static { $this->expiresAt = $d; return $this; }

    public function getSessionsRemaining(): int { return max(0, ($this->offer?->getSessionsIncluded() ?? 3) - $this->sessionsUsed); }
    public function hasSessionsLeft(): bool { return $this->getSessionsRemaining() > 0; }
    public function isBilanDone(): bool { return $this->bilanDoneAt !== null; }
    public function consumeSession(): static { $this->sessionsUsed++; return $this; }
    public function getFoundingLabel(): string { return sprintf('%s #%02d', $this->offer?->getBadgeName() ?? 'Membre Fondateur', $this->claimNumber); }
}
