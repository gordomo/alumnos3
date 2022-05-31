<?php

namespace App\Form;

use App\Entity\Curso;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\NumberType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

class CursoType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('nombre', TextType::class, ['attr' => ['class' => 'form-control']])
            ->add('precio', TextType::class, ['attr' => ['class' => 'form-control']])
            ->add('duracion', NumberType::class, ['html5' => true, 'attr' => ['class' => 'form-control']])
            ->add('dias', ChoiceType::class, ['attr' => ['class' => 'form-control'], 'required' => true, 'choices'  => [
                'Lunes' => 'Lunes',
                'Martes' => 'Martes',
                'Miercoles' => 'Miercoles',
                'Jueves' => 'Jueves',
                'Viernes' => 'Viernes',
                'Sábado' => 'Sábado',
                'Domingo' => 'Domingo',
            ],
                'multiple'=>true,
                'expanded'=>false,
            ])
        ;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => Curso::class,
        ]);
    }
}
