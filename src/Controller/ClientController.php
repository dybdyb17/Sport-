<?php

namespace App\Controller;

use App\Entity\Booking;
use App\Entity\Coach;
use App\Entity\Enum\TimeSlot;
use App\Entity\Subscription;
use App\Entity\User;
use App\Form\PackBookingType;
use App\Form\PasswordChangeFormType;
use App\Form\PreferencesFormType;
use App\Form\ProfilFormType;
use App\Repository\BookingRepository;
use App\Repository\PendingPackRequestRepository;
use App\Repository\SubscriptionRepository;
use App\Service\BookingManager;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_CLIENT')]
class ClientController extends AbstractController
{
    /**
     * Seuil (en jours) à partir duquel un pack passe en "expire bientôt"
     * (badge or au lieu de vert). Une seule constante, réutilisée par
     * l'espace client et la page /mon-espace/mes-packs.
     */
    private const PACK_EXPIRING_SOON_DAYS = 7;

    #[Route('/mon-espace', name: 'app_espace_client', methods: ['GET'])]
    public function index(
        BookingRepository $bookingRepository,
        SubscriptionRepository $subscriptionRepository,
        PendingPackRequestRepository $pprRepository,
    ): Response {
        /** @var \App\Entity\User $user */
        $user = $this->getUser();
        $all  = $bookingRepository->findForClient($user);
        $now  = new \DateTimeImmutable();

        // Packs actifs du client — filtrés par le repo (status=active
        // + sessions_remaining > 0). Un pack peut avoir toutes ses séances utilisées
        // avant expiration → on ne veut pas afficher un pack vide.
        $packsActifs = $subscriptionRepository->findActiveForClient($user);

        // Compteurs pour la barre compacte de l'espace.
        // Un flag "anyExpiring" bascule la barre en accent or si au moins un pack
        // arrive à expiration bientôt OU s'il y a des demandes en attente.
        $totalSessions = 0;
        $anyExpiring   = false;
        foreach ($packsActifs as $p) {
            $totalSessions += $p->getSessionsRemaining();
            $daysLeft = max(0, (int) $now->diff($p->getEndsAt())->days);
            if ($p->getEndsAt() >= $now && $daysLeft <= self::PACK_EXPIRING_SOON_DAYS) {
                $anyExpiring = true;
            }
        }
        // Demandes sur place en attente de validation coach.
        // La barre doit s'afficher AUSSI dans ce cas (même si aucun pack actif),
        // pour que le client sache où en est sa demande.
        $pendingOnSiteCount = count($pprRepository->findPendingOnSiteForClient($user));
        $packsStrip = [
            'count'         => count($packsActifs),
            'totalSessions' => $totalSessions,
            'anyExpiring'   => $anyExpiring || $pendingOnSiteCount > 0,
            'pendingCount'  => $pendingOnSiteCount,
        ];

        // Séances à venir (pending ou confirmed), triées du plus proche au plus lointain
        $aVenir = array_values(array_filter($all, static fn (Booking $b): bool =>
            $b->getStartAt() >= $now
            && in_array($b->getStatus(), [Booking::STATUS_PENDING, Booking::STATUS_CONFIRMED], true)
        ));
        usort($aVenir, static fn (Booking $a, Booking $b): int => $a->getStartAt() <=> $b->getStartAt());

        // La prochaine + les autres (pour l'affichage adaptatif)
        $prochaine     = $aVenir[0] ?? null;
        $autresSeances = array_slice($aVenir, 1);

        // Action en attente : une séance confirmée non encore payée via Xplor →
        // on l'affiche dans la bannière contextuelle. Priorité à la PROCHAINE
        // séance bloquée, sinon la première qu'on trouve.
        $actionAttente = null;
        foreach ($aVenir as $b) {
            if ($b->isAwaitingOnlinePayment()) {
                $actionAttente = $b;
                break;
            }
        }

        // Salutation selon l'heure
        $hour = (int) $now->format('H');
        $greeting = match (true) {
            $hour >= 6  && $hour < 12 => 'Bonjour',
            $hour >= 12 && $hour < 18 => 'Bon après-midi',
            $hour >= 18 && $hour < 22 => 'Bonsoir',
            default                   => 'Bonne nuit',
        };

        // Phrase contextuelle adaptée à l'état
        if ($prochaine) {
            $coachPrenom = explode(' ', (string) $prochaine->getCoach()?->getNomComplet())[0] ?: 'ton coach';
            $jours       = (int) $now->diff($prochaine->getStartAt())->days;
            $heure       = $prochaine->getStartAt()->format('H\hi');
            $heureH      = (int) $prochaine->getStartAt()->format('H');

            if ($jours === 0) {
                $quand = $heureH < 18 ? "aujourd'hui" : 'ce soir';
                $heroMessage = sprintf("Ta séance avec %s, c'est %s à %s. On t'attend.", $coachPrenom, $quand, $heure);
                $heroIcon = 'ti-flame';
            } elseif ($jours === 1) {
                $heroMessage = sprintf("Ta séance avec %s, c'est demain à %s. Repose-toi bien.", $coachPrenom, $heure);
                $heroIcon = 'ti-moon-stars';
            } else {
                $heroMessage = sprintf("Ta prochaine séance avec %s est dans %d jours. Tiens le rythme.", $coachPrenom, $jours);
                $heroIcon = 'ti-target-arrow';
            }
        } else {
            $heroMessage = "Prêt à reprendre l'entraînement ? Choisis ton coach et ton créneau.";
            $heroIcon = 'ti-bolt';
        }

        return $this->render('client/espace.html.twig', [
            'prochaine'      => $prochaine,
            'autresSeances'  => $autresSeances,
            'actionAttente'  => $actionAttente,
            'packsStrip'     => $packsStrip,
            'greeting'       => $greeting,
            'heroMessage'    => $heroMessage,
            'heroIcon'       => $heroIcon,
        ]);
    }

    #[Route('/mon-espace/mes-packs', name: 'app_espace_client_packs', methods: ['GET'])]
    public function packs(
        SubscriptionRepository $subscriptionRepository,
        PendingPackRequestRepository $pprRepository,
    ): Response {
        /** @var \App\Entity\User $user */
        $user           = $this->getUser();
        $packs          = $subscriptionRepository->findActiveForClient($user);
        $pendingOnSite  = $pprRepository->findPendingOnSiteForClient($user);
        $now            = new \DateTimeImmutable();

        // On enrichit chaque pack de "expiring" (bool), "daysLeft" (int) et
        // "upcomingBookings" (les séances liées à ce pack, à venir, triées).
        // Le template se contente d'afficher — pas de calcul dans le Twig.
        $items = [];
        foreach ($packs as $pack) {
            $daysLeft  = max(0, (int) $now->diff($pack->getEndsAt())->days);
            $isPast    = $pack->getEndsAt() < $now;
            $daysLeft  = $isPast ? 0 : $daysLeft;
            $expiring  = !$isPast && $daysLeft <= self::PACK_EXPIRING_SOON_DAYS;

            // Séances liées au pack, à venir (pending ou confirmed), triées.
            // Point 1 : le client doit voir que sa 1ère séance existe déjà.
            $upcoming = [];
            foreach ($pack->getBookings() as $b) {
                if ($b->getStartAt() >= $now
                    && in_array($b->getStatus(), [Booking::STATUS_PENDING, Booking::STATUS_CONFIRMED], true)
                ) {
                    $upcoming[] = $b;
                }
            }
            usort($upcoming, static fn (Booking $a, Booking $b): int => $a->getStartAt() <=> $b->getStartAt());

            $items[] = [
                'pack'             => $pack,
                'expiring'         => $expiring,
                'daysLeft'         => $daysLeft,
                'upcomingBookings' => $upcoming,
            ];
        }

        return $this->render('client/mes-packs.html.twig', [
            'items'         => $items,
            'pendingOnSite' => $pendingOnSite,
        ]);
    }

    /**
     * Réservation d'une séance avec un pack déjà acheté.
     *
     * Simplifié à l'extrême : le format/timeSlot/personsCount/fullAccess sont
     * ceux du pack. Le client choisit UNIQUEMENT coach + date/heure + message.
     * L'heure doit tomber dans la plage horaire du pack (DAY/NIGHT/ASTREINTE)
     * — vérifié via TimeSlot::fromDateTime (source de vérité de l'enum).
     *
     * Sécurité :
     *  - le pack {id} DOIT appartenir au user connecté (sinon 404 silencieux
     *    pour ne pas révéler l'existence d'autres packs)
     *  - il DOIT être actif (status=active, sessions_remaining>0, non expiré)
     *  - la séance créée est en PENDING, liée au pack, décomptée à la
     *    confirmation coach (BookingManager::confirm appelle consume())
     */
    #[Route(
        '/mon-espace/mes-packs/{id}/reserver',
        name: 'app_pack_booking_new',
        methods: ['GET', 'POST'],
        requirements: ['id' => '\d+'],
    )]
    public function packBookingNew(
        int $id,
        Request $request,
        SubscriptionRepository $subscriptionRepository,
        BookingManager $manager,
    ): Response {
        /** @var User $user */
        $user = $this->getUser();
        $pack = $subscriptionRepository->find($id);

        // Vérifs d'appartenance et d'utilisabilité (verrous serveur, pas juste UI)
        if (!$pack || $pack->getClient()?->getId() !== $user->getId()) {
            throw $this->createNotFoundException('Ce pack n\'existe pas ou ne t\'appartient pas.');
        }
        if ($pack->getStatus() !== Subscription::STATUS_ACTIVE
            || $pack->getSessionsRemaining() <= 0
            || $pack->getEndsAt() < new \DateTimeImmutable()
        ) {
            $this->addFlash('error', 'Ce pack n\'est plus utilisable (épuisé, expiré ou inactif).');
            return $this->redirectToRoute('app_espace_client_packs');
        }

        $form = $this->createForm(PackBookingType::class);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $data    = $form->getData();
            $coach   = $data['coach'] ?? null;
            $startAt = $data['startAt'] ?? null;
            $message = $data['message'] ?? null;

            if (!$coach instanceof Coach) {
                $this->addFlash('error', 'Coach invalide.');
                return $this->redirectToRoute('app_pack_booking_new', ['id' => $id]);
            }
            if (!$startAt instanceof \DateTimeInterface) {
                $this->addFlash('error', 'Date et heure invalides.');
                return $this->redirectToRoute('app_pack_booking_new', ['id' => $id]);
            }

            // Normalise en Immutable (le form peut renvoyer un DateTime muable)
            $startAt = $startAt instanceof \DateTimeImmutable
                ? $startAt
                : \DateTimeImmutable::createFromInterface($startAt);

            // Refuse une résa dans le passé
            if ($startAt < new \DateTimeImmutable()) {
                $this->addFlash('error', 'La date choisie est dans le passé.');
                return $this->redirectToRoute('app_pack_booking_new', ['id' => $id]);
            }

            // Contrainte de créneau : l'heure DOIT tomber dans la plage du pack
            $expectedSlot = $pack->getTimeSlot();
            $actualSlot   = TimeSlot::fromDateTime($startAt);
            if ($actualSlot !== $expectedSlot) {
                $this->addFlash('error', sprintf(
                    'L\'heure choisie tombe sur le créneau %s, alors que ton pack est %s. Choisis une heure dans la plage %s.',
                    strtolower($actualSlot->label()),
                    strtolower($expectedSlot->label()),
                    $expectedSlot->label(),
                ));
                return $this->redirectToRoute('app_pack_booking_new', ['id' => $id]);
            }

            try {
                $created = $manager->create(
                    $user,
                    $coach,
                    $pack->getFormat(),
                    $pack->getTimeSlot(),
                    $pack->getPersonsCount(),
                    $startAt,
                    $message,
                    $pack, // ← subscription liée : décompte à la confirm coach
                );
                $this->addFlash('success', sprintf(
                    'Ta séance du %s est enregistrée. Elle sera décomptée de ton pack dès que le coach l\'aura confirmée.',
                    $startAt->format('d/m/Y à H\hi'),
                ));
                return $this->redirectToRoute('app_espace_client_packs');
            } catch (ConflictHttpException $e) {
                // Créneau pris (rare mais possible) — message clair, pas 500
                $this->addFlash('error', 'Ce créneau n\'est plus disponible pour ce coach. Choisis-en un autre.');
                return $this->redirectToRoute('app_pack_booking_new', ['id' => $id]);
            } catch (\Throwable $e) {
                $this->addFlash('error', 'Impossible de créer la séance : ' . $e->getMessage());
                return $this->redirectToRoute('app_pack_booking_new', ['id' => $id]);
            }
        }

        // Bornes horaires du créneau :
        //  - from/to        : labels lisibles pour l'affichage humain (hint)
        //  - minTime/maxTime: bornes INCLUSIVES pour Flatpickr, alignées
        //    EXACTEMENT sur TimeSlot::fromDateTime (source de vérité serveur).
        //
        // Rappel du enum :
        //   6 ≤ h < 20 → DAY        → clampe [06:00 → 19:59]
        //   20 ≤ h     → NIGHT      → clampe [20:00 → 23:59]
        //   h < 6      → ASTREINTE  → clampe [00:00 → 05:59]
        $slotRanges = [
            TimeSlot::DAY->value => [
                'from' => '06:00', 'to' => '20:00',
                'minTime' => '06:00', 'maxTime' => '19:59',
            ],
            TimeSlot::NIGHT->value => [
                'from' => '20:00', 'to' => '00:00',
                'minTime' => '20:00', 'maxTime' => '23:59',
            ],
            TimeSlot::ASTREINTE->value => [
                'from' => '00:00', 'to' => '06:00',
                'minTime' => '00:00', 'maxTime' => '05:59',
            ],
        ];

        return $this->render('client/pack-reserver.html.twig', [
            'pack'      => $pack,
            'form'      => $form->createView(),
            'slotRange' => $slotRanges[$pack->getTimeSlot()->value],
        ]);
    }

    #[Route('/mon-espace/historique', name: 'app_espace_client_history', methods: ['GET'])]
    public function history(BookingRepository $bookingRepository): Response
    {
        /** @var \App\Entity\User $user */
        $user = $this->getUser();
        $all  = $bookingRepository->findForClient($user);
        $now  = new \DateTimeImmutable();

        // Passées = startAt < now OU statut terminal (cancelled/refused).
        // Triées de la plus récente à la plus ancienne.
        $passees = array_values(array_filter($all, static function (Booking $b) use ($now): bool {
            $isFinalStatus = in_array($b->getStatus(), [Booking::STATUS_CANCELLED, Booking::STATUS_REFUSED], true);
            return $b->getStartAt() < $now || $isFinalStatus;
        }));
        usort($passees, static fn (Booking $a, Booking $b): int => $b->getStartAt() <=> $a->getStartAt());

        return $this->render('client/historique.html.twig', [
            'passees' => $passees,
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

    #[Route('/reservation/{ref}/annuler', name: 'app_booking_cancel', methods: ['POST'], requirements: ['ref' => 'SPT-[A-F0-9]{8}'])]
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

    #[Route('/mon-espace/mon-rdv/{reference}', name: 'app_espace_client_rdv', methods: ['GET'], requirements: ['reference' => 'SPT-[A-F0-9]{8}'])]
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
