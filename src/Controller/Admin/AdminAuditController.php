<?php

namespace App\Controller\Admin;

use App\Entity\Enum\AuditAction;
use App\Repository\AuditLogRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/admin/audit')]
#[IsGranted('ROLE_ADMIN')]
class AdminAuditController extends AbstractController
{
    #[Route('', name: 'app_admin_audit', methods: ['GET'])]
    public function index(Request $request, AuditLogRepository $repo): Response
    {
        $actionFilter = null;
        $actionParam  = $request->query->get('action');
        if (is_string($actionParam) && $actionParam !== '') {
            $actionFilter = AuditAction::tryFrom($actionParam);
        }

        $roleFilter = $request->query->get('role') ?: null;
        if ($roleFilter !== null && !in_array($roleFilter, ['admin', 'coach', 'client', 'system'], true)) {
            $roleFilter = null;
        }

        $fromFilter = null;
        $toFilter   = null;
        if ($from = $request->query->get('from')) {
            try { $fromFilter = new \DateTimeImmutable($from); } catch (\Exception) {}
        }
        if ($to = $request->query->get('to')) {
            try { $toFilter = new \DateTimeImmutable($to . ' 23:59:59'); } catch (\Exception) {}
        }

        $logs = $repo->findFiltered(
            action: $actionFilter,
            actorRole: $roleFilter,
            from: $fromFilter,
            to: $toFilter,
            limit: 200,
        );

        return $this->render('admin/audit/index.html.twig', [
            'logs'         => $logs,
            'actions'      => AuditAction::cases(),
            'actionFilter' => $actionFilter,
            'roleFilter'   => $roleFilter,
            'fromFilter'   => $fromFilter?->format('Y-m-d'),
            'toFilter'     => $toFilter?->format('Y-m-d'),
        ]);
    }
}
