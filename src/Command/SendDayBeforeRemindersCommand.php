<?php

namespace App\Command;

use App\Entity\Booking;
use App\Repository\BookingRepository;
use App\Service\MailerService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Envoie un email J-1 à chaque client ayant une séance confirmée le lendemain.
 *
 * À lancer par cron une fois par jour, idéalement le matin (~10h).
 * Sur Railway : un cron job qui exécute `php bin/console app:send-day-before-reminders`.
 */
#[AsCommand(
    name: 'app:send-day-before-reminders',
    description: 'Envoie le rappel J-1 aux clients dont la séance est confirmée pour demain.'
)]
class SendDayBeforeRemindersCommand extends Command
{
    public function __construct(
        private readonly BookingRepository $bookings,
        private readonly MailerService $mailer,
        #[Autowire('%kernel.secret%')]
        private readonly string $appSecret,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption(
            'dry-run',
            null,
            InputOption::VALUE_NONE,
            'Liste les bookings concernés sans envoyer les emails.'
        );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io     = new SymfonyStyle($input, $output);
        $dryRun = (bool) $input->getOption('dry-run');

        // Demain entre 00:00 et 23:59:59 (timezone serveur)
        $tomorrow      = new \DateTimeImmutable('tomorrow');
        $tomorrowStart = $tomorrow->setTime(0, 0, 0);
        $tomorrowEnd   = $tomorrow->setTime(23, 59, 59);

        $qb = $this->bookings->createQueryBuilder('b')
            ->andWhere('b.status = :confirmed')
            ->andWhere('b.startAt >= :start')
            ->andWhere('b.startAt <= :end')
            ->andWhere('b.clientConfirmedAt IS NULL')
            ->setParameter('confirmed', Booking::STATUS_CONFIRMED)
            ->setParameter('start', $tomorrowStart)
            ->setParameter('end', $tomorrowEnd);

        /** @var Booking[] $list */
        $list = $qb->getQuery()->getResult();

        if (empty($list)) {
            $io->success('Aucune séance demain — rien à envoyer.');
            return Command::SUCCESS;
        }

        $io->title(sprintf('Rappels J-1 — %d séance(s) demain', count($list)));
        $sent = 0;
        $skipped = 0;
        foreach ($list as $b) {
            $clientLabel = $b->getClient()?->getNomComplet() ?? '?';
            $coachLabel  = $b->getCoach()?->getNomComplet() ?? '?';
            $when        = $b->getStartAt()->format('H\hi');

            if ($dryRun) {
                $io->writeln(sprintf(' • <comment>%s</comment> → %s avec %s à %s', $b->getReference(), $clientLabel, $coachLabel, $when));
                continue;
            }

            if (!$b->getClient()?->getEmail()) {
                $io->writeln(sprintf(' <fg=yellow>SKIP %s : pas d\'email client</>', $b->getReference()));
                $skipped++;
                continue;
            }
            $this->mailer->sendDayBeforeReminder($b, $this->appSecret);
            $io->writeln(sprintf(' <fg=green>✓ %s</> → %s', $b->getReference(), $clientLabel));
            $sent++;
        }

        if ($dryRun) {
            $io->note(sprintf('Dry-run : %d email(s) auraient été envoyés.', count($list)));
        } else {
            $io->success(sprintf('Envoi terminé : %d envoyé(s), %d ignoré(s).', $sent, $skipped));
        }
        return Command::SUCCESS;
    }
}
