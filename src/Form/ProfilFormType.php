<?php

namespace App\Form;

use App\Entity\User;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\Extension\Core\Type\FileType;
use Symfony\Component\Form\Extension\Core\Type\TelType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\Email;
use Symfony\Component\Validator\Constraints\File;
use Symfony\Component\Validator\Constraints\Length;
use Symfony\Component\Validator\Constraints\NotBlank;
use Symfony\Component\Validator\Constraints\Regex;

class ProfilFormType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('nomComplet', TextType::class, [
                'label' => 'Nom complet',
                'constraints' => [
                    new NotBlank(['message' => 'Le nom est obligatoire.']),
                    new Length(['max' => 100]),
                ],
            ])
            ->add('email', EmailType::class, [
                'label' => 'Adresse email',
                'constraints' => [
                    new NotBlank(['message' => 'L\'email est obligatoire.']),
                    new Email(['message' => 'Adresse email invalide.']),
                ],
            ])
            ->add('phone', TelType::class, [
                'label'    => 'Téléphone',
                'required' => true,
                'help'     => 'Indispensable : ton coach peut t\'appeler si besoin.',
                'attr'     => ['placeholder' => '06 12 34 56 78', 'autocomplete' => 'tel'],
                'constraints' => [
                    new NotBlank(['message' => 'Le numéro de téléphone est obligatoire.']),
                    new Regex([
                        'pattern' => '/^(?:(?:\+|00)33\s?|0)[1-9](?:[\s.-]?\d{2}){4}$/',
                        'message' => 'Numéro français invalide. Format attendu : 06 12 34 56 78 ou +33 6 12 34 56 78.',
                    ]),
                ],
            ])
            ->add('avatar', FileType::class, [
                'label'    => 'Photo de profil',
                'required' => false,
                'mapped'   => false,
                'help'     => 'JPG, PNG ou WebP · 5 Mo max · l\'image sera redimensionnée à 400×400.',
                'attr'     => [
                    'accept' => 'image/jpeg,image/png,image/webp',
                ],
                'constraints' => [
                    new File([
                        'maxSize'          => '5M',
                        'mimeTypes'        => ['image/jpeg', 'image/png', 'image/webp'],
                        'mimeTypesMessage' => 'Format non supporté — utilise du JPG, PNG ou WebP.',
                        'maxSizeMessage'   => 'L\'image dépasse 5 Mo, choisis un fichier plus léger.',
                    ]),
                ],
            ])
            ->add('removeAvatar', CheckboxType::class, [
                'label'    => 'Retirer ma photo actuelle',
                'required' => false,
                'mapped'   => false,
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => User::class,
        ]);
    }
}
