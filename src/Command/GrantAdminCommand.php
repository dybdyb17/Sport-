<?php

namespace App\Command;

use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:grant-admin',
    description: 'Donne le rôle ROLE_ADMIN à un utilisateur (en plus de son rôle principal).',
)]
class GrantAdminCommand extends Command
{
    public function __construct(
        private readonly UserRepository $users,
        private readonly EntityManagerInterface $em,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('email', InputArgument::REQUIRED, 'Email de l\'utilisateur')
            ->addArgument('action', InputArgument::OPTIONAL, 'grant (défaut) ou revoke', 'grant');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io     = new SymfonyStyle($input, $output);
        $email  = $input->getArgument('email');
        $action = $input->getArgument('action');

        $user = $this->users->findOneBy(['email' => $email]);
        if (!$user) {
            $io->error(sprintf('Aucun utilisateur trouvé avec l\'email "%s".', $email));
            return Command::FAILURE;
        }

        $roles = $user->getRoles();
        $hasAdmin = in_array('ROLE_ADMIN', $roles, true);

        if ($action === 'revoke') {
            if (!$hasAdmin) {
                $io->warning(sprintf('%s n\'a pas le rôle ROLE_ADMIN.', $email));
                return Command::SUCCESS;
            }
            $extra = array_values(array_filter(
                $user->getRoles(),
                fn(string $r) => $r !== 'ROLE_ADMIN' && $r !== 'ROLE_USER' && !str_starts_with($r, 'ROLE_CLIENT') && !str_starts_with($r, 'ROLE_COACH'),
            ));
            $user->setRoles($extra);
            $this->em->flush();
            $io->success(sprintf('ROLE_ADMIN retiré pour %s. Rôles actuels : %s', $email, implode(', ', $user->getRoles())));
            return Command::SUCCESS;
        }

        if ($hasAdmin) {
            $io->warning(sprintf('%s a déjà ROLE_ADMIN. Rôles : %s', $email, implode(', ', $roles)));
            return Command::SUCCESS;
        }

        $user->setRoles(['ROLE_ADMIN']);
        $this->em->flush();

        $io->success(sprintf(
            'ROLE_ADMIN accordé à %s. Rôles effectifs : %s',
            $email,
            implode(', ', $user->getRoles()),
        ));

        return Command::SUCCESS;
    }
}
