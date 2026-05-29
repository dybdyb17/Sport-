<?php

namespace App\Repository;

use App\Entity\Subscription;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Subscription>
 */
class SubscriptionRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Subscription::class);
    }

    /**
     * Abonnements actifs d'un client avec des séances restantes.
     *
     * @return Subscription[]
     */
    public function findActiveForClient(User $client): array
    {
        return $this->createQueryBuilder('s')
            ->andWhere('s.client = :client')
            ->andWhere('s.status = :status')
            ->andWhere('s.sessionsRemaining > 0')
            ->setParameter('client', $client)
            ->setParameter('status', Subscription::STATUS_ACTIVE)
            ->orderBy('s.createdAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Nombre d'abonnements par statut (les 3 clés sont toujours présentes).
     *
     * @return array{active:int,expired:int,cancelled:int}
     */
    public function countByStatus(): array
    {
        $counts = [
            Subscription::STATUS_ACTIVE    => 0,
            Subscription::STATUS_EXPIRED   => 0,
            Subscription::STATUS_CANCELLED => 0,
        ];

        $rows = $this->createQueryBuilder('s')
            ->select('s.status', 'COUNT(s.id) AS cnt')
            ->groupBy('s.status')
            ->getQuery()
            ->getResult();

        foreach ($rows as $row) {
            $key = (string) $row['status'];
            if (array_key_exists($key, $counts)) {
                $counts[$key] = (int) $row['cnt'];
            }
        }

        return $counts;
    }

    /**
     * Chiffre d'affaires mensuel récurrent estimé sur les abonnements actifs.
     */
    public function sumActiveMonthlyRevenue(): string
    {
        $raw = $this->createQueryBuilder('s')
            ->select('COALESCE(SUM(s.monthlyPrice), 0)')
            ->andWhere('s.status = :status')
            ->setParameter('status', Subscription::STATUS_ACTIVE)
            ->getQuery()
            ->getSingleScalarResult();

        return number_format((float) $raw, 2, '.', '');
    }

    /**
     * Abonnements récents avec client et coach chargés.
     *
     * @return Subscription[]
     */
    public function findAllRecent(int $limit = 50): array
    {
        return $this->createQueryBuilder('s')
            ->leftJoin('s.client', 'client')
            ->addSelect('client')
            ->leftJoin('s.coach', 'coach')
            ->addSelect('coach')
            ->leftJoin('coach.user', 'coachUser')
            ->addSelect('coachUser')
            ->orderBy('s.createdAt', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    /**
     * Abonnements récents filtrés par statut.
     *
     * @return Subscription[]
     */
    public function findRecentByStatus(string $status, int $limit = 50): array
    {
        return $this->createQueryBuilder('s')
            ->leftJoin('s.client', 'client')
            ->addSelect('client')
            ->leftJoin('s.coach', 'coach')
            ->addSelect('coach')
            ->leftJoin('coach.user', 'coachUser')
            ->addSelect('coachUser')
            ->andWhere('s.status = :status')
            ->setParameter('status', $status)
            ->orderBy('s.createdAt', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }
}
