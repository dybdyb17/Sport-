<?php

namespace App\Repository;

use App\Entity\Booking;
use App\Entity\Coach;
use App\Entity\Conversation;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Conversation>
 */
class ConversationRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Conversation::class);
    }

    public function findOneByBooking(Booking $booking): ?Conversation
    {
        return $this->createQueryBuilder('c')
            ->andWhere('c.booking = :booking')
            ->setParameter('booking', $booking)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * Cherche LA conversation entre ce client et ce coach. Une seule existe à la fois.
     * Fallback : on regarde aussi via les anciens bookings (rétro-compat) si pas de champ direct.
     */
    public function findForPair(User $client, Coach $coach): ?Conversation
    {
        // 1) Match direct sur les champs client/coach (nouveau modèle)
        $direct = $this->createQueryBuilder('c')
            ->andWhere('c.client = :client')
            ->andWhere('c.coach = :coach')
            ->setParameter('client', $client)
            ->setParameter('coach', $coach)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
        if ($direct !== null) {
            return $direct;
        }

        // 2) Fallback : conversations historiques liées à un booking de ce couple
        return $this->createQueryBuilder('c')
            ->join('c.booking', 'b')
            ->andWhere('b.client = :client')
            ->andWhere('b.coach = :coach')
            ->setParameter('client', $client)
            ->setParameter('coach', $coach)
            ->orderBy('c.createdAt', 'ASC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * Conversations où $user est client OU coach, triées par dernier message desc.
     *
     * Uses HIDDEN aggregate so MySQL's functional-dependency detection handles
     * the GROUP BY on the primary key without requiring full-select enumeration.
     *
     * @return Conversation[]
     */
    public function findForUser(User $user): array
    {
        // Nouvelle approche : match direct via c.client / c.coach.user. Fallback booking.
        return $this->createQueryBuilder('c')
            ->select('c', 'MAX(m.createdAt) AS HIDDEN lastMsg')
            ->leftJoin('c.coach', 'co')
            ->leftJoin('co.user', 'cou')
            ->leftJoin('c.booking', 'b')
            ->leftJoin('b.coach', 'bc')
            ->leftJoin('bc.user', 'bcu')
            ->leftJoin('c.messages', 'm')
            ->andWhere('c.client = :user OR cou = :user OR b.client = :user OR bcu = :user')
            ->setParameter('user', $user)
            ->groupBy('c.id')
            ->orderBy('lastMsg', 'DESC')
            ->getQuery()
            ->getResult();
    }
}
