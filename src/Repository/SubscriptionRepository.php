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
}
