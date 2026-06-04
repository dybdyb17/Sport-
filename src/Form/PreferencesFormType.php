<?php

namespace App\Form;

use App\Entity\Coach;
use App\Entity\Enum\TimeSlot;
use App\Entity\User;
use App\Repository\CoachRepository;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\EnumType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\Length;

class PreferencesFormType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('preferredTimeSlot', EnumType::class, [
                'class'        => TimeSlot::class,
                'choice_label' => fn (TimeSlot $slot) => $slot->label(),
                'required'     => false,
                'placeholder'  => '— Pas de préférence —',
                'label'        => 'Créneau favori',
                'attr'         => ['class' => 'pref-select'],
            ])
            ->add('preferredCoach', null, [
                'class'        => Coach::class,
                'choice_label' => fn (Coach $c) => $c->getNomComplet(),
                'required'     => false,
                'placeholder'  => '— Pas de préférence —',
                'label'        => 'Coach favori',
                'query_builder' => fn (CoachRepository $r) => $r->createQueryBuilder('c')
                    ->join('c.user', 'u')
                    ->orderBy('u.nomComplet', 'ASC'),
                'attr' => ['class' => 'pref-select'],
            ])
            ->add('goal', ChoiceType::class, [
                'choices' => [
                    'Perte de poids'        => 'perte_poids',
                    'Prise de masse'        => 'prise_masse',
                    'Améliorer mon sommeil' => 'sommeil',
                    'Gestion du stress'     => 'stress',
                    'Mobilité / souplesse'  => 'mobilite',
                    'Performance / compétition' => 'performance',
                    'Forme générale'        => 'forme',
                ],
                'required'     => false,
                'placeholder'  => '— Pas défini —',
                'label'        => 'Mon objectif principal',
                'attr'         => ['class' => 'pref-select'],
            ])
            ->add('coachNotes', TextareaType::class, [
                'required'    => false,
                'label'       => 'Notes pour mes coachs',
                'attr'        => [
                    'rows'        => 4,
                    'placeholder' => 'Blessures, contre-indications, attentes particulières… Tout ce que les coachs doivent savoir avant ta séance.',
                ],
                'constraints' => [new Length(['max' => 1000])],
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => User::class,
        ]);
    }
}
