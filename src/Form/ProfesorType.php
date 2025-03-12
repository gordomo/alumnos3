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
        $isEdit = $options['is_edit'];
        $instituto = $options['instituto'];
        $builder
            ->add('nombre', TextType::class, ['attr' => ['class' => 'form-control'], 'required' => !$isEdit, 'label_attr'=> ['class'=> 'form-label']])
            ->add('apellido', TextType::class, ['attr' => ['class' => 'form-control'], 'label_attr'=> ['class'=> 'form-label']])
            ->add('dni', NumberType::class, ['attr' => ['class' => 'form-control'], 'label_attr'=> ['class'=> 'form-label'],])
            ->add('email', EmailType::class, ['attr' => ['class' => 'form-control'], 'label_attr'=> ['class'=> 'form-label'],])
            ->add('tel', NumberType::class, ['attr' => ['class' => 'form-control'], 'label_attr'=> ['class'=> 'form-label'],])
            ->add('precioHora', NumberType::class, ['html5' => true,'attr' => ['class' => 'form-control'], 'label_attr'=> ['class'=> 'form-label'],])
            ->add('viatico', NumberType::class, ['html5' => true,'attr' => ['class' => 'form-control'], 'required' => false, 'label_attr'=> ['class'=> 'form-label'],])
            ->add('curso', EntityType::class, [
                'class' => Curso::class,
                'attr' => ['class' => 'form-control'],
                'label_attr'=> ['class'=> 'form-label required'],
                'choice_label' => 'nombre',
                'query_builder' => function (EntityRepository $er) use ($instituto) {
                    $curso = $er->createQueryBuilder('c')->where('c.instituto = :instituto')->setParameter('instituto', $instituto);
                    return $curso;
                },
                'multiple' => true,
                'expanded' => false,
                'label' => 'Cursos',
                'required' => false,
            ])
        ;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => Profesor::class,
            'is_edit' => false,
            'instituto' => false,
        ]);
    }
}
