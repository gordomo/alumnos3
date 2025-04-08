<?php

namespace App\Form;

use App\Entity\AlumnosPagos;
use App\Entity\Alumno;
use App\Entity\Vencimiento;
use App\Entity\Curso;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\DateType;
use Symfony\Component\Form\Extension\Core\Type\NumberType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\Extension\Core\Type\HiddenType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\NotBlank;
use Symfony\Component\Validator\Constraints\Positive;
use Symfony\Component\Validator\Constraints\Callback;
use Symfony\Component\Validator\Context\ExecutionContextInterface;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;

class AlumnosPagosType extends AbstractType
{
    private $entityManager;

    public function __construct(EntityManagerInterface $entityManager)
    {
        $this->entityManager = $entityManager;
    }

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $alumnos = $options['alumnos'];
        $cursos = $options['cursos'];
        $curso = $options['curso'];
        $vencimientos = $options['vencimientos'];

        
            $builder->add('alumno', EntityType::class, [
                'class' => Alumno::class,
                'choices' => $alumnos,
                'choice_label' => function(Alumno $alumno) {
                    return  $alumno->getApellido() . ' ' . $alumno->getNombre();
                },
                'label' => 'Alumno',
                'label_attr' => ['class' => 'form-label'],
                'required' => true,
                'attr' => ['class' => 'form-control chosen-select'],
                'constraints' => [
                    new NotBlank(['message' => 'El alumno es obligatorio'])
                ]
            ]);
        

        $builder
            ->add('fecha', DateType::class, [
                'widget' => 'single_text',
                'label' => 'Fecha',
                'label_attr' => ['class' => 'form-label'],
                'required' => true,
                'attr' => ['class' => 'form-control'],
                'constraints' => [
                    new NotBlank(['message' => 'La fecha es obligatoria'])
                ]
            ])
            ->add('mes', ChoiceType::class, [
                'choices' => [
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
                    'Diciembre' => 12
                ],
                'label' => 'Mes',
                'label_attr' => ['class' => 'form-label'],
                'required' => true,
                'attr' => ['class' => 'form-control chosen-select'],
                'constraints' => [
                    new NotBlank(['message' => 'El mes es obligatorio'])
                ]
            ])
            ->add('ano', ChoiceType::class, [
                'choices' => $this->getYearChoices(),
                'label' => 'Año',
                'label_attr' => ['class' => 'form-label'],
                'required' => true,
                'attr' => ['class' => 'form-control chosen-select'],
                'constraints' => [
                    new NotBlank(['message' => 'El año es obligatorio'])
                ]
            ])
            ->add('curso', EntityType::class, [
                'class' => Curso::class,
                'choices' => $cursos,
                'choice_label' => function(Curso $curso) {
                    return $curso->getNombre();
                },
                'placeholder' => 'Seleccione un curso',
                'label' => 'Curso', 
                'label_attr' => ['class' => 'form-label'],
                'required' => true,
                'attr' => ['class' => 'form-control chosen-select'],
                'constraints' => [
                    new NotBlank(['message' => 'El curso es obligatorio'])
                ]
            ])
            ->add('monto', NumberType::class, [
                'label' => 'Monto',
                'label_attr' => ['class' => 'form-label'],
                'required' => true,
                'scale' => 2,
                'attr' => ['class' => 'form-control'],
                'constraints' => [
                    new NotBlank(['message' => 'El monto es obligatorio']),
                    new Positive(['message' => 'El monto debe ser positivo'])
                ]
            ])
            ->add('metodoPago', ChoiceType::class, [
                'choices' => [
                    'Efectivo' => 'efectivo',
                    'Transferencia' => 'transferencia',
                    'Tarjeta de Crédito' => 'tarjeta_credito',
                    'Tarjeta de Débito' => 'tarjeta_debito',
                    'Otro' => 'otro'
                ],
                'label' => 'Método de Pago',
                'label_attr' => ['class' => 'form-label'],
                'required' => true,
                'attr' => ['class' => 'form-control chosen-select'],
                'constraints' => [
                    new NotBlank(['message' => 'El método de pago es obligatorio'])
                ]
            ])
            ->add('observacion', TextType::class, [
                'label' => 'Observación',
                'label_attr' => ['class' => 'form-label'],
                'required' => false,
                'attr' => ['class' => 'form-control']
            ]);

        
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => AlumnosPagos::class,
            'alumnos' => [],
            'cursos' => [],
            'curso' => null,
            'vencimientos' => []
        ]);
    }

    private function getYearChoices(): array
    {
        $currentYear = (int) date('Y');
        $choices = [];
        
        for ($i = 0; $i < 5; $i++) {
            $year = $currentYear - $i;
            $choices[$year] = $year;
        }
        
        return $choices;
    }

    private function getCursoChoices($cursos): array
    {
        $choices = [];
        foreach ($cursos as $curso) {
            $choices[$curso->getNombre()] = $curso->getId();
        }
        return $choices;
    }

    private function getAlumnoChoices($alumnos): array
    {
        $choices = [];
        foreach ($alumnos as $alumno) {
            $choices[$alumno->getNombre() . ' ' . $alumno->getApellido()] = $alumno->getId();
        }
        return $choices;
    }
}
