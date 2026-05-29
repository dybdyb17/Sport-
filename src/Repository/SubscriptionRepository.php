<?php

namespace App\Repository;

use App\Entity\Enum\PackType;
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

    /** @return Subscription[] */
    public function findAllActive(): array
    {
        return $this->createQueryBuilder('s')
            ->join('s.client', 'c')
            ->addSelect('c')
            ->leftJoin('s.coach', 'co')
            ->addSelect('co')
            ->leftJoin('co.user', 'cu')
            ->addSelect('cu')
            ->where('s.status = :status')
            ->setParameter('status', Subscription::STATUS_ACTIVE)
            ->orderBy('s.endsAt', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /** @return Subscription[] */
    public function findExpiringSoon(int $days = 7): array
    {
        $now   = new \DateTimeImmutable();
        $limit = $now->modify("+{$days} days");

        return $this->createQueryBuilder('s')
            ->join('s.client', 'c')
            ->addSelect('c')
            ->leftJoin('s.coach', 'co')
            ->addSelect('co')
            ->where('s.status = :status')
            ->andWhere('s.endsAt >= :now')
            ->andWhere('s.endsAt <= :limit')
            ->setParameter('status', Subscription::STATUS_ACTIVE)
            ->setParameter('now', $now)
            ->setParameter('limit', $limit)
            ->orderBy('s.endsAt', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /** @return array{active:int,byPack:array<string,int>,monthlyRecurring:string} */
    public function getGlobalStats(): array
    {
        $active = (int) $this->createQueryBuilder('s')
            ->select('COUNT(s.id)')
            ->where('s.status = :status')
            ->setParameter('status', Subscription::STATUS_ACTIVE)
            ->getQuery()
            ->getSingleScalarResult();

        $byPackRows = $this->createQueryBuilder('s')
            ->select('s.packType AS pack', 'COUNT(s.id) AS cnt')
            ->where('s.status = :status')
            ->setParameter('status', Subscription::STATUS_ACTIVE)
            ->groupBy('s.packType')
            ->getQuery()
            ->getResult();

        $byPack = [];
        foreach ($byPackRows as $row) {
            $pack = $row['pack'];
            $key  = $pack instanceof PackType ? $pack->value : (string) $pack;
            $byPack[$key] = (int) $row['cnt'];
        }

        $monthlyRevenue = (float) ($this->createQueryBuilder('s')
            ->select('COALESCE(SUM(s.monthlyPrice), 0)')
            ->where('s.status = :status')
            ->setParameter('status', Subscription::STATUS_ACTIVE)
            ->getQuery()
            ->getSingleScalarResult() ?? 0);

        return [
            'active'           => $active,
            'byPack'           => $byPack,
            'monthlyRecurring' => number_format($monthlyRevenue, 2, '.', ''),
        ];
    }
}
