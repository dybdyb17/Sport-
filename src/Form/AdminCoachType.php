<?php

namespace App\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\Extension\Core\Type\NumberType;
use Symfony\Component\Form\Extension\Core\Type\PasswordType;
use Symfony\Component\Form\Extension\Core\Type\TelType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\Email;
use Symfony\Component\Validator\Constraints\Length;
use Symfony\Component\Validator\Constraints\NotBlank;
use Symfony\Component\Validator\Constraints\Positive;

class AdminCoachType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('email', EmailType::class, [
                'label' => 'Adresse email',
                'attr'  => ['placeholder' => 'coach@sportplus.fr'],
                'constraints' => [
                    new NotBlank(['message' => 'L\'email est obligatoire.']),
                    new Email(['message' => 'Adresse email invalide.']),
                ],
            ])
            ->add('nomComplet', TextType::class, [
                'label' => 'Nom complet',
                'attr'  => ['placeholder' => 'Prénom Nom'],
                'constraints' => [
                    new NotBlank(['message' => 'Le nom est obligatoire.']),
                    new Length(['max' => 100]),
                ],
            ])
            ->add('phone', TelType::class, [
                'label'    => 'Téléphone (optionnel)',
                'required' => false,
                'attr'     => ['placeholder' => '06 12 34 56 78'],
            ])
            ->add('plainPassword', PasswordType::class, [
                'label' => 'Mot de passe',
                'attr'  => ['autocomplete' => 'new-password'],
                'constraints' => [
                    new NotBlank(['message' => 'Le mot de passe est obligatoire.']),
                    new Length([
                        'min'        => 8,
                        'minMessage' => 'Minimum {{ limit }} caractères.',
                        'max'        => 4096,
                    ]),
                ],
            ])
            ->add('hourlyRate', NumberType::class, [
                'label'  => 'Tarif horaire (€)',
                'scale'  => 2,
                'attr'   => ['placeholder' => '40.00'],
                'constraints' => [
                    new NotBlank(['message' => 'Le tarif est obligatoire.']),
                    new Positive(['message' => 'Le tarif doit être positif.']),
                ],
            ])
            ->add('specialties', ChoiceType::class, [
                'label'    => 'Spécialités',
                'choices'  => [
                    'Musculation' => 'musculation',
                    'Cardio'      => 'cardio',
                    'Nutrition'   => 'nutrition',
                    'Crossfit'    => 'crossfit',
                    'Boxe'        => 'boxe',
                    'Yoga'        => 'yoga',
                    'Mobilité'    => 'mobilite',
                ],
                'multiple' => true,
                'expanded' => true,
                'required' => false,
                'data'     => [],
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => null, // Tableau simple — le controller construit User+Coach explicitement
        ]);
    }
}
