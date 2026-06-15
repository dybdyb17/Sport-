<?php

namespace App\Service;

use App\Entity\AuditLog;
use App\Entity\Booking;
use App\Entity\Enum\AuditAction;
use App\Entity\Subscription;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Point d'entrée unique pour tracer les actions sensibles dans audit_log.
 *
 * Usage : $auditLogger->log(AuditAction::PAYMENT_DECLARED, $booking, ['method' => 'cash', 'amount' => '60.00']);
 *
 * L'acteur (User connecté), son rôle, son IP et son User-Agent sont récupérés
 * automatiquement depuis la requête courante. Aucune fuite de données possible.
 */
class AuditLogger
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly Security               $security,
        private readonly RequestStack           $requestStack,
    ) {}

    /**
     * Trace une action.
     *
     * @param Booking|Subscription|User|null $target Entité ciblée (optionnel)
     * @param array<string, scalar|array|null> $details Données libres à archiver
     */
    public function log(AuditAction $action, object|null $target = null, array $details = []): AuditLog
    {
        $log = new AuditLog();
        $log->setAction($action);
        $log->setDetails($details);

        // Acteur
        $actor = $this->security->getUser();
        if ($actor instanceof User) {
            $log->setActor($actor);
            $log->setActorLabel($actor->getNomComplet() ?? $actor->getUserIdentifier());
            $log->setActorRole($this->resolveRole($actor));
        } else {
            $log->setActorRole('system');
            $log->setActorLabel('Système');
        }

        // Cible
        if ($target !== null) {
            $log->setTargetType($this->shortClass($target));
            $log->setTargetId($this->extractId($target));
            $log->setTargetLabel($this->labelFor($target));
        }

        // Contexte requête
        $request = $this->requestStack->getCurrentRequest();
        if ($request !== null) {
            $log->setIpAddress($request->getClientIp());
            $log->setUserAgent(substr((string) $request->headers->get('User-Agent', ''), 0, 500) ?: null);
        }

        $this->em->persist($log);
        $this->em->flush();

        return $log;
    }

    private function resolveRole(User $user): string
    {
        $roles = $user->getRoles();
        if (in_array('ROLE_ADMIN', $roles, true))  return 'admin';
        if (in_array('ROLE_COACH', $roles, true))  return 'coach';
        if (in_array('ROLE_CLIENT', $roles, true)) return 'client';
        return 'user';
    }

    private function shortClass(object $target): string
    {
        $parts = explode('\\', get_class($target));
        return end($parts) ?: get_class($target);
    }

    private function extractId(object $target): ?int
    {
        if (method_exists($target, 'getId')) {
            $id = $target->getId();
            return is_int($id) ? $id : null;
        }
        return null;
    }

    private function labelFor(object $target): ?string
    {
        if ($target instanceof Booking) {
            return $target->getReference();
        }
        if ($target instanceof User) {
            return $target->getNomComplet() ?? $target->getUserIdentifier();
        }
        if ($target instanceof Subscription) {
            return sprintf('Pack #%d', $target->getId() ?? 0);
        }
        if (method_exists($target, '__toString')) {
            return (string) $target;
        }
        return null;
    }
}
