<?php

namespace App\Repository;

use App\Entity\Message;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Message>
 */
class MessageRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Message::class);
    }

    /**
     * Nombre de messages non lus dans les conversations de $user,
     * dont $user n'est PAS l'auteur.
     */
    public function countUnreadForUser(User $user): int
    {
        return (int) $this->createQueryBuilder('m')
            ->select('COUNT(m.id)')
            ->join('m.conversation', 'c')
            ->join('c.booking', 'b')
            ->join('b.coach', 'co')
            ->andWhere('m.readAt IS NULL')
            ->andWhere('m.author != :user')
            ->andWhere('b.client = :user OR co.user = :user')
            ->setParameter('user', $user)
            ->getQuery()
            ->getSingleScalarResult();
    }
}
