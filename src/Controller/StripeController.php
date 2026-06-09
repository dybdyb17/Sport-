<?php

namespace App\Controller;

use App\Entity\User;
use App\Service\FoundingOfferService;
use App\Service\StripeCheckoutService;
use Psr\Log\LoggerInterface;
use Stripe\Checkout\Session;
use Stripe\Exception\SignatureVerificationException;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

final class StripeController extends AbstractController
{
    #[Route('/offre/founding/paiement', name: 'app_founding_checkout', methods: ['POST'])]
    #[IsGranted('ROLE_CLIENT')]
    public function checkout(
        Request $request,
        FoundingOfferService $foundingOfferService,
        StripeCheckoutService $stripeCheckout,
        LoggerInterface $logger,
    ): RedirectResponse {
        if (!$this->isCsrfTokenValid('founding-checkout', (string) $request->request->get('_token'))) {
            $this->addFlash('error', 'Token de sécurité invalide. Veuillez réessayer.');

            return $this->redirectToRoute('app_founding_detail');
        }

        $user = $this->getUser();
        if (!$user instanceof User) {
            return $this->redirectToRoute('app_login');
        }

        $offer = $foundingOfferService->getActive();
        if ($offer === null) {
            $this->addFlash('error', 'Aucune offre Membre Fondateur n’est disponible actuellement.');

            return $this->redirectToRoute('app_founding_detail');
        }

        try {
            $session = $stripeCheckout->createFoundingCheckout($user, $offer);

            return new RedirectResponse((string) $session->url, Response::HTTP_SEE_OTHER);
        } catch (\Throwable $exception) {
            $logger->error('Unable to create the Stripe Checkout session.', [
                'user_id' => $user->getId(),
                'offer_id' => $offer->getId(),
                'exception' => $exception,
            ]);
            $this->addFlash('error', 'Impossible d’ouvrir le paiement Stripe pour le moment. Veuillez réessayer.');

            return $this->redirectToRoute('app_founding_detail');
        }
    }

    #[Route('/offre/founding/paiement/succes', name: 'app_founding_checkout_success', methods: ['GET'])]
    #[IsGranted('ROLE_CLIENT')]
    public function success(
        Request $request,
        StripeCheckoutService $stripeCheckout,
        LoggerInterface $logger,
    ): RedirectResponse
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            return $this->redirectToRoute('app_login');
        }

        try {
            $session = $stripeCheckout->retrieveSession((string) $request->query->get('session_id'));
            $claim = $stripeCheckout->fulfillFoundingCheckout($session, $user);
            $this->addFlash('success', sprintf(
                'Paiement confirmé ! Bienvenue, %s. Vous êtes maintenant %s.',
                $user->getNomComplet(),
                $claim->getFoundingLabel(),
            ));
        } catch (\Throwable $exception) {
            $logger->warning('Stripe Checkout return could not be fulfilled immediately.', [
                'user_id' => $user->getId(),
                'session_id' => (string) $request->query->get('session_id'),
                'exception' => $exception,
            ]);
            $this->addFlash('error', 'Le paiement n’a pas encore pu être confirmé. Votre compte sera mis à jour automatiquement dès la confirmation Stripe.');
        }

        return $this->redirectToRoute('app_founding_detail');
    }

    #[Route('/offre/founding/paiement/annule', name: 'app_founding_checkout_cancel', methods: ['GET'])]
    public function cancel(): RedirectResponse
    {
        $this->addFlash('info', 'Paiement annulé : aucun montant n’a été débité. Votre place n’a pas été activée.');

        return $this->redirectToRoute('app_founding_detail');
    }

    #[Route('/stripe/webhook', name: 'app_stripe_webhook', methods: ['POST'])]
    public function webhook(
        Request $request,
        StripeCheckoutService $stripeCheckout,
        LoggerInterface $logger,
    ): JsonResponse {
        try {
            $event = $stripeCheckout->constructWebhookEvent(
                $request->getContent(),
                (string) $request->headers->get('Stripe-Signature'),
            );
        } catch (SignatureVerificationException|\UnexpectedValueException $exception) {
            return $this->json(['error' => 'Invalid Stripe signature or payload.'], Response::HTTP_BAD_REQUEST);
        } catch (\RuntimeException $exception) {
            $logger->critical('Stripe webhook configuration error.', ['exception' => $exception]);

            return $this->json(['error' => 'Stripe webhook is not configured.'], Response::HTTP_SERVICE_UNAVAILABLE);
        }

        if (in_array($event->type, ['checkout.session.completed', 'checkout.session.async_payment_succeeded'], true)) {
            $session = $event->data->object;
            if ($session instanceof Session && $session->payment_status === 'paid') {
                try {
                    $stripeCheckout->fulfillFoundingCheckout($session);
                } catch (\LogicException $exception) {
                    $logger->warning('Stripe Checkout session could not be fulfilled.', [
                        'session_id' => $session->id,
                        'reason' => $exception->getMessage(),
                    ]);

                    return $this->json(['error' => 'Checkout session not fulfilled.'], Response::HTTP_UNPROCESSABLE_ENTITY);
                } catch (\Throwable $exception) {
                    $logger->error('Unexpected Stripe fulfillment failure.', [
                        'session_id' => $session->id,
                        'exception' => $exception,
                    ]);

                    return $this->json(['error' => 'Fulfillment failed.'], Response::HTTP_INTERNAL_SERVER_ERROR);
                }
            }
        }

        return $this->json(['received' => true]);
    }
}
