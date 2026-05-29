<?php
namespace App\Repository;

use App\Entity\FoundingOffer;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<FoundingOffer> */
class FoundingOfferRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry) { parent::__construct($registry, FoundingOffer::class); }

    public function findCurrent(): ?FoundingOffer
    {
        $now = new \DateTimeImmutable();
        return $this->createQueryBuilder('f')
            ->andWhere('f.isActive = true')
            ->andWhere('f.endsAt IS NULL OR f.endsAt > :now')
            ->setParameter('now', $now)
            ->orderBy('f.createdAt', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }
}
