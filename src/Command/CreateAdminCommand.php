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
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

#[AsCommand(name: 'app:create-admin', description: 'Crée un compte administrateur SPORT+')]
class CreateAdminCommand extends Command
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly UserPasswordHasherInterface $hasher,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('email', InputArgument::REQUIRED, 'Email admin')
            ->addArgument('password', InputArgument::REQUIRED, 'Mot de passe')
            ->addArgument('name', InputArgument::REQUIRED, 'Nom complet')
            ->addArgument('phone', InputArgument::OPTIONAL, 'Téléphone');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $email = (string) $input->getArgument('email');

        if ($this->em->getRepository(User::class)->findOneBy(['email' => $email])) {
            $io->error(sprintf('Un utilisateur existe déjà avec l\'email %s', $email));

            return Command::FAILURE;
        }

        $user = new User();
        $user
            ->setEmail($email)
            ->setNomComplet((string) $input->getArgument('name'))
            ->setPhone($input->getArgument('phone') ? (string) $input->getArgument('phone') : null)
            ->setRole(UserRole::ADMIN);
        $user->setPassword($this->hasher->hashPassword($user, (string) $input->getArgument('password')));

        $this->em->persist($user);
        $this->em->flush();

        $io->success(sprintf('Admin créé : %s (ID: %d)', $user->getEmail(), $user->getId()));

        return Command::SUCCESS;
    }
}
