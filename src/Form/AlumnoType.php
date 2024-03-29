<?php

namespace App\Form;

use App\Entity\Alumno;
use App\Entity\Curso;
use Doctrine\ORM\EntityRepository;
use PhpOffice\PhpSpreadsheet\Calculation\TextData\Text;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\DateType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
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
            ->add('telefono_fijo', TextType::class, ['attr' => ['class' => 'form-control']])
            ->add('nombre', TextType::class, ['attr' => ['class' => 'form-control']])
            ->add('apellido', TextType::class, ['attr' => ['class' => 'form-control']])
            ->add('f_nac', DateType::class, ['widget' => 'single_text', 'html5' => true, 'attr' => ['class' => 'form-control']])
            ->add('email', EmailType::class, ['attr' => ['class' => 'form-control']])
            ->add('l_nac', TextType::class, ['attr' => ['class' => 'form-control']])
            ->add('dni', TextType::class, ['attr' => ['class' => 'form-control']])
            ->add('celular', TextType::class, ['attr' => ['class' => 'form-control']])
            ->add('contacto_emergencia', TextType::class, ['attr' => ['class' => 'form-control']])
            ->add('n_tutor', TextType::class, ['attr' => ['class' => 'form-control']])
            ->add('t_tutor', TextType::class, ['attr' => ['class' => 'form-control']])
            ->add('corre_tutor', EmailType::class, ['attr' => ['class' => 'form-control']])
            ->add('dni_tutor', TextType::class, ['attr' => ['class' => 'form-control']])
            ->add('escuela', TextType::class, ['attr' => ['class' => 'form-control']])
            ->add('extras', TextType::class, ['attr' => ['class' => 'form-control']])
            ->add('g_sanguineo', TextType::class, ['attr' => ['class' => 'form-control']])
            ->add('enfermedad', TextType::class, ['attr' => ['class' => 'form-control']])
            ->add('alergico', TextType::class, ['attr' => ['class' => 'form-control']])
            ->add('activo', ChoiceType::class, ['attr' => ['class' => 'form-control'], 'choices'  => [
                'Si' => 1,
                'No' => 0,
                ]
            ])
            ->add('medicacion', TextType::class, ['attr' => ['class' => 'form-control']])
            ->add('curso', EntityType::class, [
                'class' => Curso::class,
                'choice_label' => 'nombre',
                'query_builder' => function (EntityRepository $er) {
                    $curso = $er->createQueryBuilder('c');
                    return $curso;
                },
                'multiple' => true,
                'expanded' => false,
                'required' => false,
                'label' => 'Cursos',
                'attr' => ['class' => 'form-control predictivo']
            ])
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
                'mapped' => false,
                'multiple' => true,
                'expanded' => false,
                'required' => false,
                'label' => 'Hermanos',
                'attr' => ['class' => 'form-control predictivo']
            ])
            ->add('como_conociste', ChoiceType::class, ['attr' => ['class' => 'form-control'], 'choices' => [
                "Por Familia" => "Familia",
                "Por Amigos" => "Amigos",
                "Por Facebook" => "Facebook",
                "Por Instagram" => "Instagram",
                "Otra" => "Otra"

            ]])
        ;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => Alumno::class,
        ]);
    }
}
