<?php

namespace App\Command;

use App\Entity\Booking;
use App\Repository\BookingRepository;
use App\Service\MailerService;
use Doctrine\ORM\EntityManagerInterface;
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
        private readonly EntityManagerInterface $em,
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

        // Cible : toutes les séances confirmées dans les 36h à venir, pour lesquelles
        // le rappel n'a pas encore été envoyé (par ce cron OU par BookingManager::confirm()
        // si la séance était trop proche pour attendre le prochain cron).
        // Fenêtre 36h = on couvre une matinée du surlendemain au cas où la session
        // précédente du cron a sauté. Idempotent grâce au flag reminderSentAt.
        $now    = new \DateTimeImmutable();
        $end    = $now->modify('+36 hours');

        $qb = $this->bookings->createQueryBuilder('b')
            ->andWhere('b.status = :confirmed')
            ->andWhere('b.startAt > :now')
            ->andWhere('b.startAt <= :end')
            ->andWhere('b.clientConfirmedAt IS NULL')
            ->andWhere('b.reminderSentAt IS NULL')
            ->setParameter('confirmed', Booking::STATUS_CONFIRMED)
            ->setParameter('now', $now)
            ->setParameter('end', $end)
            ->orderBy('b.startAt', 'ASC');

        /** @var Booking[] $list */
        $list = $qb->getQuery()->getResult();

        $io->title(sprintf('Rappels J-1 — %d séance(s) éligible(s) dans les 36h', count($list)));
        $sent = 0;
        $skipped = 0;
        foreach ($list as $b) {
            $clientLabel = $b->getClient()?->getNomComplet() ?? '?';
            $coachLabel  = $b->getCoach()?->getNomComplet() ?? '?';
            $when        = $b->getStartAt()->format('d/m H\hi');

            if ($dryRun) {
                $io->writeln(sprintf(' • <comment>%s</comment> → %s avec %s le %s', $b->getReference(), $clientLabel, $coachLabel, $when));
                continue;
            }

            if (!$b->getClient()?->getEmail()) {
                $io->writeln(sprintf(' <fg=yellow>SKIP %s : pas d\'email client</>', $b->getReference()));
                $skipped++;
                continue;
            }
            $ok = $this->mailer->sendDayBeforeReminder($b, $this->appSecret);
            if ($ok) {
                // Mail vraiment parti → on flag, le prochain cron ne le reprendra pas
                $b->setReminderSentAt(new \DateTimeImmutable());
                $this->em->flush();
                $io->writeln(sprintf(' <fg=green>✓ %s</> → %s (séance le %s)', $b->getReference(), $clientLabel, $when));
                $sent++;
            } else {
                // Échec d'envoi (ex: Resend down) → on ne flag PAS, retry au prochain cron
                $io->writeln(sprintf(' <fg=red>✗ %s</> → échec envoi, sera retenté au prochain cron', $b->getReference()));
                $skipped++;
            }
        }

        if ($dryRun) {
            $io->note(sprintf('Dry-run : %d email(s) auraient été envoyés.', count($list)));
            return Command::SUCCESS;
        }

        // Récap quotidien à l'admin — TOUJOURS envoyé (même si 0 mail) pour servir
        // de "dead man's switch" : si Loïc ne reçoit pas son ping un matin, c'est
        // que le cron a planté en silence. À NE PAS supprimer.
        $this->mailer->sendDayBeforeReminderSummary($sent, $skipped, $list);

        $io->success(sprintf('Envoi terminé : %d envoyé(s), %d ignoré(s). Récap admin envoyé à %s.',
            $sent,
            $skipped,
            $_ENV['APP_EMAIL_ADMIN'] ?? 'ls.sportplus13@gmail.com'
        ));
        return Command::SUCCESS;
    }
}
