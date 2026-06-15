<?php

namespace App\Controller\Admin;

use App\Repository\BookingRepository;
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
    public function index(Request $request, BookingRepository $bookings): Response
    {
        $methodFilter = $request->query->get('method');

        $qb = $bookings->createQueryBuilder('b')
            ->leftJoin('b.client', 'cl')
            ->leftJoin('b.coach', 'co')
            ->leftJoin('co.user', 'cou')
            ->addSelect('cl', 'co', 'cou')
            ->where('b.status = :confirmed')
            ->setParameter('confirmed', 'confirmed')
            ->orderBy('b.startAt', 'DESC');

        if ($methodFilter === 'pending') {
            $qb->andWhere('b.paymentMethod IS NULL');
        } elseif (in_array($methodFilter, ['cash', 'card', 'xplor'], true)) {
            $qb->andWhere('b.paymentMethod = :m')->setParameter('m', $methodFilter);
        }

        $rows = $qb->getQuery()->getResult();

        // Récap par coach
        $statsByCoach = [];
        foreach ($rows as $b) {
            $coachId = $b->getCoach()?->getId();
            if (!$coachId) continue;
            if (!isset($statsByCoach[$coachId])) {
                $statsByCoach[$coachId] = [
                    'name'    => $b->getCoach()->getNomComplet() ?? '—',
                    'total'   => 0.0,
                    'cash'    => 0.0,
                    'card'    => 0.0,
                    'xplor'   => 0.0,
                    'no_show' => 0.0,
                    'pending' => 0.0,
                ];
            }
            $price = (float) ($b->getPrice() ?? 0);
            $statsByCoach[$coachId]['total'] += $price;
            $m = $b->getPaymentMethod();
            if ($m === null) {
                $statsByCoach[$coachId]['pending'] += $price;
            } elseif (isset($statsByCoach[$coachId][$m])) {
                // Mode connu (cash/card/xplor/no_show) → on cumule
                $statsByCoach[$coachId][$m] += $price;
            } else {
                // Sécurité : si un jour une nouvelle valeur arrive (ex: 'paylib'),
                // on l'agrège sans crasher l'admin.
                $statsByCoach[$coachId][$m] = $price;
            }
        }

        return $this->render('admin/payments/index.html.twig', [
            'rows'         => $rows,
            'statsByCoach' => $statsByCoach,
            'methodFilter' => $methodFilter,
        ]);
    }
}
