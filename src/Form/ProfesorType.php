<?php

namespace App\Form;

use App\Entity\Curso;
use App\Entity\Profesor;
use Doctrine\ORM\EntityRepository;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\Extension\Core\Type\NumberType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

class ProfesorType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('nombre', TextType::class, ['attr' => ['class' => 'form-control']])
            ->add('apellido', TextType::class, ['attr' => ['class' => 'form-control']])
            ->add('dni', NumberType::class, ['attr' => ['class' => 'form-control']])
            ->add('email', EmailType::class, ['attr' => ['class' => 'form-control']])
            ->add('tel', NumberType::class, ['attr' => ['class' => 'form-control']])
            ->add('curso', EntityType::class, [
                'class' => Curso::class,
                'attr' => ['class' => 'form-control'],
                'choice_label' => 'nombre',
                'query_builder' => function (EntityRepository $er) {
                    $curso = $er->createQueryBuilder('c');
                    return $curso;
                },
                'multiple' => true,
                'expanded' => false,
                'label' => 'Cursos',
            ])
        ;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => Profesor::class,
        ]);
    }
}
