<?php

namespace App\Command;

use App\Entity\Enum\UserRole;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'app:promote-admin', description: 'Promeut un coach ou client existant en admin tout en conservant son profil coach')]
class PromoteAdminCommand extends Command
{
    public function __construct(private readonly EntityManagerInterface $em)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('email', InputArgument::REQUIRED, 'Email du compte à promouvoir');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io    = new SymfonyStyle($input, $output);
        $email = (string) $input->getArgument('email');

        /** @var User|null $user */
        $user = $this->em->getRepository(User::class)->findOneBy(['email' => $email]);

        if (!$user) {
            $io->error(sprintf('Aucun compte trouvé avec l\'email "%s".', $email));
            return Command::FAILURE;
        }

        $rolePrecedent = $user->getRole()->value;
        $hasCoach      = $user->getCoach() !== null;

        // Passe le rôle principal à ADMIN
        $user->setRole(UserRole::ADMIN);

        // Si le compte était coach, on garde ROLE_COACH dans le tableau des rôles
        // supplémentaires pour que getCoach() + accès /coach continuent de fonctionner.
        // (ROLE_ADMIN hérite déjà de ROLE_COACH via la hiérarchie security.yaml,
        //  donc c'est une sécurité supplémentaire, pas strictement nécessaire.)
        if ($hasCoach) {
            $extraRoles   = $user->getRoles();
            $extraRoles[] = 'ROLE_COACH';
            $user->setRoles(array_values(array_unique(array_filter(
                $extraRoles,
                static fn (string $r): bool => !in_array($r, ['ROLE_USER', 'ROLE_ADMIN'], true)
            ))));
        }

        $this->em->flush();

        $io->success(sprintf(
            'Compte "%s" promu ADMIN avec succès (rôle précédent : %s%s).',
            $email,
            $rolePrecedent,
            $hasCoach ? ' + profil coach conservé' : ''
        ));

        if ($hasCoach) {
            $io->note('Ce compte accède maintenant à /admin ET à /coach/dashboard avec son propre profil coach.');
        }

        return Command::SUCCESS;
    }
}
