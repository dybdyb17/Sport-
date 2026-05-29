<?php

namespace App\Repository;

use App\Entity\Booking;
use App\Entity\Coach;
use App\Entity\Enum\TimeSlot;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Booking>
 */
class BookingRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Booking::class);
    }

    /**
     * Réservations d'un coach, triées par date de créneau.
     *
     * @return Booking[]
     */
    public function findForCoach(Coach $coach): array
    {
        return $this->createQueryBuilder('b')
            ->andWhere('b.coach = :coach')
            ->setParameter('coach', $coach)
            ->orderBy('b.startAt', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Réservations d'un client, plus récentes d'abord.
     *
     * @return Booking[]
     */
    public function findForClient(User $client): array
    {
        return $this->createQueryBuilder('b')
            ->andWhere('b.client = :client')
            ->setParameter('client', $client)
            ->orderBy('b.createdAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /** @return array{pending:int,confirmed:int,refused:int,cancelled:int,total:int} */
    public function countByStatus(): array
    {
        $counts = [
            Booking::STATUS_PENDING => 0,
            Booking::STATUS_CONFIRMED => 0,
            Booking::STATUS_REFUSED => 0,
            Booking::STATUS_CANCELLED => 0,
            'total' => 0,
        ];

        $rows = $this->createQueryBuilder('b')
            ->select('b.status AS status', 'COUNT(b.id) AS cnt')
            ->groupBy('b.status')
            ->getQuery()
            ->getResult();

        foreach ($rows as $row) {
            $counts[(string) $row['status']] = (int) $row['cnt'];
            $counts['total'] += (int) $row['cnt'];
        }

        return $counts;
    }

    /** @return Booking[] */
    public function findAllRecent(int $limit = 10): array
    {
        return $this->createQueryBuilder('b')
            ->leftJoin('b.client', 'client')->addSelect('client')
            ->leftJoin('b.coach', 'coach')->addSelect('coach')
            ->leftJoin('coach.user', 'coachUser')->addSelect('coachUser')
            ->orderBy('b.createdAt', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    /**
     * @return array{total:string,structure:string,coach:string}
     */
    public function getRevenueBreakdown(\DateTimeImmutable $since, ?\DateTimeImmutable $until = null): array
    {
        $rows = $this->revenueBySlotRows($since, $until);
        $total = 0.0;
        $structure = 0.0;

        foreach ($rows as $row) {
            $slot = $row['slot'] instanceof TimeSlot ? $row['slot'] : TimeSlot::from((string) $row['slot']);
            $revenue = (float) $row['revenue'];
            $total += $revenue;
            $structure += $revenue * $slot->structureMargin();
        }

        return [
            'total' => number_format($total, 2, '.', ''),
            'structure' => number_format($structure, 2, '.', ''),
            'coach' => number_format(max(0, $total - $structure), 2, '.', ''),
        ];
    }

    /**
     * @return array<string,array{count:int,revenue:string}>
     */
    public function countAndRevenueBySlot(\DateTimeImmutable $since, ?\DateTimeImmutable $until = null): array
    {
        $stats = [];
        foreach (TimeSlot::cases() as $slot) {
            $stats[$slot->value] = ['count' => 0, 'revenue' => '0.00'];
        }

        foreach ($this->revenueBySlotRows($since, $until, true) as $row) {
            $slot = $row['slot'] instanceof TimeSlot ? $row['slot']->value : (string) $row['slot'];
            $stats[$slot] = [
                'count' => (int) $row['cnt'],
                'revenue' => number_format((float) $row['revenue'], 2, '.', ''),
            ];
        }

        return $stats;
    }

    /** @return Booking[] */
    public function findConfirmedInWindow(\DateTimeImmutable $from, \DateTimeImmutable $to): array
    {
        return $this->createQueryBuilder('b')
            ->leftJoin('b.client', 'client')->addSelect('client')
            ->leftJoin('b.coach', 'coach')->addSelect('coach')
            ->leftJoin('coach.user', 'coachUser')->addSelect('coachUser')
            ->andWhere('b.status = :status')
            ->andWhere('b.startAt >= :from')
            ->andWhere('b.startAt <= :to')
            ->setParameter('status', Booking::STATUS_CONFIRMED)
            ->setParameter('from', $from)
            ->setParameter('to', $to)
            ->orderBy('b.startAt', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /** @return array<int,array{coach:Coach,sessions:int,revenue:string,coachMargin:string}> */
    public function topCoachesByRevenue(\DateTimeImmutable $since, int $limit = 5): array
    {
        $rows = $this->createQueryBuilder('b')
            ->select('coach AS coach', 'COUNT(b.id) AS sessions', 'COALESCE(SUM(b.price), 0) AS revenue')
            ->join('b.coach', 'coach')
            ->join('coach.user', 'coachUser')->addSelect('coachUser')
            ->andWhere('b.status = :status')
            ->andWhere('b.startAt >= :since')
            ->setParameter('status', Booking::STATUS_CONFIRMED)
            ->setParameter('since', $since)
            ->groupBy('coach.id')
            ->orderBy('revenue', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();

        return array_map(static function (array $row): array {
            $revenue = (float) $row['revenue'];
            return [
                'coach' => $row['coach'],
                'sessions' => (int) $row['sessions'],
                'revenue' => number_format($revenue, 2, '.', ''),
                'coachMargin' => number_format($revenue * 0.60, 2, '.', ''),
            ];
        }, $rows);
    }

    /** @return array<int,array{slot:mixed,revenue:mixed,cnt?:mixed}> */
    private function revenueBySlotRows(\DateTimeImmutable $since, ?\DateTimeImmutable $until = null, bool $withCount = false): array
    {
        $qb = $this->createQueryBuilder('b')
            ->select('b.timeSlot AS slot', 'COALESCE(SUM(b.price), 0) AS revenue')
            ->andWhere('b.status = :status')
            ->andWhere('b.startAt >= :since')
            ->setParameter('status', Booking::STATUS_CONFIRMED)
            ->setParameter('since', $since)
            ->groupBy('b.timeSlot')
            ->orderBy('b.timeSlot', 'ASC');

        if ($withCount) {
            $qb->addSelect('COUNT(b.id) AS cnt');
        }

        if ($until) {
            $qb->andWhere('b.startAt < :until')->setParameter('until', $until);
        }

        return $qb->getQuery()->getResult();
    }

}
