<?php

namespace App\Repository;

use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Security\Core\Exception\UnsupportedUserException;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\PasswordUpgraderInterface;

/**
 * @extends ServiceEntityRepository<User>
 */
class UserRepository extends ServiceEntityRepository implements PasswordUpgraderInterface
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, User::class);
    }

    /**
     * Utilisé par Symfony pour re-hasher automatiquement les mots de passe si besoin.
     */
    public function upgradePassword(PasswordAuthenticatedUserInterface $user, string $newHashedPassword): void
    {
        if (!$user instanceof User) {
            throw new UnsupportedUserException(sprintf('Instances of "%s" are not supported.', $user::class));
        }

        $user->setPassword($newHashedPassword);
        $this->getEntityManager()->persist($user);
        $this->getEntityManager()->flush();
    }
    /**
     * @return array{client:int,coach:int,admin:int,total:int}
     */
    public function countByRole(): array
    {
        $counts = ['client' => 0, 'coach' => 0, 'admin' => 0, 'total' => 0];
        $rows = $this->createQueryBuilder('u')
            ->select('u.role AS role', 'COUNT(u.id) AS cnt')
            ->groupBy('u.role')
            ->getQuery()
            ->getResult();

        foreach ($rows as $row) {
            $role = is_object($row['role']) && property_exists($row['role'], 'value') ? $row['role']->value : (string) $row['role'];
            $counts[$role] = (int) $row['cnt'];
            $counts['total'] += (int) $row['cnt'];
        }

        return $counts;
    }

}
