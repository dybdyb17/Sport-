<?php

namespace App\Form;

use App\Entity\User;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\Extension\Core\Type\PasswordType;
use Symfony\Component\Form\Extension\Core\Type\TelType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\Length;
use Symfony\Component\Validator\Constraints\NotBlank;
use Symfony\Component\Validator\Constraints\Regex;

class RegistrationFormType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('nomComplet', TextType::class, [
                'label' => 'Nom complet',
                'attr' => ['placeholder' => 'Jean Dupont'],
            ])
            ->add('email', EmailType::class, [
                'label' => 'Email',
                'attr' => ['placeholder' => 'jean@exemple.fr'],
            ])
            ->add('phone', TelType::class, [
                'label' => 'Téléphone',
                'required' => true,
                'help' => 'Indispensable : ton coach pourra te joindre si besoin (créneau modifié, etc.)',
                'attr' => ['placeholder' => '06 12 34 56 78', 'autocomplete' => 'tel'],
                'constraints' => [
                    new NotBlank(['message' => 'Le numéro de téléphone est obligatoire pour réserver une séance.']),
                    new Regex([
                        'pattern' => '/^(?:(?:\+|00)33\s?|0)[1-9](?:[\s.-]?\d{2}){4}$/',
                        'message' => 'Numéro français invalide. Format attendu : 06 12 34 56 78 ou +33 6 12 34 56 78.',
                    ]),
                ],
            ])
            ->add('plainPassword', PasswordType::class, [
                // Non mappé : on ne stocke jamais le mot de passe en clair sur l'entité.
                'mapped' => false,
                'label' => 'Mot de passe',
                'attr' => ['autocomplete' => 'new-password'],
                'help' => '8 caractères minimum.',
                'constraints' => [
                    new NotBlank(['message' => 'Merci de saisir un mot de passe']),
                    new Length([
                        'min' => 8,
                        'minMessage' => 'Votre mot de passe doit faire au moins {{ limit }} caractères.',
                        'max' => 4096,
                    ]),
                ],
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => User::class,
        ]);
    }
}
