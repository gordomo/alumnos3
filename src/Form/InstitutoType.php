<?php

namespace App\Form;

use App\Entity\Instituto;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\FileType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\NumberType;
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
            ])
            ->add('tel', TextType::class, [
                'label' => 'Teléfono',
                'required' => !$isEdit
            ])
            ->add('email', TextType::class, [
                'label' => 'Email',
                'required' => !$isEdit
            ])
            ->add('dir', TextType::class, [
                'label' => 'Dirección',
                'required' => !$isEdit,
            ])
            ->add('password', PasswordType::class, [
                'label' => 'Contraseña',
                'mapped' => false,
                'required' => !$isEdit,
                'empty_data' => '',
                'help' => $isEdit ? 'Dejar en blanco para mantener la contraseña actual' : 'Contraseña inicial para el usuario del instituto'
            ])
            ->add('logo', FileType::class, [
                'label' => 'Logo',
                'mapped' => false,
                'required' => false,
                'constraints' => [
                    new File([
                        'maxSize' => '1024k',
                        'mimeTypes' => [
                            'image/jpeg',
                            'image/png',
                        ],
                        'mimeTypesMessage' => 'Por favor sube una imagen válida (JPG o PNG)',
                    ])
                ],
            ])
            // Condiciones de la suscripción. Son opcionales: vacías, el instituto usa el precio
            // global y no tiene mínimo.
            ->add('precioPorAlumno', NumberType::class, [
                'label' => 'Precio por alumn@',
                'required' => false,
                'scale' => 2,
                'html5' => true,
                'attr' => ['step' => '0.01', 'min' => '0'],
                'help' => 'Vacío = se usa el precio global.',
            ])
            ->add('minimoMensual', NumberType::class, [
                'label' => 'Mínimo mensual',
                'required' => false,
                'scale' => 2,
                'html5' => true,
                'attr' => ['step' => '0.01', 'min' => '0'],
                'help' => 'Nunca se factura menos que esto.',
            ])
            ->add('suscripcionExenta', CheckboxType::class, [
                'label' => 'Exento de suscripción',
                'required' => false,
                'help' => 'No se le factura ni se le limita nunca el acceso.',
            ])
        ;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => Instituto::class,
            'is_edit' => false,
        ]);
    }
}
