<?php

namespace App\Controller\Admin;

use App\Repository\BookingRepository;
use App\Repository\SubscriptionRepository;
use App\Repository\UserRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/admin/exports')]
#[IsGranted('ROLE_ADMIN')]
class AdminExportController extends AbstractController
{
    #[Route('/reservations.csv', name: 'app_admin_export_bookings', methods: ['GET'])]
    public function bookings(BookingRepository $bookingRepository): StreamedResponse
    {
        return $this->csv('sportplus-reservations.csv', [
            'Référence',
            'Date',
            'Client',
            'Coach',
            'Prestation',
            'Statut',
            'Prix',
        ], array_map(static fn ($booking): array => [
            $booking->getReference(),
            $booking->getStartAt()?->format('d/m/Y H:i') ?? '',
            $booking->getClient()?->getNomComplet() ?? '',
            $booking->getCoach()?->getNomComplet() ?? '',
            $booking->getOfferLabel(),
            $booking->getStatutLabel(),
            $booking->getPrice() ?? '',
        ], $bookingRepository->findAllRecent(500)));
    }

    #[Route('/abonnements.csv', name: 'app_admin_export_subscriptions', methods: ['GET'])]
    public function subscriptions(SubscriptionRepository $subscriptionRepository): StreamedResponse
    {
        return $this->csv('sportplus-abonnements.csv', [
            'Référence',
            'Client',
            'Coach préféré',
            'Pack',
            'Créneau',
            'Format',
            'Séances restantes',
            'Prix mensuel',
            'Statut',
            'Début',
            'Fin',
        ], array_map(static fn ($subscription): array => [
            $subscription->getReference(),
            $subscription->getClient()?->getNomComplet() ?? '',
            $subscription->getCoach()?->getNomComplet() ?? 'Tous coachs',
            $subscription->getPackType()->label(),
            $subscription->getTimeSlot()->label(),
            $subscription->getFormat()->label(),
            (string) $subscription->getSessionsRemaining(),
            $subscription->getMonthlyPrice() ?? '',
            $subscription->getStatusLabel(),
            $subscription->getStartsAt()->format('d/m/Y'),
            $subscription->getEndsAt()->format('d/m/Y'),
        ], $subscriptionRepository->findAllRecent(500)));
    }

    #[Route('/utilisateurs.csv', name: 'app_admin_export_users', methods: ['GET'])]
    public function users(UserRepository $userRepository): StreamedResponse
    {
        return $this->csv('sportplus-utilisateurs.csv', [
            'Nom',
            'Email',
            'Téléphone',
            'Rôle',
            'Inscription',
        ], array_map(static fn ($user): array => [
            $user->getNomComplet() ?? '',
            $user->getEmail() ?? '',
            $user->getPhone() ?? '',
            $user->getRole()->getLabel(),
            $user->getCreatedAt()?->format('d/m/Y') ?? '',
        ], $userRepository->findAllRecent(500)));
    }

    /**
     * @param list<string> $headers
     * @param list<list<string|null>> $rows
     */
    private function csv(string $filename, array $headers, array $rows): StreamedResponse
    {
        $response = new StreamedResponse(static function () use ($headers, $rows): void {
            $output = fopen('php://output', 'w');
            if (false === $output) {
                return;
            }

            fwrite($output, "\xEF\xBB\xBF");
            fputcsv($output, $headers, ';');

            foreach ($rows as $row) {
                fputcsv($output, $row, ';');
            }
        });

        $response->headers->set('Content-Type', 'text/csv; charset=UTF-8');
        $response->headers->set('Content-Disposition', sprintf('attachment; filename="%s"', $filename));

        return $response;
    }
}
