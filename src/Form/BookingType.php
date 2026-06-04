<?php

namespace App\Form;

use App\Entity\Booking;
use App\Entity\Coach;
use App\Entity\Enum\BookingFormat;
use App\Entity\Enum\PackType;
use App\Entity\Enum\TimeSlot;
use App\Repository\CoachRepository;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\DateTimeType;
use Symfony\Component\Form\Extension\Core\Type\EnumType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

class BookingType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('coach', EntityType::class, [
                'class'         => Coach::class,
                'choice_label'  => 'nomComplet',
                'label'         => 'Choisir ton coach',
                'placeholder'   => 'Sélectionner un coach',
                'query_builder' => fn (CoachRepository $r) => $r->createQueryBuilder('c')->orderBy('c.id', 'ASC'),
            ])
            ->add('format', EnumType::class, [
                'class'        => BookingFormat::class,
                'choice_label' => fn (BookingFormat $f) => $f->label(),
                'label'        => 'Format de séance',
                'expanded'     => false,
                'multiple'     => false,
                'attr'         => ['class' => 'booking-select', 'id' => 'booking_format'],
            ])
            ->add('timeSlot', EnumType::class, [
                'class'        => TimeSlot::class,
                'choice_label' => fn (TimeSlot $s) => $s->label(),
                'label'        => 'Créneau horaire',
                'expanded'     => false,
                'multiple'     => false,
                'attr'         => ['class' => 'booking-select', 'id' => 'booking_timeslot'],
            ])
            // Nombre de participants (GROUP uniquement) — géré dans le controller
            ->add('personsCount', ChoiceType::class, [
                'label'    => 'Nombre de participants (Groupe)',
                'choices'  => [4 => 4, 5 => 5, 6 => 6],
                'data'     => 4,
                'mapped'   => false,
                'required' => false,
                'attr'     => ['id' => 'field-persons-count'],
            ])
            // Type d'engagement : séance unique ou pack mensuel
            ->add('packType', EnumType::class, [
                'class'        => PackType::class,
                'choice_label' => fn (PackType $p) => $p->label(),
                'label'        => 'Type d\'engagement',
                'expanded'     => false,
                'multiple'     => false,
                'data'         => PackType::SINGLE,
                'mapped'       => false,
                'attr'         => ['class' => 'booking-select', 'id' => 'booking_packtype'],
            ])
            ->add('fullAccess', CheckboxType::class, [
                'label'    => 'Ajouter l\'accès salle 24h/24 — Solo +30€ · Duo +30€/pers · Group +25€/pers',
                'required' => false,
                'mapped'   => false,
            ])
            ->add('startAt', DateTimeType::class, [
                'label'  => 'Date et heure de début',
                'widget' => 'single_text',
                'input'  => 'datetime_immutable',
                'html5'  => true,
            ])
            ->add('message', TextareaType::class, [
                'label'    => 'Message au coach (optionnel)',
                'required' => false,
                'attr'     => ['rows' => 3, 'maxlength' => 500],
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => Booking::class,
        ]);
    }
}
