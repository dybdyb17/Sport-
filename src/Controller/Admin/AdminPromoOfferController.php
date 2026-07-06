<?php

namespace App\Controller\Admin;

use App\Entity\PromoOffer;
use App\Repository\PromoOfferRepository;
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
    public function index(PromoOfferRepository $offers): Response
    {
        return $this->render('admin/promo_offers/index.html.twig', [
            'offers' => $offers->findForAdmin(),
        ]);
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
