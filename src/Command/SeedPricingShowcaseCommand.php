<?php

namespace App\Command;

use App\Entity\Enum\BookingFormat;
use App\Entity\Enum\PackType;
use App\Entity\Enum\TimeSlot;
use App\Service\PricingCalculator;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:seed-pricing-demo',
    description: 'Affiche la matrice complète des tarifs SPORT+ pour vérification croisée avec la grille du boss.',
)]
class SeedPricingShowcaseCommand extends Command
{
    public function __construct(private readonly PricingCalculator $pricing)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $io->title('SPORT+ — Matrice tarifaire officielle');

        // ─── SÉANCES UNIQUES ──────────────────────────────────────────────
        $io->section('SÉANCES UNIQUES (prix par personne × nombre de participants)');

        $rows = [];
        foreach (BookingFormat::cases() as $format) {
            foreach (TimeSlot::cases() as $slot) {
                $pricePerPerson = $this->pricing->singleSessionPrice($format, $slot);

                $personsMin = $format->personsMin();
                $personsMax = $format->personsMax();
                $totalMin   = number_format((float) $pricePerPerson * $personsMin, 2, ',', ' ');
                $totalMax   = $personsMin !== $personsMax
                    ? number_format((float) $pricePerPerson * $personsMax, 2, ',', ' ')
                    : null;

                $totalCol = $totalMax
                    ? sprintf('%s € à %s €', $totalMin, $totalMax)
                    : sprintf('%s €', $totalMin);

                $rows[] = [
                    $format->label(),
                    $slot->label(),
                    sprintf('%s €/pers', $this->pricing->formatPrice($pricePerPerson)),
                    sprintf('%d - %d pers.', $personsMin, $personsMax),
                    $totalCol,
                ];
            }
        }

        $io->table(
            ['Format', 'Créneau', 'Prix / pers.', 'Participants', 'Total séance'],
            $rows,
        );

        // ─── PACKS MENSUELS ───────────────────────────────────────────────
        $io->section('PACKS MENSUELS (prix mensuel par personne)');

        $packRows = [];
        foreach (BookingFormat::cases() as $format) {
            foreach ([PackType::PACK_4, PackType::PACK_8, PackType::PACK_12] as $pack) {
                foreach (TimeSlot::cases() as $slot) {
                    $base       = $this->pricing->monthlyPackPrice($format, $pack, $slot);
                    $withAccess = $this->pricing->monthlyPackPrice($format, $pack, $slot, true);

                    $packRows[] = [
                        $format->label(),
                        $pack->label(),
                        $slot->label(),
                        $this->pricing->formatPrice($base),
                        $this->pricing->formatPrice($withAccess),
                    ];
                }
            }
        }

        $io->table(
            ['Format', 'Pack', 'Créneau', 'Prix/mois/pers', 'Avec FullAccess (+30/+25€)'],
            $packRows,
        );

        // ─── MARGE STRUCTURE/COACH ────────────────────────────────────────
        $io->section('MARGE STRUCTURE / COACH (exemple sur séance SOLO)');

        $marginRows = [];
        foreach (TimeSlot::cases() as $slot) {
            $price  = $this->pricing->singleSessionPrice(BookingFormat::SOLO, $slot);
            $margin = $this->pricing->structureMargin($price, $slot);

            $marginRows[] = [
                sprintf('SOLO · %s', $slot->label()),
                $this->pricing->formatPrice($price),
                sprintf('%d%%', (int) ($slot->structureMarginRate() * 100)),
                $this->pricing->formatPrice($margin['structure']),
                $this->pricing->formatPrice($margin['coach']),
            ];
        }

        $io->table(
            ['Offre', 'Prix total', 'Rate structure', 'Part structure', 'Part coach'],
            $marginRows,
        );

        $io->success('Matrice affichée. Vérifiez chaque ligne contre la grille du boss avant la démo.');

        return Command::SUCCESS;
    }
}
