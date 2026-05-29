<?php

namespace App\Repository;

use App\Entity\Enum\PackType;
use App\Entity\Subscription;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<Subscription> */
class SubscriptionRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Subscription::class);
    }

    /** @return Subscription[] */
    public function findAllActive(): array
    {
        return $this->baseActiveBuilder()
            ->orderBy('s.endsAt', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /** @return Subscription[] */
    public function findExpiringSoon(int $days = 7): array
    {
        return $this->baseActiveBuilder()
            ->andWhere('s.endsAt <= :until')
            ->setParameter('until', new \DateTimeImmutable(sprintf('+%d days 23:59:59', $days)))
            ->orderBy('s.endsAt', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /** @return array{active:int,byPack:array<string,int>,monthlyRecurring:string} */
    public function getGlobalStats(): array
    {
        $stats = ['active' => 0, 'byPack' => [], 'monthlyRecurring' => '0.00'];
        foreach (PackType::cases() as $pack) {
            $stats['byPack'][$pack->value] = 0;
        }

        $rows = $this->createQueryBuilder('s')
            ->select('s.packType AS pack', 'COUNT(s.id) AS cnt', 'COALESCE(SUM(s.monthlyPrice), 0) AS revenue')
            ->andWhere('s.status = :status')
            ->andWhere('s.endsAt >= :now')
            ->setParameter('status', Subscription::STATUS_ACTIVE)
            ->setParameter('now', new \DateTimeImmutable('today'))
            ->groupBy('s.packType')
            ->getQuery()
            ->getResult();

        $revenue = 0.0;
        foreach ($rows as $row) {
            $pack = $row['pack'] instanceof PackType ? $row['pack']->value : (string) $row['pack'];
            $count = (int) $row['cnt'];
            $stats['byPack'][$pack] = $count;
            $stats['active'] += $count;
            $revenue += (float) $row['revenue'];
        }
        $stats['monthlyRecurring'] = number_format($revenue, 2, '.', '');

        return $stats;
    }

    private function baseActiveBuilder()
    {
        return $this->createQueryBuilder('s')
            ->leftJoin('s.client', 'client')->addSelect('client')
            ->leftJoin('s.coach', 'coach')->addSelect('coach')
            ->leftJoin('coach.user', 'coachUser')->addSelect('coachUser')
            ->andWhere('s.status = :status')
            ->andWhere('s.endsAt >= :now')
            ->setParameter('status', Subscription::STATUS_ACTIVE)
            ->setParameter('now', new \DateTimeImmutable('today'));
    }
}
