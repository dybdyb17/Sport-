<?php

namespace App\Repository;

use App\Entity\Booking;
use App\Entity\Coach;
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

    // ── Méthodes existantes (inchangées) ──────────────────────────────────────

    /** @return Booking[] */
    public function findForCoach(Coach $coach): array
    {
        return $this->createQueryBuilder('b')
            ->andWhere('b.coach = :coach')
            ->setParameter('coach', $coach)
            ->orderBy('b.startAt', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /** @return Booking[] */
    public function findForClient(User $client): array
    {
        return $this->createQueryBuilder('b')
            ->andWhere('b.client = :client')
            ->setParameter('client', $client)
            ->orderBy('b.createdAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    // ── Méthodes coach (lot 3) ────────────────────────────────────────────────

    /** @return Booking[] */
    public function findPendingForCoach(Coach $coach): array
    {
        return $this->createQueryBuilder('b')
            ->andWhere('b.coach = :coach')
            ->andWhere('b.status = :status')
            ->setParameter('coach', $coach)
            ->setParameter('status', Booking::STATUS_PENDING)
            ->orderBy('b.startAt', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /** @return Booking[] */
    public function findConfirmedUpcomingForCoach(Coach $coach): array
    {
        return $this->createQueryBuilder('b')
            ->andWhere('b.coach = :coach')
            ->andWhere('b.status = :status')
            ->andWhere('b.startAt >= :now')
            ->setParameter('coach', $coach)
            ->setParameter('status', Booking::STATUS_CONFIRMED)
            ->setParameter('now', new \DateTimeImmutable())
            ->orderBy('b.startAt', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /** @return Booking[] */
    public function findHistoryForCoach(Coach $coach): array
    {
        return $this->createQueryBuilder('b')
            ->andWhere('b.coach = :coach')
            ->andWhere('b.status IN (:statuses)')
            ->andWhere('b.startAt < :now')
            ->setParameter('coach', $coach)
            ->setParameter('statuses', [
                Booking::STATUS_CONFIRMED,
                Booking::STATUS_REFUSED,
                Booking::STATUS_CANCELLED,
            ])
            ->setParameter('now', new \DateTimeImmutable())
            ->orderBy('b.startAt', 'DESC')
            ->setMaxResults(20)
            ->getQuery()
            ->getResult();
    }

    public function sumRevenueForCoach(Coach $coach, \DateTimeImmutable $since): string
    {
        $raw = $this->createQueryBuilder('b')
            ->select('COALESCE(SUM(b.price), 0)')
            ->andWhere('b.coach = :coach')
            ->andWhere('b.status = :status')
            ->andWhere('b.startAt >= :since')
            ->setParameter('coach', $coach)
            ->setParameter('status', Booking::STATUS_CONFIRMED)
            ->setParameter('since', $since)
            ->getQuery()
            ->getSingleScalarResult();

        return number_format((float) $raw, 2, '.', '');
    }

    public function countConfirmedThisMonthForCoach(Coach $coach): int
    {
        $from = new \DateTimeImmutable('first day of this month 00:00');
        $to   = new \DateTimeImmutable('first day of next month 00:00');

        return (int) $this->createQueryBuilder('b')
            ->select('COUNT(b.id)')
            ->andWhere('b.coach = :coach')
            ->andWhere('b.status = :status')
            ->andWhere('b.startAt >= :from')
            ->andWhere('b.startAt < :to')
            ->setParameter('coach', $coach)
            ->setParameter('status', Booking::STATUS_CONFIRMED)
            ->setParameter('from', $from)
            ->setParameter('to', $to)
            ->getQuery()
            ->getSingleScalarResult();
    }

    // ── Méthodes admin (lot 4) ────────────────────────────────────────────────

    /**
     * Nombre de réservations par statut (les 4 clés sont toujours présentes).
     *
     * @return array{pending:int,confirmed:int,refused:int,cancelled:int}
     */
    public function countByStatus(): array
    {
        $counts = [
            Booking::STATUS_PENDING   => 0,
            Booking::STATUS_CONFIRMED => 0,
            Booking::STATUS_REFUSED   => 0,
            Booking::STATUS_CANCELLED => 0,
        ];

        $rows = $this->createQueryBuilder('b')
            ->select('b.status', 'COUNT(b.id) AS cnt')
            ->groupBy('b.status')
            ->getQuery()
            ->getResult();

        foreach ($rows as $row) {
            $key = $row['status'];
            if (array_key_exists($key, $counts)) {
                $counts[$key] = (int) $row['cnt'];
            }
        }

        return $counts;
    }

    /**
     * CA global des bookings confirmed depuis $since.
     */
    public function sumRevenue(\DateTimeImmutable $since): string
    {
        $raw = $this->createQueryBuilder('b')
            ->select('COALESCE(SUM(b.price), 0)')
            ->andWhere('b.status = :status')
            ->andWhere('b.startAt >= :since')
            ->setParameter('status', Booking::STATUS_CONFIRMED)
            ->setParameter('since', $since)
            ->getQuery()
            ->getSingleScalarResult();

        return number_format((float) $raw, 2, '.', '');
    }

    /**
     * Toutes réservations récentes avec jointures pour éviter les N+1.
     *
     * @return Booking[]
     */
    public function findAllRecent(int $limit = 50): array
    {
        return $this->createQueryBuilder('b')
            ->leftJoin('b.client', 'client')
            ->addSelect('client')
            ->leftJoin('b.coach', 'coach')
            ->addSelect('coach')
            ->leftJoin('coach.user', 'coachUser')
            ->addSelect('coachUser')
            ->orderBy('b.createdAt', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    /**
     * Réservations récentes filtrées par statut.
     *
     * @return Booking[]
     */
    public function findRecentByStatus(string $status, int $limit = 50): array
    {
        return $this->createQueryBuilder('b')
            ->leftJoin('b.client', 'client')
            ->addSelect('client')
            ->leftJoin('b.coach', 'coach')
            ->addSelect('coach')
            ->leftJoin('coach.user', 'coachUser')
            ->addSelect('coachUser')
            ->andWhere('b.status = :status')
            ->setParameter('status', $status)
            ->orderBy('b.createdAt', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    // ── Méthodes admin A3 (lot A3) ───────────────────────────────────────────

    /**
     * Décompose le CA confirmé en parts structure/coach via les marges TimeSlot.
     *
     * @return array{total:string,structure:string,coach:string}
     */
    public function getRevenueBreakdown(\DateTimeImmutable $since, ?\DateTimeImmutable $until = null): array
    {
        $qb = $this->createQueryBuilder('b')
            ->select('b.timeSlot AS slot', 'COALESCE(SUM(b.price), 0) AS total')
            ->where('b.status = :status')
            ->andWhere('b.startAt >= :since')
            ->setParameter('status', Booking::STATUS_CONFIRMED)
            ->setParameter('since', $since)
            ->groupBy('b.timeSlot');

        if ($until !== null) {
            $qb->andWhere('b.startAt < :until')->setParameter('until', $until);
        }

        $rows = $qb->getQuery()->getResult();

        $grandTotal = 0.0;
        $structureTotal = 0.0;
        $coachTotal = 0.0;

        foreach ($rows as $row) {
            $slot = $row['slot'];
            $amount = (float) $row['total'];
            $grandTotal += $amount;

            if (is_string($slot)) {
                $slot = \App\Entity\Enum\TimeSlot::from($slot);
            }

            $rate = $slot->structureMarginRate();
            $structureTotal += $amount * $rate;
            $coachTotal += $amount * (1 - $rate);
        }

        return [
            'total'     => number_format($grandTotal, 2, '.', ''),
            'structure' => number_format($structureTotal, 2, '.', ''),
            'coach'     => number_format($coachTotal, 2, '.', ''),
        ];
    }

    /**
     * Nb séances confirmées + CA par créneau TimeSlot. Les 3 clés sont toujours présentes.
     *
     * @return array{day:array{count:int,revenue:string},night:array{count:int,revenue:string},astreinte:array{count:int,revenue:string}}
     */
    public function countAndRevenueBySlot(\DateTimeImmutable $since, ?\DateTimeImmutable $until = null): array
    {
        $result = [
            'day'       => ['count' => 0, 'revenue' => '0.00'],
            'night'     => ['count' => 0, 'revenue' => '0.00'],
            'astreinte' => ['count' => 0, 'revenue' => '0.00'],
        ];

        $qb = $this->createQueryBuilder('b')
            ->select('b.timeSlot AS slot', 'COUNT(b.id) AS cnt', 'COALESCE(SUM(b.price), 0) AS revenue')
            ->where('b.status = :status')
            ->andWhere('b.startAt >= :since')
            ->setParameter('status', Booking::STATUS_CONFIRMED)
            ->setParameter('since', $since)
            ->groupBy('b.timeSlot');

        if ($until !== null) {
            $qb->andWhere('b.startAt < :until')->setParameter('until', $until);
        }

        $rows = $qb->getQuery()->getResult();

        foreach ($rows as $row) {
            $slot = $row['slot'];
            $key = $slot instanceof \App\Entity\Enum\TimeSlot ? $slot->value : (string) $slot;
            if (isset($result[$key])) {
                $result[$key] = [
                    'count'   => (int) $row['cnt'],
                    'revenue' => number_format((float) $row['revenue'], 2, '.', ''),
                ];
            }
        }

        return $result;
    }

    /**
     * Réservations confirmées dans une fenêtre temporelle, avec jointures client+coach.
     *
     * @return Booking[]
     */
    public function findConfirmedInWindow(\DateTimeImmutable $from, \DateTimeImmutable $to): array
    {
        return $this->createQueryBuilder('b')
            ->join('b.client', 'client')
            ->addSelect('client')
            ->join('b.coach', 'coach')
            ->addSelect('coach')
            ->join('coach.user', 'coachUser')
            ->addSelect('coachUser')
            ->where('b.status = :status')
            ->andWhere('b.startAt >= :from')
            ->andWhere('b.startAt <= :to')
            ->setParameter('status', Booking::STATUS_CONFIRMED)
            ->setParameter('from', $from)
            ->setParameter('to', $to)
            ->orderBy('b.startAt', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Top coachs par CA confirmé sur la période.
     *
     * @return array<array{coach:\App\Entity\Coach,revenue:string,sessions:int}>
     */
    public function topCoachesByRevenue(\DateTimeImmutable $since, int $limit = 5): array
    {
        $rows = $this->createQueryBuilder('b')
            ->select(
                'IDENTITY(b.coach) AS coachId',
                'SUM(b.price) AS revenue',
                'COUNT(b.id) AS sessions',
            )
            ->where('b.status = :status')
            ->andWhere('b.startAt >= :since')
            ->setParameter('status', Booking::STATUS_CONFIRMED)
            ->setParameter('since', $since)
            ->groupBy('b.coach')
            ->orderBy('revenue', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();

        if (empty($rows)) {
            return [];
        }

        $coachIds = array_column($rows, 'coachId');
        $coaches = $this->getEntityManager()->createQueryBuilder()
            ->select('c', 'u')
            ->from(\App\Entity\Coach::class, 'c')
            ->join('c.user', 'u')
            ->where('c.id IN (:ids)')
            ->setParameter('ids', $coachIds)
            ->getQuery()
            ->getResult();

        $coachesById = [];
        foreach ($coaches as $coach) {
            $coachesById[$coach->getId()] = $coach;
        }

        $result = [];
        foreach ($rows as $row) {
            $coach = $coachesById[(int) $row['coachId']] ?? null;
            if ($coach === null) {
                continue;
            }
            $result[] = [
                'coach'    => $coach,
                'revenue'  => number_format((float) $row['revenue'], 2, '.', ''),
                'sessions' => (int) $row['sessions'],
            ];
        }

        return $result;
    }

    /**
     * Performance détaillée par coach : pour chaque coach actif sur la période,
     * détaille le nombre de séances et le CA par créneau (day/night/astreinte).
     *
     * @return array<array{coach:\App\Entity\Coach, total:int, totalRevenue:string, day:array{count:int,revenue:string}, night:array{count:int,revenue:string}, astreinte:array{count:int,revenue:string}}>
     */
    public function performanceByCoachAndSlot(\DateTimeImmutable $since, ?\DateTimeImmutable $until = null): array
    {
        $qb = $this->createQueryBuilder('b')
            ->select(
                'IDENTITY(b.coach) AS coachId',
                'b.timeSlot AS slot',
                'COUNT(b.id) AS cnt',
                'COALESCE(SUM(b.price), 0) AS revenue',
            )
            ->where('b.status = :status')
            ->andWhere('b.startAt >= :since')
            ->setParameter('status', Booking::STATUS_CONFIRMED)
            ->setParameter('since', $since)
            ->groupBy('b.coach, b.timeSlot');

        if ($until !== null) {
            $qb->andWhere('b.startAt < :until')->setParameter('until', $until);
        }

        $rows = $qb->getQuery()->getResult();
        if (empty($rows)) {
            return [];
        }

        // Hydratation des coachs
        $coachIds = array_unique(array_map(static fn ($r) => (int) $r['coachId'], $rows));
        $coaches = $this->getEntityManager()->createQueryBuilder()
            ->select('c', 'u')
            ->from(\App\Entity\Coach::class, 'c')
            ->join('c.user', 'u')
            ->where('c.id IN (:ids)')
            ->setParameter('ids', $coachIds)
            ->getQuery()
            ->getResult();
        $coachesById = [];
        foreach ($coaches as $coach) {
            $coachesById[$coach->getId()] = $coach;
        }

        // Agrégation : coachId → ['day' => ..., 'night' => ..., 'astreinte' => ...]
        $byCoach = [];
        foreach ($rows as $row) {
            $coachId = (int) $row['coachId'];
            $slot = $row['slot'];
            $slotKey = $slot instanceof \App\Entity\Enum\TimeSlot ? $slot->value : (string) $slot;
            if (!in_array($slotKey, ['day', 'night', 'astreinte'], true)) {
                continue;
            }
            if (!isset($byCoach[$coachId])) {
                $byCoach[$coachId] = [
                    'day'       => ['count' => 0, 'revenue' => '0.00'],
                    'night'     => ['count' => 0, 'revenue' => '0.00'],
                    'astreinte' => ['count' => 0, 'revenue' => '0.00'],
                ];
            }
            $byCoach[$coachId][$slotKey] = [
                'count'   => (int) $row['cnt'],
                'revenue' => number_format((float) $row['revenue'], 2, '.', ''),
            ];
        }

        // Compose le résultat final, trié par CA total décroissant
        $out = [];
        foreach ($byCoach as $coachId => $slots) {
            $coach = $coachesById[$coachId] ?? null;
            if ($coach === null) continue;
            $total = $slots['day']['count'] + $slots['night']['count'] + $slots['astreinte']['count'];
            $totalRevenue = (float) $slots['day']['revenue']
                          + (float) $slots['night']['revenue']
                          + (float) $slots['astreinte']['revenue'];
            $out[] = [
                'coach'        => $coach,
                'total'        => $total,
                'totalRevenue' => number_format($totalRevenue, 2, '.', ''),
                'day'          => $slots['day'],
                'night'        => $slots['night'],
                'astreinte'    => $slots['astreinte'],
            ];
        }
        usort($out, static fn ($a, $b) => (float) $b['totalRevenue'] <=> (float) $a['totalRevenue']);
        return $out;
    }
}
