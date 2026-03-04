<?php

namespace App\Form;

use App\Entity\CursoHorario;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\CallbackTransformer;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

class CursoHorarioType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $timeChoices = $options['time_choices'] ?? $this->getTimeChoices();

        $builder
            ->add('dia', ChoiceType::class, [
                'required' => true,
                'label' => 'Día',
                'choices' => [
                    'Lunes' => 'Lunes',
                    'Martes' => 'Martes',
                    'Miercoles' => 'Miercoles',
                    'Jueves' => 'Jueves',
                    'Viernes' => 'Viernes',
                    'Sabado' => 'Sabado',
                    'Domingo' => 'Domingo',
                ],
                'attr' => ['class' => 'form-control form-select'],
                'label_attr' => ['class' => 'form-label'],
            ])
            ->add('horarioInicio', ChoiceType::class, [
                'required' => true,
                'label' => 'Inicio',
                'choices' => $timeChoices,
                'attr' => ['class' => 'form-control form-select horario-inicio-select'],
                'label_attr' => ['class' => 'form-label'],
            ])
            ->add('horarioFin', ChoiceType::class, [
                'required' => true,
                'label' => 'Fin',
                'choices' => $timeChoices,
                'attr' => ['class' => 'form-control form-select horario-fin-select'],
                'label_attr' => ['class' => 'form-label'],
            ]);

        $timeTransformer = new CallbackTransformer(
            function ($dateTime) {
                return $dateTime instanceof \DateTimeInterface ? $dateTime->format('H:i') : '';
            },
            function ($value) {
                if ($value === null || $value === '') {
                    return null;
                }
                $d = \DateTime::createFromFormat('H:i', $value);
                return $d ?: null;
            }
        );
        $builder->get('horarioInicio')->addModelTransformer($timeTransformer);
        $builder->get('horarioFin')->addModelTransformer($timeTransformer);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => CursoHorario::class,
            'time_choices' => [],
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
