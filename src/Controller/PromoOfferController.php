<?php

namespace App\Controller;

use App\Entity\PromoOffer;
use App\Entity\PromoPurchase;
use App\Repository\PromoOfferRepository;
use App\Repository\PromoPurchaseRepository;
use App\Service\StripeCheckoutService;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class PromoOfferController extends AbstractController
{
    #[Route('/offres/paiement/succes', name: 'app_promo_offer_success', methods: ['GET'])]
    public function success(Request $request, StripeCheckoutService $stripeCheckout, LoggerInterface $logger): Response
    {
        try {
            $session = $stripeCheckout->retrieveSession((string) $request->query->get('session_id'));
            $purchase = $stripeCheckout->fulfillPromoOfferCheckout($session);
        } catch (\Throwable $exception) {
            $logger->warning('Promo offer checkout return could not be fulfilled immediately.', [
                'session_id' => (string) $request->query->get('session_id'),
                'reason' => $exception->getMessage(),
            ]);

            return $this->render('promo_offer/pending.html.twig');
        }

        return $this->redirectToRoute('app_promo_offer_ticket', ['reference' => $purchase->getReference()]);
    }

    #[Route('/offres/paiement/annule', name: 'app_promo_offer_cancel', methods: ['GET'])]
    public function cancel(Request $request, PromoPurchaseRepository $purchases, EntityManagerInterface $em): RedirectResponse
    {
        $reference = (string) $request->query->get('purchase');
        if ($reference !== '') {
            $purchase = $purchases->findOneBy(['reference' => $reference]);
            if ($purchase instanceof PromoPurchase && !$purchase->isPaid()) {
                $purchase->setStatus(PromoPurchase::STATUS_CANCELLED);
                $em->flush();
                return $this->redirectToRoute('app_promo_offer_show', ['slug' => $purchase->getOffer()->getSlug()]);
            }
        }

        return $this->redirectToRoute('app_home');
    }

    #[Route('/offres/ticket/{reference}', name: 'app_promo_offer_ticket', methods: ['GET'], requirements: ['reference' => 'OFR-[A-F0-9]{8}'])]
    public function ticket(string $reference, PromoPurchaseRepository $purchases): Response
    {
        $purchase = $purchases->findOneBy(['reference' => $reference]);
        if (!$purchase instanceof PromoPurchase) {
            throw $this->createNotFoundException();
        }

        return $this->render('promo_offer/ticket.html.twig', ['purchase' => $purchase]);
    }

    #[Route('/offres/{slug}', name: 'app_promo_offer_show', methods: ['GET'], requirements: ['slug' => '[a-z0-9-]+'])]
    public function show(string $slug, PromoOfferRepository $offers): Response
    {
        $offer = $offers->findOneBy(['slug' => $slug]);
        if (!$offer instanceof PromoOffer) {
            throw $this->createNotFoundException();
        }

        return $this->render('promo_offer/show.html.twig', ['offer' => $offer]);
    }

    #[Route('/offres/{slug}/payer', name: 'app_promo_offer_checkout', methods: ['POST'], requirements: ['slug' => '[a-z0-9-]+'])]
    public function checkout(
        string $slug,
        Request $request,
        PromoOfferRepository $offers,
        EntityManagerInterface $em,
        StripeCheckoutService $stripeCheckout,
        LoggerInterface $logger,
    ): RedirectResponse {
        $offer = $offers->findOneBy(['slug' => $slug]);
        if (!$offer instanceof PromoOffer) {
            throw $this->createNotFoundException();
        }
        if (!$offer->isCurrentlyAvailable()) {
            $this->addFlash('error', 'Cette offre n’est plus disponible.');
            return $this->redirectToRoute('app_promo_offer_show', ['slug' => $slug]);
        }
        if (!$this->isCsrfTokenValid('promo-offer-' . $offer->getId(), (string) $request->request->get('_token'))) {
            $this->addFlash('error', 'Token de sécurité invalide. Réessaie depuis la page de l’offre.');
            return $this->redirectToRoute('app_promo_offer_show', ['slug' => $slug]);
        }

        $name = trim((string) $request->request->get('buyer_name'));
        $email = mb_strtolower(trim((string) $request->request->get('buyer_email')));
        $phone = trim((string) $request->request->get('buyer_phone')) ?: null;

        if ($name === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $this->addFlash('error', 'Indique ton nom et une adresse email valide pour réserver.');
            return $this->redirectToRoute('app_promo_offer_show', ['slug' => $slug]);
        }

        // -5% Fondateur : basé UNIQUEMENT sur $this->getUser() (compte
        // authentifié), jamais sur buyer_email saisi. Le prix effectif est
        // figé ici → lu par promoPurchasePriceInCents à la création ET à la
        // vérification du montant au retour Stripe. Cohérence garantie.
        [$effectiveAmount, $isFounding] = $this->resolveEffectivePromoAmount($offer);

        $purchase = (new PromoPurchase())
            ->setOffer($offer)
            ->setBuyerName($name)
            ->setBuyerEmail($email)
            ->setBuyerPhone($phone)
            ->setAmount($effectiveAmount)
            ->setFoundingDiscountApplied($isFounding)
            ->setCurrency($offer->getCurrency())
            ->setIntendedPaymentMethod('stripe');

        $em->persist($purchase);
        $em->flush();

        try {
            $session = $stripeCheckout->createPromoOfferCheckout($purchase);
            return new RedirectResponse((string) $session->url, Response::HTTP_SEE_OTHER);
        } catch (\Throwable $exception) {
            $logger->error('Unable to create Stripe Checkout session for promo offer.', [
                'offer_id' => $offer->getId(),
                'purchase_id' => $purchase->getId(),
                'exception' => $exception,
            ]);
            $this->addFlash('error', 'Impossible d’ouvrir le paiement Stripe pour le moment. Réessaie dans un instant.');
            return $this->redirectToRoute('app_promo_offer_show', ['slug' => $slug]);
        }
    }

    /**
     * Réserver une offre pour paiement au club (espèces / CB).
     *
     * Crée un PromoPurchase status=pending + intendedPaymentMethod=cash sans
     * passer par Stripe. L'admin/coach valide ensuite à la venue du client
     * (route dédiée). Un email de confirmation est envoyé au client avec
     * les instructions ("viens au club, on te remettra ton QR").
     *
     * N'est autorisé que si l'offre a allowsOnSitePayment=true. Sinon 404.
     */
    #[Route('/offres/{slug}/reserver-au-club', name: 'app_promo_offer_reserve_onsite', methods: ['POST'], requirements: ['slug' => '[a-z0-9-]+'])]
    public function reserveOnsite(
        string $slug,
        Request $request,
        PromoOfferRepository $offers,
        EntityManagerInterface $em,
        \App\Service\MailerService $mailer,
        LoggerInterface $logger,
    ): RedirectResponse {
        $offer = $offers->findOneBy(['slug' => $slug]);
        if (!$offer instanceof PromoOffer) {
            throw $this->createNotFoundException();
        }
        if (!$offer->isCurrentlyAvailable()) {
            $this->addFlash('error', 'Cette offre n’est plus disponible.');
            return $this->redirectToRoute('app_promo_offer_show', ['slug' => $slug]);
        }
        if (!$offer->allowsOnSitePayment()) {
            // Paiement au club non activé pour cette offre → 404 (empêche
            // un client de forcer la route en tapant l'URL directement).
            throw $this->createNotFoundException();
        }
        if (!$this->isCsrfTokenValid('promo-offer-onsite-' . $offer->getId(), (string) $request->request->get('_token'))) {
            $this->addFlash('error', 'Token de sécurité invalide. Réessaie depuis la page de l’offre.');
            return $this->redirectToRoute('app_promo_offer_show', ['slug' => $slug]);
        }

        $name  = trim((string) $request->request->get('buyer_name'));
        $email = mb_strtolower(trim((string) $request->request->get('buyer_email')));
        $phone = trim((string) $request->request->get('buyer_phone')) ?: null;

        if ($name === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $this->addFlash('error', 'Indique ton nom et une adresse email valide pour réserver.');
            return $this->redirectToRoute('app_promo_offer_show', ['slug' => $slug]);
        }

        [$effectiveAmount, $isFounding] = $this->resolveEffectivePromoAmount($offer);

        $purchase = (new PromoPurchase())
            ->setOffer($offer)
            ->setBuyerName($name)
            ->setBuyerEmail($email)
            ->setBuyerPhone($phone)
            ->setAmount($effectiveAmount)
            ->setFoundingDiscountApplied($isFounding)
            ->setCurrency($offer->getCurrency())
            ->setIntendedPaymentMethod('cash');
        // status reste PENDING (défaut). paymentMethod / paidAt restent NULL
        // tant que l'admin/coach n'a pas validé au comptoir.

        $em->persist($purchase);
        $em->flush();

        // Email confirmation "réservation enregistrée, viens au club"
        try {
            $mailer->sendPromoReservationOnsite($purchase);
        } catch (\Throwable $e) {
            // On ne bloque pas le flow si l'email plante — la réservation
            // est en base, l'admin la voit et peut la traiter.
            $logger->error('Failed to send promo onsite reservation email.', [
                'purchase_id' => $purchase->getId(),
                'exception'   => $e,
            ]);
        }

        return $this->redirectToRoute('app_promo_offer_pending', ['reference' => $purchase->getReference()]);
    }

    /**
     * Page de confirmation "réservation enregistrée, en attente de paiement
     * au club". Accessible sans compte via la référence (unique + non
     * devinable puisque OFR-[A-F0-9]{8}).
     */
    #[Route('/offres/reservation/{reference}', name: 'app_promo_offer_pending', methods: ['GET'], requirements: ['reference' => 'OFR-[A-F0-9]{8}'])]
    public function reservationPending(
        string $reference,
        PromoPurchaseRepository $purchases,
    ): Response {
        $purchase = $purchases->findOneBy(['reference' => $reference]);
        if (!$purchase instanceof PromoPurchase) {
            throw $this->createNotFoundException();
        }
        return $this->render('promo_offer/pending.html.twig', ['purchase' => $purchase]);
    }

    /**
     * Calcule le prix effectif d'une offre pour l'utilisateur courant.
     *
     * Le -5% Fondateur est appliqué UNIQUEMENT si un user est connecté ET
     * est fondateur (jamais basé sur buyer_email — usurpation possible).
     * Le prix retourné est celui qui sera figé dans PromoPurchase.amount,
     * puis lu par Stripe à la création ET à la vérification du montant.
     *
     * @return array{0: string, 1: bool} [prix effectif au format DECIMAL, flag -5% appliqué]
     */
    private function resolveEffectivePromoAmount(PromoOffer $offer): array
    {
        $user = $this->getUser();
        $isFounding = $user instanceof \App\Entity\User && $user->isFoundingMember();
        if (!$isFounding) {
            return [$offer->getPrice(), false];
        }
        // -5% sur le prix de l'offre — arrondi 2 décimales cohérent avec le
        // setter setAmount() qui refera number_format 2 décimales.
        $reduced = number_format((float) $offer->getPrice() * 0.95, 2, '.', '');
        return [$reduced, true];
    }
}
