<?php

namespace App\Controller\Admin;

use App\Repository\BookingRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/admin/checkin')]
#[IsGranted('ROLE_ADMIN')]
class AdminCheckinController extends AbstractController
{
    #[Route('/{reference}', name: 'app_admin_checkin_validate', methods: ['GET', 'POST'])]
    public function validate(
        string $reference,
        Request $request,
        BookingRepository $bookings,
        EntityManagerInterface $em,
    ): Response {
        $booking = $bookings->findOneBy(['reference' => $reference]);
        if (!$booking) {
            throw $this->createNotFoundException('Réservation introuvable.');
        }

        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('checkin_'.$booking->getReference(), (string) $request->request->get('_token'))) {
                $this->addFlash('danger', 'Jeton CSRF invalide.');
                return $this->redirectToRoute('app_admin_checkin_validate', ['reference' => $reference]);
            }
            $booking->setCheckinAt(new \DateTimeImmutable());
            $booking->setCheckinBy($this->getUser());
            $em->flush();
            $this->addFlash('success', 'Check-in validé pour '.$booking->getClient()->getNomComplet());
            return $this->redirectToRoute('app_admin_checkin_validate', ['reference' => $reference]);
        }

        return $this->render('admin/checkin/validate.html.twig', ['booking' => $booking]);
    }
}
