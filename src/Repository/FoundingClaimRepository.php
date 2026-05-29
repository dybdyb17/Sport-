<?php

namespace App\Repository;

use App\Entity\FoundingClaim;
use App\Entity\FoundingOffer;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<FoundingClaim> */
class FoundingClaimRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, FoundingClaim::class);
    }

    /** @return FoundingClaim[] */
    public function findAllWithUser(): array
    {
        return $this->createQueryBuilder('c')
            ->leftJoin('c.user', 'u')->addSelect('u')
            ->leftJoin('c.offer', 'o')->addSelect('o')
            ->orderBy('c.claimNumber', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /** @return array{total:int,bilanDone:int,sessionsConsumed:int,sessionsTotal:int} */
    public function getStats(?FoundingOffer $offer): array
    {
        if (!$offer) {
            return ['total' => 0, 'bilanDone' => 0, 'sessionsConsumed' => 0, 'sessionsTotal' => 0];
        }

        $row = $this->createQueryBuilder('c')
            ->select('COUNT(c.id) AS total', 'COALESCE(SUM(CASE WHEN c.bilanDone = true THEN 1 ELSE 0 END), 0) AS bilanDone', 'COALESCE(SUM(c.sessionsUsed), 0) AS sessionsConsumed')
            ->andWhere('c.offer = :offer')
            ->setParameter('offer', $offer)
            ->getQuery()
            ->getSingleResult();

        $total = (int) $row['total'];

        return [
            'total' => $total,
            'bilanDone' => (int) $row['bilanDone'],
            'sessionsConsumed' => (int) $row['sessionsConsumed'],
            'sessionsTotal' => $total * 3,
        ];
    }
}
