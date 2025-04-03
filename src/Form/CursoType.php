<?php

namespace App\Form;

use App\Entity\Curso;
use App\Entity\Profesor;
use Doctrine\ORM\EntityRepository;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\DateType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\Extension\Core\Type\TimeType;
use Symfony\Component\Form\FormBuilderInterface;
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
                'attr' => ['class' => 'form-control'],
                'label_attr' => ['class' => 'form-label required'],
                'widget' => 'single_text',
                'label' => 'Fecha de Inicio',
                'constraints' => [
                    new Callback([$this, 'validateFechas'])
                ]
            ])
            ->add('fechaFin', DateType::class, [
                'required' => true,
                'attr' => ['class' => 'form-control'],
                'label_attr' => ['class' => 'form-label required'],
                'widget' => 'single_text',
                'label' => 'Fecha de Fin',
                'constraints' => [
                    new Callback([$this, 'validateFechas'])
                ]
            ])
            ->add('horarioInicio', ChoiceType::class, [
                'required' => true,
                'mapped' => false,
                'attr' => ['class' => 'form-control'],
                'label_attr' => ['class' => 'form-label required'],
                'choices' => $this->getTimeChoices(),
                'label' => 'Horario de Inicio',
                'constraints' => [
                    new Callback([$this, 'validateHorarios'])
                ]
            ])
            ->add('horarioFin', ChoiceType::class, [
                'required' => true,
                'mapped' => false,
                'attr' => ['class' => 'form-control'],
                'label_attr' => ['class' => 'form-label required'],
                'choices' => $this->getTimeChoices(),
                'label' => 'Horario de Fin',
                'constraints' => [
                    new Callback([$this, 'validateHorarios'])
                ]
            ])
            ->add('dias', ChoiceType::class, [
                'required' => false,
                'attr' => ['class' => 'form-control chosen-select'],
                'label_attr' => ['class' => 'form-label'],
                'choices' => [
                    'Lunes' => 'Lunes',
                    'Martes' => 'Martes',
                    'Miercoles' => 'Miercoles',
                    'Jueves' => 'Jueves',
                    'Viernes' => 'Viernes',
                    'Sabado' => 'Sabado',
                    'Domingo' => 'Domingo'
                ],
                'multiple' => true,
                'expanded' => false,
                'label' => 'Días'
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

        // Agregar validación adicional para asegurar que los horarios se validen junto con las fechas
        $builder->addEventListener(FormEvents::POST_SUBMIT, function (FormEvent $event) {
            $form = $event->getForm();
            $data = $event->getData();

            if ($form->isSubmitted() && $form->isValid()) {
                $fechaInicio = $form->get('fechaInicio')->getData();
                $fechaFin = $form->get('fechaFin')->getData();
                $horaInicio = $form->get('horarioInicio')->getData();
                $horaFin = $form->get('horarioFin')->getData();

                if ($fechaInicio && $fechaFin && $fechaFin < $fechaInicio) {
                    $form->get('fechaFin')->addError(new FormError('La fecha de fin debe ser posterior a la fecha de inicio'));
                }

                if ($horaInicio && $horaFin && $horaFin <= $horaInicio) {
                    $form->get('horarioFin')->addError(new FormError('El horario de fin debe ser posterior al horario de inicio'));
                }
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

    public function validateHorarios($object, ExecutionContextInterface $context)
    {
        $form = $context->getRoot();
        $horaInicio = $form->get('horarioInicio')->getData();
        $horaFin = $form->get('horarioFin')->getData();

        if ($horaInicio && $horaFin && $horaFin <= $horaInicio) {
            $context->buildViolation('El horario de fin debe ser posterior al horario de inicio')
                ->atPath('horarioFin')
                ->addViolation();
        }
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => Curso::class,
            'instituto' => null,
        ]);
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
