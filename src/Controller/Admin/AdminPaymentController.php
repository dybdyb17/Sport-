<?php

namespace App\Controller\Admin;

use App\Repository\CoachRepository;
use App\Service\AdminExportService;
use App\Service\PaymentJournalBuilder;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/admin/paiements')]
#[IsGranted('ROLE_ADMIN')]
class AdminPaymentController extends AbstractController
{
    #[Route('', name: 'app_admin_payments', methods: ['GET'])]
    public function index(
        Request $request,
        PaymentJournalBuilder $builder,
        CoachRepository $coaches,
    ): Response {
        $filters = $this->resolveFilters($request);

        $journal = $builder->build($filters);

        $periodOptions = $this->buildPeriodOptions();

        $coachList = $coaches->createQueryBuilder('c')
            ->leftJoin('c.user', 'u')->addSelect('u')
            ->orderBy('u.nomComplet', 'ASC')
            ->getQuery()->getResult();

        return $this->render('admin/payments/index.html.twig', [
            'events'        => $journal['events'],
            'totals'        => $journal['totals'],
            'filters'       => $filters,
            'periodOptions' => $periodOptions,
            'coachList'     => $coachList,
        ]);
    }

    #[Route('/export.csv', name: 'app_admin_payments_export', methods: ['GET'])]
    public function export(
        Request $request,
        PaymentJournalBuilder $builder,
        AdminExportService $export,
    ): Response {
        $filters = $this->resolveFilters($request);
        $journal = $builder->build($filters);

        $csv = $export->exportPayments($journal['events']);

        $suffix = $this->filenameSuffixFromFilters($filters);
        $response = new Response($csv);
        $response->headers->set('Content-Type', 'text/csv; charset=UTF-8');
        $response->headers->set(
            'Content-Disposition',
            sprintf('attachment; filename="paiements-sportplus-%s.csv"', $suffix),
        );

        return $response;
    }

    /**
     * Normalise les query params en filtres exploitables + bornes de période.
     *
     * @return array{
     *     type: string, method: string, status: string, coachId: int|null,
     *     periodKey: string, from: \DateTimeImmutable, to: \DateTimeImmutable
     * }
     */
    private function resolveFilters(Request $request): array
    {
        $type   = $request->query->get('type', 'all');
        $method = $request->query->get('method', 'all');
        $status = $request->query->get('status', 'all');

        $type   = in_array($type,   ['all', 'seance', 'pack', 'fondateur'], true) ? $type   : 'all';
        $method = in_array($method, ['all', 'stripe', 'cash', 'card'],       true) ? $method : 'all';
        $status = in_array($status, ['all', 'paid', 'pending'],              true) ? $status : 'all';

        $coachRaw = $request->query->get('coach');
        $coachId  = ($coachRaw !== null && $coachRaw !== '' && $coachRaw !== 'all')
            ? (int) $coachRaw
            : null;

        $periodKey = $request->query->get('period', 'current');
        [$from, $to] = $this->parsePeriod($periodKey);

        return compact('type', 'method', 'status', 'coachId', 'periodKey', 'from', 'to');
    }

    /**
     * @return array{0: \DateTimeImmutable, 1: \DateTimeImmutable}
     */
    private function parsePeriod(string $key): array
    {
        $tz  = new \DateTimeZone('Europe/Paris');
        $now = new \DateTimeImmutable('now', $tz);

        if ($key === 'all') {
            return [new \DateTimeImmutable('2020-01-01', $tz), $now->modify('+1 day')];
        }

        if (preg_match('/^ym:(\d{4})-(\d{2})$/', $key, $m)) {
            $y = (int) $m[1];
            $mo = (int) $m[2];
            $from = new \DateTimeImmutable(sprintf('%04d-%02d-01 00:00:00', $y, $mo), $tz);
            $to   = $from->modify('first day of next month');
            return [$from, $to];
        }

        // Par défaut : mois courant
        $from = new \DateTimeImmutable($now->format('Y-m-01 00:00:00'), $tz);
        $to   = $from->modify('first day of next month');
        return [$from, $to];
    }

    /**
     * 12 derniers mois + "Tout".
     *
     * @return array<int, array{key: string, label: string}>
     */
    private function buildPeriodOptions(): array
    {
        $tz  = new \DateTimeZone('Europe/Paris');
        $now = new \DateTimeImmutable('now', $tz);

        $options = [['key' => 'current', 'label' => 'Ce mois-ci']];

        for ($i = 1; $i <= 11; $i++) {
            $dt = $now->modify(sprintf('-%d months', $i));
            $key = 'ym:' . $dt->format('Y-m');
            $label = ucfirst(\IntlDateFormatter::formatObject($dt, 'MMMM y', 'fr_FR'));
            $options[] = ['key' => $key, 'label' => $label];
        }

        $options[] = ['key' => 'all', 'label' => 'Tout l\'historique'];

        return $options;
    }

    /** @param array{periodKey: string, ...} $filters */
    private function filenameSuffixFromFilters(array $filters): string
    {
        if ($filters['periodKey'] === 'all')     return 'tout';
        if ($filters['periodKey'] === 'current') return (new \DateTimeImmutable())->format('Y-m');
        if (preg_match('/^ym:(\d{4}-\d{2})$/', $filters['periodKey'], $m)) return $m[1];
        return 'export';
    }
}
