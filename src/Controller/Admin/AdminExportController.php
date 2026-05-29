<?php

namespace App\Controller\Admin;

use App\Repository\FoundingOfferRepository;
use App\Service\AdminExportService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/admin/exports')]
#[IsGranted('ROLE_ADMIN')]
class AdminExportController extends AbstractController
{
    #[Route('/revenue', name: 'app_admin_export_revenue', methods: ['GET'])]
    public function revenue(Request $request, AdminExportService $exportService): Response
    {
        [$from, $to] = $this->period($request);

        return $this->csv($exportService->exportRevenue($from, $to), sprintf('ca-%s.csv', $from->format('Y-m')));
    }

    #[Route('/founding', name: 'app_admin_export_founding', methods: ['GET'])]
    public function founding(AdminExportService $exportService, FoundingOfferRepository $offerRepository): Response
    {
        return $this->csv($exportService->exportFoundingMembers($offerRepository->findCurrent()), 'fondateurs.csv');
    }

    #[Route('/subscriptions', name: 'app_admin_export_subscriptions', methods: ['GET'])]
    public function subscriptions(AdminExportService $exportService): Response
    {
        return $this->csv($exportService->exportSubscriptions(), 'abonnements.csv');
    }

    #[Route('/bookings', name: 'app_admin_export_bookings', methods: ['GET'])]
    public function bookings(Request $request, AdminExportService $exportService): Response
    {
        [$from, $to] = $this->period($request);

        return $this->csv($exportService->exportBookings($from, $to), sprintf('reservations-%s.csv', $from->format('Y-m')));
    }

    /** @return array{0:\DateTimeImmutable,1:\DateTimeImmutable} */
    private function period(Request $request): array
    {
        $from = $request->query->get('from')
            ? new \DateTimeImmutable((string) $request->query->get('from') . ' 00:00')
            : new \DateTimeImmutable('first day of this month 00:00');
        $to = $request->query->get('to')
            ? new \DateTimeImmutable((string) $request->query->get('to') . ' 23:59:59')
            : new \DateTimeImmutable('first day of next month 00:00');

        return [$from, $to];
    }

    private function csv(string $content, string $filename): Response
    {
        return new Response($content, Response::HTTP_OK, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="' . $filename . '"',
        ]);
    }
}
