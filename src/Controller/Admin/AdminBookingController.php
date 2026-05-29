<?php

namespace App\Controller\Admin;

use App\Entity\Booking;
use App\Repository\BookingRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/admin/reservations')]
#[IsGranted('ROLE_ADMIN')]
class AdminBookingController extends AbstractController
{
    private const VALID_STATUSES = [
        Booking::STATUS_PENDING,
        Booking::STATUS_CONFIRMED,
        Booking::STATUS_REFUSED,
        Booking::STATUS_CANCELLED,
    ];

    #[Route('', name: 'app_admin_bookings', methods: ['GET'])]
    public function index(Request $request, BookingRepository $bookingRepository): Response
    {
        $status   = $request->query->get('status');
        $filtered = null !== $status && in_array($status, self::VALID_STATUSES, true);

        $bookings = $filtered
            ? $bookingRepository->findRecentByStatus($status)
            : $bookingRepository->findAllRecent();

        return $this->render('admin/bookings/index.html.twig', [
            'bookings'     => $bookings,
            'activeStatus' => $filtered ? $status : null,
        ]);
    }
}
