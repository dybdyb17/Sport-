<?php

namespace App\Entity;

use App\Repository\PromoPurchaseRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: PromoPurchaseRepository::class)]
#[ORM\UniqueConstraint(name: 'UNIQ_PROMO_PURCHASE_REFERENCE', fields: ['reference'])]
#[ORM\UniqueConstraint(name: 'UNIQ_PROMO_PURCHASE_QR_TOKEN', fields: ['qrToken'])]
class PromoPurchase
{
    public const STATUS_PENDING = 'pending';
    public const STATUS_PAID = 'paid';
    public const STATUS_CANCELLED = 'cancelled';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 20, unique: true)]
    private string $reference;

    #[ORM\ManyToOne(inversedBy: 'purchases')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private PromoOffer $offer;

    #[ORM\Column(length: 120)]
    private string $buyerName = '';

    #[ORM\Column(length: 180)]
    private string $buyerEmail = '';

    #[ORM\Column(length: 30, nullable: true)]
    private ?string $buyerPhone = null;

    #[ORM\Column(length: 20, options: ['default' => self::STATUS_PENDING])]
    private string $status = self::STATUS_PENDING;

    #[ORM\Column(type: Types::DECIMAL, precision: 8, scale: 2)]
    private string $amount = '0.00';

    #[ORM\Column(length: 3, options: ['default' => 'eur'])]
    private string $currency = 'eur';

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $stripeCheckoutSessionId = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $stripePaymentIntentId = null;

    /**
     * Mode de paiement DÉCLARÉ par le client à la réservation (cash|card|stripe).
     * Sert à distinguer une purchase en attente de paiement Stripe (le client
     * a lancé le checkout mais n'a pas fini) d'une purchase en attente de
     * paiement au club (cash/card). Aligné sur Booking.intendedPaymentMethod.
     */
    #[ORM\Column(length: 10, nullable: true)]
    private ?string $intendedPaymentMethod = null;

    /**
     * Mode de paiement RÉELLEMENT effectué (posé après confirmation) :
     *   - 'stripe' : posé par le webhook checkout.session.completed
     *   - 'cash' | 'card' : posé par la validation admin/coach au club
     * Reste NULL tant que le paiement n'est pas confirmé.
     * Contrat identique à Booking : ∈ {stripe, cash, card, NULL}.
     */
    #[ORM\Column(length: 10, nullable: true)]
    private ?string $paymentMethod = null;

    /** Trace de qui a validé le paiement au club (admin/coach). */
    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $paymentValidatedAt = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?User $paymentValidatedBy = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $paidAt = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $checkinAt = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?User $checkinBy = null;

    #[ORM\Column(length: 64, unique: true)]
    private string $qrToken;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    public function __construct()
    {
        $this->reference = 'OFR-' . strtoupper(bin2hex(random_bytes(4)));
        $this->qrToken = bin2hex(random_bytes(24));
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int { return $this->id; }
    public function getReference(): string { return $this->reference; }
    public function getOffer(): PromoOffer { return $this->offer; }
    public function setOffer(PromoOffer $offer): static { $this->offer = $offer; return $this; }
    public function getBuyerName(): string { return $this->buyerName; }
    public function setBuyerName(string $buyerName): static { $this->buyerName = trim($buyerName); return $this; }
    public function getBuyerEmail(): string { return $this->buyerEmail; }
    public function setBuyerEmail(string $buyerEmail): static { $this->buyerEmail = mb_strtolower(trim($buyerEmail)); return $this; }
    public function getBuyerPhone(): ?string { return $this->buyerPhone; }
    public function setBuyerPhone(?string $buyerPhone): static { $this->buyerPhone = $buyerPhone !== null ? trim($buyerPhone) : null; return $this; }
    public function getStatus(): string { return $this->status; }
    public function setStatus(string $status): static { $this->status = in_array($status, [self::STATUS_PENDING, self::STATUS_PAID, self::STATUS_CANCELLED], true) ? $status : self::STATUS_PENDING; return $this; }
    public function getAmount(): string { return $this->amount; }
    public function setAmount(string $amount): static { $this->amount = number_format((float) str_replace(',', '.', $amount), 2, '.', ''); return $this; }
    public function getCurrency(): string { return $this->currency; }
    public function setCurrency(string $currency): static { $this->currency = strtolower($currency ?: 'eur'); return $this; }
    public function getStripeCheckoutSessionId(): ?string { return $this->stripeCheckoutSessionId; }
    public function setStripeCheckoutSessionId(?string $id): static { $this->stripeCheckoutSessionId = $id; return $this; }
    public function getStripePaymentIntentId(): ?string { return $this->stripePaymentIntentId; }
    public function setStripePaymentIntentId(?string $id): static { $this->stripePaymentIntentId = $id; return $this; }
    public function getIntendedPaymentMethod(): ?string { return $this->intendedPaymentMethod; }
    public function setIntendedPaymentMethod(?string $method): static
    {
        $this->intendedPaymentMethod = in_array($method, ['stripe', 'cash', 'card'], true) ? $method : null;
        return $this;
    }
    public function getPaymentMethod(): ?string { return $this->paymentMethod; }
    public function setPaymentMethod(?string $method): static
    {
        $this->paymentMethod = in_array($method, ['stripe', 'cash', 'card'], true) ? $method : null;
        return $this;
    }
    public function getPaymentValidatedAt(): ?\DateTimeImmutable { return $this->paymentValidatedAt; }
    public function setPaymentValidatedAt(?\DateTimeImmutable $dt): static { $this->paymentValidatedAt = $dt; return $this; }
    public function getPaymentValidatedBy(): ?User { return $this->paymentValidatedBy; }
    public function setPaymentValidatedBy(?User $u): static { $this->paymentValidatedBy = $u; return $this; }
    public function isAwaitingOnSitePayment(): bool
    {
        return $this->status === self::STATUS_PENDING
            && in_array($this->intendedPaymentMethod, ['cash', 'card'], true);
    }
    public function getPaidAt(): ?\DateTimeImmutable { return $this->paidAt; }
    public function setPaidAt(?\DateTimeImmutable $paidAt): static { $this->paidAt = $paidAt; return $this; }
    public function getCheckinAt(): ?\DateTimeImmutable { return $this->checkinAt; }
    public function setCheckinAt(?\DateTimeImmutable $checkinAt): static { $this->checkinAt = $checkinAt; return $this; }
    public function getCheckinBy(): ?User { return $this->checkinBy; }
    public function setCheckinBy(?User $checkinBy): static { $this->checkinBy = $checkinBy; return $this; }
    public function getQrToken(): string { return $this->qrToken; }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
    public function isPaid(): bool { return $this->status === self::STATUS_PAID && $this->paidAt !== null; }
    public function isQrUnlocked(): bool { return $this->isPaid(); }
    public function isCheckedIn(): bool { return $this->checkinAt !== null; }

    public function getAmountFormatted(): string
    {
        return number_format((float) $this->amount, 2, ',', "\u{202F}") . ' €';
    }
}
