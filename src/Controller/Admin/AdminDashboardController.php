<?php

namespace App\Controller\Admin;

use App\Entity\Enum\TimeSlot;
use App\Repository\BookingRepository;
use App\Repository\FoundingClaimRepository;
use App\Repository\FoundingOfferRepository;
use App\Repository\SubscriptionRepository;
use App\Repository\UserRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_ADMIN')]
class AdminDashboardController extends AbstractController
{
    #[Route('/admin', name: 'app_admin_dashboard', methods: ['GET'])]
    public function index(
        BookingRepository $bookingRepository,
        UserRepository $userRepository,
        FoundingOfferRepository $foundingOfferRepository,
        FoundingClaimRepository $claimRepository,
        SubscriptionRepository $subscriptionRepository,
    ): Response {
        $since = new \DateTimeImmutable('first day of this month 00:00');
        $until = new \DateTimeImmutable('first day of next month 00:00');
        $foundingOffer = $foundingOfferRepository->findCurrent();

        $weekFrom = new \DateTimeImmutable('monday this week 00:00');
        $weekTo = $weekFrom->modify('+6 days 23:59:59');
        $weekBookings = $bookingRepository->findConfirmedInWindow($weekFrom, $weekTo);

        return $this->render('admin/dashboard.html.twig', [
            'periodLabel' => 'Mois en cours',
            'since' => $since,
            'until' => $until,
            'breakdown' => $bookingRepository->getRevenueBreakdown($since, $until),
            'breakdownAllTime' => $bookingRepository->getRevenueBreakdown(new \DateTimeImmutable('2020-01-01 00:00')),
            'slotStats' => $bookingRepository->countAndRevenueBySlot($since, $until),
            'userCounts' => $userRepository->countByRole(),
            'bookingCounts' => $bookingRepository->countByStatus(),
            'foundingOffer' => $foundingOffer,
            'foundingStats' => $claimRepository->getStats($foundingOffer),
            'subStats' => $subscriptionRepository->getGlobalStats(),
            'expiringSubs' => $subscriptionRepository->findExpiringSoon(7),
            'topCoaches' => $bookingRepository->topCoachesByRevenue($since, 5),
            'recentBookings' => $bookingRepository->findAllRecent(10),
            'weekBookings' => $weekBookings,
            'weekFrom' => $weekFrom,
            'weekTo' => $weekTo,
        ]);
    }

    #[Route('/admin/calendrier', name: 'app_admin_calendar', methods: ['GET'])]
    public function calendar(Request $request, BookingRepository $bookingRepository): Response
    {
        $weekParam = $request->query->get('week');
        $from = $weekParam
            ? new \DateTimeImmutable($weekParam . ' 00:00')
            : new \DateTimeImmutable('monday this week 00:00');
        $from = $from->modify('monday this week 00:00');
        $to = $from->modify('+6 days 23:59:59');

        $grid = [];
        for ($day = 1; $day <= 7; ++$day) {
            foreach (TimeSlot::cases() as $slot) {
                $grid[$day][$slot->value] = [];
            }
        }

        $bookings = $bookingRepository->findConfirmedInWindow($from, $to);
        foreach ($bookings as $booking) {
            $day = (int) $booking->getStartAt()?->format('N');
            $grid[$day][$booking->getTimeSlot()->value][] = $booking;
        }

        return $this->render('admin/calendar.html.twig', [
            'from' => $from,
            'to' => $to,
            'prevWeek' => $from->modify('-7 days'),
            'nextWeek' => $from->modify('+7 days'),
            'slots' => TimeSlot::cases(),
            'grid' => $grid,
            'bookings' => $bookings,
            'totalSessions' => count($bookings),
            'totalRevenue' => number_format(array_sum(array_map(static fn ($b): float => (float) $b->getPrice(), $bookings)), 2, '.', ''),
        ]);
    }
}
