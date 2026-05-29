<?php

namespace App\Controller\Admin;

use App\Repository\BookingRepository;
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
        UserRepository $userRepository,
        BookingRepository $bookingRepository,
    ): Response {
        $userCounts = $userRepository->countByRole();
        $since      = new \DateTimeImmutable('first day of this month 00:00');

        return $this->render('admin/dashboard.html.twig', [
            'userCounts'       => $userCounts,
            'bookingCounts'    => $bookingRepository->countByStatus(),
            'revenueThisMonth' => $bookingRepository->sumRevenue($since),
            'revenueAllTime'   => $bookingRepository->sumRevenue(new \DateTimeImmutable('2020-01-01')),
            'recentBookings'   => $bookingRepository->findAllRecent(10),
            'totalUsers'       => array_sum($userCounts),
            'totalCoaches'     => $userCounts['COACH'],
        ]);
    }
}
