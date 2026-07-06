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

        $purchase = (new PromoPurchase())
            ->setOffer($offer)
            ->setBuyerName($name)
            ->setBuyerEmail($email)
            ->setBuyerPhone($phone)
            ->setAmount($offer->getPrice())
            ->setCurrency($offer->getCurrency());

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
}
