<?php

namespace App\Repository;

use App\Entity\AuditLog;
use App\Entity\Enum\AuditAction;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<AuditLog>
 */
class AuditLogRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, AuditLog::class);
    }

    /**
     * @return AuditLog[]
     */
    public function findFiltered(
        ?AuditAction $action = null,
        ?string $actorRole = null,
        ?int $actorId = null,
        ?\DateTimeImmutable $from = null,
        ?\DateTimeImmutable $to = null,
        int $limit = 100,
    ): array {
        $qb = $this->createQueryBuilder('l')
            ->orderBy('l.createdAt', 'DESC')
            ->setMaxResults($limit);

        if ($action !== null) {
            $qb->andWhere('l.action = :action')->setParameter('action', $action);
        }
        if ($actorRole !== null) {
            $qb->andWhere('l.actorRole = :role')->setParameter('role', $actorRole);
        }
        if ($actorId !== null) {
            $qb->andWhere('IDENTITY(l.actor) = :aid')->setParameter('aid', $actorId);
        }
        if ($from !== null) {
            $qb->andWhere('l.createdAt >= :from')->setParameter('from', $from);
        }
        if ($to !== null) {
            $qb->andWhere('l.createdAt <= :to')->setParameter('to', $to);
        }

        return $qb->getQuery()->getResult();
    }

    /** Compte les actions sensibles d'un coach sur les N derniers jours (détection anomalies). */
    public function countActionsForCoach(int $coachUserId, AuditAction $action, int $days): int
    {
        $since = new \DateTimeImmutable(sprintf('-%d days', $days));

        return (int) $this->createQueryBuilder('l')
            ->select('COUNT(l.id)')
            ->andWhere('l.action = :action')->setParameter('action', $action)
            ->andWhere('IDENTITY(l.actor) = :uid')->setParameter('uid', $coachUserId)
            ->andWhere('l.createdAt >= :since')->setParameter('since', $since)
            ->getQuery()
            ->getSingleScalarResult();
    }
}
