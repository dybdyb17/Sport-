<?php

namespace App\Repository;

use App\Entity\PromoPurchase;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<PromoPurchase> */
class PromoPurchaseRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, PromoPurchase::class);
    }

    /**
     * Retourne les PromoPurchase PAYÉES (status = paid ET paidAt non null)
     * dont buyerEmail correspond à l'email fourni (comparaison insensible à
     * la casse).
     *
     * Utilisé par l'espace client pour rattacher une promo à un compte sans
     * lien User rigide : si le compte connecté a le même email que l'acheteur,
     * la promo apparaît dans son espace. Un achat fait sans compte reste
     * accessible via son ticket par référence — ce rattachement est purement
     * en lecture, additionnel, jamais bloquant.
     *
     * Join sur offer pour éviter le N+1 à l'affichage (titre, prix, etc.).
     *
     * @return PromoPurchase[]
     */
    public function findPaidForEmail(string $email): array
    {
        return $this->createQueryBuilder('p')
            ->leftJoin('p.offer', 'o')->addSelect('o')
            ->where('LOWER(p.buyerEmail) = LOWER(:email)')
            ->andWhere('p.status = :paid')
            ->andWhere('p.paidAt IS NOT NULL')
            ->setParameter('email', $email)
            ->setParameter('paid', PromoPurchase::STATUS_PAID)
            ->orderBy('p.paidAt', 'DESC')
            ->getQuery()
            ->getResult();
    }
}
