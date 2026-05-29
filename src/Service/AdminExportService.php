<?php

namespace App\Service;

use App\Entity\Booking;
use App\Entity\Enum\TimeSlot;
use App\Entity\FoundingOffer;
use App\Repository\BookingRepository;
use App\Repository\FoundingClaimRepository;
use App\Repository\SubscriptionRepository;

class AdminExportService
{
    public function __construct(
        private readonly BookingRepository $bookingRepository,
        private readonly FoundingClaimRepository $claimRepository,
        private readonly SubscriptionRepository $subscriptionRepository,
    ) {}

    public function exportRevenue(\DateTimeImmutable $since, \DateTimeImmutable $until): string
    {
        $rows = [['Date', 'Référence', 'Client', 'Coach', 'Format', 'Créneau', 'Personnes', 'Prix total', 'Part structure', 'Part coach', 'Statut']];
        foreach ($this->bookingRepository->findConfirmedInWindow($since, $until) as $booking) {
            $price = (float) $booking->getPrice();
            $margin = $booking->getTimeSlot()->structureMargin();
            $structure = $price * $margin;
            $rows[] = $this->bookingRow($booking, [
                number_format($structure, 2, ',', ''),
                number_format($price - $structure, 2, ',', ''),
                $booking->getStatutLabel(),
            ]);
        }

        return $this->csv($rows);
    }

    public function exportFoundingMembers(?FoundingOffer $offer): string
    {
        $rows = [['N°', 'Nom complet', 'Email', 'Inscrit le', 'Séances utilisées', 'Séances restantes', 'Bilan effectué', 'Date bilan']];
        foreach ($this->claimRepository->findAllWithUser() as $claim) {
            if ($offer && $claim->getOffer() !== $offer) {
                continue;
            }
            $user = $claim->getUser();
            $rows[] = [
                $claim->getClaimNumber(),
                $user?->getNomComplet(),
                $user?->getEmail(),
                $claim->getCreatedAt()->format('d/m/Y'),
                $claim->getSessionsUsed(),
                $claim->getSessionsRemaining(),
                $claim->isBilanDone() ? 'Oui' : 'Non',
                $claim->getBilanDoneAt()?->format('d/m/Y H:i') ?? '',
            ];
        }

        return $this->csv($rows);
    }

    public function exportSubscriptions(): string
    {
        $rows = [['Référence', 'Client', 'Coach', 'Format', 'Créneau', 'Pack', 'Sessions restantes', 'Prix mensuel', 'Démarre le', 'Expire le', 'Statut']];
        foreach ($this->subscriptionRepository->findAllActive() as $sub) {
            $rows[] = [
                $sub->getReference(),
                $sub->getClient()?->getNomComplet(),
                $sub->getCoach()?->getNomComplet(),
                $sub->getFormat()->label(),
                $sub->getTimeSlot()->label(),
                $sub->getPackType()->label(),
                $sub->getSessionsRemaining(),
                number_format((float) $sub->getMonthlyPrice(), 2, ',', ''),
                $sub->getStartsAt()->format('d/m/Y'),
                $sub->getEndsAt()->format('d/m/Y'),
                $sub->getStatus(),
            ];
        }

        return $this->csv($rows);
    }

    public function exportBookings(\DateTimeImmutable $since, \DateTimeImmutable $until): string
    {
        $rows = [['Date', 'Référence', 'Client', 'Coach', 'Format', 'Créneau', 'Personnes', 'Prix total', 'Statut']];
        foreach ($this->bookingRepository->findConfirmedInWindow($since, $until) as $booking) {
            $rows[] = $this->bookingRow($booking, [$booking->getStatutLabel()]);
        }

        return $this->csv($rows);
    }

    /** @param list<mixed> $tail */
    private function bookingRow(Booking $booking, array $tail): array
    {
        return array_merge([
            $booking->getStartAt()?->format('d/m/Y H:i'),
            $booking->getReference(),
            $booking->getClient()?->getNomComplet(),
            $booking->getCoach()?->getNomComplet(),
            $booking->getFormat()->label(),
            $booking->getTimeSlot()->label(),
            $booking->getPersonsCount(),
            number_format((float) $booking->getPrice(), 2, ',', ''),
        ], $tail);
    }

    /** @param list<list<mixed>> $rows */
    private function csv(array $rows): string
    {
        $handle = fopen('php://temp', 'r+');
        fwrite($handle, "\xEF\xBB\xBF");
        foreach ($rows as $row) {
            fputcsv($handle, $row, ';');
        }
        rewind($handle);

        return stream_get_contents($handle) ?: '';
    }
}
