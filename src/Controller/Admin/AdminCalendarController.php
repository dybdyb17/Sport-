<?php

namespace App\Controller\Admin;

use App\Repository\BookingRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/admin')]
#[IsGranted('ROLE_ADMIN')]
class AdminCalendarController extends AbstractController
{
    #[Route('/calendrier', name: 'app_admin_calendar', methods: ['GET'])]
    public function calendar(Request $request, BookingRepository $bookingRepo): Response
    {
        $weekParam = $request->query->get('week');

        if ($weekParam !== null && preg_match('/^\d{4}-\d{2}-\d{2}$/', $weekParam)) {
            try {
                $from = new \DateTimeImmutable($weekParam . ' 00:00:00');
                // Ramener au lundi de la semaine si ce n'est pas déjà un lundi
                $from = new \DateTimeImmutable($from->format('o-\WW-1') . ' 00:00:00');
            } catch (\Exception) {
                $from = new \DateTimeImmutable('monday this week 00:00:00');
            }
        } else {
            $from = new \DateTimeImmutable('monday this week 00:00:00');
        }

        $to = $from->modify('+6 days')->setTime(23, 59, 59);

        $bookings = $bookingRepo->findConfirmedInWindow($from, $to);

        $grid = [];
        for ($i = 1; $i <= 7; $i++) {
            $grid[$i] = ['day' => [], 'night' => [], 'astreinte' => []];
        }

        $totalRevenue = 0.0;
        foreach ($bookings as $booking) {
            $dayOfWeek = (int) $booking->getStartAt()->format('N');
            $slot      = $booking->getTimeSlot()->value;
            $grid[$dayOfWeek][$slot][] = $booking;
            $totalRevenue += (float) $booking->getPrice();
        }

        $today    = new \DateTimeImmutable('monday this week 00:00:00');
        $prevWeek = $from->modify('-7 days');
        $nextWeek = $from->modify('+7 days');

        return $this->render('admin/calendar.html.twig', [
            'from'          => $from,
            'to'            => $to,
            'grid'          => $grid,
            'prevWeek'      => $prevWeek,
            'nextWeek'      => $nextWeek,
            'todayWeek'     => $today,
            'totalSessions' => count($bookings),
            'totalRevenue'  => number_format($totalRevenue, 2, '.', ''),
        ]);
    }
}
