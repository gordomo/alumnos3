<?php

namespace App\Form;

use App\Entity\Curso;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

class CursoType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('nombre', TextType::class)

            ->add('dias', ChoiceType::class, ['required' => true, 'choices'  => [
                'Lunes' => 0,
                'Martes' => 1,
                'Miercoles' => 2,
                'Jueves' => 3,
                'Viernes' => 4,
                'Sábado' => 5,
                'Domingo' => 6,
            ],
                'multiple'=>true,
                'expanded'=>true,
                'mapped'=> false
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
