<?php

namespace App\Service;

use App\Entity\Enum\TimeSlot;
use App\Entity\FoundingOffer;
use App\Repository\BookingRepository;
use App\Repository\FoundingClaimRepository;
use App\Repository\SubscriptionRepository;

class AdminExportService
{
    public function __construct(
        private readonly BookingRepository $bookingRepo,
        private readonly FoundingClaimRepository $claimRepo,
        private readonly SubscriptionRepository $subRepo,
    ) {}

    public function exportRevenue(\DateTimeImmutable $since, \DateTimeImmutable $until): string
    {
        $bookings = $this->bookingRepo->findConfirmedInWindow($since, $until);

        $rows = [['Date', 'Référence', 'Client', 'Coach', 'Format', 'Créneau', 'Personnes', 'Prix total', 'Part structure', 'Part coach', 'Statut']];

        foreach ($bookings as $b) {
            $price     = (float) $b->getPrice();
            $rate      = $b->getTimeSlot()->structureMarginRate();
            $structure = number_format($price * $rate, 2, ',', '');
            $coach     = number_format($price * (1 - $rate), 2, ',', '');
            $rows[] = [
                $b->getStartAt()->format('d/m/Y'),
                $b->getReference(),
                $b->getClient()->getNomComplet(),
                $b->getCoach()->getNomComplet(),
                $b->getFormat()->label(),
                $b->getTimeSlot()->label(),
                (string) $b->getPersonsCount(),
                number_format($price, 2, ',', ''),
                $structure,
                $coach,
                $b->getStatutLabel(),
            ];
        }

        return $this->toCsv($rows);
    }

    public function exportFoundingMembers(FoundingOffer $offer): string
    {
        $claims = $this->claimRepo->findAllWithUser();

        $rows = [['N°', 'Nom complet', 'Email', 'Inscrit le', 'Séances utilisées', 'Séances restantes', 'Bilan effectué', 'Date bilan']];

        foreach ($claims as $claim) {
            $rows[] = [
                (string) $claim->getClaimNumber(),
                $claim->getUser()->getNomComplet(),
                $claim->getUser()->getEmail(),
                $claim->getClaimedAt()->format('d/m/Y'),
                (string) $claim->getSessionsUsed(),
                (string) $claim->getSessionsRemaining(),
                $claim->isBilanDone() ? 'Oui' : 'Non',
                $claim->getBilanDoneAt()?->format('d/m/Y') ?? '',
            ];
        }

        return $this->toCsv($rows);
    }

    public function exportSubscriptions(): string
    {
        $subs = $this->subRepo->findAllActive();

        $rows = [['Référence', 'Client', 'Coach', 'Format', 'Créneau', 'Pack', 'Sessions restantes', 'Prix mensuel', 'Démarre le', 'Expire le', 'Statut']];

        foreach ($subs as $s) {
            $rows[] = [
                $s->getReference() ?? '',
                $s->getClient()->getNomComplet(),
                $s->getCoach()?->getNomComplet() ?? '',
                $s->getFormat()->label(),
                $s->getTimeSlot()->label(),
                $s->getPackType()->label(),
                (string) $s->getSessionsRemaining(),
                number_format((float) $s->getMonthlyPrice(), 2, ',', '') . ' €',
                $s->getStartsAt()->format('d/m/Y'),
                $s->getEndsAt()->format('d/m/Y'),
                $s->getStatus(),
            ];
        }

        return $this->toCsv($rows);
    }

    public function exportBookings(\DateTimeImmutable $since, \DateTimeImmutable $until): string
    {
        $bookings = $this->bookingRepo->findConfirmedInWindow($since, $until);

        $rows = [['Date', 'Référence', 'Client', 'Coach', 'Format', 'Créneau', 'Personnes', 'Prix total', 'Statut']];

        foreach ($bookings as $b) {
            $rows[] = [
                $b->getStartAt()->format('d/m/Y'),
                $b->getReference(),
                $b->getClient()->getNomComplet(),
                $b->getCoach()->getNomComplet(),
                $b->getFormat()->label(),
                $b->getTimeSlot()->label(),
                (string) $b->getPersonsCount(),
                number_format((float) $b->getPrice(), 2, ',', '') . ' €',
                $b->getStatutLabel(),
            ];
        }

        return $this->toCsv($rows);
    }

    /** Génère une string CSV (UTF-8 BOM, séparateur ;) prête pour Excel français. */
    private function toCsv(array $rows): string
    {
        $bom = "\xEF\xBB\xBF";
        $lines = [];

        foreach ($rows as $row) {
            $cells = array_map(function (string $cell): string {
                // Échappement CSV : guillemets doubles si le champ contient ; " ou saut de ligne
                if (str_contains($cell, ';') || str_contains($cell, '"') || str_contains($cell, "\n")) {
                    return '"' . str_replace('"', '""', $cell) . '"';
                }
                return $cell;
            }, $row);
            $lines[] = implode(';', $cells);
        }

        return $bom . implode("\r\n", $lines) . "\r\n";
    }
}
