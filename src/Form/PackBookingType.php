<?php

namespace App\Form;

use App\Entity\Coach;
use App\Repository\CoachRepository;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\DateTimeType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * Réservation d'une séance avec un pack DÉJÀ acheté.
 *
 * Volontairement séparé de BookingType : quand le client réserve avec son
 * pack, le format / timeSlot / packType / personsCount / fullAccess sont
 * TOUS déterminés par le pack lui-même. Il n'y a pas non plus de mode de
 * paiement (déjà payé). Donc on ne garde que 3 champs, avec des libellés
 * adaptés au contexte "réserver dans mon pack".
 *
 * Un form dédié est plus lisible et évite d'entrelacer une logique
 * conditionnelle dans BookingType.
 */
class PackBookingType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('coach', EntityType::class, [
                'class'         => Coach::class,
                'choice_label'  => 'nomComplet',
                'label'         => 'Coach',
                'placeholder'   => 'Sélectionner un coach',
                'query_builder' => fn (CoachRepository $r) => $r->createQueryBuilder('c')->orderBy('c.id', 'ASC'),
            ])
            ->add('startAt', DateTimeType::class, [
                'widget'   => 'single_text',
                'label'    => 'Date et heure de la séance',
                'html5'    => true,
                // Le contrôleur vérifie ensuite que l'heure tombe bien dans
                // la plage horaire du pack (DAY/NIGHT/ASTREINTE) via
                // TimeSlot::fromDateTime — pas la peine d'imposer des min/max
                // ici qui pénaliseraient le picker natif.
            ])
            ->add('message', TextareaType::class, [
                'required' => false,
                'label'    => 'Un mot pour ton coach (optionnel)',
                'attr'     => ['rows' => 3, 'maxlength' => 500],
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        // Pas de data_class : on récupère les valeurs bruts dans l'action
        // (coach = objet, startAt = DateTimeImmutable, message = string?).
        $resolver->setDefaults([
            'csrf_protection' => true,
        ]);
    }
}
