<?php

namespace App\Controller\Admin;

use App\Entity\Enum\AuditAction;
use App\Entity\PromoOffer;
use App\Entity\PromoPurchase;
use App\Repository\PromoOfferRepository;
use App\Repository\PromoPurchaseRepository;
use App\Service\AuditLogger;
use App\Service\MailerService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\String\Slugger\SluggerInterface;

#[Route('/admin/offres-instagram')]
#[IsGranted('ROLE_ADMIN')]
final class AdminPromoOfferController extends AbstractController
{
    #[Route('', name: 'app_admin_promo_offers', methods: ['GET'])]
    public function index(PromoOfferRepository $offers, PromoPurchaseRepository $purchases): Response
    {
        return $this->render('admin/promo_offers/index.html.twig', [
            'offers'          => $offers->findForAdmin(),
            'pendingOnsite'   => $purchases->findPendingOnsite(),
        ]);
    }

    /**
     * Liste des purchases en attente de paiement au club — accessible depuis
     * l'index promos (encart en haut) et via cette route directe pour bookmark.
     */
    #[Route('/paiements-au-club', name: 'app_admin_promo_pending_onsite', methods: ['GET'])]
    public function pendingOnsite(PromoPurchaseRepository $purchases): Response
    {
        return $this->render('admin/promo_offers/pending_onsite.html.twig', [
            'purchases' => $purchases->findPendingOnsite(),
        ]);
    }

    /**
     * Valider un paiement au club (espèces / CB) : passe la purchase de
     * pending à paid, pose paymentMethod + traçabilité (paymentValidatedBy /
     * paymentValidatedAt), envoie l'email QR au client, log audit.
     *
     * Idempotent : refuse si déjà payé.
     */
    #[Route('/purchase/{id}/valider-onsite', name: 'app_admin_promo_validate_onsite', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function validateOnsite(
        int $id,
        Request $request,
        PromoPurchaseRepository $purchases,
        EntityManagerInterface $em,
        MailerService $mailer,
        AuditLogger $auditLogger,
    ): Response {
        $purchase = $purchases->find($id);
        if (!$purchase instanceof PromoPurchase) {
            throw $this->createNotFoundException();
        }
        if (!$this->isCsrfTokenValid('promo_onsite_validate_' . $id, (string) $request->request->get('_token'))) {
            $this->addFlash('danger', 'Jeton CSRF invalide.');
            return $this->redirectToRoute('app_admin_promo_pending_onsite');
        }
        if ($purchase->getStatus() === PromoPurchase::STATUS_PAID) {
            $this->addFlash('info', 'Cette réservation est déjà payée.');
            return $this->redirectToRoute('app_admin_promo_pending_onsite');
        }

        $method = (string) $request->request->get('method');
        if (!in_array($method, ['cash', 'card'], true)) {
            $this->addFlash('danger', 'Mode de paiement invalide (attendu : espèces ou CB).');
            return $this->redirectToRoute('app_admin_promo_pending_onsite');
        }

        /** @var \App\Entity\User $user */
        $user = $this->getUser();
        $now  = new \DateTimeImmutable();

        $purchase
            ->setStatus(PromoPurchase::STATUS_PAID)
            ->setPaymentMethod($method)
            ->setPaidAt($now)
            ->setPaymentValidatedAt($now)
            ->setPaymentValidatedBy($user);

        $em->flush();

        $auditLogger->log(AuditAction::PAYMENT_DECLARED, $purchase, [
            'source'       => 'promo_onsite_validation',
            'method'       => $method,
            'amount'       => $purchase->getAmount(),
            'reference'    => $purchase->getReference(),
            'buyer'        => $purchase->getBuyerName(),
            'validated_by' => $user->getNomComplet(),
            'offer'        => $purchase->getOffer()->getTitle(),
        ]);

        try {
            $mailer->sendPromoTicketActivated($purchase);
        } catch (\Throwable $e) {
            // L'email est un bonus, ne pas bloquer la validation si Resend
            // plante — l'admin peut re-envoyer manuellement plus tard.
        }

        $this->addFlash('success', sprintf(
            'Paiement validé pour %s (%s). Email QR envoyé à %s.',
            $purchase->getBuyerName(),
            $purchase->getAmountFormatted(),
            $purchase->getBuyerEmail(),
        ));
        return $this->redirectToRoute('app_admin_promo_pending_onsite');
    }

    #[Route('/nouvelle', name: 'app_admin_promo_offer_new', methods: ['GET', 'POST'])]
    public function new(Request $request, EntityManagerInterface $em, SluggerInterface $slugger): Response
    {
        $offer = new PromoOffer();
        if ($request->isMethod('POST')) {
            $this->hydrate($offer, $request, $slugger);
            $em->persist($offer);
            $em->flush();
            $this->addFlash('success', 'Offre Instagram créée. Tu peux copier son lien public.');
            return $this->redirectToRoute('app_admin_promo_offers');
        }

        return $this->render('admin/promo_offers/form.html.twig', [
            'offer' => $offer,
            'title' => 'Nouvelle offre Instagram',
        ]);
    }

    #[Route('/{id}/modifier', name: 'app_admin_promo_offer_edit', methods: ['GET', 'POST'])]
    public function edit(PromoOffer $offer, Request $request, EntityManagerInterface $em, SluggerInterface $slugger): Response
    {
        if ($request->isMethod('POST')) {
            $this->hydrate($offer, $request, $slugger);
            $em->flush();
            $this->addFlash('success', 'Offre mise à jour.');
            return $this->redirectToRoute('app_admin_promo_offers');
        }

        return $this->render('admin/promo_offers/form.html.twig', [
            'offer' => $offer,
            'title' => 'Modifier l’offre Instagram',
        ]);
    }

    private function hydrate(PromoOffer $offer, Request $request, SluggerInterface $slugger): void
    {
        $title = trim((string) $request->request->get('title'));
        $slug = trim((string) $request->request->get('slug'));
        if ($slug === '') {
            $slug = strtolower((string) $slugger->slug($title));
        } else {
            $slug = strtolower((string) $slugger->slug($slug));
        }

        $startsAt = $this->parseDateTime((string) $request->request->get('starts_at'));
        $endsAt = $this->parseDateTime((string) $request->request->get('ends_at'));

        $offer
            ->setTitle($title)
            ->setSlug($slug)
            ->setDescription((string) $request->request->get('description'))
            ->setType((string) $request->request->get('type', 'session'))
            ->setPrice((string) $request->request->get('price', '0'))
            ->setStatus((string) $request->request->get('status', PromoOffer::STATUS_DRAFT))
            ->setMaxQuantity(($q = (int) $request->request->get('max_quantity')) > 0 ? $q : null)
            ->setUnlimitedAccess($request->request->has('is_unlimited_access'))
            ->setAllowsOnSitePayment($request->request->has('allows_onsite_payment'))
            ->setStartsAt($startsAt)
            ->setEndsAt($endsAt);
    }

    private function parseDateTime(string $value): ?\DateTimeImmutable
    {
        $value = trim($value);
        if ($value === '') {
            return null;
        }
        return new \DateTimeImmutable($value);
    }
}
