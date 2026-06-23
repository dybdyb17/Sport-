<?php

namespace App\Controller\Admin;

use App\Repository\BookingRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\ExpressionLanguage\Expression;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/admin/checkin')]
class AdminCheckinController extends AbstractController
{
    /**
     * Validation du check-in via le QR client.
     *
     * Accès : admin (passe-partout) OU coach (limité à SES propres séances).
     * Contrôle fin coach assigné fait dans le corps, pas via IsGranted, pour
     * pouvoir afficher une page d'erreur lisible au coach plutôt qu'un 403 brut.
     *
     * Route et nom INCHANGÉS — le QR client encode déjà cette URL,
     * la modifier invaliderait tous les QR déjà générés.
     */
    #[Route('/{reference}', name: 'app_admin_checkin_validate', methods: ['GET', 'POST'])]
    #[IsGranted(new Expression('is_granted("ROLE_ADMIN") or is_granted("ROLE_COACH")'))]
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

        // Contrôle fin : un coach ne peut valider QUE ses propres séances.
        // S'applique en GET (affichage) ET en POST (validation effective) — sinon
        // un coach non assigné pourrait forger la requête POST.
        $user            = $this->getUser();
        $isAdmin         = $this->isGranted('ROLE_ADMIN');
        $isAssignedCoach = $booking->getCoach()?->getUser() === $user;

        if (!$isAdmin && !$isAssignedCoach) {
            return $this->render('admin/checkin/forbidden.html.twig', [
                'booking' => $booking,
            ], new Response('', Response::HTTP_FORBIDDEN));
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
