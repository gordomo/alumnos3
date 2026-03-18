<?php

namespace App\Form;

use App\Entity\MetodoPago;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\NotBlank;

class MetodoPagoType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('nombre', TextType::class, [
                'label' => 'Nombre del Método de Pago',
                'attr' => ['class' => 'form-control', 'placeholder' => 'Ej: Efectivo, Transferencia'],
                'constraints' => [
                    new NotBlank(['message' => 'El nombre es obligatorio'])
                ]
            ])
            ->add('activo', CheckboxType::class, [
                'label' => 'Activo',
                'required' => false,
                'attr' => ['class' => 'form-check-input'],
                'label_attr' => ['class' => 'form-check-label']
            ])
            ->add('orden', IntegerType::class, [
                'label' => 'Orden',
                'attr' => ['class' => 'form-control', 'min' => 0],
                'required' => false
            ])
        ;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => MetodoPago::class,
        ]);
    }
}
