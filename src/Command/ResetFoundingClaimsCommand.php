<?php

namespace App\Command;

use App\Entity\FoundingClaim;
use App\Entity\User;
use App\Repository\FoundingOfferRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Reset des places "fondateur" prises (utile après une phase de tests).
 *
 * Par défaut : remet placesTaken à 0 SANS toucher aux FoundingClaim existants.
 * Avec --delete-claims : supprime aussi les claims de l'offre courante
 * (déconnecte les users + détruit les records).
 *
 * À lancer via Railway shell sur le service App :
 *   php bin/console app:reset-founding-claims --delete-claims
 */
#[AsCommand(
    name: 'app:reset-founding-claims',
    description: 'Reset le compteur de places fondateur (et optionnellement supprime les claims existants).'
)]
class ResetFoundingClaimsCommand extends Command
{
    public function __construct(
        private readonly FoundingOfferRepository $offerRepo,
        private readonly EntityManagerInterface $em,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('delete-claims', null, InputOption::VALUE_NONE, 'Supprime aussi tous les FoundingClaim de l\'offre courante.')
            ->addOption('reset-to', null, InputOption::VALUE_REQUIRED, 'Force placesTaken à une valeur précise (défaut : 0).', '0');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io        = new SymfonyStyle($input, $output);
        $deleteAll = (bool) $input->getOption('delete-claims');
        $resetTo   = max(0, (int) $input->getOption('reset-to'));

        $offer = $this->offerRepo->findCurrent();
        if (!$offer) {
            $io->error('Aucune offre Fondateur active trouvée.');
            return Command::FAILURE;
        }

        $io->title(sprintf('Offre courante : %s', $offer->getTitle() ?? '(sans titre)'));
        $io->writeln(sprintf(' • Capacité totale  : <info>%d</info>', $offer->getTotalPlaces()));
        $io->writeln(sprintf(' • Places prises    : <info>%d</info>', $offer->getPlacesTaken()));
        $io->writeln(sprintf(' • Places restantes : <info>%d</info>', $offer->getTotalPlaces() - $offer->getPlacesTaken()));

        // 1) Optionnel : suppression des claims existants
        if ($deleteAll) {
            /** @var FoundingClaim[] $claims */
            $claims = $this->em->getRepository(FoundingClaim::class)->findBy(['offer' => $offer]);
            $io->section(sprintf('Suppression de %d claim(s)...', count($claims)));

            foreach ($claims as $claim) {
                $user = $claim->getUser();
                $this->em->remove($claim);
                $io->writeln(sprintf(' <fg=red>✕</> claim #%d (%s)', $claim->getId(), $user?->getEmail() ?? '?'));
            }
            $this->em->flush();
        }

        // 2) Reset du compteur
        $previousTaken = $offer->getPlacesTaken();
        $offer->setPlacesTaken($resetTo);
        $this->em->flush();

        $io->success(sprintf(
            'placesTaken : %d → %d. Places dispo : %d / %d.',
            $previousTaken,
            $resetTo,
            $offer->getTotalPlaces() - $resetTo,
            $offer->getTotalPlaces()
        ));

        if (!$deleteAll && $previousTaken > $resetTo) {
            $io->warning(sprintf(
                'Note : %d claim(s) existent encore en BDD mais le compteur ne les reflète plus. '.
                'Si ces claims doivent disparaître complètement (et libérer les users côté espace client), '.
                'relance avec --delete-claims.',
                $previousTaken
            ));
        }

        return Command::SUCCESS;
    }
}
