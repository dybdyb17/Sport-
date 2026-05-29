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

    /** @return FoundingClaim[] */
    public function findAllWithUser(): array
    {
        return $this->createQueryBuilder('fc')
            ->join('fc.user', 'u')
            ->addSelect('u')
            ->orderBy('fc.claimNumber', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /** @return array{total:int,bilanDone:int,sessionsConsumed:int,sessionsTotal:int} */
    public function getStats(FoundingOffer $offer): array
    {
        $total = (int) $this->createQueryBuilder('fc')
            ->select('COUNT(fc.id)')
            ->where('fc.offer = :offer')
            ->setParameter('offer', $offer)
            ->getQuery()
            ->getSingleScalarResult();

        $bilanDone = (int) $this->createQueryBuilder('fc')
            ->select('COUNT(fc.id)')
            ->where('fc.offer = :offer')
            ->andWhere('fc.bilanDoneAt IS NOT NULL')
            ->setParameter('offer', $offer)
            ->getQuery()
            ->getSingleScalarResult();

        $sessionsConsumed = (int) ($this->createQueryBuilder('fc')
            ->select('COALESCE(SUM(fc.sessionsUsed), 0)')
            ->where('fc.offer = :offer')
            ->setParameter('offer', $offer)
            ->getQuery()
            ->getSingleScalarResult() ?? 0);

        return [
            'total'            => $total,
            'bilanDone'        => $bilanDone,
            'sessionsConsumed' => $sessionsConsumed,
            'sessionsTotal'    => $total * $offer->getSessionsIncluded(),
        ];
    }
}
