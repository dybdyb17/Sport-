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
}
