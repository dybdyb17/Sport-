<?php

namespace App\Command;

use App\Entity\Enum\PackRequestStatus;
use App\Entity\PendingPackRequest;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Purge les PendingPackRequest abandonnées (Stripe ouvert puis fermé sans payer).
 *
 * ⚠️ CRITIQUE — Ne purge QUE les demandes de paiement EN LIGNE (Stripe) qui
 * n'ont jamais été matérialisées. Ne touche PAS :
 *  - les demandes SUR PLACE (paymentMethod = cash/card) : un client peut
 *    légitimement mettre plusieurs jours à venir payer au comptoir ;
 *  - les demandes CONFIRMED / REFUSED (déjà traitées) ;
 *  - les demandes liées à une Subscription (protection ceinture + bretelles).
 *
 * Défaut : --dry-run (liste sans supprimer). --force pour exécuter réellement.
 * --hours=X pour ajuster le seuil (défaut 24h).
 *
 * Usage typique — vérification manuelle mensuelle :
 *   php bin/console app:purge-pending-packs             (dry-run 24h)
 *   php bin/console app:purge-pending-packs --hours=48  (dry-run 48h)
 *   php bin/console app:purge-pending-packs --force     (suppression réelle 24h)
 */
#[AsCommand(
    name: 'app:purge-pending-packs',
    description: 'Supprime les PendingPackRequest Stripe abandonnées (>24h par défaut). Dry-run par défaut, --force pour exécuter.'
)]
class PurgePendingPacksCommand extends Command
{
    private const DEFAULT_HOURS = 24;

    public function __construct(
        private readonly EntityManagerInterface $em,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('force', null, InputOption::VALUE_NONE, 'Exécute réellement la suppression (sans ce flag = dry-run).')
            ->addOption('hours', null, InputOption::VALUE_REQUIRED, 'Âge minimum en heures avant purge (défaut : 24).', (string) self::DEFAULT_HOURS);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io    = new SymfonyStyle($input, $output);
        $force = (bool) $input->getOption('force');
        $hours = max(1, (int) $input->getOption('hours'));

        $cutoff = new \DateTimeImmutable(sprintf('-%d hours', $hours));

        $io->title(sprintf(
            'Purge PendingPackRequest — Stripe abandonnées depuis plus de %d h',
            $hours
        ));
        $io->writeln(sprintf(' • Mode         : <info>%s</info>', $force ? 'FORCE (suppression réelle)' : 'DRY-RUN (aucune suppression)'));
        $io->writeln(sprintf(' • Seuil âge    : <info>créées avant le %s</info>', $cutoff->format('d/m/Y H:i')));
        $io->newLine();

        // Requête stricte : uniquement Stripe + pending + jamais fulfilled +
        // aucun lien vers un Subscription + createdAt < cutoff.
        // La double garde (paymentMethod = 'stripe' ET subscription IS NULL)
        // évite tout risque de purger une demande sur place ou déjà matérialisée.
        /** @var PendingPackRequest[] $candidates */
        $candidates = $this->em->createQueryBuilder()
            ->select('p')
            ->from(PendingPackRequest::class, 'p')
            ->andWhere('p.paymentMethod = :stripe')
            ->andWhere('p.status = :pending')
            ->andWhere('p.fulfilledAt IS NULL')
            ->andWhere('p.subscription IS NULL')
            ->andWhere('p.createdAt < :cutoff')
            ->setParameter('stripe', 'stripe')
            ->setParameter('pending', PackRequestStatus::PENDING)
            ->setParameter('cutoff', $cutoff)
            ->orderBy('p.createdAt', 'ASC')
            ->getQuery()
            ->getResult();

        if (empty($candidates)) {
            $io->success('Aucune demande à purger. Base propre.');
            return Command::SUCCESS;
        }

        $io->section(sprintf('%d demande(s) candidate(s) à la purge', count($candidates)));

        $rows = [];
        foreach ($candidates as $ppr) {
            $rows[] = [
                $ppr->getId(),
                $ppr->getClient()?->getEmail() ?? '—',
                $ppr->getCoach()?->getNomComplet() ?? '—',
                $ppr->getPackType()->label(),
                $ppr->getCreatedAt()->format('d/m/Y H:i'),
                $this->humanAge($ppr->getCreatedAt()),
            ];
        }

        $io->table(
            ['ID', 'Client', 'Coach', 'Pack', 'Créée le', 'Âge'],
            $rows
        );

        if (!$force) {
            $io->warning(sprintf(
                '%d demande(s) SERAIENT supprimée(s). Relance avec --force pour exécuter.',
                count($candidates)
            ));
            return Command::SUCCESS;
        }

        // Exécution réelle
        foreach ($candidates as $ppr) {
            $this->em->remove($ppr);
        }
        $this->em->flush();

        $io->success(sprintf('%d demande(s) supprimée(s) définitivement.', count($candidates)));

        return Command::SUCCESS;
    }

    private function humanAge(\DateTimeImmutable $createdAt): string
    {
        $diff = $createdAt->diff(new \DateTimeImmutable());
        if ($diff->d > 0) {
            return sprintf('%d j %02d h', $diff->d, $diff->h);
        }
        return sprintf('%d h %02d min', $diff->h, $diff->i);
    }
}
