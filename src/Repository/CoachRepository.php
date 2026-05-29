<?php

namespace App\Repository;

use App\Entity\Coach;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Coach>
 */
class CoachRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Coach::class);
    }

    // ── Méthodes admin (lot 4) ────────────────────────────────────────────────

    /**
     * Tous les coachs avec leur User joint (évite les N+1), triés par nom.
     *
     * @return Coach[]
     */
    public function findAllWithUser(): array
    {
        return $this->createQueryBuilder('c')
            ->leftJoin('c.user', 'u')
            ->addSelect('u')
            ->orderBy('u.nomComplet', 'ASC')
            ->getQuery()
            ->getResult();
    }
}
