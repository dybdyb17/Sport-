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
    public function exportRevenue(Request $request, AdminExportService $exp): Response
    {
        [$since, $until, $label] = $this->parsePeriod($request);
        $csv = $exp->exportRevenue($since, $until);

        return $this->csvResponse($csv, 'ca-' . $label . '.csv');
    }

    #[Route('/founding', name: 'app_admin_export_founding', methods: ['GET'])]
    public function exportFounding(AdminExportService $exp, FoundingOfferRepository $offerRepo): Response
    {
        $offer = $offerRepo->findOneBy([], ['createdAt' => 'DESC']);
        if ($offer === null) {
            $this->addFlash('error', 'Aucune offre Founding trouvée.');
            return $this->redirectToRoute('app_admin_founding_list');
        }

        $csv = $exp->exportFoundingMembers($offer);
        return $this->csvResponse($csv, 'fondateurs-' . date('Y-m-d') . '.csv');
    }

    #[Route('/subscriptions', name: 'app_admin_export_subscriptions', methods: ['GET'])]
    public function exportSubscriptions(AdminExportService $exp): Response
    {
        $csv = $exp->exportSubscriptions();
        return $this->csvResponse($csv, 'abonnements-' . date('Y-m-d') . '.csv');
    }

    #[Route('/bookings', name: 'app_admin_export_bookings', methods: ['GET'])]
    public function exportBookings(Request $request, AdminExportService $exp): Response
    {
        [$since, $until, $label] = $this->parsePeriod($request);
        $csv = $exp->exportBookings($since, $until);

        return $this->csvResponse($csv, 'reservations-' . $label . '.csv');
    }

    private function parsePeriod(Request $request): array
    {
        $from = $request->query->get('from');
        $to   = $request->query->get('to');

        try {
            $since = $from ? new \DateTimeImmutable($from . ' 00:00:00') : new \DateTimeImmutable('first day of this month 00:00:00');
            $until = $to   ? new \DateTimeImmutable($to . ' 23:59:59')   : new \DateTimeImmutable('last day of this month 23:59:59');
        } catch (\Exception) {
            $since = new \DateTimeImmutable('first day of this month 00:00:00');
            $until = new \DateTimeImmutable('last day of this month 23:59:59');
        }

        $label = $since->format('Y-m');

        return [$since, $until, $label];
    }

    private function csvResponse(string $csv, string $filename): Response
    {
        return new Response($csv, 200, [
            'Content-Type'        => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="' . $filename . '"',
            'Cache-Control'       => 'no-cache, no-store, must-revalidate',
        ]);
    }
}
