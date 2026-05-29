<?php
namespace App\Repository;

use App\Entity\FoundingClaim;
use App\Entity\FoundingOffer;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<FoundingClaim> */
class FoundingClaimRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry) { parent::__construct($registry, FoundingClaim::class); }

    public function findForUser(User $user): ?FoundingClaim
    {
        return $this->createQueryBuilder('fc')
            ->andWhere('fc.user = :user')
            ->setParameter('user', $user)
            ->getQuery()
            ->getOneOrNullResult();
    }

    public function countForOffer(FoundingOffer $offer): int
    {
        return (int) $this->createQueryBuilder('fc')
            ->select('COUNT(fc.id)')
            ->andWhere('fc.offer = :offer')
            ->setParameter('offer', $offer)
            ->getQuery()
            ->getSingleScalarResult();
    }

    public function nextClaimNumber(FoundingOffer $offer): int
    {
        $max = $this->createQueryBuilder('fc')
            ->select('MAX(fc.claimNumber)')
            ->andWhere('fc.offer = :offer')
            ->setParameter('offer', $offer)
            ->getQuery()
            ->getSingleScalarResult();
        return ($max ?? 0) + 1;
    }
}
