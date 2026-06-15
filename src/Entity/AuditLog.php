<?php

namespace App\Entity;

use App\Entity\Enum\AuditAction;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Journal d'audit des actions sensibles (paiements, no-show, modifs admin, etc.).
 * Lecture seule depuis l'UI — uniquement écrit via AuditLogger pour garantir
 * la traçabilité sans contournement possible.
 */
#[ORM\Entity(repositoryClass: \App\Repository\AuditLogRepository::class)]
#[ORM\Table(name: 'audit_log')]
#[ORM\Index(columns: ['action'], name: 'idx_audit_action')]
#[ORM\Index(columns: ['actor_id'], name: 'idx_audit_actor')]
#[ORM\Index(columns: ['target_type', 'target_id'], name: 'idx_audit_target')]
#[ORM\Index(columns: ['created_at'], name: 'idx_audit_created_at')]
class AuditLog
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 50, enumType: AuditAction::class)]
    private AuditAction $action;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?User $actor = null;

    /** Snapshot textuel de l'acteur (au cas où le compte est supprimé plus tard). */
    #[ORM\Column(length: 180, nullable: true)]
    private ?string $actorLabel = null;

    /** Rôle qui a effectué l'action (admin / coach / client). */
    #[ORM\Column(length: 20)]
    private string $actorRole = 'client';

    /** Type d'entité ciblée (ex: "Booking", "User", "Subscription"). */
    #[ORM\Column(length: 30, nullable: true)]
    private ?string $targetType = null;

    #[ORM\Column(nullable: true)]
    private ?int $targetId = null;

    /** Libellé lisible de la cible (ex: référence booking, nom user). */
    #[ORM\Column(length: 180, nullable: true)]
    private ?string $targetLabel = null;

    /** Détails libres en JSON (ex: {"method":"cash","amount":"60.00"}). */
    #[ORM\Column(type: Types::JSON)]
    private array $details = [];

    #[ORM\Column(length: 45, nullable: true)]
    private ?string $ipAddress = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $userAgent = null;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
    }

    // ── getters/setters ──────────────────────────────────────────────
    public function getId(): ?int { return $this->id; }
    public function getAction(): AuditAction { return $this->action; }
    public function setAction(AuditAction $action): static { $this->action = $action; return $this; }
    public function getActor(): ?User { return $this->actor; }
    public function setActor(?User $actor): static { $this->actor = $actor; return $this; }
    public function getActorLabel(): ?string { return $this->actorLabel; }
    public function setActorLabel(?string $l): static { $this->actorLabel = $l; return $this; }
    public function getActorRole(): string { return $this->actorRole; }
    public function setActorRole(string $r): static { $this->actorRole = $r; return $this; }
    public function getTargetType(): ?string { return $this->targetType; }
    public function setTargetType(?string $t): static { $this->targetType = $t; return $this; }
    public function getTargetId(): ?int { return $this->targetId; }
    public function setTargetId(?int $id): static { $this->targetId = $id; return $this; }
    public function getTargetLabel(): ?string { return $this->targetLabel; }
    public function setTargetLabel(?string $l): static { $this->targetLabel = $l; return $this; }
    public function getDetails(): array { return $this->details; }
    public function setDetails(array $d): static { $this->details = $d; return $this; }
    public function getIpAddress(): ?string { return $this->ipAddress; }
    public function setIpAddress(?string $ip): static { $this->ipAddress = $ip; return $this; }
    public function getUserAgent(): ?string { return $this->userAgent; }
    public function setUserAgent(?string $ua): static { $this->userAgent = $ua; return $this; }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
}
