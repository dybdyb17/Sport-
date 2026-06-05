<?php

namespace App\Command;

use App\Entity\Coach;
use App\Entity\Enum\UserRole;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:promote-coach',
    description: 'Promeut un client existant en coach : change le rôle + crée l\'entité Coach.'
)]
class PromoteCoachCommand extends Command
{
    public function __construct(private readonly EntityManagerInterface $em)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('email', InputArgument::REQUIRED, 'Email du compte à promouvoir')
            ->addArgument('rate', InputArgument::OPTIONAL, 'Taux horaire en € (défaut: 40)', '40');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io    = new SymfonyStyle($input, $output);
        $email = (string) $input->getArgument('email');
        $rate  = (string) $input->getArgument('rate');

        /** @var User|null $user */
        $user = $this->em->getRepository(User::class)->findOneBy(['email' => $email]);

        if (!$user) {
            $io->error(sprintf('Aucun compte trouvé avec l\'email "%s".', $email));
            return Command::FAILURE;
        }

        $rolePrecedent = $user->getRole()->value;

        // 1. Bump le rôle principal à COACH (sauf si déjà ADMIN, qu'on garde)
        if ($user->getRole() !== UserRole::ADMIN) {
            $user->setRole(UserRole::COACH);
        }

        // 2. Crée le profil Coach s'il n'existe pas déjà
        if ($user->getCoach() === null) {
            $coach = new Coach();
            $coach
                ->setUser($user)
                ->setHourlyRate(number_format((float) $rate, 2, '.', ''))
                ->setSpecialties(['musculation', 'cardio', 'nutrition']);
            $this->em->persist($coach);
            $coachCreated = true;
        } else {
            $coachCreated = false;
        }

        $this->em->flush();

        $io->success(sprintf(
            'Compte "%s" promu COACH avec succès (rôle précédent : %s).',
            $email,
            $rolePrecedent
        ));

        if ($coachCreated) {
            $io->note(sprintf('Profil coach créé (taux horaire : %s €). À éditer ensuite via /admin/coachs.', $rate));
        } else {
            $io->note('Profil coach déjà existant, seul le rôle a été ajusté.');
        }

        $io->note('Le coach peut maintenant se connecter et accéder à /coach/dashboard.');

        return Command::SUCCESS;
    }
}
