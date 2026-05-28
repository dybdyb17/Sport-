<?php

namespace App\Form;

use App\Entity\Booking;
use App\Entity\Coach;
use App\Repository\CoachRepository;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\DateTimeType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

class BookingType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('coach', EntityType::class, [
                'class' => Coach::class,
                'choice_label' => 'nomComplet',
                'label' => 'Choisir ton coach',
                'placeholder' => 'Sélectionner un coach',
                'query_builder' => fn (CoachRepository $r) => $r->createQueryBuilder('c')->orderBy('c.id', 'ASC'),
            ])
            ->add('serviceType', ChoiceType::class, [
                'choices' => [
                    '🌙 Night Coach (21h-06h)' => 'night_coach',
                    '👥 Small Group' => 'small_group',
                    '☀️ Coaching Journée' => 'solo_day',
                    '🏋️ Groupe 6 pers.' => 'groupe_6',
                ],
                'label' => 'Type de séance',
                'placeholder' => 'Choisir une prestation',
            ])
            ->add('startAt', DateTimeType::class, [
                'label' => 'Date et heure',
                'widget' => 'single_text',
                'input' => 'datetime_immutable',
                'html5' => true,
            ])
            ->add('message', TextareaType::class, [
                'label' => 'Message au coach (optionnel)',
                'required' => false,
                'attr' => ['rows' => 3],
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => Booking::class,
        ]);
    }
}
