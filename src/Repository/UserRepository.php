<?php

namespace App\Repository;

use App\Entity\Enum\UserRole;
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

    public function upgradePassword(PasswordAuthenticatedUserInterface $user, string $newHashedPassword): void
    {
        if (!$user instanceof User) {
            throw new UnsupportedUserException(sprintf('Instances of "%s" are not supported.', $user::class));
        }

        $user->setPassword($newHashedPassword);
        $this->getEntityManager()->persist($user);
        $this->getEntityManager()->flush();
    }

    // ── Méthodes admin (lot 4) ────────────────────────────────────────────────

    /**
     * Nombre d'utilisateurs par rôle (les 3 clés CLIENT/COACH/ADMIN sont toujours présentes).
     *
     * @return array{CLIENT:int,COACH:int,ADMIN:int}
     */
    public function countByRole(): array
    {
        $counts = ['CLIENT' => 0, 'COACH' => 0, 'ADMIN' => 0];

        $rows = $this->createQueryBuilder('u')
            ->select('u.role', 'COUNT(u.id) AS cnt')
            ->groupBy('u.role')
            ->getQuery()
            ->getResult();

        foreach ($rows as $row) {
            // Doctrine ORM 3 hydrate les enums en instance PHP
            $role = $row['role'];
            $key  = $role instanceof UserRole
                ? strtoupper($role->value)
                : strtoupper((string) $role);

            if (array_key_exists($key, $counts)) {
                $counts[$key] = (int) $row['cnt'];
            }
        }

        return $counts;
    }

    /**
     * Utilisateurs les plus récents.
     *
     * @return User[]
     */
    public function findAllRecent(int $limit = 50): array
    {
        return $this->createQueryBuilder('u')
            ->orderBy('u.createdAt', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }
}
