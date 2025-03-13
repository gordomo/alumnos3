<?php

namespace App\Form;

use App\Entity\Alumno;
use App\Entity\AlumnosPagos;

use App\Entity\Curso;
use Doctrine\ORM\EntityRepository;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\DateType;
use Symfony\Component\Form\Extension\Core\Type\NumberType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

class AlumnosPagosType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $instituto = $options['instituto'];
        $builder
            ->add('fecha', DateType::class, ['widget' => 'single_text', 'html5' => true, 'attr' => ['class' => 'form-control']])
            ->add('monto', NumberType::class, 
                [
                    'attr' => [
                        'class' => 'form-control', 
                        'step' => '0.01', // Permitir hasta dos decimales
                        'placeholder' => 'Use punto como separador de decimales, por ejemplo 50250.20',
                    ]
                ])
            ->add('ano', TextType::class, ['attr' => ['class' => 'form-control']])
            ->add('mes', ChoiceType::class, ['attr' => ['class' => 'form-control predictivo'], 'required' => true, 'choices'  => [
                'Enero' => 1,
                'Febrero' => 2,
                'Marzo' => 3,
                'Abril' => 4,
                'Mayo' => 5,
                'Junio' => 6,
                'Julio' => 7,
                'Agosto' => 8,
                'Septiembre' => 9,
                'Octubre' => 10,
                'Noviembre' => 11,
                'Diciembre' => 12,
            ]])
            ->add('curso', EntityType::class, [
                'class' => Curso::class,
                'choice_label' => 'nombre',
                'query_builder' => function (EntityRepository $er) use ($instituto) {
                    $curso = $er->createQueryBuilder('c')->where('c.instituto = :instituto')->setParameter('instituto', $instituto);
                    return $curso;
                },
                'multiple' => false,
                'expanded' => false,
                'required' => false,
                'label' => 'Cursos',
                'attr' => ['class' => 'form-control predictivo']
            ])
            ->add('metodoPago', ChoiceType::class, [
                'choices' => [
                    'Efectivo' => 'efectivo',
                    'Tarjeta' => 'tarjeta',
                    'Transferencia' => 'transferencia'
                ],
                'required' => true,
                'label' => 'Método de Pago',
                'attr' => ['class' => 'form-control']
            ])
        ;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => AlumnosPagos::class,
            'instituto' => false,
        ]);
    }
}
