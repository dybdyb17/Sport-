<?php

namespace App\Service;

use App\Entity\Booking;
use App\Entity\Enum\AuditAction;
use App\Entity\Enum\BookingFormat;
use App\Entity\Enum\PackRequestStatus;
use App\Entity\Enum\PackType;
use App\Entity\Enum\TimeSlot;
use App\Entity\FoundingClaim;
use App\Entity\PendingPackRequest;
use App\Entity\Subscription;
use App\Repository\AuditLogRepository;
use App\Repository\BookingRepository;
use App\Repository\FoundingClaimRepository;
use App\Repository\PendingPackRequestRepository;
use App\Repository\SubscriptionRepository;

/**
 * Construit le journal unifié des paiements admin.
 *
 * Trois sources fusionnées en événements homogènes triés par paidAt DESC :
 *   1. Bookings encaissés (paymentMethod IS NOT NULL, coveredBy IS NULL)
 *   2. Subscriptions payés (paidAt IS NOT NULL)
 *   3. FoundingClaims payés (paidAt IS NOT NULL)
 *
 * + Deux sources d'événements "à encaisser" (argent attendu pas encore en caisse) :
 *   a) Bookings confirmed, coveredBy NULL, paymentMethod NULL
 *   b) PendingPackRequest PENDING avec paymentMethod IN (cash, card)
 *
 * RÈGLE COMPTABLE ABSOLUE : les séances couvertes (coveredBy = subscription | founding)
 * ne comptent JAMAIS ici — l'argent a déjà été comptabilisé à l'achat du pack /
 * de l'offre. Les inclure = double comptage.
 */
class PaymentJournalBuilder
{
    public function __construct(
        private readonly BookingRepository $bookings,
        private readonly SubscriptionRepository $subscriptions,
        private readonly FoundingClaimRepository $foundings,
        private readonly PendingPackRequestRepository $pendingPacks,
        private readonly AuditLogRepository $auditLogs,
        private readonly PricingCalculator $pricing,
    ) {}

    /**
     * @param array{
     *     type?: string,        // all | seance | pack | fondateur
     *     method?: string,      // all | stripe | cash | card
     *     status?: string,      // all | paid | pending
     *     coachId?: int|null,
     *     from: \DateTimeImmutable,
     *     to: \DateTimeImmutable,
     * } $filters
     *
     * @return array{
     *     events: array<int, array<string, mixed>>,
     *     totals: array{total: float, stripe: float, onSite: float, pending: float}
     * }
     */
    public function build(array $filters): array
    {
        $from = $filters['from'];
        $to   = $filters['to'];

        $events = [];

        // ── 1. Séances encaissées ─────────────────────────────────────────
        $paidBookings = $this->bookings->createQueryBuilder('b')
            ->leftJoin('b.client', 'cl')->addSelect('cl')
            ->leftJoin('b.coach', 'co')->addSelect('co')
            ->leftJoin('co.user', 'cou')->addSelect('cou')
            ->leftJoin('b.paymentDeclaredBy', 'pdb')->addSelect('pdb')
            ->where('b.paymentMethod IS NOT NULL')
            ->andWhere('b.coveredBy IS NULL')
            ->andWhere('b.paymentDeclaredAt BETWEEN :from AND :to')
            ->setParameter('from', $from)
            ->setParameter('to', $to)
            ->orderBy('b.paymentDeclaredAt', 'DESC')
            ->getQuery()->getResult();

        // Une seule requête AuditLog groupée pour récupérer la source (dashboard vs scan)
        $bookingIds = array_map(fn(Booking $b) => $b->getId(), $paidBookings);
        $bookingSources = $this->fetchAuditSourcesForBookings($bookingIds);

        foreach ($paidBookings as $b) {
            $method   = $b->getPaymentMethod();
            $source   = $bookingSources[$b->getId()] ?? null;
            $declared = $b->getPaymentDeclaredBy();

            $canal = null;
            if ($method === 'stripe') {
                $canal = 'en ligne';
            } elseif ($source === 'checkin_scan') {
                $canal = 'scan QR';
            } elseif ($source === 'coach_dashboard') {
                $canal = 'dashboard coach';
            }

            $stripeUrl = null;
            if ($method === 'stripe') {
                $pi = $b->getStripeData()['payment_intent_id'] ?? null;
                if ($pi) {
                    $stripeUrl = 'https://dashboard.stripe.com/payments/' . $pi;
                }
            }

            $events[] = [
                'paidAt'      => $b->getPaymentDeclaredAt(),
                'type'        => 'seance',
                'clientName'  => $b->getClient()?->getNomComplet() ?? '—',
                'coachId'     => $b->getCoach()?->getId(),
                'coachName'   => $b->getCoach()?->getNomComplet(),
                'detail'      => sprintf('%s · %s', $b->getReference(), $b->getCoach()?->getNomComplet() ?? '—'),
                'method'      => $method,
                'validatedBy' => $declared?->getNomComplet(),
                'canal'       => $canal,
                'amount'      => (float) $b->getPrice(),
                'stripeUrl'   => $stripeUrl,
                'pending'     => false,
            ];
        }

        // ── 2. Packs payés ────────────────────────────────────────────────
        $paidSubs = $this->subscriptions->createQueryBuilder('s')
            ->leftJoin('s.client', 'cl')->addSelect('cl')
            ->leftJoin('s.coach', 'co')->addSelect('co')
            ->leftJoin('co.user', 'cou')->addSelect('cou')
            ->where('s.paidAt IS NOT NULL')
            ->andWhere('s.paidAt BETWEEN :from AND :to')
            ->setParameter('from', $from)
            ->setParameter('to', $to)
            ->orderBy('s.paidAt', 'DESC')
            ->getQuery()->getResult();

        // Audit source groupé pour les Subscriptions (source pack_onsite_validation
        // + validated_by depuis details JSON).
        $subIds     = array_map(fn(Subscription $s) => $s->getId(), $paidSubs);
        $subAudits  = $this->fetchAuditForSubscriptions($subIds);

        foreach ($paidSubs as $s) {
            $method = $s->getPaymentMethod();
            $auditDetails = $subAudits[$s->getId()] ?? [];

            $validatedBy = null;
            $canal       = null;
            if ($method === 'stripe') {
                $canal = 'en ligne';
            } else {
                $canal = 'sur place';
                $validatedBy = $auditDetails['validated_by'] ?? null;
            }

            $stripeUrl = null;
            if ($method === 'stripe' && $s->getStripePaymentIntentId()) {
                $stripeUrl = 'https://dashboard.stripe.com/payments/' . $s->getStripePaymentIntentId();
            }

            // Montant TOTAL du pack = monthlyPrice (par personne) × personsCount
            $amount = (float) $s->getMonthlyPrice() * $s->getPersonsCount();

            $events[] = [
                'paidAt'      => $s->getPaidAt(),
                'type'        => 'pack',
                'clientName'  => $s->getClient()?->getNomComplet() ?? '—',
                'coachId'     => $s->getCoach()?->getId(),
                'coachName'   => $s->getCoach()?->getNomComplet(),
                'detail'      => sprintf(
                    '%s · %s · %s',
                    $s->getPackType()->label(),
                    $s->getFormat()->label(),
                    $s->getTimeSlot()->shortLabel(),
                ),
                'method'      => $method,
                'validatedBy' => $validatedBy,
                'canal'       => $canal,
                'amount'      => $amount,
                'stripeUrl'   => $stripeUrl,
                'pending'     => false,
            ];
        }

        // ── 3. Fondateurs payés ───────────────────────────────────────────
        $paidClaims = $this->foundings->createQueryBuilder('fc')
            ->leftJoin('fc.user', 'u')->addSelect('u')
            ->leftJoin('fc.offer', 'o')->addSelect('o')
            ->where('fc.paidAt IS NOT NULL')
            ->andWhere('fc.paidAt BETWEEN :from AND :to')
            ->setParameter('from', $from)
            ->setParameter('to', $to)
            ->orderBy('fc.paidAt', 'DESC')
            ->getQuery()->getResult();

        foreach ($paidClaims as $fc) {
            $stripeUrl = null;
            if ($fc->getStripePaymentIntentId()) {
                $stripeUrl = 'https://dashboard.stripe.com/payments/' . $fc->getStripePaymentIntentId();
            }

            $events[] = [
                'paidAt'      => $fc->getPaidAt(),
                'type'        => 'fondateur',
                'clientName'  => $fc->getUser()?->getNomComplet() ?? '—',
                'coachId'     => null,
                'coachName'   => null,
                'detail'      => $fc->getFoundingLabel(),
                'method'      => 'stripe',
                'validatedBy' => null,
                'canal'       => 'en ligne',
                'amount'      => (float) ($fc->getOffer()?->getPrice() ?? 0),
                'stripeUrl'   => $stripeUrl,
                'pending'     => false,
            ];
        }

        // ── 4. Séances confirmées à encaisser (pending) ───────────────────
        //     Créées dans la période (b.createdAt) — l'argent est attendu pour ces séances.
        $pendingBookings = $this->bookings->createQueryBuilder('b')
            ->leftJoin('b.client', 'cl')->addSelect('cl')
            ->leftJoin('b.coach', 'co')->addSelect('co')
            ->leftJoin('co.user', 'cou')->addSelect('cou')
            ->where('b.status = :confirmed')
            ->andWhere('b.paymentMethod IS NULL')
            ->andWhere('b.coveredBy IS NULL')
            ->andWhere('b.noShow = false')
            ->andWhere('b.createdAt BETWEEN :from AND :to')
            ->setParameter('confirmed', 'confirmed')
            ->setParameter('from', $from)
            ->setParameter('to', $to)
            ->orderBy('b.startAt', 'DESC')
            ->getQuery()->getResult();

        foreach ($pendingBookings as $b) {
            $events[] = [
                'paidAt'      => $b->getStartAt(),  // pour le tri : on utilise la date de séance
                'type'        => 'seance',
                'clientName'  => $b->getClient()?->getNomComplet() ?? '—',
                'coachId'     => $b->getCoach()?->getId(),
                'coachName'   => $b->getCoach()?->getNomComplet(),
                'detail'      => sprintf('%s · %s', $b->getReference(), $b->getCoach()?->getNomComplet() ?? '—'),
                'method'      => $b->getIntendedPaymentMethod(),
                'validatedBy' => null,
                'canal'       => null,
                'amount'      => (float) $b->getPrice(),
                'stripeUrl'   => null,
                'pending'     => true,
                'pendingLabel' => 'À encaisser',
            ];
        }

        // ── 5. Packs sur place en attente de validation coach ─────────────
        $pendingPprs = $this->pendingPacks->createQueryBuilder('p')
            ->leftJoin('p.client', 'cl')->addSelect('cl')
            ->leftJoin('p.coach', 'co')->addSelect('co')
            ->leftJoin('co.user', 'cou')->addSelect('cou')
            ->where('p.status = :pending')
            ->andWhere('p.paymentMethod IN (:onsite)')
            ->andWhere('p.createdAt BETWEEN :from AND :to')
            ->setParameter('pending', PackRequestStatus::PENDING)
            ->setParameter('onsite', ['cash', 'card'])
            ->setParameter('from', $from)
            ->setParameter('to', $to)
            ->orderBy('p.createdAt', 'DESC')
            ->getQuery()->getResult();

        foreach ($pendingPprs as $ppr) {
            $unitPrice = (float) $this->pricing->monthlyPackPrice(
                $ppr->getFormat(),
                $ppr->getPackType(),
                $ppr->getTimeSlot(),
                $ppr->isFullAccess(),
            );
            $totalAmount = $unitPrice * $ppr->getPersonsCount();

            $events[] = [
                'paidAt'      => $ppr->getCreatedAt(),
                'type'        => 'pack',
                'clientName'  => $ppr->getClient()->getNomComplet() ?? '—',
                'coachId'     => $ppr->getCoach()->getId(),
                'coachName'   => $ppr->getCoach()->getNomComplet(),
                'detail'      => sprintf(
                    '%s · %s · %s',
                    $ppr->getPackType()->label(),
                    $ppr->getFormat()->label(),
                    $ppr->getTimeSlot()->shortLabel(),
                ),
                'method'      => $ppr->getPaymentMethod(),
                'validatedBy' => null,
                'canal'       => null,
                'amount'      => $totalAmount,
                'stripeUrl'   => null,
                'pending'     => true,
                'pendingLabel' => 'Pack en attente de validation',
            ];
        }

        // ── Filtres en mémoire ────────────────────────────────────────────
        $type    = $filters['type']    ?? 'all';
        $method  = $filters['method']  ?? 'all';
        $status  = $filters['status']  ?? 'all';
        $coachId = $filters['coachId'] ?? null;

        $events = array_values(array_filter($events, function (array $e) use ($type, $method, $status, $coachId): bool {
            if ($type !== 'all' && $e['type'] !== $type) return false;
            if ($method !== 'all' && $e['method'] !== $method) return false;
            if ($status === 'paid' && $e['pending']) return false;
            if ($status === 'pending' && !$e['pending']) return false;
            if ($coachId !== null && $e['coachId'] !== $coachId) return false;
            return true;
        }));

        // Tri : les "à encaisser" en tête (par date décroissante) puis les payés (par paidAt DESC)
        usort($events, function (array $a, array $b): int {
            if ($a['pending'] !== $b['pending']) {
                return $a['pending'] ? -1 : 1;
            }
            return $b['paidAt'] <=> $a['paidAt'];
        });

        // ── Totaux (sur les événements filtrés) ───────────────────────────
        $totals = ['total' => 0.0, 'stripe' => 0.0, 'onSite' => 0.0, 'pending' => 0.0];
        foreach ($events as $e) {
            if ($e['pending']) {
                $totals['pending'] += $e['amount'];
            } else {
                $totals['total'] += $e['amount'];
                if ($e['method'] === 'stripe') {
                    $totals['stripe'] += $e['amount'];
                } elseif (in_array($e['method'], ['cash', 'card'], true)) {
                    $totals['onSite'] += $e['amount'];
                }
            }
        }

        return ['events' => $events, 'totals' => $totals];
    }

    /**
     * Récupère en UNE requête les sources (checkin_scan / coach_dashboard) des
     * audits PAYMENT_DECLARED pour les Booking donnés.
     *
     * @param int[] $bookingIds
     * @return array<int, string>  targetId → source
     */
    private function fetchAuditSourcesForBookings(array $bookingIds): array
    {
        if ($bookingIds === []) return [];

        $logs = $this->auditLogs->createQueryBuilder('a')
            ->where('a.action = :action')
            ->andWhere('a.targetType = :type')
            ->andWhere('a.targetId IN (:ids)')
            ->setParameter('action', AuditAction::PAYMENT_DECLARED)
            ->setParameter('type', 'Booking')
            ->setParameter('ids', $bookingIds)
            ->getQuery()->getResult();

        $out = [];
        foreach ($logs as $log) {
            $details = $log->getDetails();
            if (isset($details['source'])) {
                $out[$log->getTargetId()] = $details['source'];
            }
        }
        return $out;
    }

    /**
     * Récupère en UNE requête les details JSON des audits PAYMENT_DECLARED
     * pour les Subscription (pour piocher validated_by côté sur-place).
     *
     * @param int[] $subIds
     * @return array<int, array<string, mixed>>  targetId → details
     */
    private function fetchAuditForSubscriptions(array $subIds): array
    {
        if ($subIds === []) return [];

        $logs = $this->auditLogs->createQueryBuilder('a')
            ->where('a.action = :action')
            ->andWhere('a.targetType = :type')
            ->andWhere('a.targetId IN (:ids)')
            ->setParameter('action', AuditAction::PAYMENT_DECLARED)
            ->setParameter('type', 'Subscription')
            ->setParameter('ids', $subIds)
            ->getQuery()->getResult();

        $out = [];
        foreach ($logs as $log) {
            $out[$log->getTargetId()] = $log->getDetails();
        }
        return $out;
    }
}
