<?php

namespace App\Form;

use App\Entity\Curso;
use App\Entity\Evaluacion;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\DateType;
use Symfony\Component\Form\Extension\Core\Type\NumberType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

class EvaluacionType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $dateFormat = $options['date_format'];
        $symfonyFormat = $this->phpToSymfonyDateFormat($dateFormat);
        /** @var Curso|null $curso */
        $curso = $options['curso'];

        $builder
            ->add('nombre', TextType::class, [
                'required' => true,
                'attr' => ['class' => 'form-control', 'placeholder' => 'Ej: Trabajo práctico 1'],
                'label_attr' => ['class' => 'form-label required'],
                'label' => 'Nombre de la evaluación',
                'constraints' => [
                    new Assert\NotBlank(['message' => 'El nombre no puede estar vacío']),
                    new Assert\Length(['max' => 150, 'maxMessage' => 'El nombre no puede superar los {{ limit }} caracteres']),
                ],
            ])
            ->add('fecha', DateType::class, [
                'required' => true,
                'attr' => ['class' => 'form-control', 'placeholder' => $dateFormat],
                'label_attr' => ['class' => 'form-label required'],
                'widget' => 'single_text',
                'format' => $symfonyFormat,
                'html5' => false,
                'label' => 'Fecha',
                'constraints' => [
                    new Assert\NotNull(['message' => 'La fecha es obligatoria']),
                    // Advertencia, no bloqueo: puede querer registrar algo fuera del
                    // período (una recuperación, por ejemplo).
                    new Assert\Callback(function ($fecha, ExecutionContextInterface $context) use ($curso) {
                        if (!$fecha || !$curso) {
                            return;
                        }
                        $inicio = $curso->getFechaInicio();
                        $fin = $curso->getFechaFin();
                        if ($inicio && $fecha < $inicio) {
                            $context->buildViolation('La fecha es anterior al inicio del curso (' . $inicio->format('d/m/Y') . ').')
                                ->addViolation();
                        }
                        if ($fin && $fecha > $fin) {
                            $context->buildViolation('La fecha es posterior al fin del curso (' . $fin->format('d/m/Y') . ').')
                                ->addViolation();
                        }
                    }),
                ],
            ])
            ->add('peso', NumberType::class, [
                'required' => false,
                'scale' => 2,
                'attr' => ['class' => 'form-control', 'step' => '0.01', 'min' => '0.01'],
                'label_attr' => ['class' => 'form-label'],
                'label' => 'Peso en el promedio',
                'help' => 'Dejalo en 1 para que todas las evaluaciones pesen igual.',
                'constraints' => [
                    new Assert\Positive(['message' => 'El peso debe ser mayor que cero']),
                ],
            ])
            ->add('cuentaParaPromedio', CheckboxType::class, [
                'required' => false,
                'label' => 'Cuenta para el promedio',
                'label_attr' => ['class' => 'form-check-label'],
                'attr' => ['class' => 'form-check-input'],
                'help' => 'Desmarcalo para evaluaciones diagnósticas o de práctica.',
            ])
            ->add('descripcion', TextareaType::class, [
                'required' => false,
                'attr' => ['class' => 'form-control', 'rows' => 3],
                'label_attr' => ['class' => 'form-label'],
                'label' => 'Descripción (opcional)',
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => Evaluacion::class,
            'date_format' => 'd/m/Y',
            'curso' => null,
        ]);
    }

    private function phpToSymfonyDateFormat(string $phpFormat): string
    {
        return match ($phpFormat) {
            'd/m/Y' => 'dd/MM/yyyy',
            'm/d/Y' => 'MM/dd/yyyy',
            'Y-m-d' => 'yyyy-MM-dd',
            'd-m-Y' => 'dd-MM-yyyy',
            default => 'dd/MM/yyyy',
        };
    }
}
