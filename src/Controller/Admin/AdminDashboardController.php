<?php

namespace App\Controller\Admin;

use App\Repository\BookingRepository;
use App\Repository\FoundingClaimRepository;
use App\Repository\FoundingOfferRepository;
use App\Repository\SubscriptionRepository;
use App\Repository\UserRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/admin')]
#[IsGranted('ROLE_ADMIN')]
class AdminDashboardController extends AbstractController
{
    #[Route('', name: 'app_admin_dashboard', methods: ['GET'])]
    public function index(
        UserRepository $userRepo,
        BookingRepository $bookingRepo,
        FoundingOfferRepository $foundingRepo,
        FoundingClaimRepository $claimRepo,
        SubscriptionRepository $subRepo,
    ): Response {
        $since        = new \DateTimeImmutable('first day of this month 00:00');
        $until        = new \DateTimeImmutable('first day of next month 00:00');
        $sinceAllTime = new \DateTimeImmutable('2020-01-01');

        $foundingOffer = $foundingRepo->findCurrent();
        $foundingStats = $foundingOffer !== null ? $claimRepo->getStats($foundingOffer) : null;

        $userCounts = $userRepo->countByRole();

        // Données calendrier semaine courante pour l'encart dashboard
        $monday  = new \DateTimeImmutable('monday this week 00:00');
        $sunday  = new \DateTimeImmutable('sunday this week 23:59:59');
        $weekBookings = $bookingRepo->findConfirmedInWindow($monday, $sunday);
        $weekGrid = $this->buildWeekGrid($weekBookings);

        return $this->render('admin/dashboard.html.twig', [
            'periodLabel'      => 'Mois en cours',
            'since'            => $since,
            'until'            => $until,
            'breakdown'        => $bookingRepo->getRevenueBreakdown($since, $until),
            'breakdownAllTime' => $bookingRepo->getRevenueBreakdown($sinceAllTime),
            'slotStats'        => $bookingRepo->countAndRevenueBySlot($since, $until),
            'userCounts'       => $userCounts,
            'bookingCounts'    => $bookingRepo->countByStatus(),
            'foundingOffer'    => $foundingOffer,
            'foundingStats'    => $foundingStats,
            'subStats'         => $subRepo->getGlobalStats(),
            'expiringSubs'     => $subRepo->findExpiringSoon(7),
            'topCoaches'       => $bookingRepo->topCoachesByRevenue($since, 5),
            'recentBookings'   => $bookingRepo->findAllRecent(10),
            'totalUsers'       => array_sum($userCounts),
            'totalCoaches'     => $userCounts['COACH'],
            'weekMonday'       => $monday,
            'weekGrid'         => $weekGrid,
            'weekBookings'     => $weekBookings,
        ]);
    }

    /** @param \App\Entity\Booking[] $bookings */
    private function buildWeekGrid(array $bookings): array
    {
        $grid = [];
        for ($i = 1; $i <= 7; $i++) {
            $grid[$i] = ['day' => [], 'night' => [], 'astreinte' => []];
        }

        foreach ($bookings as $booking) {
            $dayOfWeek = (int) $booking->getStartAt()->format('N'); // 1=Lun, 7=Dim
            $slot      = $booking->getTimeSlot()->value;
            $grid[$dayOfWeek][$slot][] = $booking;
        }

        return $grid;
    }
}
