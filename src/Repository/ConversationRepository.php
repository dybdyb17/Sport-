<?php

namespace App\Repository;

use App\Entity\Booking;
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
     * Conversations où $user est client OU coach, triées par dernier message desc.
     *
     * Uses HIDDEN aggregate so MySQL's functional-dependency detection handles
     * the GROUP BY on the primary key without requiring full-select enumeration.
     *
     * @return Conversation[]
     */
    public function findForUser(User $user): array
    {
        return $this->createQueryBuilder('c')
            ->select('c', 'MAX(m.createdAt) AS HIDDEN lastMsg')
            ->join('c.booking', 'b')
            ->join('b.coach', 'co')
            ->leftJoin('c.messages', 'm')
            ->andWhere('b.client = :user OR co.user = :user')
            ->setParameter('user', $user)
            ->groupBy('c.id')
            ->orderBy('lastMsg', 'DESC')
            ->getQuery()
            ->getResult();
    }
}
