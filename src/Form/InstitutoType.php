<?php

namespace App\Form;

use App\Entity\Instituto;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\FileType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\Extension\Core\Type\PasswordType;
use Symfony\Component\Validator\Constraints\File;

class InstitutoType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $isEdit = $options['is_edit'];
        
        $builder
            ->add('nombre', TextType::class, [
                'label' => 'Nombre',
            ])->add('tel', TextType::class, [
                'label' => 'Teléfono',
                'required' => !$isEdit
            ])->add('email', TextType::class, [
                'label' => 'email',
                'required' => !$isEdit])
            ->add('password', PasswordType::class, [
                'label' => 'Password',
                'mapped' => false,
                'required' => !$isEdit,
            ])->add('dir', TextType::class, [
                'label' => 'Dirección',
                'required' => !$isEdit,
            ])->add('logo', FileType::class, [
                'label' => 'Logo',
                'mapped' => false,
                'required' => !$isEdit,
                'constraints' => [
                    new File([
                        'maxSize' => '5024000k',
                        'mimeTypes' => [
                            'image/jpeg',
                            'image/jpg',
                            'image/png',
                            'image/gif',
                            'image/bmp',
                            'image/webp',
                        ],
                        'mimeTypesMessage' => 'Por favor, sube una imagen válida. Los formatos permitidos son JPG, PNG, GIF, BMP o WEBP.',
                    ])
                ],
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => Instituto::class,
            'is_edit' => false,
        ]);
    }
}
