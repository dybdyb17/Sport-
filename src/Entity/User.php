<?php

namespace App\Entity;

use App\Entity\Enum\UserRole;
use App\Entity\FoundingClaim;
use App\Repository\UserRepository;
use App\Entity\Subscription;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity(repositoryClass: UserRepository::class)]
#[ORM\Table(name: '`user`')]
#[ORM\UniqueConstraint(name: 'UNIQ_IDENTIFIER_EMAIL', fields: ['email'])]
class User implements UserInterface, PasswordAuthenticatedUserInterface
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 180, unique: true)]
    #[Assert\NotBlank]
    #[Assert\Email]
    private ?string $email = null;

    /**
     * @var list<string>
     */
    #[ORM\Column]
    private array $roles = [];

    #[ORM\Column]
    private ?string $password = null;

    #[ORM\Column(length: 100)]
    #[Assert\NotBlank]
    private ?string $nomComplet = null;

    #[ORM\Column(length: 20, nullable: true)]
    private ?string $phone = null;

    #[ORM\Column(enumType: UserRole::class)]
    private UserRole $role = UserRole::CLIENT;

    #[ORM\Column]
    private ?\DateTimeImmutable $createdAt = null;

    /**
     * Compte coach éventuellement associé (si l'utilisateur est un coach).
     */
    #[ORM\OneToOne(mappedBy: 'user', cascade: ['persist', 'remove'])]
    private ?Coach $coach = null;

    /**
     * Réservations faites par cet utilisateur en tant que client.
     *
     * @var Collection<int, Booking>
     */
    #[ORM\OneToMany(mappedBy: 'client', targetEntity: Booking::class)]
    private Collection $bookings;

    /**
     * Packs mensuels souscrits par cet utilisateur.
     *
     * @var Collection<int, Subscription>
     */
    #[ORM\OneToMany(mappedBy: 'client', targetEntity: Subscription::class)]
    private Collection $subscriptions;

    #[ORM\OneToOne(mappedBy: 'user', targetEntity: FoundingClaim::class)]
    private ?FoundingClaim $foundingClaim = null;

    public function __construct()
    {
        $this->createdAt     = new \DateTimeImmutable();
        $this->bookings      = new ArrayCollection();
        $this->subscriptions = new ArrayCollection();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getEmail(): ?string
    {
        return $this->email;
    }

    public function setEmail(string $email): static
    {
        $this->email = $email;

        return $this;
    }

    /**
     * Identifiant visuel/technique de l'utilisateur (jamais le mot de passe).
     */
    public function getUserIdentifier(): string
    {
        return (string) $this->email;
    }

    /**
     * @return list<string>
     */
    public function getRoles(): array
    {
        $roles = $this->roles;
        $roles[] = 'ROLE_' . strtoupper($this->role->value);
        $roles[] = 'ROLE_USER';

        return array_values(array_unique($roles));
    }

    /**
     * @param list<string> $roles
     */
    public function setRoles(array $roles): static
    {
        $this->roles = $roles;

        return $this;
    }

    public function getPassword(): ?string
    {
        return $this->password;
    }

    public function setPassword(string $password): static
    {
        $this->password = $password;

        return $this;
    }

    /**
     * @see UserInterface
     */
    public function eraseCredentials(): void
    {
        // Si des données sensibles temporaires étaient stockées, les effacer ici.
    }

    public function getNomComplet(): ?string
    {
        return $this->nomComplet;
    }

    public function setNomComplet(string $nomComplet): static
    {
        $this->nomComplet = $nomComplet;

        return $this;
    }

    public function getPhone(): ?string
    {
        return $this->phone;
    }

    public function setPhone(?string $phone): static
    {
        $this->phone = $phone;

        return $this;
    }

    public function getRole(): UserRole
    {
        return $this->role;
    }

    public function setRole(UserRole $role): static
    {
        $this->role = $role;

        return $this;
    }

    public function getCreatedAt(): ?\DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function setCreatedAt(\DateTimeImmutable $createdAt): static
    {
        $this->createdAt = $createdAt;

        return $this;
    }

    public function getCoach(): ?Coach
    {
        return $this->coach;
    }

    public function setCoach(?Coach $coach): static
    {
        // Garantir la cohérence du côté propriétaire de la relation.
        if ($coach !== null && $coach->getUser() !== $this) {
            $coach->setUser($this);
        }

        $this->coach = $coach;

        return $this;
    }

    public function isCoach(): bool
    {
        return $this->role === UserRole::COACH;
    }

    public function getFoundingClaim(): ?FoundingClaim { return $this->foundingClaim; }
    public function isFoundingMember(): bool { return $this->foundingClaim !== null; }

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
            $booking->setClient($this);
        }

        return $this;
    }

    public function removeBooking(Booking $booking): static
    {
        if ($this->bookings->removeElement($booking)) {
            if ($booking->getClient() === $this) {
                $booking->setClient(null);
            }
        }

        return $this;
    }

    /**
     * @return Collection<int, Subscription>
     */
    public function getSubscriptions(): Collection
    {
        return $this->subscriptions;
    }

    public function addSubscription(Subscription $subscription): static
    {
        if (!$this->subscriptions->contains($subscription)) {
            $this->subscriptions->add($subscription);
            $subscription->setClient($this);
        }

        return $this;
    }

    public function removeSubscription(Subscription $subscription): static
    {
        $this->subscriptions->removeElement($subscription);

        return $this;
    }
}
