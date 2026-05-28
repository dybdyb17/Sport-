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
}
