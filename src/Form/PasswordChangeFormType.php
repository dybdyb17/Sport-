<?php

namespace App\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\PasswordType;
use Symfony\Component\Form\Extension\Core\Type\RepeatedType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\Length;
use Symfony\Component\Validator\Constraints\NotBlank;

class PasswordChangeFormType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('currentPassword', PasswordType::class, [
                'label'  => 'Mot de passe actuel',
                'mapped' => false,
                'attr'   => ['autocomplete' => 'current-password'],
                'constraints' => [
                    new NotBlank(['message' => 'Saisis ton mot de passe actuel.']),
                ],
            ])
            ->add('newPassword', RepeatedType::class, [
                'type'            => PasswordType::class,
                'mapped'          => false,
                'invalid_message' => 'Les deux mots de passe ne correspondent pas.',
                'first_options'   => [
                    'label' => 'Nouveau mot de passe',
                    'attr'  => ['autocomplete' => 'new-password'],
                ],
                'second_options'  => [
                    'label' => 'Confirme le nouveau mot de passe',
                    'attr'  => ['autocomplete' => 'new-password'],
                ],
                'constraints' => [
                    new NotBlank(['message' => 'Le nouveau mot de passe est obligatoire.']),
                    new Length([
                        'min'        => 8,
                        'minMessage' => 'Minimum {{ limit }} caractères.',
                        'max'        => 4096,
                    ]),
                ],
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([]);
    }
}
