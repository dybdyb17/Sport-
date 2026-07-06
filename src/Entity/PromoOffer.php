<?php

namespace App\Entity;

use App\Repository\PromoOfferRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: PromoOfferRepository::class)]
#[ORM\UniqueConstraint(name: 'UNIQ_PROMO_OFFER_SLUG', fields: ['slug'])]
class PromoOffer
{
    public const STATUS_DRAFT = 'draft';
    public const STATUS_ACTIVE = 'active';
    public const STATUS_ARCHIVED = 'archived';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 140)]
    private string $title = '';

    #[ORM\Column(length: 160, unique: true)]
    private string $slug = '';

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $description = null;

    #[ORM\Column(length: 40)]
    private string $type = 'session';

    #[ORM\Column(type: Types::DECIMAL, precision: 8, scale: 2)]
    private string $price = '0.00';

    #[ORM\Column(length: 3, options: ['default' => 'eur'])]
    private string $currency = 'eur';

    #[ORM\Column(length: 20, options: ['default' => self::STATUS_DRAFT])]
    private string $status = self::STATUS_DRAFT;

    #[ORM\Column(nullable: true)]
    private ?int $maxQuantity = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $startsAt = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $endsAt = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $updatedAt;

    /** @var Collection<int, PromoPurchase> */
    #[ORM\OneToMany(mappedBy: 'offer', targetEntity: PromoPurchase::class)]
    private Collection $purchases;

    public function __construct()
    {
        $now = new \DateTimeImmutable();
        $this->createdAt = $now;
        $this->updatedAt = $now;
        $this->purchases = new ArrayCollection();
    }

    public function getId(): ?int { return $this->id; }
    public function getTitle(): string { return $this->title; }
    public function setTitle(string $title): static { $this->title = trim($title); $this->touch(); return $this; }
    public function getSlug(): string { return $this->slug; }
    public function setSlug(string $slug): static { $this->slug = trim($slug); $this->touch(); return $this; }
    public function getDescription(): ?string { return $this->description; }
    public function setDescription(?string $description): static { $this->description = $description !== null ? trim($description) : null; $this->touch(); return $this; }
    public function getType(): string { return $this->type; }
    public function setType(string $type): static { $this->type = trim($type) ?: 'session'; $this->touch(); return $this; }
    public function getPrice(): string { return $this->price; }
    public function setPrice(string $price): static { $this->price = number_format((float) str_replace(',', '.', $price), 2, '.', ''); $this->touch(); return $this; }
    public function getCurrency(): string { return $this->currency; }
    public function setCurrency(string $currency): static { $this->currency = strtolower($currency ?: 'eur'); $this->touch(); return $this; }
    public function getStatus(): string { return $this->status; }
    public function setStatus(string $status): static { $this->status = in_array($status, [self::STATUS_DRAFT, self::STATUS_ACTIVE, self::STATUS_ARCHIVED], true) ? $status : self::STATUS_DRAFT; $this->touch(); return $this; }
    public function getMaxQuantity(): ?int { return $this->maxQuantity; }
    public function setMaxQuantity(?int $maxQuantity): static { $this->maxQuantity = $maxQuantity !== null && $maxQuantity > 0 ? $maxQuantity : null; $this->touch(); return $this; }
    public function getStartsAt(): ?\DateTimeImmutable { return $this->startsAt; }
    public function setStartsAt(?\DateTimeImmutable $startsAt): static { $this->startsAt = $startsAt; $this->touch(); return $this; }
    public function getEndsAt(): ?\DateTimeImmutable { return $this->endsAt; }
    public function setEndsAt(?\DateTimeImmutable $endsAt): static { $this->endsAt = $endsAt; $this->touch(); return $this; }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
    public function getUpdatedAt(): \DateTimeImmutable { return $this->updatedAt; }
    /** @return Collection<int, PromoPurchase> */
    public function getPurchases(): Collection { return $this->purchases; }

    public function isActive(): bool { return $this->status === self::STATUS_ACTIVE; }

    public function paidPurchasesCount(): int
    {
        return $this->purchases->filter(static fn (PromoPurchase $purchase): bool => $purchase->isPaid())->count();
    }

    public function hasPlacesLeft(): bool
    {
        return $this->maxQuantity === null || $this->paidPurchasesCount() < $this->maxQuantity;
    }

    public function isCurrentlyAvailable(?\DateTimeImmutable $now = null): bool
    {
        $now ??= new \DateTimeImmutable();
        if (!$this->isActive() || !$this->hasPlacesLeft()) {
            return false;
        }
        if ($this->startsAt !== null && $this->startsAt > $now) {
            return false;
        }
        if ($this->endsAt !== null && $this->endsAt < $now) {
            return false;
        }
        return true;
    }

    public function getPriceFormatted(): string
    {
        return number_format((float) $this->price, 2, ',', "\u{202F}") . ' €';
    }

    private function touch(): void { $this->updatedAt = new \DateTimeImmutable(); }
}
