<?php
namespace App\Service;

use App\Entity\FoundingClaim;
use App\Entity\FoundingOffer;
use App\Entity\User;
use App\Repository\FoundingClaimRepository;
use App\Repository\FoundingOfferRepository;
use Doctrine\ORM\EntityManagerInterface;

class FoundingOfferService
{
    private ?FoundingOffer $cache = null;
    private bool $loaded = false;

    public function __construct(
        private readonly FoundingOfferRepository $offerRepo,
        private readonly FoundingClaimRepository $claimRepo,
        private readonly EntityManagerInterface  $em,
        private readonly MailerService           $mailer,
    ) {}

    public function getActive(): ?FoundingOffer
    {
        if (!$this->loaded) {
            $this->cache  = $this->offerRepo->findCurrent();
            $this->loaded = true;
        }
        return $this->cache;
    }

    public function claim(User $user): FoundingClaim
    {
        $offer = $this->getActive();
        if (null === $offer) throw new \LogicException('Aucune offre Founding active pour le moment.');
        if (!$offer->hasPlacesLeft()) throw new \LogicException('L\'offre est complète — toutes les places ont été prises.');
        if (null !== $this->claimRepo->findForUser($user)) throw new \LogicException('Vous êtes déjà Membre Fondateur.');

        $claim = new FoundingClaim();
        $claim->setOffer($offer)->setUser($user)->setClaimNumber($this->claimRepo->nextClaimNumber($offer));
        $offer->incrementPlacesTaken();

        $this->em->persist($claim);
        $this->em->flush();

        $this->mailer->sendFoundingWelcomeToUser($claim);
        $this->mailer->sendNewFoundingAlertToAdmin($claim);

        return $claim;
    }

    public function consumeSessionFor(User $user): bool
    {
        $claim = $this->claimRepo->findForUser($user);
        if (null === $claim || !$claim->hasSessionsLeft()) return false;
        $claim->consumeSession();
        $this->em->flush();
        return true;
    }

    public function markBilanDone(User $user): void
    {
        $claim = $this->claimRepo->findForUser($user);
        if (null === $claim) return;
        $claim->setBilanDoneAt(new \DateTimeImmutable());
        $this->em->flush();
    }
}
