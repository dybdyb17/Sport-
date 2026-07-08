<?php

namespace App\Entity;

use App\Entity\Enum\TimeSlot;
use App\Entity\Enum\UserRole;
use App\Entity\FoundingClaim;
use App\Repository\UserRepository;
use App\Entity\Subscription;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
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

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $lastSeenAt = null;

    #[ORM\Column(length: 20, nullable: true, enumType: TimeSlot::class)]
    private ?TimeSlot $preferredTimeSlot = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?Coach $preferredCoach = null;

    #[ORM\Column(length: 50, nullable: true)]
    private ?string $goal = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $coachNotes = null;

    /**
     * Avatar personnel du user (uploadé par lui-même via son profil).
     * Stocké en base64 dans la colonne, resize à 400x400 max côté serveur
     * pour éviter les images géantes.
     *
     * Indépendant de Coach.photoData qui reste la photo pro utilisée sur
     * la carte publique du coach (uploadée par Loïc). Un même user peut
     * avoir user.photoData (avatar perso) ET coach.photoData (photo pro).
     */
    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $photoData = null;

    #[ORM\Column(length: 32, nullable: true)]
    private ?string $photoMimeType = null;

    /**
     * ⚠️ PAS de valeur par défaut sur la propriété (volontaire).
     *
     * Avec PHP 8.4 + Doctrine ORM 3.x, les entités sont chargées via des
     * native lazy objects. Si une propriété a une valeur par défaut au niveau
     * de la déclaration, PHP la considère comme « déjà initialisée » et ne
     * déclenche PAS l'hydratation du proxy quand on y accède. Conséquence :
     * `$repository->find($id)` retournerait un User dont $role reste à la
     * valeur par défaut (CLIENT) au lieu de la vraie valeur en DB → un coach
     * authentifié apparaîtrait comme ROLE_CLIENT à Symfony (vécu 23/06 sur
     * /admin/checkin/{ref} : 403 sur le coach assigné).
     *
     * La valeur par défaut pour `new User()` est gérée dans __construct.
     */
    #[ORM\Column(enumType: UserRole::class)]
    private UserRole $role;

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
        // Valeur par défaut du role déplacée ici (vs propriété) pour permettre
        // aux proxies lazy de Doctrine de déclencher l'hydratation à l'accès
        // — cf commentaire sur la déclaration $role.
        $this->role          = UserRole::CLIENT;
        $this->createdAt     = new \DateTimeImmutable();
        $this->bookings      = new ArrayCollection();
        $this->subscriptions = new ArrayCollection();
    }

    /**
     * Sérialisation custom pour le token de sécurité Symfony.
     * On ne sérialise QUE le minimum dont Symfony a besoin pour reconstruire
     * l'utilisateur côté session (ID, email, password, roles, role enum).
     * Le reste est rechargé depuis la DB via le UserProvider à chaque requête.
     *
     * ⚠️ `role` (l'enum) DOIT être sérialisé : la colonne `roles` (array) est
     * souvent vide [] en DB pour les comptes coach/client purs. Si l'enum
     * n'est pas restauré, `__unserialize` retombe sur la valeur par défaut
     * UserRole::CLIENT → getRoles() retourne juste ROLE_CLIENT → un coach
     * connecté apparaît comme client à Symfony et se prend des 403 sur ses
     * propres pages (cas vécu 23/06 sur /admin/checkin/{ref}).
     *
     * Évite aussi les TypeError lors de changements de typage (DateTime
     * → DateTimeImmutable) entre versions, car les vieilles sessions ne
     * portent plus ces champs.
     */
    public function __serialize(): array
    {
        return [
            'id'       => $this->id,
            'email'    => $this->email,
            'password' => $this->password,
            'roles'    => $this->roles,
            'role'     => $this->role->value,
        ];
    }

    public function __unserialize(array $data): void
    {
        $this->id       = $data['id']       ?? null;
        $this->email    = $data['email']    ?? null;
        $this->password = $data['password'] ?? null;
        $this->roles    = $data['roles']    ?? [];

        // Restauration du role enum. Les vieilles sessions sérialisées avant ce
        // fix n'ont pas 'role' → fallback CLIENT (sans toucher au reste, le
        // UserProvider refresh corrigera au tour d'après en rechargeant depuis
        // la DB → l'enum est maintenant sans valeur par défaut sur la
        // propriété, donc le proxy lazy hydrate bien au premier accès).
        // Fallback EXPLICITE à CLIENT (pas de "?? $this->role") car $this->role
        // n'a plus de valeur par défaut → accès non initialisé = Error PHP 8.4.
        $this->role = isset($data['role'])
            ? (UserRole::tryFrom((string) $data['role']) ?? UserRole::CLIENT)
            : UserRole::CLIENT;
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

    public function getLastSeenAt(): ?\DateTimeImmutable { return $this->lastSeenAt; }
    public function setLastSeenAt(?\DateTimeImmutable $lastSeenAt): static { $this->lastSeenAt = $lastSeenAt; return $this; }

    public function getPhotoData(): ?string { return $this->photoData; }
    public function setPhotoData(?string $photoData): static { $this->photoData = $photoData; return $this; }
    public function getPhotoMimeType(): ?string { return $this->photoMimeType; }
    public function setPhotoMimeType(?string $photoMimeType): static { $this->photoMimeType = $photoMimeType; return $this; }

    /**
     * Data URI de l'avatar (utilisable direct en src="…"), null si pas d'avatar.
     * Pattern identique à Coach.getPhotoSrc() pour cohérence.
     */
    public function getPhotoSrc(): ?string
    {
        if ($this->photoData && $this->photoMimeType) {
            return sprintf('data:%s;base64,%s', $this->photoMimeType, $this->photoData);
        }
        return null;
    }

    /**
     * Première lettre du nomComplet (ou email en fallback) pour l'avatar
     * initiales quand pas de photo. Toujours uppercase.
     */
    public function getInitial(): string
    {
        $source = $this->nomComplet ?: $this->email ?: '?';
        return mb_strtoupper(mb_substr($source, 0, 1));
    }

    public function getPreferredTimeSlot(): ?TimeSlot { return $this->preferredTimeSlot; }
    public function setPreferredTimeSlot(?TimeSlot $preferredTimeSlot): static { $this->preferredTimeSlot = $preferredTimeSlot; return $this; }

    public function getPreferredCoach(): ?Coach { return $this->preferredCoach; }
    public function setPreferredCoach(?Coach $preferredCoach): static { $this->preferredCoach = $preferredCoach; return $this; }

    public function getGoal(): ?string { return $this->goal; }
    public function setGoal(?string $goal): static { $this->goal = $goal; return $this; }

    public function getCoachNotes(): ?string { return $this->coachNotes; }
    public function setCoachNotes(?string $coachNotes): static { $this->coachNotes = $coachNotes; return $this; }

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
            // Comparaison par ID (et pas par instance) : avec les proxies lazy
            // Doctrine, $booking->getClient() peut être une instance différente
            // de $this même si c'est le même user en DB. Si l'entité n'est
            // pas encore persistée (getId() null), on tombe sur null === null
            // → true → on dénoue quand même, ce qui est le comportement attendu
            // (un removeBooking sur une entité non persistée détache toujours).
            if ($booking->getClient()?->getId() === $this->getId()) {
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
