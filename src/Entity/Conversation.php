<?php

namespace App\Entity;

use App\Repository\ConversationRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: ConversationRepository::class)]
class Conversation
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    // Booking historique (rétro-compat). Pour les nouvelles conv, on s'appuie sur client + coach.
    #[ORM\OneToOne(inversedBy: 'conversation')]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?Booking $booking = null;

    // Identité d'une conversation = couple (client, coach). Une seule conv par couple.
    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'CASCADE')]
    private ?User $client = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'CASCADE')]
    private ?Coach $coach = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    /**
     * @var Collection<int, Message>
     */
    #[ORM\OneToMany(
        mappedBy: 'conversation',
        targetEntity: Message::class,
        cascade: ['persist', 'remove'],
        orphanRemoval: true,
    )]
    private Collection $messages;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
        $this->messages  = new ArrayCollection();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getBooking(): ?Booking
    {
        return $this->booking;
    }

    public function setBooking(?Booking $booking): static
    {
        $this->booking = $booking;
        // Si on attache un booking, on synchronise automatiquement client + coach
        if ($booking !== null) {
            if ($this->client === null) {
                $this->client = $booking->getClient();
            }
            if ($this->coach === null) {
                $this->coach = $booking->getCoach();
            }
        }
        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getClient(): ?User
    {
        // Source de vérité : champ direct. Fallback sur booking pour les anciennes conv non migrées.
        return $this->client ?? $this->booking?->getClient();
    }

    public function setClient(?User $client): static
    {
        $this->client = $client;
        return $this;
    }

    public function getCoach(): ?Coach
    {
        return $this->coach ?? $this->booking?->getCoach();
    }

    public function setCoach(?Coach $coach): static
    {
        $this->coach = $coach;
        return $this;
    }

    /**
     * @return Collection<int, Message>
     */
    public function getMessages(): Collection
    {
        return $this->messages;
    }

    public function addMessage(Message $message): static
    {
        if (!$this->messages->contains($message)) {
            $this->messages->add($message);
            $message->setConversation($this);
        }

        return $this;
    }

    public function removeMessage(Message $message): static
    {
        if ($this->messages->removeElement($message)) {
            if ($message->getConversation() === $this) {
                $message->setConversation(null);
            }
        }

        return $this;
    }

    public function getLastMessage(): ?Message
    {
        if ($this->messages->isEmpty()) {
            return null;
        }

        $messages = $this->messages->toArray();
        usort(
            $messages,
            static fn (Message $a, Message $b): int => $b->getCreatedAt() <=> $a->getCreatedAt(),
        );

        return $messages[0];
    }
}
