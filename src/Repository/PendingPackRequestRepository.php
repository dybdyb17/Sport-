<?php

namespace App\Repository;

use App\Entity\Coach;
use App\Entity\Enum\PackRequestStatus;
use App\Entity\PendingPackRequest;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<PendingPackRequest>
 */
class PendingPackRequestRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, PendingPackRequest::class);
    }

    /**
     * Demandes SUR PLACE en attente d'un coach donné.
     * Utilisé par le dashboard coach pour lister ce qu'il doit valider.
     *
     * @return PendingPackRequest[]
     */
    public function findPendingOnSiteForCoach(Coach $coach): array
    {
        return $this->createQueryBuilder('p')
            ->andWhere('p.coach = :coach')
            ->andWhere('p.status = :pending')
            ->andWhere('p.paymentMethod IN (:onsite)')
            ->setParameter('coach', $coach)
            ->setParameter('pending', PackRequestStatus::PENDING)
            ->setParameter('onsite', ['cash', 'card'])
            ->orderBy('p.createdAt', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Demandes SUR PLACE en attente d'un client donné (pour affichage grisé
     * dans /mon-espace/mes-packs).
     *
     * @return PendingPackRequest[]
     */
    public function findPendingOnSiteForClient(User $client): array
    {
        return $this->createQueryBuilder('p')
            ->andWhere('p.client = :client')
            ->andWhere('p.status = :pending')
            ->andWhere('p.paymentMethod IN (:onsite)')
            ->setParameter('client', $client)
            ->setParameter('pending', PackRequestStatus::PENDING)
            ->setParameter('onsite', ['cash', 'card'])
            ->orderBy('p.createdAt', 'DESC')
            ->getQuery()
            ->getResult();
    }
}
