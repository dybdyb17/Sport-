<?php

namespace App\Service;

use App\Entity\FoundingClaim;
use App\Entity\FoundingOffer;
use App\Entity\User;
use App\Repository\FoundingClaimRepository;
use App\Repository\FoundingOfferRepository;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

class FoundingOfferService
{
    private ?FoundingOffer $cache = null;
    private bool $loaded = false;

    public function __construct(
        private readonly FoundingOfferRepository $offerRepo,
        private readonly FoundingClaimRepository $claimRepo,
        private readonly EntityManagerInterface $em,
        private readonly MailerService $mailer,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function getActive(): ?FoundingOffer
    {
        if (!$this->loaded) {
            $this->cache = $this->offerRepo->findCurrent();
            $this->loaded = true;
        }

        return $this->cache;
    }

    public function claimPaid(
        User $user,
        FoundingOffer $offer,
        string $stripeCheckoutSessionId,
        ?string $stripePaymentIntentId,
    ): FoundingClaim {
        /** @var array{claim: FoundingClaim, created: bool} $result */
        $result = $this->em->wrapInTransaction(function (EntityManagerInterface $em) use (
            $user,
            $offer,
            $stripeCheckoutSessionId,
            $stripePaymentIntentId,
        ): array {
            $existingBySession = $this->claimRepo->findOneBy([
                'stripeCheckoutSessionId' => $stripeCheckoutSessionId,
            ]);
            if ($existingBySession instanceof FoundingClaim) {
                return ['claim' => $existingBySession, 'created' => false];
            }

            $existingForUser = $this->claimRepo->findForUser($user);
            if ($existingForUser instanceof FoundingClaim) {
                return ['claim' => $existingForUser, 'created' => false];
            }

            $lockedOffer = $em->find(FoundingOffer::class, $offer->getId(), LockMode::PESSIMISTIC_WRITE);
            if (!$lockedOffer instanceof FoundingOffer || !$lockedOffer->isStillRunning()) {
                throw new \LogicException('L’offre Membre Fondateur n’est plus active.');
            }
            if (!$lockedOffer->hasPlacesLeft()) {
                throw new \LogicException('L’offre est complète — toutes les places ont été prises.');
            }

            $claim = (new FoundingClaim())
                ->setOffer($lockedOffer)
                ->setUser($user)
                ->setClaimNumber($this->claimRepo->nextClaimNumber($lockedOffer))
                ->setStripeCheckoutSessionId($stripeCheckoutSessionId)
                ->setStripePaymentIntentId($stripePaymentIntentId)
                ->setPaidAt(new \DateTimeImmutable());

            $lockedOffer->incrementPlacesTaken();
            $em->persist($claim);

            return ['claim' => $claim, 'created' => true];
        });

        if ($result['created']) {
            $this->sendWelcomeNotifications($result['claim']);
        }

        return $result['claim'];
    }

    public function consumeSessionFor(User $user): bool
    {
        $claim = $this->claimRepo->findForUser($user);
        if (null === $claim || !$claim->hasSessionsLeft()) {
            return false;
        }

        $claim->consumeSession();
        $this->em->flush();

        return true;
    }

    public function markBilanDone(User $user): void
    {
        $claim = $this->claimRepo->findForUser($user);
        if (null === $claim) {
            return;
        }

        $claim->setBilanDoneAt(new \DateTimeImmutable());
        $this->em->flush();
    }

    private function sendWelcomeNotifications(FoundingClaim $claim): void
    {
        try {
            $this->mailer->sendFoundingWelcomeToUser($claim);
            $this->mailer->sendNewFoundingAlertToAdmin($claim);
        } catch (\Throwable $exception) {
            $this->logger->error('Founding member payment succeeded, but notification emails failed.', [
                'claim_id' => $claim->getId(),
                'stripe_checkout_session_id' => $claim->getStripeCheckoutSessionId(),
                'exception' => $exception,
            ]);
        }
    }
}
