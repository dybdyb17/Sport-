<?php
namespace App\Twig;

use App\Entity\FoundingOffer;
use App\Service\FoundingOfferService;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

class FoundingOfferExtension extends AbstractExtension
{
    public function __construct(private readonly FoundingOfferService $service) {}

    public function getFunctions(): array
    {
        return [
            new TwigFunction('current_founding_offer', $this->currentFoundingOffer(...)),
        ];
    }

    public function currentFoundingOffer(): ?FoundingOffer
    {
        return $this->service->getActive();
    }
}
