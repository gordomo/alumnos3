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
use Symfony\Component\OptionsResolver\OptionsResolver;

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
                'label' => 'Fecha de Inicio'
            ])
            ->add('fechaFin', DateType::class, [
                'required' => true,
                'attr' => ['class' => 'form-control'],
                'label_attr' => ['class' => 'form-label required'],
                'widget' => 'single_text',
                'label' => 'Fecha de Fin'
            ])
            ->add('horarioInicio', ChoiceType::class, [
                'required' => true,
                'attr' => ['class' => 'form-control'],
                'label_attr' => ['class' => 'form-label required'],
                'choices' => $this->getTimeChoices(),
                'label' => 'Horario de Inicio'
            ])
            ->add('horarioFin', ChoiceType::class, [
                'required' => true,
                'attr' => ['class' => 'form-control'],
                'label_attr' => ['class' => 'form-label required'],
                'choices' => $this->getTimeChoices(),
                'label' => 'Horario de Fin'
            ])
            ->add('dias', ChoiceType::class, [
                'required' => true,
                'attr' => ['class' => 'form-control chosen-select'],
                'label_attr' => ['class' => 'form-label required'],
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
