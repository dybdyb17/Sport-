<?php

namespace App\Controller;

use App\Entity\Booking;
use App\Entity\User;
use App\Form\PasswordChangeFormType;
use App\Form\PreferencesFormType;
use App\Form\ProfilFormType;
use App\Repository\BookingRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_CLIENT')]
class ClientController extends AbstractController
{
    #[Route('/mon-espace', name: 'app_espace_client', methods: ['GET'])]
    public function index(BookingRepository $bookingRepository): Response
    {
        /** @var \App\Entity\User $user */
        $user = $this->getUser();
        $all  = $bookingRepository->findForClient($user);
        $now  = new \DateTimeImmutable();

        $estAVenir = static function (Booking $b) use ($now): bool {
            return $b->getStartAt() >= $now
                && in_array($b->getStatus(), [Booking::STATUS_PENDING, Booking::STATUS_CONFIRMED], true);
        };

        $reservationsAVenir  = array_values(array_filter($all, $estAVenir));
        $reservationsPassees = array_values(array_filter($all, static fn (Booking $b): bool => !$estAVenir($b)));

        $nbConfirmees = count(array_filter($all, static fn (Booking $b): bool => $b->isConfirmed()));
        $nbEnAttente  = count(array_filter($all, static fn (Booking $b): bool => $b->isPending()));

        // Trouver la prochaine séance à venir (pending ou confirmed)
        $prochaine = null;
        foreach ($reservationsAVenir as $b) {
            if ($prochaine === null || $b->getStartAt() < $prochaine->getStartAt()) {
                $prochaine = $b;
            }
        }

        // Salutation selon l'heure
        $hour = (int) (new \DateTime())->format('H');
        $greeting = match (true) {
            $hour >= 6  && $hour < 12 => 'Bonjour',
            $hour >= 12 && $hour < 18 => 'Bon après-midi',
            $hour >= 18 && $hour < 22 => 'Bonsoir',
            default                   => 'Bonne nuit',
        };

        return $this->render('client/espace.html.twig', [
            'reservationsAVenir'  => $reservationsAVenir,
            'reservationsPassees' => $reservationsPassees,
            'total'               => count($all),
            'nbConfirmees'        => $nbConfirmees,
            'nbEnAttente'         => $nbEnAttente,
            'prochaine'           => $prochaine,
            'greeting'            => $greeting,
        ]);
    }

    #[Route('/mon-espace/profil', name: 'app_espace_client_profil', methods: ['GET', 'POST'])]
    public function profil(
        Request $request,
        EntityManagerInterface $em,
        UserPasswordHasherInterface $hasher,
    ): Response {
        /** @var User $user */
        $user = $this->getUser();

        $profilForm = $this->createForm(ProfilFormType::class, $user);
        $profilForm->handleRequest($request);

        if ($profilForm->isSubmitted() && $profilForm->isValid()) {
            $newEmail = $user->getEmail();
            $existing = $em->getRepository(User::class)->findOneBy(['email' => $newEmail]);
            if ($existing && $existing->getId() !== $user->getId()) {
                $this->addFlash('danger', sprintf('L\'adresse "%s" est déjà utilisée par un autre compte.', $newEmail));
                return $this->redirectToRoute('app_espace_client_profil');
            }

            $em->flush();
            $this->addFlash('success', 'Tes informations ont été mises à jour.');
            return $this->redirectToRoute('app_espace_client_profil');
        }

        $passwordForm = $this->createForm(PasswordChangeFormType::class);
        $passwordForm->handleRequest($request);

        if ($passwordForm->isSubmitted() && $passwordForm->isValid()) {
            $currentPassword = $passwordForm->get('currentPassword')->getData();
            $newPassword     = $passwordForm->get('newPassword')->getData();

            if (!$hasher->isPasswordValid($user, $currentPassword)) {
                $this->addFlash('danger', 'Mot de passe actuel incorrect.');
                return $this->redirectToRoute('app_espace_client_profil');
            }

            $user->setPassword($hasher->hashPassword($user, $newPassword));
            $em->flush();
            $this->addFlash('success', 'Mot de passe modifié avec succès.');
            return $this->redirectToRoute('app_espace_client_profil');
        }

        $preferencesForm = $this->createForm(PreferencesFormType::class, $user);
        $preferencesForm->handleRequest($request);

        if ($preferencesForm->isSubmitted() && $preferencesForm->isValid()) {
            $em->flush();
            $this->addFlash('success', 'Tes préférences ont été enregistrées.');
            return $this->redirectToRoute('app_espace_client_profil');
        }

        return $this->render('client/profil.html.twig', [
            'profilForm'      => $profilForm,
            'passwordForm'    => $passwordForm,
            'preferencesForm' => $preferencesForm,
        ]);
    }

    #[Route('/reservation/{ref}/annuler', name: 'app_booking_cancel', methods: ['POST'])]
    public function cancel(
        string $ref,
        Request $request,
        BookingRepository $bookingRepository,
        EntityManagerInterface $em,
    ): Response {
        $booking = $bookingRepository->findOneBy(['reference' => $ref]);

        if (!$booking) {
            throw $this->createNotFoundException('Réservation introuvable.');
        }

        /** @var \App\Entity\User $user */
        $user = $this->getUser();

        if ($booking->getClient() !== $user) {
            throw $this->createAccessDeniedException();
        }

        $token = (string) $request->request->get('_token');
        if (!$this->isCsrfTokenValid('cancel' . $booking->getId(), $token)) {
            $this->addFlash('error', 'Jeton CSRF invalide.');

            return $this->redirectToRoute('app_espace_client');
        }

        if (!$booking->canBeCancelled()) {
            $this->addFlash('error', 'Cette séance ne peut plus être annulée (moins de 2h avant le début ou statut incompatible).');

            return $this->redirectToRoute('app_espace_client');
        }

        $booking->setStatus(Booking::STATUS_CANCELLED);
        $em->flush();

        $this->addFlash('success', 'Séance annulée. Aucun frais ne sera prélevé.');

        return $this->redirectToRoute('app_espace_client');
    }

    #[Route('/mon-espace/mon-rdv/{reference}', name: 'app_espace_client_rdv', methods: ['GET'])]
    public function monRdv(string $reference, BookingRepository $bookings): Response
    {
        /** @var User $user */
        $user = $this->getUser();
        $booking = $bookings->findOneBy(['reference' => $reference, 'client' => $user]);
        if (!$booking) {
            throw $this->createNotFoundException('Réservation introuvable.');
        }
        return $this->render('client/mon-rdv.html.twig', [
            'booking' => $booking,
        ]);
    }

}
