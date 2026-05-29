<?php
namespace App\Command;

use App\Entity\FoundingOffer;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'app:seed-founding-offer', description: 'Crée l\'offre Founding Member LAUNCH-2026')]
class SeedFoundingOfferCommand extends Command
{
    public function __construct(private readonly EntityManagerInterface $em)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $existing = $this->em->getRepository(FoundingOffer::class)->findOneBy(['code' => 'LAUNCH-2026']);
        if ($existing) {
            $io->info(sprintf('Offre LAUNCH-2026 déjà existante — %d/%d places prises.', $existing->getPlacesTaken(), $existing->getTotalPlaces()));
            return Command::SUCCESS;
        }

        $offer = new FoundingOffer();
        $offer
            ->setCode('LAUNCH-2026')
            ->setTitle('Offre Founding Member')
            ->setDescription("3 séances de coaching premium (Night Coach ou Small Group) + bilan forme offert dès la 1ère séance. Réservée aux 50 premiers inscrits, non renouvelable. Badge Membre Fondateur conservé à vie.")
            ->setTotalPlaces(50)
            ->setSessionsIncluded(3)
            ->setPrice('99.00')
            ->setRegularPrice('175.00')
            ->setIncludesFreeBilan(true)
            ->setIncludesPriorityNight(true)
            ->setBadgeName('Membre Fondateur')
            ->setIsActive(true);

        $this->em->persist($offer);
        $this->em->flush();

        $io->success('Offre LAUNCH-2026 créée — 50 places disponibles.');
        return Command::SUCCESS;
    }
}
