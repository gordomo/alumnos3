<?php

namespace App\Form;

use App\Entity\Alumno;
use Doctrine\ORM\EntityRepository;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\DateType;
use Symfony\Component\Form\Extension\Core\Type\NumberType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

class AlumnoType extends AbstractType
{
    public $alumno;
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $this->alumno = $options['data'];
        $builder
            ->add('telefono_fijo', NumberType::class)
            ->add('nombre', TextType::class)
            ->add('apellido', TextType::class)
            ->add('f_nac', DateType::class, ['widget' => 'single_text', 'html5' => true])
            ->add('email', TextType::class)
            ->add('l_nac', TextType::class)
            ->add('dni', NumberType::class)
            ->add('celular', NumberType::class)
            ->add('contacto_emergencia', TextType::class)
            ->add('n_tutor', TextType::class)
            ->add('t_tutor', NumberType::class)
            ->add('corre_tutor', TextType::class)
            ->add('dni_tutor', NumberType::class)
            ->add('escuela', TextType::class)
            ->add('extras', TextType::class)
            ->add('g_sanguineo', TextType::class)
            ->add('enfermedad', TextType::class)
            ->add('alergico', TextType::class)
            ->add('medicacion', TextType::class)
            ->add('curso', ChoiceType::class, ['choices' => [
                "TODDLERS (4/5 años)" => "TODDLERS",
                "FIRST CONTACT (6 años)" => "FIRST CONTACT",
                "FIRST KIDS (7 años)" => "FIRST KIDS",
                "SECOND KIDS (8/9 años)" => "SECOND KIDS",
                "THIRD KIDS (10 años)" => "THIRD KIDS",
                "FOURTH KIDS (11 años)" => "FOURTH KIDS",
                "FIRST TEENS (12 años)" => "FIRST TEENS",
                "TEENS (13/14 años )" => "TEENS",
                "ADULTS ELEMENTARY (Adultos)" => "ADULTS ELEMENTARY",
                "ADULTS INTERMEDIATE (Adultos)" => "ADULTS INTERMEDIATE",
                ]
            ])
            ->add('como_conociste', ChoiceType::class, ['choices' => [
                "Por Familia" => "Familia",
                "Por Amigos" => "Amigos",
                "Por Facebook" => "Facebook",
                "Por Instagram" => "Instagram",
                "Otra" => "Otra",

            ]])
            ->add('hermanos', EntityType::class, [
                'class' => Alumno::class,
                'choice_label' => 'NombreApellido',
                'query_builder' => function (EntityRepository $er) {
                    $hermanos = $er->createQueryBuilder('u');
                    if (!empty($this->alumno->getId())) {
                        $hermanos->where('u.id != :id')->setParameter('id', $this->alumno->getId());
                    }
                    return $hermanos;
                },
                "mapped" => false,
                'multiple' => true,
                'expanded' => true,
                'label' => 'Hermanos',
            ])
        ;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => Alumno::class,
        ]);
    }
}
