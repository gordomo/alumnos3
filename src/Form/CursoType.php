<?php

namespace App\Form;

use App\Entity\Curso;
use App\Entity\Profesor;
use Doctrine\ORM\EntityRepository;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CollectionType;
use Symfony\Component\Form\Extension\Core\Type\DateType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormError;
use Symfony\Component\Form\FormEvent;
use Symfony\Component\Form\FormEvents;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\Callback;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

class CursoType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $instituto = $options['instituto'];
        $dateFormat = $options['date_format'] ?? 'd/m/Y';
        $symfonyFormat = $this->phpToSymfonyDateFormat($dateFormat);

        $builder
            ->add('nombre', TextType::class, [
                'required' => true,
                'attr' => ['class' => 'form-control'],
                'label_attr' => ['class' => 'form-label required'],
                'label' => 'Nombre'
            ])
            ->add('precio', TextType::class, [
                'required' => true,
                'attr' => ['class' => 'form-control'],
                'label_attr' => ['class' => 'form-label required'],
                'label' => 'Precio'
            ])
            ->add('fechaInicio', DateType::class, [
                'required' => true,
                'attr' => ['class' => 'form-control', 'placeholder' => $dateFormat],
                'label_attr' => ['class' => 'form-label required'],
                'widget' => 'single_text',
                'format' => $symfonyFormat,
                'html5' => false,
                'label' => 'Fecha de Inicio',
                'constraints' => [
                    new Callback([$this, 'validateFechas'])
                ]
            ])
            ->add('fechaFin', DateType::class, [
                'required' => true,
                'attr' => ['class' => 'form-control', 'placeholder' => $dateFormat],
                'label_attr' => ['class' => 'form-label required'],
                'widget' => 'single_text',
                'format' => $symfonyFormat,
                'html5' => false,
                'label' => 'Fecha de Fin',
                'constraints' => [
                    new Callback([$this, 'validateFechas'])
                ]
            ])
            ->add('horarios', CollectionType::class, [
                'entry_type' => CursoHorarioType::class,
                'entry_options' => ['time_choices' => $this->getTimeChoices()],
                'allow_add' => true,
                'allow_delete' => true,
                'by_reference' => false,
                'label' => 'Horarios por día',
                'label_attr' => ['class' => 'form-label'],
            ])
            ->add('profesores', EntityType::class, [
                'class' => Profesor::class,
                'choice_label' => function(Profesor $profesor) {
                    return $profesor->getNombre() . ' ' . $profesor->getApellido();
                },
                'query_builder' => function (EntityRepository $er) use ($instituto) {
                    return $er->createQueryBuilder('p')
                        ->where('p.instituto = :instituto')
                        ->setParameter('instituto', $instituto)
                        ->orderBy('p.nombre', 'ASC');
                },
                'required' => false,
                'multiple' => true,
                'expanded' => false,
                'attr' => ['class' => 'form-control chosen-select'],
                'label_attr' => ['class' => 'form-label'],
                'label' => 'Profesores'
            ]);

        $builder->addEventListener(FormEvents::POST_SUBMIT, function (FormEvent $event) {
            $form = $event->getForm();
            if (!$form->isSubmitted()) {
                return;
            }
            $fechaInicio = $form->get('fechaInicio')->getData();
            $fechaFin = $form->get('fechaFin')->getData();
            if ($fechaInicio && $fechaFin && $fechaFin < $fechaInicio) {
                $form->get('fechaFin')->addError(new FormError('La fecha de fin debe ser posterior a la fecha de inicio'));
            }
            $horarios = $form->get('horarios')->getData();
            $tieneAlMenosUnoCompleto = false;
            if ($horarios) {
                foreach ($horarios as $h) {
                    if ($h && $h->getDia() && $h->getHorarioInicio() && $h->getHorarioFin()) {
                        $tieneAlMenosUnoCompleto = true;
                        $inicio = $h->getHorarioInicio() instanceof \DateTimeInterface ? $h->getHorarioInicio() : new \DateTime($h->getHorarioInicio());
                        $fin = $h->getHorarioFin() instanceof \DateTimeInterface ? $h->getHorarioFin() : new \DateTime($h->getHorarioFin());
                        if ($fin <= $inicio) {
                            $form->get('horarios')->addError(new FormError('En cada fila, el horario de fin debe ser posterior al de inicio.'));
                            break;
                        }
                    }
                }
            }
            if (!$tieneAlMenosUnoCompleto) {
                $form->get('horarios')->addError(new FormError('Agregue al menos un horario completo (día + inicio + fin).'));
            }
        });
    }

    public function validateFechas($object, ExecutionContextInterface $context)
    {
        $form = $context->getRoot();
        $fechaInicio = $form->get('fechaInicio')->getData();
        $fechaFin = $form->get('fechaFin')->getData();

        if ($fechaInicio && $fechaFin && $fechaFin < $fechaInicio) {
            $context->buildViolation('La fecha de fin debe ser posterior a la fecha de inicio')
                ->atPath('fechaFin')
                ->addViolation();
        }
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => Curso::class,
            'instituto' => null,
            'date_format' => 'd/m/Y',
        ]);
    }

    /**
     * Convierte formato PHP (d/m/Y, etc.) al formato que usa Symfony DateType (IntlDateFormatter).
     */
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

    private function getTimeChoices(): array
    {
        $choices = [];
        $start = new \DateTime('00:00');
        $end = new \DateTime('23:59');
        $interval = new \DateInterval('PT15M');
        $period = new \DatePeriod($start, $interval, $end);

        foreach ($period as $time) {
            $choices[$time->format('H:i')] = $time->format('H:i');
        }

        return $choices;
    }
}
