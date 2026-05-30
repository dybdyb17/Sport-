<?php

namespace App\Command;

use App\Entity\Booking;
use App\Entity\Coach;
use App\Entity\Enum\BookingFormat;
use App\Entity\Enum\TimeSlot;
use App\Entity\FoundingClaim;
use App\Entity\FoundingOffer;
use App\Entity\User;
use App\Service\MailerService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:test-emails',
    description: 'Envoie un email de test pour chaque template SPORT+ (utilise le DSN configuré)'
)]
class TestEmailsCommand extends Command
{
    public function __construct(private readonly MailerService $mailer)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('recipient', InputArgument::REQUIRED, 'Email destinataire')
            ->addOption('type', 't', InputOption::VALUE_OPTIONAL,
                'Type d\'email à tester : booking_pending|booking_confirmed|booking_refused|founding|registration|admin_alert|all',
                'all'
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io        = new SymfonyStyle($input, $output);
        $recipient = (string) $input->getArgument('recipient');
        $type      = (string) $input->getOption('type');

        $io->title('SPORT+ — Test des emails');
        $io->text("Destinataire : <info>{$recipient}</info>");

        [$booking, $client, $coach, $user, $claim, $offer] = $this->buildFixtures($recipient);

        $types = $type === 'all'
            ? ['booking_pending', 'booking_confirmed', 'booking_refused', 'founding', 'registration', 'admin_alert']
            : [$type];

        foreach ($types as $t) {
            match ($t) {
                'booking_pending'   => $this->mailer->sendBookingPendingToCoach($booking),
                'booking_confirmed' => $this->mailer->sendBookingConfirmedToClient($booking),
                'booking_refused'   => $this->mailer->sendBookingRefusedToClient($booking, 'Créneau déjà réservé par un autre client.'),
                'founding'          => $this->mailer->sendFoundingWelcomeToUser($claim),
                'registration'      => $this->mailer->sendRegistrationWelcome($user),
                'admin_alert'       => $this->mailer->sendNewFoundingAlertToAdmin($claim),
                default             => $io->warning("Type inconnu : {$t}"),
            };
            $io->writeln("<fg=green>✓</> Email <info>{$t}</info> envoyé à <info>{$recipient}</info>");
            usleep(1200000); // 1.2s — respecte la limite Mailtrap free (1 email/sec)
        }

        $io->success(count($types) . ' email(s) envoyé(s). Vérifiez le profiler Symfony → onglet "E-mails".');

        return Command::SUCCESS;
    }

    /** Construit des objets en mémoire (non persistés) pour les tests. */
    private function buildFixtures(string $recipient): array
    {
        // User client (destinataire des tests)
        $client = new User();
        $client->setNomComplet('Jean Dupont');
        $client->setEmail($recipient);

        // User coach
        $coachUser = new User();
        $coachUser->setNomComplet('Marie Martin');
        $coachUser->setEmail($recipient);

        $coach = new Coach();
        $coach->setUser($coachUser);
        $coach->setHourlyRate('40.00');

        // Booking
        $booking = new Booking();
        $booking->setClient($client);
        $booking->setCoach($coach);
        $booking->setFormat(BookingFormat::SOLO);
        $booking->setTimeSlot(TimeSlot::DAY);
        $booking->setPersonsCount(1);
        $booking->setStartAt(new \DateTimeImmutable('+2 days 10:00'));
        $booking->setPrice('40.00');

        // Founding
        $offer = new FoundingOffer();
        $offer->setCode('FOUNDING-2026');
        $offer->setTitle('Offre Lancement SPORT+');
        $offer->setDescription('Offre test');
        $offer->setTotalPlaces(50);
        $offer->setPlacesTaken(12);
        $offer->setPrice('99.00');
        $offer->setRegularPrice('175.00');
        $offer->setSessionsIncluded(3);
        $offer->setBadgeName('Membre Fondateur');
        $offer->setIsActive(true);

        $claim = new FoundingClaim();
        $claim->setOffer($offer);
        $claim->setUser($client);
        $claim->setClaimNumber(13);

        return [$booking, $client, $coach, $client, $claim, $offer];
    }
}
