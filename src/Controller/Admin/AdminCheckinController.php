<?php

namespace App\Controller\Admin;

use App\Entity\Booking;
use App\Repository\BookingRepository;
use App\Service\BookingManager;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
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
     * Implémenté via `#[IsGranted('ROLE_COACH')]` qui couvre les 2 cas grâce à
     * la role_hierarchy (security.yaml : ROLE_ADMIN: [ROLE_COACH, ...]). Un
     * simple client/visiteur est bloqué par Symfony avant d'entrer ici.
     * (Pas de syntaxe multi-rôle via attribut ici : le composant
     * symfony/expression-language n'est pas installé sur ce projet,
     * l'utiliser provoquait une 500.)
     *
     * Contrôle fin coach assigné fait dans le corps pour pouvoir afficher une
     * page d'erreur lisible au coach plutôt qu'un 403 brut.
     *
     * Route et nom INCHANGÉS — le QR client encode déjà cette URL,
     * la modifier invaliderait tous les QR déjà générés.
     */
    #[Route('/{reference}', name: 'app_admin_checkin_validate', methods: ['GET', 'POST'], requirements: ['reference' => 'SPT-[A-F0-9]{8}'])]
    #[IsGranted('ROLE_COACH')]
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

        if (($forbidden = $this->enforceCoachAssigned($booking)) !== null) {
            return $forbidden;
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

    /**
     * Encaissement d'une séance directement depuis la page de scan QR.
     *
     * Même mécanique métier que CoachController::declarePayment (mêmes règles :
     * cash/card uniquement, jamais stripe, idempotent, audit) — délègue à
     * BookingManager::declareOnSitePayment avec source 'checkin_scan' pour
     * distinguer dans les logs.
     *
     * Route et chemin volontairement dérivés de la route de validation existante
     * (préfixe /admin/checkin/{reference}) pour rester cohérent, avec le même
     * requirement regex sur la reference (empêche 'pack' ou autre valeur bizarre).
     */
    #[Route(
        '/{reference}/encaisser',
        name: 'app_admin_checkin_declare_payment',
        methods: ['POST'],
        requirements: ['reference' => 'SPT-[A-F0-9]{8}'],
    )]
    #[IsGranted('ROLE_COACH')]
    public function declarePaymentOnScan(
        string $reference,
        Request $request,
        BookingRepository $bookings,
        BookingManager $bookingManager,
    ): Response {
        $booking = $bookings->findOneBy(['reference' => $reference]);
        if (!$booking) {
            throw $this->createNotFoundException('Réservation introuvable.');
        }

        // Même garde coach assigné que la validation (POST peut être forgé sinon)
        if (($forbidden = $this->enforceCoachAssigned($booking)) !== null) {
            return $forbidden;
        }

        if (!$this->isCsrfTokenValid('checkin_pay_'.$booking->getReference(), (string) $request->request->get('_token'))) {
            $this->addFlash('danger', 'Jeton CSRF invalide.');
            return $this->redirectToRoute('app_admin_checkin_validate', ['reference' => $reference]);
        }

        try {
            /** @var \App\Entity\User $user */
            $user = $this->getUser();
            $bookingManager->declareOnSitePayment(
                $booking,
                (string) $request->request->get('method'),
                null,
                $user,
                'checkin_scan',
            );
            $this->addFlash('success', 'Paiement enregistré.');
        } catch (\InvalidArgumentException $e) {
            $this->addFlash('danger', 'Mode de paiement invalide.');
        } catch (\LogicException $e) {
            $this->addFlash('info', $e->getMessage());
        }

        return $this->redirectToRoute('app_admin_checkin_validate', ['reference' => $reference]);
    }

    /**
     * Vérifie que le user connecté est admin OU le coach assigné du booking.
     * Retourne une Response 403 lisible sinon (partagée avec la vue forbidden).
     *
     * Comparaison par ID (et PAS par instance via ===) : le projet a
     * enable_native_lazy_objects activé (PHP 8.4 + Doctrine ORM 3.x), le
     * proxy lazy peut renvoyer une autre instance du même user en DB.
     */
    private function enforceCoachAssigned(Booking $booking): ?Response
    {
        $user            = $this->getUser();
        $isAdmin         = $this->isGranted('ROLE_ADMIN');
        $coachUserId     = $booking->getCoach()?->getUser()?->getId();
        $isAssignedCoach = $coachUserId !== null && $coachUserId === $user?->getId();

        if (!$isAdmin && !$isAssignedCoach) {
            return $this->render('admin/checkin/forbidden.html.twig', [
                'booking' => $booking,
            ], new Response('', Response::HTTP_FORBIDDEN));
        }

        return null;
    }
}
